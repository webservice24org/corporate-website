<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Expenses;

use App\Models\Accounting\AccountingExpense;
use App\Models\Accounting\AccountingExpenseAttachment;
use App\Models\Accounting\AccountingExpenseAttachmentDownload;
use App\Models\Accounting\AccountingExpenseAttachmentEvent;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AccountingExpenseAttachmentService
{
    public function __construct(private readonly AccountingExpenseWorkflow $workflow, private readonly PurchaseCompanyAccess $access, private readonly AccountingExpenseHistory $history, private readonly AccountingExpenseAttachmentLedger $ledger, private readonly AccountingExpenseAttachmentFiles $files, private readonly AccountingExpenseContract $contract, private readonly AccountingCanonicalPayload $canonical) {}

    /** @return array<string, mixed> */
    public function listing(User $actor, int $expense): array
    {
        $company = $this->authorize($actor, $expense, 'view');

        return DB::transaction(function () use ($actor, $expense, $company): array {
            $row = $this->locked($actor, $expense, $company, 'view');
            $states = $this->ledger->verify($row);
            $rows = $this->ledger->safe($states);

            if (! $actor->can('erp.expense-attachments.download')) {
                foreach ($rows as &$file) {
                    $file['download_url'] = null;
                }
                unset($file);
            }

            $events = [];

            foreach (AccountingExpenseAttachmentEvent::query()->where('company_id', $company)->where('expense_id', $expense)->orderBy('sequence')->get() as $event) {
                $events[] = ['sequence' => (int) $event->getAttribute('sequence'), 'attachment_id' => (int) $event->getAttribute('attachment_id'), 'action' => (string) $event->getAttribute('action'), 'actor_id' => (int) $event->getAttribute('actor_id'), 'reason' => (string) $event->getAttribute('reason'), 'occurred_at' => $event->getAttribute('occurred_at')->format(DATE_ATOM)];
            }

            return ['expense_id' => $expense, 'revision' => (int) $row->getAttribute('revision'), 'attachment_revision' => (int) $row->getAttribute('attachment_revision'), 'data' => $rows, 'history' => $events, 'availability' => 'Bytes are verified at download, submission and approval.', 'can_upload' => $row->getAttribute('status') === 'draft' && $actor->can('erp.expense-attachments.upload') && $actor->can('erp.expenses.edit'), 'can_withdraw' => $row->getAttribute('status') === 'draft' && $actor->can('erp.expense-attachments.withdraw') && $actor->can('erp.expenses.edit')];
        });
    }

    public function upload(User $actor, int $expense, int $revision, string $key, string $reason, UploadedFile $upload): AccountingExpenseAttachment
    {
        $company = $this->authorize($actor, $expense, 'upload');
        $keyHash = $this->contract->key($key);
        $reason = $this->contract->text($reason, 2000);

        $input = $this->files->inspect($upload);
        $request = $input;
        unset($request['bytes']);

        $requestHash = $this->canonical->hash(['actor_id' => (int) $actor->getKey(), 'revision' => $revision, 'reason' => $reason, 'file' => $request]);
        $written = null;
        $created = false;

        try {
            // No automatic transaction retry around external file writes.

            return DB::transaction(function () use ($actor, $expense, $company, $revision, $keyHash, $reason, $input, $requestHash, &$written, &$created): AccountingExpenseAttachment {
                $row = $this->locked($actor, $expense, $company, 'upload');

                $existing = AccountingExpenseAttachment::query()->where('company_id', $company)->where('expense_id', $expense)->where('request_key_hash', $keyHash)->first();

                if ($existing !== null) {
                    if ($existing->getAttribute('request_hash') !== $requestHash) {
                        $this->fail('This upload key belongs to a different actor, file or command.');
                    }

                    $copy = $this->files->snapshot((string) $existing->getAttribute('storage_key'), (string) $existing->getAttribute('sha256'), (int) $existing->getAttribute('size_bytes'));
                    fclose($copy);

                    return $existing;
                }

                $this->draft($row, $revision);
                $states = $this->ledger->verify($row);
                $active = 0;
                $bytes = $input['size'];

                foreach ($states as $state) {
                    $bytes += (int) $state['file']->getAttribute('size_bytes');
                    if ($state['active']) {
                        $active++;
                        if ($state['file']->getAttribute('sha256') === $input['sha256']) {
                            $this->fail('These exact bytes are already active supporting evidence.');
                        }
                    }
                }

                if (count($states) >= AccountingExpenseAttachmentFiles::MAX_FILES || $active >= AccountingExpenseAttachmentFiles::MAX_ACTIVE || $bytes > AccountingExpenseAttachmentFiles::MAX_TOTAL_BYTES) {
                    $this->fail('Expense evidence is limited to 10 active files, 20 retained files and 50 MiB of retained bytes.');
                }

                $before = $this->history->summary($row);
                $at = CarbonImmutable::now('UTC');
                $written = $this->files->key($company, $expense, $input['extension']);

                $this->files->write($written, $input['bytes'], $created);

                $file = AccountingExpenseAttachment::query()->create(['company_id' => $company, 'expense_id' => $expense, 'original_name' => $input['name'], 'extension' => $input['extension'], 'mime_type' => $input['mime'], 'size_bytes' => $input['size'], 'sha256' => $input['sha256'], 'storage_key' => $written, 'uploaded_by' => (int) $actor->getKey(), 'uploaded_at' => $at, 'request_key_hash' => $keyHash, 'request_hash' => $requestHash]);

                $this->append($actor, $row, $file, 'upload', $reason, $at, $before);

                return $file;
            });
        } catch (\Throwable $e) {
            if ($written !== null && $created) {
                // Never delete a referenced file after an ambiguous commit outcome.

                try {
                    if (! AccountingExpenseAttachment::query()->where('storage_key', $written)->exists()) {
                        $this->files->discardUncommitted($written);
                    }
                } catch (\Throwable $cleanup) {
                    report($cleanup);
                }
            }

            throw $e;
        }
    }

    public function withdraw(User $actor, int $expense, int $attachment, int $revision, string $reason): void
    {
        $company = $this->authorize($actor, $expense, 'withdraw');
        $reason = $this->contract->text($reason, 2000);

        DB::transaction(function () use ($actor, $expense, $company, $attachment, $revision, $reason): void {
            $row = $this->locked($actor, $expense, $company, 'withdraw');
            $this->draft($row, $revision);
            $states = $this->ledger->verify($row);

            if (! isset($states[$attachment])) {
                abort(404);
            }

            if (! $states[$attachment]['active']) {
                $this->fail('This retained file has already been withdrawn.');
            }

            $this->append($actor, $row, $states[$attachment]['file'], 'withdraw', $reason, CarbonImmutable::now('UTC'), $this->history->summary($row));
        });
    }

    public function download(User $actor, int $expense, int $attachment): StreamedResponse
    {
        $company = $this->authorize($actor, $expense, 'download');

        $prepared = DB::transaction(function () use ($actor, $expense, $company, $attachment): array {
            $row = $this->locked($actor, $expense, $company, 'download');
            $states = $this->ledger->verify($row);

            if (! isset($states[$attachment])) {
                abort(404);
            }

            $file = $states[$attachment]['file'];
            $copy = $this->files->snapshot((string) $file->getAttribute('storage_key'), (string) $file->getAttribute('sha256'), (int) $file->getAttribute('size_bytes'));

            try {
                AccountingExpenseAttachmentDownload::query()->create(['company_id' => $company, 'expense_id' => $expense, 'attachment_id' => $attachment, 'actor_id' => (int) $actor->getKey(), 'attachment_revision' => (int) $row->getAttribute('attachment_revision'), 'attachment_hash' => (string) $row->getAttribute('attachment_hash'), 'sha256' => (string) $file->getAttribute('sha256'), 'size_bytes' => (int) $file->getAttribute('size_bytes'), 'occurred_at' => CarbonImmutable::now('UTC')]);

                return ['copy' => $copy, 'name' => 'expense-'.$expense.'-attachment-'.$attachment.'.'.$file->getAttribute('extension')];
            } catch (\Throwable $e) {
                fclose($copy);
                throw $e;
            }
        });

        $copy = $prepared['copy'];

        try {
            return response()->streamDownload(static function () use ($copy): void {
                try {
                    fpassthru($copy);
                } finally {
                    fclose($copy);
                }
            }, $prepared['name'], ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'X-Frame-Options' => 'DENY', 'Referrer-Policy' => 'no-referrer']);
        } catch (\Throwable $e) {
            fclose($copy);
            throw $e;
        }
    }

    /** @param array<string, mixed> $before */
    private function append(User $actor, AccountingExpense $row, AccountingExpenseAttachment $file, string $action, string $reason, CarbonImmutable $at, array $before): void
    {
        $sequence = (int) $row->getAttribute('attachment_revision') + 1;
        $previous = $row->getAttribute('attachment_hash');

        $payload = ['contract' => AccountingExpenseAttachmentLedger::VERSION, 'company_id' => (int) $row->getAttribute('company_id'), 'expense_id' => (int) $row->getKey(), 'sequence' => $sequence, 'action' => $action, 'actor_id' => (int) $actor->getKey(), 'reason' => $reason, 'occurred_at' => $at->format('Y-m-d H:i:s'), 'previous_hash' => $previous, 'file' => $this->ledger->file($file)];
        $hash = $this->canonical->hash($payload);

        $event = AccountingExpenseAttachmentEvent::query()->create(['company_id' => $row->getAttribute('company_id'), 'expense_id' => $row->getKey(), 'attachment_id' => $file->getKey(), 'sequence' => $sequence, 'action' => $action, 'actor_id' => (int) $actor->getKey(), 'reason' => $reason, 'occurred_at' => $at, 'previous_hash' => $previous, 'payload' => $payload, 'payload_hash' => $hash]);

        $row->forceFill(['attachment_revision' => $sequence, 'attachment_head_id' => (int) $event->getKey(), 'attachment_hash' => $hash, 'revision' => (int) $row->getAttribute('revision') + 1, 'updated_by' => (int) $actor->getKey(), 'last_edited_by' => (int) $actor->getKey()])->save();

        $this->history->append($row, $actor, 'edit', 'Evidence '.$action.': '.$reason, $before);
        $this->history->verify($row->refresh());
    }

    private function authorize(User $actor, int $expense, string $action): int
    {
        $permission = 'erp.expense-attachments.'.$action;

        if (! in_array($permission, AccountingExpenseAttachmentLedger::PERMISSIONS, true) || ! $actor->can($permission) || ! $actor->can('erp.expense-attachments.view') || (in_array($action, ['upload', 'withdraw'], true) && ! $actor->can('erp.expenses.edit'))) {
            throw new AuthorizationException('Private expense evidence needs its native view/action authority.');
        }

        return (int) $this->workflow->find($actor, $expense)->getAttribute('company_id');
    }

    private function locked(User $actor, int $expense, int $company, string $action): AccountingExpense
    {
        Company::query()->whereKey($company)->lockForUpdate()->firstOrFail();
        $this->authorize($actor, $expense, $action);
        $this->access->authorize($actor, $company, 'erp.expenses.view');

        $row = $this->workflow->find($actor, $expense);
        $this->history->verify($row);

        return $row;
    }

    private function draft(AccountingExpense $row, int $revision): void
    {
        if ($row->getAttribute('status') !== 'draft' || (int) $row->getAttribute('revision') !== $revision) {
            $this->fail('Change evidence only on the current draft revision. Submitted, approved and cancelled evidence is retained.');
        }

        $owner = Company::query()->findOrFail((int) $row->getAttribute('company_id'));

        if ($owner->getAttribute('status') !== 'active') {
            $this->fail('New evidence changes require an active company.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['attachment' => $message]);
    }
}

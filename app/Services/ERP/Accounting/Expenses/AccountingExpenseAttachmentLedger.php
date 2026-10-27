<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Expenses;

use App\Models\Accounting\AccountingExpense;
use App\Models\Accounting\AccountingExpenseAttachment;
use App\Models\Accounting\AccountingExpenseAttachmentEvent;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/** Retained upload/withdrawal chain. Download access logs deliberately do not change approved intent. */
final class AccountingExpenseAttachmentLedger
{
    public const VERSION = 'expense-evidence.v1';

    public const PERMISSIONS = ['erp.expense-attachments.view', 'erp.expense-attachments.upload', 'erp.expense-attachments.withdraw', 'erp.expense-attachments.download'];

    public function __construct(private readonly AccountingCanonicalPayload $canonical, private readonly AccountingExpenseAttachmentFiles $files) {}

    /** @return array<string, mixed> */
    public function file(AccountingExpenseAttachment $file): array
    {
        $data = [];

        foreach (['id', 'company_id', 'expense_id', 'uploaded_by', 'size_bytes'] as $field) {
            $data[$field] = (int) $file->getAttribute($field);
        }

        foreach (['original_name', 'extension', 'mime_type', 'sha256', 'storage_key', 'request_key_hash', 'request_hash'] as $field) {
            $data[$field] = (string) $file->getAttribute($field);
        }

        $data['uploaded_at'] = $file->getAttribute('uploaded_at')->format('Y-m-d H:i:s');

        return $data;
    }

    /** @return array<int, array{file: AccountingExpenseAttachment, active: bool}> */
    public function verify(AccountingExpense $expense): array
    {
        $company = (int) $expense->getAttribute('company_id');
        $id = (int) $expense->getKey();

        $files = [];
        $sequence = 0;
        $hash = null;
        $head = null;
        $bytes = 0;

        foreach (AccountingExpenseAttachment::query()->where('company_id', $company)->where('expense_id', $id)->get() as $file) {
            $files[(int) $file->getKey()] = $file;
        }

        if (count($files) > AccountingExpenseAttachmentFiles::MAX_FILES) {
            $this->fail();
        }

        $states = [];

        foreach (AccountingExpenseAttachmentEvent::query()->where('company_id', $company)->where('expense_id', $id)->orderBy('sequence')->get() as $event) {
            $payload = $event->getAttribute('payload');
            $attachment = (int) $event->getAttribute('attachment_id');

            if (! isset($files[$attachment]) || ! is_array($payload)) {
                $this->fail();
            }

            $file = $files[$attachment];
            $summary = $this->file($file);
            $sequence++;

            $expected = ['contract' => self::VERSION, 'company_id' => $company, 'expense_id' => $id, 'sequence' => $sequence, 'action' => (string) $event->getAttribute('action'), 'actor_id' => (int) $event->getAttribute('actor_id'), 'reason' => (string) $event->getAttribute('reason'), 'occurred_at' => $event->getAttribute('occurred_at')->format('Y-m-d H:i:s'), 'previous_hash' => $hash, 'file' => $summary];

            if ((int) $event->getAttribute('sequence') !== $sequence || $event->getAttribute('previous_hash') !== $hash || $this->canonical->hash($payload) !== $this->canonical->hash($expected) || $event->getAttribute('payload_hash') !== $this->canonical->hash($expected) || trim($expected['reason']) === '') {
                $this->fail();
            }

            $action = $event->getAttribute('action');

            if ($action === 'upload') {
                if (isset($states[$attachment]) || $summary['uploaded_by'] !== $expected['actor_id'] || $summary['uploaded_at'] !== $expected['occurred_at'] || $summary['size_bytes'] < 1 || $summary['size_bytes'] > AccountingExpenseAttachmentFiles::MAX_BYTES || ! in_array($summary['extension'], ['pdf', 'png', 'jpg'], true) || ! preg_match('/\A[a-f0-9]{64}\z/', $summary['sha256']) || ! str_starts_with($summary['storage_key'], 'company/'.$company.'/expense/'.$id.'/')) {
                    $this->fail();
                }

                $states[$attachment] = ['file' => $file, 'active' => true];
                $bytes += $summary['size_bytes'];
            } elseif ($action === 'withdraw') {
                if (! isset($states[$attachment]) || ! $states[$attachment]['active']) {
                    $this->fail();
                }

                $states[$attachment]['active'] = false;
            } else {
                $this->fail();
            }

            $hash = $event->getAttribute('payload_hash');
            $head = (int) $event->getKey();
        }

        $revision = (int) $expense->getAttribute('attachment_revision');
        $storedHead = $expense->getAttribute('attachment_head_id');

        if ($sequence !== $revision || $head !== ($storedHead === null ? null : (int) $storedHead) || $hash !== $expense->getAttribute('attachment_hash') || count($states) !== count($files) || $bytes > AccountingExpenseAttachmentFiles::MAX_TOTAL_BYTES || count(array_filter($states, static fn (array $state): bool => $state['active'])) > AccountingExpenseAttachmentFiles::MAX_ACTIVE) {
            $this->fail();
        }

        return $states;
    }

    public function assertReview(User $actor, AccountingExpense $expense): void
    {
        $states = $this->verify($expense);

        foreach ($states as $state) {
            if (! $state['active']) {
                continue;
            }

            if (! $actor->can('erp.expense-attachments.view') || ! $actor->can('erp.expense-attachments.download')) {
                throw new AuthorizationException('Reviewing attached expense evidence requires its view and download authority.');
            }

            $file = $state['file'];
            $copy = $this->files->snapshot((string) $file->getAttribute('storage_key'), (string) $file->getAttribute('sha256'), (int) $file->getAttribute('size_bytes'));
            fclose($copy);
        }
    }

    /**
     * @param  array<int, array{file: AccountingExpenseAttachment, active: bool}>  $states
     * @return list<array<string, mixed>>
     */
    public function safe(array $states): array
    {
        $rows = [];

        foreach ($states as $state) {
            $file = $state['file'];
            $id = (int) $file->getKey();
            $expense = (int) $file->getAttribute('expense_id');

            $rows[] = ['id' => $id, 'name' => (string) $file->getAttribute('original_name'), 'mime_type' => (string) $file->getAttribute('mime_type'), 'size_bytes' => (int) $file->getAttribute('size_bytes'), 'active' => $state['active'], 'uploaded_by' => (int) $file->getAttribute('uploaded_by'), 'uploaded_at' => $file->getAttribute('uploaded_at')->format(DATE_ATOM), 'download_url' => '/erp/accounting/expenses/'.$expense.'/attachments/'.$id.'/download'];
        }

        return $rows;
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['attachment_integrity' => 'Expense evidence records, revision and protected history head do not agree. Missing evidence is not repaired.']);
    }
}

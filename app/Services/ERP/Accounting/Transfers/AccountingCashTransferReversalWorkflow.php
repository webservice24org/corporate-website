<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Transfers;

use App\Models\Accounting\AccountingCashTransfer;
use App\Models\Accounting\AccountingCashTransferReversalAuditEvent;
use App\Models\Accounting\AccountingCashTransferReversalRequest;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingConfigurationGuard;
use App\Services\ERP\Accounting\Source\AccountingSourcePeriod;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Preparation and independent review only. Commands here prepare/review intent; the separate posting service records the complete reversal operation. */
final class AccountingCashTransferReversalWorkflow
{
    public function __construct(
        private readonly PurchaseCompanyAccess $access,
        private readonly AccountingConfigurationGuard $configuration,
        private readonly AccountingBusinessDate $dates,
        private readonly AccountingSourcePeriod $periods,
        private readonly AccountingCanonicalPayload $canonical,
        private readonly AccountingCashTransferReversalOriginal $original,
        private readonly AccountingCashTransferReversalContract $contract,
        private readonly AccountingCashTransferReversalIntent $intent,
    ) {}

    /** @param array<string, mixed> $input */
    public function prepare(User $actor, int $transferId, array $input, ?int $requestId = null): AccountingCashTransferReversalRequest
    {
        $allowed = ['document_date', 'reason', $requestId === null ? 'key' : 'revision'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            $this->fail('Only the reversal date, reason and request identity may be supplied.');
        }
        $rules = ['document_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'], 'reason' => ['required', 'string', 'max:2000']];
        if ($requestId === null) {
            $rules['key'] = ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{16,64}\z/'];
        } else {
            $rules['revision'] = ['required', 'integer', 'min:1'];
        }
        $data = Validator::make($input, $rules)->validate();
        $data['reason'] = $this->reason($data['reason']);
        $permission = $requestId === null ? 'erp.cash-transfer-reversals.create' : 'erp.cash-transfer-reversals.edit';
        $found = $this->root($actor, $transferId, $permission);
        $companyId = (int) $found->getAttribute('company_id');
        $requestHash = $this->canonical->hash(['maker_id' => (int) $actor->getKey(), 'original_transfer_id' => $transferId, 'document_date' => $data['document_date'], 'reason' => $data['reason']]);

        return DB::transaction(function () use ($actor, $transferId, $companyId, $permission, $requestId, $data, $requestHash): AccountingCashTransferReversalRequest {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $companyId, $permission);
            AccountingCashTransfer::query()->where('company_id', $companyId)->whereKey($transferId)->lockForUpdate()->firstOrFail();
            if ($requestId === null) {
                $existing = AccountingCashTransferReversalRequest::query()->where('company_id', $companyId)->where('creation_key_hash', hash('sha256', $data['key']))->lockForUpdate()->first();
                if ($existing !== null) {
                    if ((int) $existing->getAttribute('original_transfer_id') !== $transferId || (int) $existing->getAttribute('created_by') !== (int) $actor->getKey() || ! hash_equals((string) $existing->getAttribute('creation_request_hash'), $requestHash)) {
                        $this->fail('Creation key belongs to a different original, maker or request.');
                    }
                    // Replay verifies existing proof; it never reopens a cancelled slot or uses current date/setup gates.
                    $this->stored($existing);
                    $this->sameOriginal($existing);
                    if ($existing->getAttribute('status') === 'posted') {
                        app(AccountingCashTransferReversalEvidence::class)->verify($companyId, (int) $existing->getKey());
                    }

                    return $existing;
                }
            }
            $this->configuration->lockForSetup($company);
            $row = $requestId === null ? null : AccountingCashTransferReversalRequest::query()->where('company_id', $companyId)->where('original_transfer_id', $transferId)->lockForUpdate()->findOrFail($requestId);
            if ($row !== null) {
                $this->state($row, (int) $data['revision'], 'draft');
                $this->stored($row);
            }
            $conflict = AccountingCashTransferReversalRequest::query()->where('company_id', $companyId)->where('original_transfer_id', $transferId)
                ->when($requestId !== null, static fn ($query) => $query->where('id', '<>', $requestId))
                ->where(function ($query): void {
                    $query->where('status', '<>', 'cancelled')->orWhereNotNull('active_transfer_id');
                })->exists();
            if ($conflict) {
                $this->fail('An active or inconsistent reversal request already exists for this transfer.');
            }
            $proof = $this->original->capture($companyId, $transferId);
            $this->eligibleDate($actor, $company, $proof, $data['document_date']);
            if ($row !== null && ! hash_equals((string) $row->getAttribute('original_snapshot_hash'), $this->canonical->hash($proof))) {
                $this->fail('Original posting evidence changed. It cannot be refreshed into a different reversal intent.');
            }
            $before = $row === null ? null : $this->summary($row);
            if ($row === null) {
                $row = AccountingCashTransferReversalRequest::query()->create([
                    'company_id' => $companyId, 'original_transfer_id' => $transferId, 'active_transfer_id' => $transferId,
                    'original_journal_id' => $proof['journal_id'], 'source_cash_account_id' => $proof['source']['id'], 'destination_cash_account_id' => $proof['destination']['id'],
                    'currency_code' => $proof['currency_code'], 'amount' => $proof['amount'], 'document_date' => $data['document_date'], 'reason' => $data['reason'],
                    'original_snapshot' => $proof, 'original_snapshot_hash' => $this->canonical->hash($proof), 'creation_key_hash' => hash('sha256', $data['key']), 'creation_request_hash' => $requestHash,
                    'created_by' => (int) $actor->getKey(), 'updated_by' => (int) $actor->getKey(), 'last_edited_by' => (int) $actor->getKey(),
                ]);
            } else {
                $row->forceFill(['document_date' => $data['document_date'], 'reason' => $data['reason'], 'revision' => (int) $row->getAttribute('revision') + 1, 'last_edited_by' => (int) $actor->getKey(), 'updated_by' => (int) $actor->getKey()])->save();
            }
            $this->audit($actor, $row, $requestId === null ? 'created' : 'edited', $data['reason'], $before);

            return $row;
        }, 3);
    }

    public function submit(User $actor, int $id, int $revision): AccountingCashTransferReversalRequest
    {
        return $this->change($actor, $id, $revision, 'submit');
    }

    public function approve(User $actor, int $id, int $revision): AccountingCashTransferReversalRequest
    {
        return $this->change($actor, $id, $revision, 'approve');
    }

    public function reject(User $actor, int $id, int $revision, string $reason): AccountingCashTransferReversalRequest
    {
        return $this->change($actor, $id, $revision, 'reject', $this->reason($reason));
    }

    public function cancel(User $actor, int $id, int $revision, string $reason): AccountingCashTransferReversalRequest
    {
        return $this->change($actor, $id, $revision, 'cancel', $this->reason($reason));
    }

    /** Trusted callers must whitelist models before exposing them through future HTTP endpoints. */
    public function find(User $actor, int $id, string $permission = 'erp.cash-transfer-reversals.view'): AccountingCashTransferReversalRequest
    {
        $this->permission($actor, $permission);
        $query = AccountingCashTransferReversalRequest::query();
        if (! $actor->hasRole('super-admin')) {
            $query->whereIn('company_id', $actor->employee()->select('company_id'));
        }

        return $query->findOrFail($id);
    }

    /** Authorized, read-only proposal. It never validates a request as financially posted. @return array<string, mixed> */
    public function preview(User $actor, int $id): array
    {
        $found = $this->find($actor, $id);
        $companyId = (int) $found->getAttribute('company_id');

        return DB::transaction(function () use ($actor, $id, $companyId): array {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $companyId, 'erp.cash-transfer-reversals.view');
            $row = $this->find($actor, $id);
            $this->stored($row);
            $proof = $this->sameOriginal($row);
            if ($row->getAttribute('status') === 'posted') {
                app(AccountingCashTransferReversalEvidence::class)->verify($companyId, $id);
            }

            return $this->contract->plan($proof, $row->getAttribute('document_date')->format('Y-m-d'));
        }, 3);
    }

    private function change(User $actor, int $id, int $revision, string $action, ?string $reason = null): AccountingCashTransferReversalRequest
    {
        $permission = 'erp.cash-transfer-reversals.'.(in_array($action, ['approve', 'reject'], true) ? 'approve' : $action);
        $found = $this->find($actor, $id, $permission);
        $companyId = (int) $found->getAttribute('company_id');
        $transferId = (int) $found->getAttribute('original_transfer_id');

        return DB::transaction(function () use ($actor, $id, $revision, $action, $reason, $permission, $companyId, $transferId): AccountingCashTransferReversalRequest {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $companyId, $permission);
            $this->configuration->lockForSetup($company);
            AccountingCashTransfer::query()->where('company_id', $companyId)->whereKey($transferId)->lockForUpdate()->firstOrFail();
            $row = AccountingCashTransferReversalRequest::query()->where('company_id', $companyId)->where('original_transfer_id', $transferId)->lockForUpdate()->findOrFail($id);
            $this->stored($row);
            $before = $this->summary($row);
            if ($action === 'cancel') {
                if ($revision < 1 || (int) $row->getAttribute('revision') !== $revision || ! in_array($row->getAttribute('status'), ['draft', 'submitted', 'approved'], true)) {
                    $this->fail('Only a current unposted reversal request may be cancelled.');
                }
                $row->forceFill(['status' => 'cancelled', 'active_transfer_id' => null, 'cancelled_by' => (int) $actor->getKey(), 'cancelled_at' => CarbonImmutable::now('UTC'), 'cancellation_reason' => $reason]);
            } elseif ($action === 'reject') {
                $this->state($row, $revision, 'submitted');
                $row->forceFill(['status' => 'draft', 'submitted_by' => null, 'submitted_at' => null, 'submitted_payload_hash' => null, 'approved_by' => null, 'approved_at' => null, 'approved_payload_hash' => null]);
            } else {
                $this->state($row, $revision, $action === 'submit' ? 'draft' : 'submitted');
                $proof = $this->sameOriginal($row);
                $this->eligibleDate($actor, $company, $proof, $row->getAttribute('document_date')->format('Y-m-d'));
                $hash = $this->intent->hash($row);
                if ($action === 'submit') {
                    $row->forceFill(['status' => 'submitted', 'submitted_by' => (int) $actor->getKey(), 'submitted_at' => CarbonImmutable::now('UTC'), 'submitted_payload_hash' => $hash]);
                } else {
                    if (! $this->contract->independent((int) $actor->getKey(), (int) $row->getAttribute('created_by'), (int) $row->getAttribute('last_edited_by'), (int) $row->getAttribute('submitted_by'))) {
                        throw new AuthorizationException('Approval must be independent of the maker, editor and submitter.');
                    }
                    $row->forceFill(['status' => 'approved', 'approved_by' => (int) $actor->getKey(), 'approved_at' => CarbonImmutable::now('UTC'), 'approved_payload_hash' => $hash]);
                }
            }
            $row->forceFill(['revision' => $revision + 1, 'updated_by' => (int) $actor->getKey()])->save();
            $this->audit($actor, $row, match ($action) {
                'submit' => 'submitted', 'approve' => 'approved', 'reject' => 'rejected', 'cancel' => 'cancelled', default => throw new \LogicException('Unknown reversal action.')
            }, $reason, $before);

            return $row;
        }, 3);
    }

    /** @param array<string, mixed> $proof */
    private function eligibleDate(User $actor, Company $company, array $proof, string $date): void
    {
        $this->contract->plan($proof, $date);
        if ($date > $this->dates->today($company)) {
            $this->fail('The reversal date cannot be in the company future.');
        }
        $this->periods->lock($actor, $company, $date);
    }

    /** @return array<string, mixed> */
    private function sameOriginal(AccountingCashTransferReversalRequest $row): array
    {
        $proof = $this->original->capture((int) $row->getAttribute('company_id'), (int) $row->getAttribute('original_transfer_id'));
        if (! hash_equals((string) $row->getAttribute('original_snapshot_hash'), $this->canonical->hash($proof))) {
            $this->fail('Original posting proof changed. A retry or review cannot repair or refresh it.');
        }

        return $proof;
    }

    private function stored(AccountingCashTransferReversalRequest $row): void
    {
        $state = (string) $row->getAttribute('status');
        $hash = $this->intent->hash($row);
        if (! in_array($state, ['draft', 'submitted', 'approved', 'cancelled', 'posted'], true)) {
            $this->fail('Unsupported stored reversal state.');
        }
        if ($state === 'cancelled') {
            if ($row->getAttribute('active_transfer_id') !== null || (int) $row->getAttribute('cancelled_by') < 1 || $row->getAttribute('cancelled_at') === null || trim((string) $row->getAttribute('cancellation_reason')) === '') {
                $this->fail('Cancelled reversal metadata changed.');
            }
        } elseif ((int) $row->getAttribute('active_transfer_id') !== (int) $row->getAttribute('original_transfer_id') || $row->getAttribute('cancelled_by') !== null || $row->getAttribute('cancelled_at') !== null || $row->getAttribute('cancellation_reason') !== null) {
            $this->fail('Active reversal slot or metadata changed.');
        }
        $submitted = $row->getAttribute('submitted_by') !== null;
        $approved = $row->getAttribute('approved_by') !== null;
        if (($state === 'draft' && ($submitted || $approved)) || ($state === 'submitted' && (! $submitted || $approved)) || (in_array($state, ['approved', 'posted'], true) && (! $submitted || ! $approved))) {
            $this->fail('Stored reversal review state changed.');
        }
        if ($submitted && ((int) $row->getAttribute('submitted_by') < 1 || $row->getAttribute('submitted_at') === null || ! hash_equals($hash, (string) $row->getAttribute('submitted_payload_hash')))) {
            $this->fail('Stored submitted reversal intent changed.');
        }
        if ($approved && (! $submitted || $row->getAttribute('approved_at') === null || ! $this->contract->independent((int) $row->getAttribute('approved_by'), (int) $row->getAttribute('created_by'), (int) $row->getAttribute('last_edited_by'), (int) $row->getAttribute('submitted_by')) || ! hash_equals($hash, (string) $row->getAttribute('approved_payload_hash')))) {
            $this->fail('Stored approved reversal intent changed.');
        }
        if (! $submitted && ($row->getAttribute('submitted_at') !== null || $row->getAttribute('submitted_payload_hash') !== null)) {
            $this->fail('Unexpected submitted reversal stamps.');
        }
        if (! $approved && ($row->getAttribute('approved_at') !== null || $row->getAttribute('approved_payload_hash') !== null)) {
            $this->fail('Unexpected approval stamps.');
        }
    }

    private function root(User $actor, int $id, string $permission): AccountingCashTransfer
    {
        $this->permission($actor, $permission);
        $query = AccountingCashTransfer::query();
        if (! $actor->hasRole('super-admin')) {
            $query->whereIn('company_id', $actor->employee()->select('company_id'));
        }

        return $query->findOrFail($id);
    }

    private function permission(User $actor, string $permission): void
    {
        if (! in_array($permission, AccountingCashTransferReversalContract::PERMISSIONS, true) || ! $actor->exists || ! $actor->can($permission)) {
            throw new AuthorizationException('Native transfer reversal permission is required.');
        }
    }

    private function state(AccountingCashTransferReversalRequest $row, int $revision, string $state): void
    {
        if ($revision < 1 || $row->getAttribute('status') !== $state || (int) $row->getAttribute('revision') !== $revision) {
            $this->fail('Reversal state or revision changed; reload before continuing.');
        }
    }

    private function reason(string $reason): string
    {
        $value = trim($reason);
        if ($value === '' || mb_strlen($value) > 2000) {
            $this->fail('Provide a reason of up to 2,000 characters.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function summary(AccountingCashTransferReversalRequest $row): array
    {
        return ['status' => (string) $row->getAttribute('status'), 'revision' => (int) $row->getAttribute('revision'), 'original_transfer_id' => (int) $row->getAttribute('original_transfer_id'), 'document_date' => $row->getAttribute('document_date')->format('Y-m-d'), 'amount' => (string) $row->getAttribute('amount'), 'currency_code' => (string) $row->getAttribute('currency_code')];
    }

    /** @param array<string, mixed>|null $before */
    private function audit(User $actor, AccountingCashTransferReversalRequest $row, string $action, ?string $reason, ?array $before): void
    {
        AccountingCashTransferReversalAuditEvent::query()->create(['company_id' => (int) $row->getAttribute('company_id'), 'reversal_request_id' => (int) $row->getKey(), 'actor_id' => (int) $actor->getKey(), 'action' => $action, 'reason' => $reason, 'before_payload' => $before, 'after_payload' => $this->summary($row), 'occurred_at' => CarbonImmutable::now('UTC')]);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['cash_transfer_reversal' => $message]);
    }
}

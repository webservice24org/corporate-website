<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Transfers;

use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingCashTransfer;
use App\Models\Accounting\AccountingCashTransferAuditEvent;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingConfigurationGuard;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Native preparation only. An approved transfer is not posted, reserved, reconciled or moved money. */
final class AccountingCashTransferWorkflow
{
    public function __construct(
        private readonly PurchaseCompanyAccess $access,
        private readonly AccountingConfigurationGuard $configuration,
        private readonly AccountingMappingService $mappings,
        private readonly AccountingBusinessDate $dates,
        private readonly AccountingCanonicalPayload $canonical,
        private readonly AccountingCashTransferContract $contract,
        private readonly AccountingCashTransferIntent $intent,
        private readonly AccountingCashTransferNumberService $numbers,
    ) {}

    public function find(User $actor, int $id, string $permission = 'erp.cash-transfers.view'): AccountingCashTransfer
    {
        if (! in_array($permission, AccountingCashTransferContract::PERMISSIONS, true) || ! $actor->exists || ! $actor->can($permission)) {
            throw new AuthorizationException('Cash transfer permission is required.');
        }
        $query = AccountingCashTransfer::query();
        if (! $actor->hasRole('super-admin')) {
            $query->whereIn('company_id', $actor->employee()->select('company_id'));
        }

        return $query->findOrFail($id);
    }

    /** @param array<string, mixed> $input */
    public function save(User $actor, array $input, ?int $id = null): AccountingCashTransfer
    {
        $allowed = ['company_id', 'document_date', 'source_cash_account_id', 'destination_cash_account_id', 'amount', 'purpose', 'notes', $id === null ? 'key' : 'revision'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            $this->fail('Unexpected transfer input field.');
        }
        $rules = [
            'company_id' => ['required', 'integer', 'min:1'], 'document_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'],
            'source_cash_account_id' => ['required', 'integer', 'min:1'], 'destination_cash_account_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required'], 'purpose' => ['required', 'string', 'max:2000'], 'notes' => ['nullable', 'string', 'max:4000'],
        ];
        if ($id === null) {
            $rules['key'] = ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{16,64}\z/'];
        } else {
            $rules['revision'] = ['required', 'integer', 'min:1'];
        }
        $data = Validator::make($input, $rules)->validate();
        $data['company_id'] = (int) $data['company_id'];
        $data['source_cash_account_id'] = (int) $data['source_cash_account_id'];
        $data['destination_cash_account_id'] = (int) $data['destination_cash_account_id'];
        $data['amount'] = $this->contract->amount($data['amount']);
        $data['purpose'] = trim($data['purpose']);
        $data['notes'] = isset($data['notes']) && trim($data['notes']) !== '' ? trim($data['notes']) : null;
        if ($data['purpose'] === '') {
            $this->fail('Provide the transfer purpose.');
        }
        $permission = $id === null ? 'erp.cash-transfers.create' : 'erp.cash-transfers.edit';
        $this->access->authorize($actor, $data['company_id'], $permission);
        $request = array_diff_key($data, array_flip(['key', 'revision']));
        $requestHash = $this->canonical->hash(['maker_id' => (int) $actor->getKey(), 'request' => $request]);

        return DB::transaction(function () use ($actor, $data, $id, $permission, $requestHash): AccountingCashTransfer {
            $company = Company::query()->whereKey($data['company_id'])->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, (int) $company->getKey(), $permission);
            if ($id === null) {
                $existing = AccountingCashTransfer::query()->where('company_id', $company->getKey())->where('creation_key_hash', hash('sha256', $data['key']))->first();
                if ($existing !== null) {
                    if ((int) $existing->getAttribute('created_by') !== (int) $actor->getKey() || ! hash_equals((string) $existing->getAttribute('creation_request_hash'), $requestHash)) {
                        $this->fail('The creation key belongs to a different request or maker.');
                    }
                    $state = (string) $existing->getAttribute('status');
                    if (! in_array($state, ['draft', 'submitted', 'approved', 'cancelled', 'posted'], true)) {
                        $this->fail('Existing transfer state is unsupported by this foundation.');
                    }
                    $currentHash = $this->intent->hash($existing);
                    if ((in_array($state, ['submitted', 'approved', 'posted'], true) && ! hash_equals((string) $existing->getAttribute('submitted_payload_hash'), $currentHash)) || (in_array($state, ['approved', 'posted'], true) && ! hash_equals((string) $existing->getAttribute('approved_payload_hash'), $currentHash))) {
                        $this->fail('Existing reviewed transfer evidence changed; a creation retry cannot repair it.');
                    }
                    if ($state === 'posted') {
                        app(AccountingCashTransferEvidence::class)->verify((int) $company->getKey(), (int) $existing->getKey());
                    }

                    return $existing;
                }
            }
            $this->configuration->lockForSetup($company);
            $row = $id === null ? null : AccountingCashTransfer::query()->where('company_id', $company->getKey())->lockForUpdate()->findOrFail($id);
            if ($row !== null) {
                $this->state($row, (int) $data['revision'], 'draft');
                if ($row->getAttribute('document_date')->format('Y-m-d') !== $data['document_date'] || $row->getAttribute('currency_code') !== $company->getAttribute('currency_code')) {
                    $this->fail('Numbered transfer date/currency cannot change.');
                }
            }
            $context = $this->bindings($company, $data['document_date'], $data['source_cash_account_id'], $data['destination_cash_account_id'], $data['amount']);
            $before = $row === null ? null : $this->snapshot($row);
            $values = [
                'source_cash_account_id' => $data['source_cash_account_id'], 'destination_cash_account_id' => $data['destination_cash_account_id'],
                'amount' => $data['amount'], 'purpose' => $data['purpose'], 'notes' => $data['notes'],
                'source_snapshot' => $context['source'], 'destination_snapshot' => $context['destination'],
                'mapping_version_id' => $context['version_id'], 'mapping_hash' => $context['mapping_hash'],
                'updated_by' => (int) $actor->getKey(), 'last_edited_by' => (int) $actor->getKey(),
            ];
            if ($row === null) {
                $row = AccountingCashTransfer::query()->create([
                    ...$values, 'company_id' => (int) $company->getKey(), 'document_date' => $data['document_date'],
                    'currency_code' => (string) $company->getAttribute('currency_code'),
                    'number' => $this->numbers->next((int) $company->getKey(), $data['document_date']),
                    'creation_key_hash' => hash('sha256', $data['key']), 'creation_request_hash' => $requestHash,
                    'created_by' => (int) $actor->getKey(),
                ]);
            } else {
                $row->forceFill([...$values, 'revision' => (int) $row->getAttribute('revision') + 1])->save();
            }
            $this->audit($actor, $row, $id === null ? 'created' : 'edited', null, $before);

            return $row;
        }, 3);
    }

    public function submit(User $actor, int $id, int $revision): AccountingCashTransfer
    {
        return $this->change($actor, $id, 'erp.cash-transfers.submit', function (Company $company, AccountingCashTransfer $row) use ($actor, $revision): AccountingCashTransfer {
            $this->state($row, $revision, 'draft');
            $this->review($company, $row);
            $before = $this->snapshot($row);
            $row->forceFill(['status' => 'submitted', 'revision' => $revision + 1, 'submitted_by' => (int) $actor->getKey(), 'submitted_at' => CarbonImmutable::now('UTC'), 'submitted_payload_hash' => $this->intent->hash($row), 'updated_by' => (int) $actor->getKey()])->save();
            $this->audit($actor, $row, 'submitted', null, $before);

            return $row;
        });
    }

    public function approve(User $actor, int $id, int $revision): AccountingCashTransfer
    {
        return $this->change($actor, $id, 'erp.cash-transfers.approve', function (Company $company, AccountingCashTransfer $row) use ($actor, $revision): AccountingCashTransfer {
            $this->state($row, $revision, 'submitted');
            $this->review($company, $row);
            if (in_array((int) $actor->getKey(), [(int) $row->getAttribute('created_by'), (int) $row->getAttribute('last_edited_by'), (int) $row->getAttribute('submitted_by')], true)) {
                $this->fail('Approval requires an independent actor who did not create, edit or submit this intent.');
            }
            $hash = $this->intent->hash($row);
            if (! hash_equals((string) $row->getAttribute('submitted_payload_hash'), $hash)) {
                $this->fail('Submitted transfer intent changed; return it for review.');
            }
            $before = $this->snapshot($row);
            $row->forceFill(['status' => 'approved', 'revision' => $revision + 1, 'approved_by' => (int) $actor->getKey(), 'approved_at' => CarbonImmutable::now('UTC'), 'approved_payload_hash' => $hash, 'updated_by' => (int) $actor->getKey()])->save();
            $this->audit($actor, $row, 'approved', null, $before);

            return $row;
        });
    }

    public function reject(User $actor, int $id, int $revision, string $reason): AccountingCashTransfer
    {
        $reason = $this->reason($reason);

        return $this->change($actor, $id, 'erp.cash-transfers.approve', function (Company $company, AccountingCashTransfer $row) use ($actor, $revision, $reason): AccountingCashTransfer {
            $this->state($row, $revision, 'submitted');
            $before = $this->snapshot($row);
            $row->forceFill(['status' => 'draft', 'revision' => $revision + 1, 'submitted_by' => null, 'submitted_at' => null, 'submitted_payload_hash' => null, 'approved_by' => null, 'approved_at' => null, 'approved_payload_hash' => null, 'updated_by' => (int) $actor->getKey()])->save();
            $this->audit($actor, $row, 'rejected', $reason, $before);

            return $row;
        });
    }

    public function cancel(User $actor, int $id, int $revision, string $reason): AccountingCashTransfer
    {
        $reason = $this->reason($reason);

        return $this->change($actor, $id, 'erp.cash-transfers.cancel', function (Company $company, AccountingCashTransfer $row) use ($actor, $revision, $reason): AccountingCashTransfer {
            if ((int) $row->getAttribute('revision') !== $revision || ! in_array($row->getAttribute('status'), ['draft', 'submitted', 'approved'], true)) {
                $this->fail('Transfer state/revision changed; reload before cancellation.');
            }
            $before = $this->snapshot($row);
            $row->forceFill(['status' => 'cancelled', 'revision' => $revision + 1, 'cancelled_by' => (int) $actor->getKey(), 'cancelled_at' => CarbonImmutable::now('UTC'), 'cancellation_reason' => $reason, 'updated_by' => (int) $actor->getKey()])->save();
            $this->audit($actor, $row, 'cancelled', $reason, $before);

            return $row;
        });
    }

    /** @param callable(Company, AccountingCashTransfer): AccountingCashTransfer $action */
    private function change(User $actor, int $id, string $permission, callable $action): AccountingCashTransfer
    {
        $found = $this->find($actor, $id, $permission);
        $companyId = (int) $found->getAttribute('company_id');

        return DB::transaction(function () use ($actor, $id, $companyId, $permission, $action): AccountingCashTransfer {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $companyId, $permission);
            $this->configuration->lockForSetup($company);
            $row = AccountingCashTransfer::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);

            return $action($company, $row);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function bindings(Company $company, string $date, int $sourceId, int $destinationId, string $amount): array
    {
        if ($date > $this->dates->today($company)) {
            $this->fail('A transfer cannot have a future company business date.');
        }
        $rows = AccountingCashAccount::query()->where('company_id', $company->getKey())->whereIn('id', [$sourceId, $destinationId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $source = $rows->get($sourceId);
        $destination = $rows->get($destinationId);
        if ($source === null || $destination === null) {
            throw (new ModelNotFoundException)->setModel(AccountingCashAccount::class);
        }
        if ($source->getAttribute('status') !== 'active' || $destination->getAttribute('status') !== 'active') {
            $this->fail('Both transfer bindings must be active for new intent.');
        }
        $a = $this->binding($source);
        $b = $this->binding($destination);
        $this->contract->plan((int) $company->getKey(), (string) $company->getAttribute('currency_code'), $amount, $a, $b);
        $mapping = $this->mappings->resolveForSource($company, $date, [$a['role'], $b['role']]);
        if ($mapping['accounts'][$a['role']] !== $a['account_id'] || $mapping['accounts'][$b['role']] !== $b['account_id']) {
            $this->fail('The effective profile no longer matches the fixed transfer binding identities.');
        }

        return ['source' => $a, 'destination' => $b, 'version_id' => $mapping['version_id'], 'mapping_hash' => $mapping['payload_hash']];
    }

    /** @return array{id: int, company_id: int, account_id: int, role: string, kind: string, currency_code: string} */
    private function binding(AccountingCashAccount $row): array
    {
        return ['id' => (int) $row->getKey(), 'company_id' => (int) $row->getAttribute('company_id'), 'account_id' => (int) $row->getAttribute('account_id'), 'role' => (string) $row->getAttribute('role'), 'kind' => (string) $row->getAttribute('kind'), 'currency_code' => (string) $row->getAttribute('currency_code')];
    }

    private function review(Company $company, AccountingCashTransfer $row): void
    {
        $current = $this->bindings($company, $row->getAttribute('document_date')->format('Y-m-d'), (int) $row->getAttribute('source_cash_account_id'), (int) $row->getAttribute('destination_cash_account_id'), (string) $row->getAttribute('amount'));
        $source = $row->getAttribute('source_snapshot');
        $destination = $row->getAttribute('destination_snapshot');
        if (! is_array($source) || ! is_array($destination)) {
            $this->fail('Transfer binding snapshots are damaged.');
        }
        if ((string) $company->getAttribute('currency_code') !== $row->getAttribute('currency_code') || $this->canonical->hash($current['source']) !== $this->canonical->hash($source) || $this->canonical->hash($current['destination']) !== $this->canonical->hash($destination) || $current['version_id'] !== (int) $row->getAttribute('mapping_version_id') || $current['mapping_hash'] !== $row->getAttribute('mapping_hash')) {
            $this->fail('Transfer setup changed. Edit a returned draft and submit a fresh intent.');
        }
    }

    private function state(AccountingCashTransfer $row, int $revision, string $status): void
    {
        if ((int) $row->getAttribute('revision') !== $revision || $row->getAttribute('status') !== $status) {
            $this->fail('Transfer state/revision changed; reload before continuing.');
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(AccountingCashTransfer $row): array
    {
        return ['status' => (string) $row->getAttribute('status'), 'revision' => (int) $row->getAttribute('revision'), 'source_cash_account_id' => (int) $row->getAttribute('source_cash_account_id'), 'destination_cash_account_id' => (int) $row->getAttribute('destination_cash_account_id'), 'amount' => (string) $row->getAttribute('amount'), 'currency_code' => (string) $row->getAttribute('currency_code')];
    }

    /** @param array<string, mixed>|null $before */
    private function audit(User $actor, AccountingCashTransfer $row, string $action, ?string $reason, ?array $before): void
    {
        AccountingCashTransferAuditEvent::query()->create(['company_id' => (int) $row->getAttribute('company_id'), 'cash_transfer_id' => (int) $row->getKey(), 'actor_id' => (int) $actor->getKey(), 'action' => $action, 'reason' => $reason, 'before_payload' => $before, 'after_payload' => $this->snapshot($row), 'occurred_at' => CarbonImmutable::now('UTC')]);
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000) {
            $this->fail('Provide a reason of up to 2,000 characters.');
        }

        return $reason;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['cash_transfer' => $message]);
    }
}

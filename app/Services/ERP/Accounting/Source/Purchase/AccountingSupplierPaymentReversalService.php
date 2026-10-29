<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Purchase;

use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\AccountingSupplierPaymentOperation;
use App\Models\Company;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\SupplierPaymentAuditLog;
use App\Models\SupplierPaymentReversalRequest;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Native maker/checker review followed by atomic, exact, complete cash reversal. No HTTP endpoint in B3. */
final class AccountingSupplierPaymentReversalService
{
    public function __construct(private readonly AccountingSupplierPaymentService $payments, private readonly PurchaseCompanyAccess $access, private readonly AccountingSupplierPaymentOperations $operations, private readonly AccountingSupplierPaymentReversalOperations $reversals, private readonly AccountingSupplierPaymentReversalIntent $intent, private readonly AccountingSupplierPaymentChangeContract $contract, private readonly AccountingSupplierPaymentPosting $posting, private readonly AccountingSupplierPaymentLedger $ledger, private readonly SupplierPaymentNumberService $numbers, private readonly AccountingBusinessDate $dates, private readonly AccountingCanonicalPayload $canonical) {}

    /** @param array<string, mixed> $input */
    public function prepare(User $actor, int $paymentId, array $input, ?int $requestId = null): SupplierPaymentReversalRequest
    {
        $data = Validator::make($input, ['document_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:2000'], 'revision' => [$requestId === null ? 'nullable' : 'required', 'integer', 'min:1']])->validate();
        if (array_diff(array_keys($input), ['document_date', 'reason', 'revision']) !== []) {
            $this->fail('Unsupported reversal preparation input.');
        }
        $found = $this->payments->find($actor, $paymentId, 'erp.supplier-payments.reverse');
        $companyId = (int) $found->getAttribute('company_id');
        $this->access->authorize($actor, $companyId, 'erp.supplier-payments.reverse');

        return DB::transaction(function () use ($actor, $paymentId, $companyId, $data, $requestId): SupplierPaymentReversalRequest {
            $company = Company::query()->lockForUpdate()->findOrFail($companyId);
            $setting = AccountingSetting::query()->where('company_id', $companyId)->lockForUpdate()->first();
            $root = SupplierPayment::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($paymentId);
            $this->writable($company, $setting, false);
            $this->access->authorize($actor, $companyId, 'erp.supplier-payments.reverse');
            $this->original($root);
            $this->posting->verify($companyId, $paymentId);
            if (SupplierPayment::query()->where('reverses_payment_id', $paymentId)->exists() || SupplierPaymentReversalRequest::query()->where('supplier_payment_id', $paymentId)->where('status', '!=', 'cancelled')->when($requestId !== null, static fn ($q) => $q->where('id', '!=', $requestId))->exists()) {
                $this->fail('An active reversal request or completed reversal already exists.');
            }
            $this->date($company, $data['document_date']);
            $reason = $this->reason($data['reason']);
            $position = $this->operations->position($root);
            $this->contract->reversal($position['root'], $this->operations->history($root), ['revision' => $position['root']['revision'], 'history_hash' => $position['history_hash'], 'key' => 'reversal_preview_only', 'reason' => $reason]);
            if ($requestId === null) {
                $row = new SupplierPaymentReversalRequest;
                $row->forceFill(['company_id' => $companyId, 'supplier_payment_id' => $paymentId, 'created_by' => $actor->getKey()]);
            } else {
                $row = SupplierPaymentReversalRequest::query()->where('company_id', $companyId)->where('supplier_payment_id', $paymentId)->lockForUpdate()->findOrFail($requestId);
                $this->state($row, (int) $data['revision'], 'draft');
                $row->setAttribute('revision', (int) $row->getAttribute('revision') + 1);
            }
            $row->forceFill(['document_date' => $data['document_date'], 'reason' => $reason, 'original_revision' => $position['root']['revision'], 'original_payload_hash' => $root->getAttribute('payload_hash'), 'history_hash' => $position['history_hash'], 'last_edited_by' => $actor->getKey(), 'updated_by' => $actor->getKey()])->save();
            $this->audit($actor, $row, $requestId === null ? 'reversal_prepared' : 'reversal_edited', $reason);

            return $row;
        }, 3);
    }

    public function submit(User $actor, int $id, int $revision): SupplierPaymentReversalRequest
    {
        return $this->review($actor, $id, $revision, 'submit');
    }

    public function approve(User $actor, int $id, int $revision): SupplierPaymentReversalRequest
    {
        return $this->review($actor, $id, $revision, 'approve');
    }

    public function reject(User $actor, int $id, int $revision, string $reason): SupplierPaymentReversalRequest
    {
        return $this->review($actor, $id, $revision, 'reject', $this->reason($reason));
    }

    public function cancel(User $actor, int $id, int $revision, string $reason): SupplierPaymentReversalRequest
    {
        return $this->review($actor, $id, $revision, 'cancel', $this->reason($reason));
    }

    private function review(User $actor, int $id, int $revision, string $action, ?string $reason = null): SupplierPaymentReversalRequest
    {
        $permission = in_array($action, ['approve', 'reject'], true) ? 'erp.supplier-payments.approve' : 'erp.supplier-payments.reverse';
        $found = $this->find($actor, $id, $permission);
        $companyId = (int) $found->getAttribute('company_id');

        return DB::transaction(function () use ($actor, $id, $revision, $action, $reason, $permission, $companyId): SupplierPaymentReversalRequest {
            $company = Company::query()->lockForUpdate()->findOrFail($companyId);
            $setting = AccountingSetting::query()->where('company_id', $companyId)->lockForUpdate()->first();
            $row = SupplierPaymentReversalRequest::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            $this->access->authorize($actor, $companyId, $permission);
            $this->writable($company, $setting, false);
            $root = SupplierPayment::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail((int) $row->getAttribute('supplier_payment_id'));
            $this->original($root);
            if ($action === 'cancel') {
                if (! in_array($row->getAttribute('status'), ['draft', 'submitted', 'approved'], true) || (int) $row->getAttribute('revision') !== $revision) {
                    $this->fail('Only a current unposted reversal request can be cancelled.');
                } $row->forceFill(['status' => 'cancelled', 'cancellation_reason' => $reason, 'cancelled_by' => $actor->getKey(), 'cancelled_at' => CarbonImmutable::now('UTC')]);
            } elseif ($action === 'reject') {
                $this->state($row, $revision, 'submitted');
                $row->forceFill(['status' => 'draft', 'submitted_by' => null, 'submitted_at' => null, 'submitted_payload_hash' => null, 'approved_by' => null, 'approved_at' => null, 'approved_payload_hash' => null]);
            } else {
                $this->state($row, $revision, $action === 'submit' ? 'draft' : 'submitted');
                $this->current($company, $root, $row);
                $hash = $this->intent->hash($row);
                if ($action === 'submit') {
                    $row->forceFill(['status' => 'submitted', 'submitted_by' => $actor->getKey(), 'submitted_at' => CarbonImmutable::now('UTC'), 'submitted_payload_hash' => $hash]);
                } else {
                    if (in_array((int) $actor->getKey(), [(int) $row->getAttribute('created_by'), (int) $row->getAttribute('last_edited_by')], true)) {
                        throw new AuthorizationException('An independent reversal approver is required.');
                    } if ($row->getAttribute('submitted_payload_hash') !== $hash) {
                        $this->fail('Submitted reversal intent changed.');
                    } $row->forceFill(['status' => 'approved', 'approved_by' => $actor->getKey(), 'approved_at' => CarbonImmutable::now('UTC'), 'approved_payload_hash' => $hash]);
                }
            }
            $row->forceFill(['revision' => $revision + 1, 'updated_by' => $actor->getKey()])->save();
            $this->audit($actor, $row, 'reversal_'.$action, $reason);

            return $row;
        }, 3);
    }

    public function post(User $actor, int $id, int $revision, string $key): SupplierPaymentReversalRequest
    {
        Validator::make(['revision' => $revision, 'key' => $key], ['revision' => ['required', 'integer', 'min:1'], 'key' => ['required', 'regex:/\A[A-Za-z0-9_-]{16,64}\z/']])->validate();
        $found = $this->find($actor, $id, 'erp.supplier-payments.reverse');
        $companyId = (int) $found->getAttribute('company_id');

        return DB::transaction(function () use ($actor, $id, $revision, $key, $companyId): SupplierPaymentReversalRequest {
            $company = Company::query()->lockForUpdate()->findOrFail($companyId);
            $setting = AccountingSetting::query()->where('company_id', $companyId)->lockForUpdate()->first();
            $row = SupplierPaymentReversalRequest::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            $this->access->authorize($actor, $companyId, 'erp.supplier-payments.reverse');
            $root = SupplierPayment::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail((int) $row->getAttribute('supplier_payment_id'));
            $this->original($root);
            if ($row->getAttribute('status') === 'posted') {
                if ($row->getAttribute('idempotency_key') !== $key) {
                    $this->fail('Reversal retry differs from its recorded key.');
                }
                $this->posting->verify($companyId, (int) $root->getKey());
                $this->balances($root);
                $proof = $this->reversals->forPayment($companyId, (int) $root->getKey());
                $record = AccountingSupplierPaymentOperation::query()->where('company_id', $companyId)->where('supplier_payment_id', $root->getKey())->where('kind', 'reverse')->where('payload_hash', $this->canonical->hash($proof['body']))->firstOrFail();
                $this->posting->replayReverse($actor, $root, $record);

                return $row;
            }
            $this->writable($company, $setting, true);
            $this->state($row, $revision, 'approved');
            $this->current($company, $root, $row);
            $hash = $this->intent->hash($row);
            if ($row->getAttribute('approved_payload_hash') !== $hash || $row->getAttribute('submitted_payload_hash') !== $hash || SupplierPayment::query()->where('reverses_payment_id', $root->getKey())->exists()) {
                $this->fail('Reversal approval changed or original cash is already reversed.');
            }
            if (SupplierPayment::query()->where('company_id', $companyId)->where('idempotency_key', $key)->exists() || SupplierPaymentReversalRequest::query()->where('company_id', $companyId)->where('idempotency_key', $key)->exists() || AccountingSupplierPaymentOperation::query()->where('company_id', $companyId)->where('kind', 'reverse')->where('key_hash', hash('sha256', $key))->exists()) {
                $this->fail('This company reversal posting key is already in use.');
            }
            $plan = $this->contract->reversal($this->operations->root($root), $this->operations->history($root), ['revision' => (int) $row->getAttribute('original_revision'), 'history_hash' => (string) $row->getAttribute('history_hash'), 'key' => $key, 'reason' => (string) $row->getAttribute('reason')]);
            $balances = $this->balances($root);
            $restored = [];
            $instant = CarbonImmutable::now('UTC');
            if ($this->dates->fromInstant($company, $instant) !== $row->getAttribute('document_date')->format('Y-m-d')) {
                $this->fail('The company business date changed during reversal preparation; obtain fresh date review.');
            }
            $members = [];
            foreach ($plan['compensations'] as $ordinal => $original) {
                $bill = $original['supplier_bill_id'];
                $restored[$bill] = bcadd($restored[$bill] ?? '0.00', $original['amount'], 2);
                $allocation = SupplierPaymentAllocation::query()->create(['company_id' => $companyId, 'supplier_payment_id' => $root->getKey(), 'supplier_bill_id' => $bill, 'supplier_payable_id' => $original['supplier_payable_id'], 'amount' => $original['amount'], 'reverses_allocation_id' => $original['id'], 'posted_by' => $actor->getKey(), 'posted_at' => $instant, 'payload_hash' => $plan['request_hash'], 'idempotency_key' => $key.':reverse:'.$ordinal, 'reason' => $row->getAttribute('reason')]);
                $members[] = ['source_kind' => 'supplier_payment_allocation', 'source_id' => (int) $allocation->getKey(), 'event_kind' => 'reversed'];
            }
            foreach ($restored as $bill => $amount) {
                $balance = $balances[$bill];
                if (bccomp($amount, $balance['paid'], 2) > 0) {
                    $this->fail('Full reversal exceeds verified paid AP.');
                } DB::table('supplier_payables')->where('id', $balance['payable_id'])->where('company_id', $companyId)->update(['paid_amount' => bcsub($balance['paid'], $amount, 2)]);
            }
            $cash = SupplierPayment::query()->create([...$root->only(['supplier_id', 'currency_code', 'cash_account_id', 'payment_method', 'amount', 'advance_amount', 'supplier_snapshot', 'issuer_snapshot']), 'company_id' => $companyId, 'number' => $this->numbers->next($companyId, $row->getAttribute('document_date')->format('Y-m-d')), 'document_date' => $row->getAttribute('document_date')->format('Y-m-d'), 'reason' => $row->getAttribute('reason'), 'status' => 'posted', 'revision' => 1, 'reverses_payment_id' => $root->getKey(), 'created_by' => $row->getAttribute('created_by'), 'last_edited_by' => $row->getAttribute('last_edited_by'), 'updated_by' => $actor->getKey(), 'submitted_by' => $row->getAttribute('submitted_by'), 'submitted_at' => $row->getAttribute('submitted_at'), 'approved_by' => $row->getAttribute('approved_by'), 'approved_at' => $row->getAttribute('approved_at'), 'posted_by' => $actor->getKey(), 'posted_at' => $instant, 'submitted_payload_hash' => $hash, 'approved_payload_hash' => $hash, 'payload_hash' => $hash, 'idempotency_key' => $key]);
            $row->forceFill(['status' => 'posted', 'revision' => $revision + 1, 'reversal_payment_id' => $cash->getKey(), 'idempotency_key' => $key, 'posted_by' => $actor->getKey(), 'posted_at' => $instant, 'updated_by' => $actor->getKey()])->save();
            $members[] = ['source_kind' => 'supplier_payment', 'source_id' => (int) $cash->getKey(), 'event_kind' => 'reversed'];
            $body = ['company_id' => $companyId, 'supplier_payment_id' => (int) $root->getKey(), 'kind' => 'reverse', 'contract_version' => AccountingSupplierPaymentContract::VERSION, 'key_hash' => $plan['key_hash'], 'request_hash' => $plan['request_hash'], 'members' => $members];
            $record = AccountingSupplierPaymentOperation::query()->create([...$body, 'payload_hash' => $this->canonical->hash($body), 'created_by' => $actor->getKey()]);
            $this->posting->recordReverse($actor, $root, $record);
            $this->audit($actor, $row, 'reversal_posted', null);

            return $row;
        }, 3);
    }

    public function find(User $actor, int $id, string $permission = 'erp.supplier-payments.reverse'): SupplierPaymentReversalRequest
    {
        if (! $actor->exists || ! $actor->can($permission)) {
            throw new AuthorizationException('Reversal workflow permission required.');
        } $q = SupplierPaymentReversalRequest::query();
        if (! $actor->hasRole('super-admin')) {
            $q->whereIn('company_id', $actor->employee()->select('company_id'));
        }

        return $q->findOrFail($id);
    }

    /** @return array<int, array{payable_id: int, amount: string, paid: string, credited: string, outstanding: string}> */
    private function balances(SupplierPayment $root): array
    {
        $ids = array_unique(array_column($this->operations->history($root), 'supplier_bill_id'));
        sort($ids, SORT_NUMERIC);
        $rows = [];
        foreach ($ids as $id) {
            $rows[$id] = $this->ledger->bill((int) $root->getAttribute('company_id'), (int) $root->getAttribute('supplier_id'), (string) $root->getAttribute('currency_code'), $id, true);
        }

        return $rows;
    }

    private function current(Company $company, SupplierPayment $root, SupplierPaymentReversalRequest $row): void
    {
        $this->posting->verify((int) $company->getKey(), (int) $root->getKey());
        $this->date($company, $row->getAttribute('document_date')->format('Y-m-d'));
        $position = $this->operations->position($root);
        if ($position['history_hash'] !== $row->getAttribute('history_hash') || $root->getAttribute('payload_hash') !== $row->getAttribute('original_payload_hash')) {
            $this->fail('Original payment or allocation history changed; prepare fresh independent review.');
        }
    }

    private function original(SupplierPayment $row): void
    {
        if ($row->getAttribute('status') !== 'posted' || $row->getAttribute('reverses_payment_id') !== null) {
            $this->fail('Full reversal requires an original posted payment.');
        }
    }

    private function writable(Company $company, ?AccountingSetting $setting, bool $posting): void
    {
        if ($company->getAttribute('status') !== 'active' || ($setting !== null && $setting->getAttribute('cutover_locked_at') !== null) || ($posting && ($setting === null || $setting->getAttribute('state') !== 'enabled'))) {
            $this->fail('Reversal writes require an active unlocked company; new cash reversal additionally requires enabled accounting.');
        }
    }

    private function date(Company $company, string $date): void
    {
        if ($date !== $this->dates->today($company)) {
            $this->fail('Use the current company business date for a new reversal. An expired approval needs fresh date review.');
        }
    }

    private function reason(string $reason): string
    {
        $value = trim($reason);
        if ($value === '' || mb_strlen($value) > 2000) {
            $this->fail('Provide a reversal reason of up to two thousand characters.');
        }

        return $value;
    }

    private function state(SupplierPaymentReversalRequest $row, int $revision, string $state): void
    {
        if ((int) $row->getAttribute('revision') !== $revision || $row->getAttribute('status') !== $state) {
            $this->fail('Reversal state or revision changed; reload it.');
        }
    }

    private function audit(User $actor, SupplierPaymentReversalRequest $row, string $action, ?string $reason): void
    {
        SupplierPaymentAuditLog::query()->create(['company_id' => $row->getAttribute('company_id'), 'supplier_payment_id' => $row->getAttribute('supplier_payment_id'), 'actor_id' => $actor->getKey(), 'action' => $action, 'reason' => $reason, 'old_values' => null, 'new_values' => ['reversal_request_id' => (int) $row->getKey(), 'status' => $row->getAttribute('status'), 'revision' => (int) $row->getAttribute('revision')], 'created_at' => CarbonImmutable::now('UTC')]);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['supplier_payment_reversal' => $message]);
    }
}

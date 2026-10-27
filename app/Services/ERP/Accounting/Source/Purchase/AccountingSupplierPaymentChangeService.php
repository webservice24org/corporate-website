<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Purchase;

use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\AccountingSupplierPaymentOperation;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\SupplierPaymentAuditLog;
use App\Models\SupplierPaymentReversalRequest;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Internal authorized allocation writer. It never changes original cash, approval or initial targets. */
final class AccountingSupplierPaymentChangeService
{
    public function __construct(private readonly AccountingSupplierPaymentService $payments, private readonly PurchaseCompanyAccess $access, private readonly AccountingSupplierPaymentOperations $operations, private readonly AccountingSupplierPaymentChangeContract $contract, private readonly AccountingSupplierPaymentPosting $posting, private readonly AccountingSupplierPaymentLedger $ledger, private readonly AccountingCanonicalPayload $canonical) {}

    /** Append exact compensations followed by new allocations in one complete financial transaction.
     * @param  array<string, mixed>  $request
     */
    public function change(User $actor, int $id, array $request): SupplierPayment
    {
        Validator::make($request, ['key' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{16,64}\z/']])->validate();
        // Resolve/authorize outside this transaction so a waiting lock cannot retain a pre-lock MySQL snapshot.
        $found = $this->payments->find($actor, $id, 'erp.supplier-payments.allocate');
        $companyId = (int) $found->getAttribute('company_id');
        $this->access->authorize($actor, $companyId, 'erp.supplier-payments.allocate');

        return DB::transaction(function () use ($actor, $id, $request, $companyId): SupplierPayment {
            $company = Company::query()->lockForUpdate()->findOrFail($companyId);
            $setting = AccountingSetting::query()->where('company_id', $companyId)->lockForUpdate()->first();
            $payment = SupplierPayment::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            $this->access->authorize($actor, $companyId, 'erp.supplier-payments.allocate');
            if ($payment->getAttribute('status') !== 'posted' || $payment->getAttribute('reverses_payment_id') !== null) {
                $this->fail('Only an original posted payment can fund a later allocation.');
            }
            $record = AccountingSupplierPaymentOperation::query()->where('company_id', $companyId)->where('supplier_payment_id', $id)->where('kind', 'change')->where('key_hash', hash('sha256', $request['key']))->lockForUpdate()->first();
            // Historical retry precedes today's settings, history, supplier and period gates.
            if ($record !== null) {
                $this->posting->verify($companyId, $id);
                $this->checkPayables($payment);
                $proof = $this->operations->verifyChange($payment, $record);
                $plan = $this->contract->change($this->operations->root($payment), $proof['history_before'], $request);
                if (! hash_equals((string) $record->getAttribute('request_hash'), $plan['request_hash'])) {
                    $this->fail('Allocation retry differs from its original recorded intent.');
                }
                $this->posting->replayChange($actor, $payment, $record);

                return $payment;
            }
            $this->posting->verify($companyId, $id);
            if (AccountingSupplierPaymentOperation::query()->where('company_id', $companyId)->where('kind', 'change')->where('key_hash', hash('sha256', $request['key']))->exists()) {
                $this->fail('This company allocation operation key is already in use.');
            }
            if ($company->getAttribute('status') !== 'active' || $setting === null || $setting->getAttribute('state') !== 'enabled' || $setting->getAttribute('cutover_locked_at') !== null) {
                $this->fail('New allocation operations require an active, enabled and unlocked accounting company.');
            }
            if (SupplierPayment::query()->where('reverses_payment_id', $id)->exists() || SupplierPaymentReversalRequest::query()->where('supplier_payment_id', $id)->whereIn('status', ['draft', 'submitted', 'approved', 'posted'])->exists()) {
                $this->fail('A prepared or posted reversal blocks new allocation changes.');
            }
            Supplier::query()->where('company_id', $companyId)->where('currency_code', $payment->getAttribute('currency_code'))->lockForUpdate()->findOrFail((int) $payment->getAttribute('supplier_id'));
            $before = $this->operations->position($payment);
            $plan = $this->contract->change($before['root'], $this->operations->history($payment), $request);
            $billIds = array_unique([...array_column($this->operations->history($payment), 'supplier_bill_id'), ...array_column($plan['targets'], 'supplier_bill_id')]);
            sort($billIds, SORT_NUMERIC);
            $balances = [];
            $restored = [];
            $new = [];
            foreach ($plan['compensations'] as $compensation) {
                $bill = $compensation['supplier_bill_id'];
                $restored[$bill] = bcadd($restored[$bill] ?? '0.00', $compensation['amount'], 2);
            }
            foreach ($plan['targets'] as $target) {
                $new[$target['supplier_bill_id']] = $target['amount'];
            }
            foreach ($billIds as $bill) {
                $balance = $this->ledger->bill($companyId, (int) $payment->getAttribute('supplier_id'), (string) $payment->getAttribute('currency_code'), $bill, true);
                $restore = $restored[$bill] ?? '0.00';
                $allocate = $new[$bill] ?? '0.00';
                if (bccomp($restore, $balance['paid'], 2) > 0 || bccomp($allocate, bcadd($balance['outstanding'], $restore, 2), 2) > 0) {
                    $this->fail('Compensation or new allocation exceeds verified supplier AP.');
                }
                $balances[$bill] = $balance;
            }
            $instant = CarbonImmutable::now('UTC');
            if (SupplierPaymentAllocation::query()->where('supplier_payment_id', $id)->where('posted_at', '>', $instant)->lockForUpdate()->first(['id']) !== null) {
                $this->fail('A new operation cannot predate existing payment allocation history.');
            }
            $members = [];
            $ordinal = 0;
            foreach ($plan['compensations'] as $compensation) {
                $row = $this->append($actor, $payment, $request['key'], $ordinal++, $plan['request_hash'], trim($request['reason']), $instant, $compensation['supplier_bill_id'], $compensation['supplier_payable_id'], $compensation['amount'], $compensation['id']);
                $members[] = ['source_kind' => 'supplier_payment_allocation', 'source_id' => (int) $row->getKey(), 'event_kind' => 'reversed'];
            }
            foreach ($plan['targets'] as $target) {
                $row = $this->append($actor, $payment, $request['key'], $ordinal++, $plan['request_hash'], trim($request['reason']), $instant, $target['supplier_bill_id'], $balances[$target['supplier_bill_id']]['payable_id'], $target['amount'], null);
                $members[] = ['source_kind' => 'supplier_payment_allocation', 'source_id' => (int) $row->getKey(), 'event_kind' => 'created'];
            }
            foreach ($balances as $bill => $balance) {
                if (! isset($restored[$bill]) && ! isset($new[$bill])) {
                    continue;
                }
                $paid = bcadd(bcsub($balance['paid'], $restored[$bill] ?? '0.00', 2), $new[$bill] ?? '0.00', 2);
                DB::table('supplier_payables')->where('id', $balance['payable_id'])->where('company_id', $companyId)->update(['paid_amount' => $paid]);
            }
            $body = ['company_id' => $companyId, 'supplier_payment_id' => $id, 'kind' => 'change', 'contract_version' => AccountingSupplierPaymentContract::VERSION, 'key_hash' => $plan['key_hash'], 'request_hash' => $plan['request_hash'], 'members' => $members];
            $record = AccountingSupplierPaymentOperation::query()->create([...$body, 'payload_hash' => $this->canonical->hash($body), 'created_by' => $actor->getKey()]);
            $this->posting->recordChange($actor, $payment, $record);
            $after = $this->operations->position($payment);
            SupplierPaymentAuditLog::query()->create(['company_id' => $companyId, 'supplier_payment_id' => $id, 'actor_id' => $actor->getKey(), 'action' => 'allocations_changed', 'reason' => trim($request['reason']), 'old_values' => ['history_hash' => $before['history_hash'], 'available' => $before['available']], 'new_values' => ['history_hash' => $after['history_hash'], 'available' => $after['available'], 'operation_hash' => $record->getAttribute('payload_hash')], 'created_at' => $instant]);

            return $payment;
        }, 3);
    }

    /** Historical retries must also reconcile the current paid summaries, without rewriting them. */
    private function checkPayables(SupplierPayment $payment): void
    {
        $ids = array_unique(array_column($this->operations->history($payment), 'supplier_bill_id'));
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            $this->ledger->bill((int) $payment->getAttribute('company_id'), (int) $payment->getAttribute('supplier_id'), (string) $payment->getAttribute('currency_code'), $id, true);
        }
    }

    private function append(User $actor, SupplierPayment $payment, string $key, int $ordinal, string $hash, string $reason, CarbonImmutable $instant, int $bill, int $payable, string $amount, ?int $original): SupplierPaymentAllocation
    {
        return SupplierPaymentAllocation::query()->create(['company_id' => $payment->getAttribute('company_id'), 'supplier_payment_id' => $payment->getKey(), 'supplier_bill_id' => $bill, 'supplier_payable_id' => $payable, 'amount' => $amount, 'reverses_allocation_id' => $original, 'posted_by' => $actor->getKey(), 'posted_at' => $instant, 'payload_hash' => $hash, 'idempotency_key' => $key.':change:'.$ordinal, 'reason' => $reason]);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['supplier_payment' => $message]);
    }
}

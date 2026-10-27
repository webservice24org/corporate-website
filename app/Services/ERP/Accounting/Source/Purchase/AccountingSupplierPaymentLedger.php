<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Purchase;

use App\Models\Accounting\AccountingSupplierCredit;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Services\ERP\Accounting\AccountingDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Current supported AP balance. Does not reinterpret old paid summaries as a payment ledger. */
final class AccountingSupplierPaymentLedger
{
    public function __construct(private readonly AccountingDecimal $decimal, private readonly AccountingPurchaseEvidence $purchase, private readonly AccountingSupplierPaymentPosting $posting, private readonly AccountingSupplierPaymentEvidence $evidence, private readonly AccountingSupplierPaymentOperations $operations) {}

    /** Caller holds company/settings locks; bills are requested in stable target order.
     * @return array{payable_id: int, amount: string, paid: string, credited: string, outstanding: string}
     */
    public function bill(int $company, int $supplier, string $currency, int $id, bool $financial): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('AP balance verification requires its native transaction.');
        }
        $bill = SupplierBill::query()->where('company_id', $company)->where('supplier_id', $supplier)->where('currency_code', $currency)->where('status', 'approved')->lockForUpdate()->findOrFail($id);
        $payable = DB::table('supplier_payables')->where('company_id', $company)->where('supplier_id', $supplier)->where('supplier_bill_id', $id)->where('currency_code', $currency)->lockForUpdate()->first();
        if ($payable === null) {
            $this->fail('Owned bill payable is missing.');
        }
        $amount = $this->decimal->money((string) $bill->getAttribute('grand_total'));
        if ($this->decimal->money((string) $payable->amount) !== $amount) {
            $this->fail('Payable original amount differs from the bill.');
        }
        if ($financial) {
            $this->purchase->recognized($company, 'supplier_bill', $id);
        }
        if (AccountingSupplierCredit::query()->where('supplier_bill_id', $id)->where('company_id', '<>', $company)->exists()) {
            $this->fail('Supplier credit history crosses company ownership.');
        }
        $credited = '0.00';
        foreach (AccountingSupplierCredit::query()->where('company_id', $company)->where('supplier_bill_id', $id)->where('status', 'issued')->orderBy('id')->get() as $credit) {
            if ($financial) {
                $this->purchase->recognized($company, 'supplier_credit', (int) $credit->getKey());
            } $credited = $this->decimal->total([$credited, (string) $credit->getAttribute('amount')]);
        }
        $paid = '0.00';
        $verified = [];
        foreach (SupplierPaymentAllocation::query()->where('supplier_bill_id', $id)->orderBy('id')->cursor() as $allocation) {
            if ((int) $allocation->getAttribute('company_id') !== $company) {
                $this->fail('Payment allocation history crosses company ownership.');
            }
            $paymentId = (int) $allocation->getAttribute('supplier_payment_id');
            $parent = SupplierPayment::query()->where('company_id', $company)->where('supplier_id', $supplier)->where('currency_code', $currency)->where('status', 'posted')->whereNull('reverses_payment_id')->findOrFail($paymentId);
            if (! isset($verified[$paymentId])) {
                if ($financial) {
                    $this->posting->verify($company, $paymentId);
                } else {
                    $this->operations->position($parent);
                } $verified[$paymentId] = true;
            }
            if ($financial) {
                $this->evidence->recognized($company, 'supplier_payment_allocation', (int) $allocation->getKey());
            }
            if ((int) $allocation->getAttribute('supplier_payable_id') !== (int) $payable->id) {
                $this->fail('Allocation payable identity differs.');
            }
            $value = $this->decimal->money((string) $allocation->getAttribute('amount'));
            $paid = $allocation->getAttribute('reverses_allocation_id') === null ? $this->decimal->total([$paid, $value]) : bcsub($paid, $value, 2);
            if (bccomp($paid, '0', 2) < 0) {
                $this->fail('Payment compensation exceeds its original recognized allocation.');
            }
        }
        if ($paid !== $this->decimal->money((string) $payable->paid_amount) || $credited !== $this->decimal->money((string) $payable->credited_amount) || bccomp($this->decimal->total([$paid, $credited]), $amount, 2) > 0) {
            $this->fail('Paid/credited summaries do not reconcile to authoritative native history.');
        }

        return ['payable_id' => (int) $payable->id, 'amount' => $amount, 'paid' => $paid, 'credited' => $credited, 'outstanding' => bcsub(bcsub($amount, $paid, 2), $credited, 2)];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['supplier_payment' => $message]);
    }
}

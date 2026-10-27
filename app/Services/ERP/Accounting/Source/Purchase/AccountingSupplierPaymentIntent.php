<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Purchase;

use App\Models\Accounting\AccountingCashAccount;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentTarget;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Private native fingerprint. Personal/reference fields are hashed here, never copied to GL evidence. */
final class AccountingSupplierPaymentIntent
{
    public function __construct(private readonly AccountingSupplierPaymentContract $contract, private readonly AccountingCanonicalPayload $canonical) {}

    /** @return list<array{supplier_bill_id: int, supplier_payable_id: int, amount: string}> */
    public function targets(SupplierPayment $payment): array
    {
        $this->transaction();
        $rows = SupplierPaymentTarget::query()->where('supplier_payment_id', $payment->getKey())->orderBy('supplier_bill_id')->limit(101)->get();
        $targets = [];
        $split = [];
        foreach ($rows as $row) {
            if ((int) $row->getAttribute('company_id') !== (int) $payment->getAttribute('company_id')) {
                throw ValidationException::withMessages(['supplier_payment' => 'Initial target ownership differs.']);
            }
            $targets[] = ['supplier_bill_id' => (int) $row->getAttribute('supplier_bill_id'), 'supplier_payable_id' => (int) $row->getAttribute('supplier_payable_id'), 'amount' => (string) $row->getAttribute('amount')];
            $split[] = ['supplier_bill_id' => (int) $row->getAttribute('supplier_bill_id'), 'amount' => (string) $row->getAttribute('amount')];
        }
        $this->contract->initialSplit((string) $payment->getAttribute('amount'), $split, (string) $payment->getAttribute('advance_amount'));

        return $targets;
    }

    public function hash(SupplierPayment $payment): string
    {
        $this->transaction();
        $date = $payment->getAttribute('document_date');
        if (! $date instanceof \DateTimeInterface) {
            throw ValidationException::withMessages(['supplier_payment' => 'Native payment date is missing.']);
        }
        $cash = AccountingCashAccount::query()->where('company_id', $payment->getAttribute('company_id'))->findOrFail((int) $payment->getAttribute('cash_account_id'));
        $binding = ['id' => (int) $cash->getKey(), 'role' => (string) $cash->getAttribute('role'), 'account_id' => (int) $cash->getAttribute('account_id'), 'currency_code' => (string) $cash->getAttribute('currency_code'), 'kind' => (string) $cash->getAttribute('kind')];
        $header = $payment->only(['id', 'number', 'company_id', 'supplier_id', 'currency_code', 'cash_account_id', 'payment_method', 'amount', 'advance_amount', 'payment_reference', 'notes', 'supplier_snapshot', 'issuer_snapshot', 'created_by', 'last_edited_by']);
        foreach (['id', 'company_id', 'supplier_id', 'cash_account_id', 'created_by', 'last_edited_by'] as $identity) {
            $header[$identity] = (int) $header[$identity];
        }
        $header['document_date'] = $date->format('Y-m-d');

        return $this->canonical->hash(['cash_binding' => $binding, 'contract' => AccountingSupplierPaymentContract::VERSION, 'header' => $header, 'targets' => $this->targets($payment)]);
    }

    private function transaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Payment intent belongs to a caller-owned native transaction.');
        }
    }
}

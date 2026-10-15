<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use App\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Trusted internal reader; callers authorize and lock the company first. No cash writes. */
final class SalesReceiptLedger
{
    public function money(string $value, bool $positive = false): string
    {
        if (preg_match('/\A[0-9]{1,16}(?:\.[0-9]{1,2})?\z/', $value) !== 1) {
            $this->fail('Use nonnegative monetary values with at most two decimal places.');
        }
        $value = bcadd($value, '0', 2);
        if ($positive && bccomp($value, '0', 2) <= 0) {
            $this->fail('Amount must be greater than zero.');
        }

        return $value;
    }

    /** @return array{allocated: string, available: string, reversed: bool, reversal_id: int|null, is_reversal: bool, active_ids: list<int>} */
    public function state(CustomerReceipt $receipt): array
    {
        $this->transaction();
        $receipt = CustomerReceipt::query()->lockForUpdate()->findOrFail($receipt->getKey());
        if (! in_array($receipt->getAttribute('status'), ['draft', 'posted', 'cancelled'], true) || (($receipt->getAttribute('status') === 'posted') !== ($receipt->getAttribute('posted_at') !== null))) {
            $this->fail('Receipt posting state requires review.');
        }
        $amount = $this->money((string) $receipt->getAttribute('amount'), true);
        $isReversal = $receipt->getAttribute('reverses_receipt_id') !== null;
        if ($isReversal) {
            $original = CustomerReceipt::query()->lockForUpdate()->findOrFail($receipt->getAttribute('reverses_receipt_id'));
            $this->pair($original, $receipt);
        }
        $reversal = CustomerReceipt::query()->where('reverses_receipt_id', $receipt->getKey())->lockForUpdate()->first();
        if ($reversal !== null) {
            $this->pair($receipt, $reversal);
        }
        $allocated = '0.00';
        $activeIds = [];
        $rows = CustomerReceiptAllocation::query()->where('customer_receipt_id', $receipt->getKey())->whereNull('reverses_allocation_id')->orderBy('id')->lockForUpdate()->get();
        foreach ($rows as $row) {
            if ((int) $row->getAttribute('company_id') !== (int) $receipt->getAttribute('company_id') || $row->getAttribute('posted_at') === null) {
                $this->fail('Receipt allocation ownership/posting metadata requires review.');
            }
            $value = $this->money((string) $row->getAttribute('amount'), true);
            $undo = CustomerReceiptAllocation::query()->where('reverses_allocation_id', $row->getKey())->lockForUpdate()->first();
            if ($undo !== null) {
                foreach (['company_id', 'customer_receipt_id', 'sales_invoice_id'] as $field) {
                    if ((int) $undo->getAttribute($field) !== (int) $row->getAttribute($field)) {
                        $this->fail('Allocation reversal identity requires review.');
                    }
                }
                if ($undo->getAttribute('posted_at') === null || $this->money((string) $undo->getAttribute('amount'), true) !== $value || CustomerReceiptAllocation::query()->where('reverses_allocation_id', $undo->getKey())->exists()) {
                    $this->fail('Allocation reversal amount or reversal chain requires review.');
                }

                continue;
            }
            $invoice = SalesInvoice::query()->where('company_id', $receipt->getAttribute('company_id'))->findOrFail($row->getAttribute('sales_invoice_id'));
            if ((int) $invoice->getAttribute('customer_id') !== (int) $receipt->getAttribute('customer_id') || $invoice->getAttribute('currency_code') !== $receipt->getAttribute('currency_code') || $invoice->getAttribute('status') !== 'issued' || $invoice->getAttribute('issued_at') === null) {
                $this->fail('Active allocation requires a matching issued invoice.');
            }
            $allocated = $this->money(bcadd($allocated, $value, 2));
            $activeIds[] = (int) $row->getKey();
        }
        // Detect reversal rows attached to another receipt, absent originals or reversal-of-reversal corruption.
        $undos = CustomerReceiptAllocation::query()->where('customer_receipt_id', $receipt->getKey())->whereNotNull('reverses_allocation_id')->lockForUpdate()->get();
        foreach ($undos as $undo) {
            $original = CustomerReceiptAllocation::query()->findOrFail($undo->getAttribute('reverses_allocation_id'));
            if ($original->getAttribute('reverses_allocation_id') !== null || (int) $original->getAttribute('customer_receipt_id') !== (int) $receipt->getKey() || (int) $undo->getAttribute('company_id') !== (int) $receipt->getAttribute('company_id')) {
                $this->fail('Allocation compensation lineage requires review.');
            }
        }
        if (bccomp($allocated, $amount, 2) > 0 || (($reversal !== null || $isReversal || $receipt->getAttribute('status') !== 'posted') && bccomp($allocated, '0', 2) > 0)) {
            $this->fail('Receipt amount, posting or reversal state does not support its active allocations.');
        }

        return ['allocated' => $allocated, 'available' => $receipt->getAttribute('status') === 'posted' && ! $isReversal && $reversal === null ? bcsub($amount, $allocated, 2) : '0.00',
            'reversed' => $reversal !== null, 'reversal_id' => $reversal === null ? null : (int) $reversal->getKey(), 'is_reversal' => $isReversal, 'active_ids' => $activeIds];
    }

    public function availableAdvance(int $companyId, int $customerId, string $currency): string
    {
        $this->transaction();
        $receipts = CustomerReceipt::query()->where('company_id', $companyId)->where('customer_id', $customerId)->where('currency_code', $currency)->orderBy('id')->lockForUpdate()->get();
        $amount = '0.00';
        foreach ($receipts as $receipt) {
            $state = $this->state($receipt);
            $amount = $this->money(bcadd($amount, $state['available'], 2));
        }

        return $amount;
    }

    private function pair(CustomerReceipt $original, CustomerReceipt $reversal): void
    {
        if ($original->getAttribute('status') !== 'posted' || $original->getAttribute('posted_at') === null || $original->getAttribute('reverses_receipt_id') !== null
            || $reversal->getAttribute('status') !== 'posted' || $reversal->getAttribute('posted_at') === null || (int) $reversal->getAttribute('reverses_receipt_id') !== (int) $original->getKey()
            || (int) $original->getAttribute('company_id') !== (int) $reversal->getAttribute('company_id') || (int) $original->getAttribute('customer_id') !== (int) $reversal->getAttribute('customer_id')
            || $original->getAttribute('currency_code') !== $reversal->getAttribute('currency_code') || $this->money((string) $original->getAttribute('amount'), true) !== $this->money((string) $reversal->getAttribute('amount'), true)
            || trim((string) $reversal->getAttribute('reversal_reason')) === '') {
            $this->fail('Receipt reversal identity, amount, reason or posting state requires review.');
        }
    }

    private function transaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Receipt ledger reads require an authorized caller transaction and company lock.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['receipt' => $message]);
    }
}

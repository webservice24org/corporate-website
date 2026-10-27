<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source;

use App\Services\ERP\Accounting\Adjustments\AccountingBankAdjustmentAdapter;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseFinanceAdapter;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferAdapter;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferReversalAdapter;
use Illuminate\Validation\ValidationException;

/** Closed production bindings. Explicit constructor bindings remain available for isolated contract tests. */
final class AccountingSourceEventRegistry
{
    /** @param array<string, class-string> $bindings */
    public function __construct(private readonly array $bindings = [
        'expense_finance.posted' => AccountingExpenseFinanceAdapter::class,
        'bank_adjustment.posted' => AccountingBankAdjustmentAdapter::class,
        'cash_transfer_reversal.posted' => AccountingCashTransferReversalAdapter::class,
        'cash_transfer.posted' => AccountingCashTransferAdapter::class,
        'goods_receipt.posted' => Purchase\GoodsReceiptAccountingAdapter::class,
        'supplier_bill.approved' => Purchase\SupplierBillAccountingAdapter::class,
        'supplier_payment.posted' => Purchase\SupplierPaymentAccountingAdapter::class,
        'supplier_payment.reversed' => Purchase\SupplierPaymentAccountingAdapter::class,
        'supplier_payment_allocation.created' => Purchase\SupplierPaymentAllocationAccountingAdapter::class,
        'supplier_payment_allocation.reversed' => Purchase\SupplierPaymentAllocationAccountingAdapter::class,
        'supplier_credit.issued' => Purchase\SupplierCreditAccountingAdapter::class,
        'purchase_return.posted' => Purchase\PurchaseReturnAccountingAdapter::class,
        'stock_transfer.posted' => Purchase\StockTransferAccountingAdapter::class,
        'sales_delivery.posted' => Sales\SalesDeliveryAccountingAdapter::class,
        'sales_invoice.issued' => Sales\SalesInvoiceAccountingAdapter::class,
        'sales_return.posted' => Sales\SalesReturnAccountingAdapter::class,
        'sales_credit_note.issued' => Sales\SalesCreditNoteAccountingAdapter::class,
        'customer_credit_allocation.created' => Sales\CustomerCreditAllocationAccountingAdapter::class,
        'customer_credit_allocation.reversed' => Sales\CustomerCreditAllocationAccountingAdapter::class,
        'customer_receipt.posted' => Sales\CustomerReceiptAccountingAdapter::class,
        'customer_receipt.reversed' => Sales\CustomerReceiptAccountingAdapter::class,
        'customer_receipt_allocation.created' => Sales\CustomerReceiptAllocationAccountingAdapter::class,
        'customer_receipt_allocation.reversed' => Sales\CustomerReceiptAllocationAccountingAdapter::class,
    ]) {}

    /** @return array<string, array<string, list<string>>> */
    public function contracts(): array
    {
        return [
            'expense_finance.posted' => ['financial' => []],
            'bank_adjustment.posted' => ['cash' => []],
            'cash_transfer_reversal.posted' => ['cash' => []],
            'cash_transfer.posted' => ['cash' => []],
            'goods_receipt.posted' => ['valuation' => ['zero_source_cost', 'nonstock_completion', 'subcent_valuation']],
            'supplier_bill.approved' => ['commercial' => []],
            'supplier_payment.posted' => ['cash' => []],
            'supplier_payment.reversed' => ['cash' => []],
            'supplier_payment_allocation.created' => ['allocation' => []],
            'supplier_payment_allocation.reversed' => ['allocation' => []],
            'supplier_credit.issued' => ['commercial' => []],
            'purchase_return.posted' => ['valuation' => ['zero_source_cost', 'subcent_valuation']],
            'sales_delivery.posted' => ['valuation' => ['zero_source_cost', 'nonstock_completion', 'subcent_valuation']],
            'sales_invoice.issued' => ['commercial' => ['zero_commercial_value'], 'cost' => ['zero_source_cost', 'nonstock_completion', 'zero_cost_release']],
            'customer_receipt.posted' => ['cash' => []],
            'customer_receipt_allocation.created' => ['allocation' => []],
            'customer_receipt_allocation.reversed' => ['allocation' => []],
            'customer_receipt.reversed' => ['cash' => []],
            'sales_return.posted' => ['valuation' => ['zero_source_cost', 'billed_damaged_return', 'zero_return_valuation']],
            'sales_credit_note.issued' => ['commercial' => []],
            'customer_credit_allocation.created' => ['transfer' => ['same_customer_credit_transfer']],
            'customer_credit_allocation.reversed' => ['transfer' => ['same_customer_credit_transfer']],
            'stock_transfer.posted' => ['valuation' => ['same_company_transfer']],
            'inventory_adjustment.posted' => ['valuation' => ['zero_source_cost']],
        ];
    }

    /** @return array<string, list<string>> */
    public function definition(string $kind, string $event): array
    {
        $key = $kind.'.'.$event;
        $contracts = $this->contracts();
        if (! isset($contracts[$key])) {
            throw ValidationException::withMessages(['source_event' => 'Unregistered accounting source event.']);
        }

        return $contracts[$key];
    }

    public function adapter(string $kind, string $event): AccountingSourceAdapter
    {
        $this->definition($kind, $event);
        $class = $this->bindings[$kind.'.'.$event] ?? null;
        if ($class === null || ! is_a($class, AccountingSourceAdapter::class, true)) {
            throw ValidationException::withMessages(['source_adapter' => 'This event has no installed trusted adapter. No financial effect was produced.']);
        }

        return app($class);
    }
}

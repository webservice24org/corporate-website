<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source;

use App\Models\Accounting\AccountingBankAdjustment;
use App\Models\Accounting\AccountingCashTransfer;
use App\Models\Accounting\AccountingCashTransferReversalRequest;
use App\Models\Accounting\AccountingExpenseFinancePlan;
use App\Models\Accounting\AccountingSupplierCredit;
use App\Models\CustomerCreditAllocation;
use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use App\Models\GoodsReceipt;
use App\Models\PurchaseReturn;
use App\Models\SalesCreditNote;
use App\Models\SalesDelivery;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\StockTransfer;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use Illuminate\Database\Eloquent\Model;

/** Closed identifiers: user input never supplies a table, model, permission or route. */
final class AccountingReconciliationCatalog
{
    /** @return array<string, array{model: class-string<Model>, family: string, status: string|null, stamp: string, date: string|null, permission: string|null, path: string|null, amount: string|null}> */
    public function definitions(): array
    {
        return [
            'expense_finance' => $this->definition(AccountingExpenseFinancePlan::class, 'expense_finance', 'posted', 'posted_at', 'document_date', 'erp.expense-finance.view', '/erp/accounting/expense-finance-workspace/', 'amount'),
            'bank_adjustment' => $this->definition(AccountingBankAdjustment::class, 'bank_adjustment', 'posted', 'posted_at', 'document_date', 'erp.bank-adjustments.view', '/erp/accounting/bank-adjustments/', 'amount'),
            'cash_transfer_reversal' => $this->definition(AccountingCashTransferReversalRequest::class, 'cash_transfer_reversal', 'posted', 'posted_at', 'document_date', 'erp.cash-transfer-reversals.view', '/erp/accounting/cash-transfer-reversals/', 'amount'),
            'cash_transfer' => $this->definition(AccountingCashTransfer::class, 'cash_transfer', 'posted', 'posted_at', 'document_date', 'erp.cash-transfers.view', '/erp/accounting/cash-transfers/', 'amount'),
            'goods_receipt' => $this->definition(GoodsReceipt::class, 'purchase', 'posted', 'posted_at', null, 'erp.goods-receipts.view', '/erp/purchase/receipts/', null),
            'supplier_payment' => $this->definition(SupplierPayment::class, 'supplier_payment', 'posted', 'posted_at', 'document_date', 'erp.supplier-payments.view', '/erp/accounting/supplier-payments/', 'amount'),
            'supplier_payment_allocation' => $this->definition(SupplierPaymentAllocation::class, 'supplier_payment', null, 'posted_at', null, null, null, 'amount'),
            'supplier_bill' => $this->definition(SupplierBill::class, 'purchase', 'approved', 'approved_at', 'invoice_date', 'erp.supplier-bills.view', '/erp/purchase/bills/', 'grand_total'),
            'supplier_credit' => $this->definition(AccountingSupplierCredit::class, 'purchase', 'issued', 'issued_at', 'document_date', null, null, 'amount'),
            'purchase_return' => $this->definition(PurchaseReturn::class, 'purchase', 'posted', 'posted_at', null, 'erp.purchase-returns.view', '/erp/purchase/returns/', null),
            'stock_transfer' => $this->definition(StockTransfer::class, 'purchase', 'posted', 'posted_at', null, null, null, null),
            'sales_delivery' => $this->definition(SalesDelivery::class, 'sales', 'posted', 'posted_at', 'document_date', 'erp.sales-deliveries.view', '/erp/sales/deliveries/', null),
            'sales_invoice' => $this->definition(SalesInvoice::class, 'sales', 'issued', 'issued_at', 'document_date', 'erp.sales-invoices.view', '/erp/sales/invoices/', 'grand_total'),
            'sales_return' => $this->definition(SalesReturn::class, 'sales', 'posted', 'posted_at', 'document_date', 'erp.sales-returns.view', '/erp/sales/returns/', null),
            'sales_credit_note' => $this->definition(SalesCreditNote::class, 'sales', 'issued', 'issued_at', 'document_date', 'erp.sales-credit-notes.view', '/erp/sales/credit-notes/', 'grand_total'),
            'customer_receipt' => $this->definition(CustomerReceipt::class, 'receipt', 'posted', 'posted_at', 'document_date', 'erp.customer-receipts.view', '/erp/sales/receipts/', 'amount'),
            'customer_receipt_allocation' => $this->definition(CustomerReceiptAllocation::class, 'receipt', null, 'posted_at', null, null, null, 'amount'),
            'customer_credit_allocation' => $this->definition(CustomerCreditAllocation::class, 'sales', null, 'posted_at', null, null, null, 'amount'),
        ];
    }

    /** @param class-string<Model> $model
     * @return array{model: class-string<Model>, family: string, status: string|null, stamp: string, date: string|null, permission: string|null, path: string|null, amount: string|null}
     */
    private function definition(string $model, string $family, ?string $status, string $stamp, ?string $date, ?string $permission, ?string $path, ?string $amount): array
    {
        return compact('model', 'family', 'status', 'stamp', 'date', 'permission', 'path', 'amount');
    }
}

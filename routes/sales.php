<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Sales\CustomerCreditController;
use App\Http\Controllers\ERP\Sales\CustomerReceiptController;
use App\Http\Controllers\ERP\Sales\SalesCreditNoteController;
use App\Http\Controllers\ERP\Sales\SalesCustomerController;
use App\Http\Controllers\ERP\Sales\SalesDeliveryController;
use App\Http\Controllers\ERP\Sales\SalesInvoiceController;
use App\Http\Controllers\ERP\Sales\SalesOrderController;
use App\Http\Controllers\ERP\Sales\SalesQuotationController;
use App\Http\Controllers\ERP\Sales\SalesReportController;
use App\Http\Controllers\ERP\Sales\SalesReservationController;
use App\Http\Controllers\ERP\Sales\SalesReturnController;
use Illuminate\Support\Facades\Route;

// Include ONLY inside the existing authenticated /erp, erp. route group.
Route::prefix('sales/customers')->name('sales.customers.')->middleware('can:erp.sales-customers.view')->group(function (): void {
    Route::get('/', [SalesCustomerController::class, 'index'])->name('index');
    Route::get('/{customer}', [SalesCustomerController::class, 'show'])->whereNumber('customer')->name('show');
});

Route::prefix('sales/quotations')->name('sales.quotations.')->group(function (): void {
    Route::get('/', [SalesQuotationController::class, 'index'])->middleware('can:erp.sales-quotations.view')->name('index');
    Route::get('/create', [SalesQuotationController::class, 'create'])->middleware('can:erp.sales-quotations.create')->name('create');
    Route::get('/catalog', [SalesQuotationController::class, 'catalog'])->name('catalog');
    Route::post('/', [SalesQuotationController::class, 'store'])->middleware('can:erp.sales-quotations.create')->name('store');
    Route::get('/{quotation}', [SalesQuotationController::class, 'show'])->whereNumber('quotation')->middleware('can:erp.sales-quotations.view')->name('show');
    Route::get('/{quotation}/print', [SalesQuotationController::class, 'print'])->whereNumber('quotation')->middleware('can:erp.sales-quotations.view')->name('print');
    Route::get('/{quotation}/edit', [SalesQuotationController::class, 'edit'])->whereNumber('quotation')->middleware('can:erp.sales-quotations.edit')->name('edit');
    Route::put('/{quotation}', [SalesQuotationController::class, 'update'])->whereNumber('quotation')->middleware('can:erp.sales-quotations.edit')->name('update');
    Route::post('/{quotation}/{action}', [SalesQuotationController::class, 'action'])->whereNumber('quotation')->whereIn('action', ['submit', 'approve', 'reject', 'send', 'accept', 'expire', 'cancel', 'resolve-lead'])->name('action');
});

Route::prefix('sales/orders')->name('sales.orders.')->group(function (): void {
    Route::get('/', [SalesOrderController::class, 'index'])->middleware('can:erp.sales-orders.view')->name('index');
    Route::get('/create', [SalesOrderController::class, 'create'])->middleware('can:erp.sales-orders.create')->name('create');
    Route::get('/sources', [SalesOrderController::class, 'sourcesJson'])->middleware('can:erp.sales-orders.create')->name('sources');
    Route::post('/', [SalesOrderController::class, 'store'])->middleware('can:erp.sales-orders.create')->name('store');
    Route::get('/{order}', [SalesOrderController::class, 'show'])->whereNumber('order')->middleware('can:erp.sales-orders.view')->name('show');
    Route::get('/{order}/print', [SalesOrderController::class, 'print'])->whereNumber('order')->middleware('can:erp.sales-orders.view')->name('print');
    Route::get('/{order}/edit', [SalesOrderController::class, 'edit'])->whereNumber('order')->middleware('can:erp.sales-orders.edit')->name('edit');
    Route::put('/{order}', [SalesOrderController::class, 'update'])->whereNumber('order')->middleware('can:erp.sales-orders.edit')->name('update');
    Route::get('/{order}/credit-check', [SalesOrderController::class, 'credit'])->whereNumber('order')->middleware('can:erp.sales-orders.confirm')->name('credit');
    Route::post('/{order}/{action}', [SalesOrderController::class, 'action'])->whereNumber('order')->whereIn('action', ['submit', 'approve', 'reject', 'confirm', 'credit-override', 'cancel', 'close'])->name('action');
});

Route::prefix('sales/reservations')->name('sales.reservations.')->group(function (): void {
    Route::get('/', [SalesReservationController::class, 'index'])->middleware('can:erp.sales-reservations.view')->name('index');
    Route::get('/{order}', [SalesReservationController::class, 'show'])->whereNumber('order')->middleware('can:erp.sales-reservations.view')->name('show');
    Route::post('/{order}/allocate', [SalesReservationController::class, 'allocate'])->whereNumber('order')->middleware(['can:erp.sales-reservations.view', 'can:erp.sales-reservations.allocate'])->name('allocate');
    Route::post('/{order}/release', [SalesReservationController::class, 'release'])->whereNumber('order')->middleware(['can:erp.sales-reservations.view', 'can:erp.sales-reservations.release'])->name('release');
});

Route::prefix('sales/deliveries')->name('sales.deliveries.')->group(function (): void {
    Route::get('/', [SalesDeliveryController::class, 'index'])->middleware('can:erp.sales-deliveries.view')->name('index');
    Route::get('/create', [SalesDeliveryController::class, 'create'])->middleware(['can:erp.sales-deliveries.view', 'can:erp.sales-deliveries.create'])->name('create');
    Route::post('/', [SalesDeliveryController::class, 'store'])->middleware(['can:erp.sales-deliveries.view', 'can:erp.sales-deliveries.create'])->name('store');
    Route::get('/{delivery}', [SalesDeliveryController::class, 'show'])->whereNumber('delivery')->middleware('can:erp.sales-deliveries.view')->name('show');
    Route::get('/{delivery}/print', [SalesDeliveryController::class, 'print'])->whereNumber('delivery')->middleware('can:erp.sales-deliveries.view')->name('print');
    Route::get('/{delivery}/edit', [SalesDeliveryController::class, 'edit'])->whereNumber('delivery')->middleware(['can:erp.sales-deliveries.view', 'can:erp.sales-deliveries.edit'])->name('edit');
    Route::put('/{delivery}', [SalesDeliveryController::class, 'update'])->whereNumber('delivery')->middleware(['can:erp.sales-deliveries.view', 'can:erp.sales-deliveries.edit'])->name('update');
    Route::post('/{delivery}/post', [SalesDeliveryController::class, 'post'])->whereNumber('delivery')->middleware(['can:erp.sales-deliveries.view', 'can:erp.sales-deliveries.post'])->name('post');
    Route::post('/{delivery}/cancel', [SalesDeliveryController::class, 'cancel'])->whereNumber('delivery')->middleware(['can:erp.sales-deliveries.view', 'can:erp.sales-deliveries.cancel'])->name('cancel');
});

Route::get('sales/receivables', [SalesInvoiceController::class, 'receivables'])->middleware('can:erp.customer-receivables.view')->name('sales.receivables.index');
Route::prefix('sales/invoices')->name('sales.invoices.')->group(function (): void {
    Route::get('/', [SalesInvoiceController::class, 'index'])->middleware('can:erp.sales-invoices.view')->name('index');
    Route::get('/create', [SalesInvoiceController::class, 'create'])->middleware(['can:erp.sales-invoices.view', 'can:erp.sales-invoices.create'])->name('create');
    Route::post('/', [SalesInvoiceController::class, 'store'])->middleware(['can:erp.sales-invoices.view', 'can:erp.sales-invoices.create'])->name('store');
    Route::get('/{invoice}', [SalesInvoiceController::class, 'show'])->whereNumber('invoice')->middleware('can:erp.sales-invoices.view')->name('show');
    Route::get('/{invoice}/print', [SalesInvoiceController::class, 'print'])->whereNumber('invoice')->middleware('can:erp.sales-invoices.view')->name('print');
    Route::get('/{invoice}/edit', [SalesInvoiceController::class, 'edit'])->whereNumber('invoice')->middleware(['can:erp.sales-invoices.view', 'can:erp.sales-invoices.edit'])->name('edit');
    Route::put('/{invoice}', [SalesInvoiceController::class, 'update'])->whereNumber('invoice')->middleware(['can:erp.sales-invoices.view', 'can:erp.sales-invoices.edit'])->name('update');
    Route::post('/{invoice}/issue', [SalesInvoiceController::class, 'issue'])->whereNumber('invoice')->middleware(['can:erp.sales-invoices.view', 'can:erp.sales-invoices.issue'])->name('issue');
    Route::post('/{invoice}/cancel', [SalesInvoiceController::class, 'cancel'])->whereNumber('invoice')->middleware(['can:erp.sales-invoices.view', 'can:erp.sales-invoices.cancel'])->name('cancel');
});

Route::get('sales/receipts/statement', [CustomerReceiptController::class, 'statement'])->middleware('can:erp.customer-receivables.view')->name('sales.receipts.statement');
Route::prefix('sales/receipts')->name('sales.receipts.')->middleware('can:erp.customer-receipts.view')->group(function (): void {
    Route::get('/', [CustomerReceiptController::class, 'index'])->name('index');
    Route::get('/create', [CustomerReceiptController::class, 'create'])->middleware('can:erp.customer-receipts.create')->name('create');
    Route::post('/', [CustomerReceiptController::class, 'store'])->middleware('can:erp.customer-receipts.create')->name('store');
    Route::get('/{receipt}/edit', [CustomerReceiptController::class, 'edit'])->whereNumber('receipt')->middleware('can:erp.customer-receipts.edit')->name('edit');
    Route::put('/{receipt}', [CustomerReceiptController::class, 'update'])->whereNumber('receipt')->middleware('can:erp.customer-receipts.edit')->name('update');
    Route::get('/{receipt}/print', [CustomerReceiptController::class, 'print'])->whereNumber('receipt')->name('print');
    Route::post('/{receipt}/post', [CustomerReceiptController::class, 'post'])->whereNumber('receipt')->middleware('can:erp.customer-receipts.post')->name('post');
    Route::post('/{receipt}/allocate', [CustomerReceiptController::class, 'allocate'])->whereNumber('receipt')->middleware('can:erp.customer-receipts.allocate')->name('allocate');
    Route::post('/{receipt}/allocations/{allocation}/reverse', [CustomerReceiptController::class, 'reverseAllocation'])->whereNumber('receipt')->whereNumber('allocation')->middleware('can:erp.customer-receipts.reverse')->name('reverseAllocation');
    Route::post('/{receipt}/reverse', [CustomerReceiptController::class, 'reverse'])->whereNumber('receipt')->middleware('can:erp.customer-receipts.reverse')->name('reverse');
    Route::post('/{receipt}/cancel', [CustomerReceiptController::class, 'cancel'])->whereNumber('receipt')->middleware('can:erp.customer-receipts.cancel')->name('cancel');
    Route::get('/{receipt}', [CustomerReceiptController::class, 'show'])->whereNumber('receipt')->name('show');
});

Route::prefix('sales/returns')->name('sales.returns.')->middleware('can:erp.sales-returns.view')->group(function (): void {
    Route::get('/', [SalesReturnController::class, 'index'])->name('index');
    Route::get('/create', [SalesReturnController::class, 'create'])->middleware('can:erp.sales-returns.create')->name('create');
    Route::post('/', [SalesReturnController::class, 'store'])->middleware('can:erp.sales-returns.create')->name('store');
    Route::get('/{return}/edit', [SalesReturnController::class, 'edit'])->whereNumber('return')->middleware('can:erp.sales-returns.edit')->name('edit');
    Route::put('/{return}', [SalesReturnController::class, 'update'])->whereNumber('return')->middleware('can:erp.sales-returns.edit')->name('update');
    Route::get('/{return}/print', [SalesReturnController::class, 'print'])->whereNumber('return')->name('print');
    Route::post('/{return}/inspect', [SalesReturnController::class, 'inspect'])->whereNumber('return')->middleware('can:erp.sales-returns.inspect')->name('inspect');
    Route::post('/{return}/post', [SalesReturnController::class, 'post'])->whereNumber('return')->middleware('can:erp.sales-returns.post')->name('post');
    Route::post('/{return}/cancel', [SalesReturnController::class, 'cancel'])->whereNumber('return')->middleware('can:erp.sales-returns.cancel')->name('cancel');
    Route::get('/{return}', [SalesReturnController::class, 'show'])->whereNumber('return')->name('show');
});

Route::prefix('sales/credit-notes')->name('sales.credit-notes.')->middleware('can:erp.sales-credit-notes.view')->group(function (): void {
    Route::get('/', [SalesCreditNoteController::class, 'index'])->name('index');
    Route::get('/create', [SalesCreditNoteController::class, 'create'])->middleware('can:erp.sales-credit-notes.create')->name('create');
    Route::post('/', [SalesCreditNoteController::class, 'store'])->middleware('can:erp.sales-credit-notes.create')->name('store');
    Route::get('/{note}/edit', [SalesCreditNoteController::class, 'edit'])->whereNumber('note')->middleware('can:erp.sales-credit-notes.edit')->name('edit');
    Route::put('/{note}', [SalesCreditNoteController::class, 'update'])->whereNumber('note')->middleware('can:erp.sales-credit-notes.edit')->name('update');
    Route::get('/{note}/print', [SalesCreditNoteController::class, 'print'])->whereNumber('note')->name('print');
    Route::post('/{note}/issue', [SalesCreditNoteController::class, 'issue'])->whereNumber('note')->middleware('can:erp.sales-credit-notes.issue')->name('issue');
    Route::post('/{note}/cancel', [SalesCreditNoteController::class, 'cancel'])->whereNumber('note')->middleware('can:erp.sales-credit-notes.cancel')->name('cancel');
    Route::get('/{note}', [SalesCreditNoteController::class, 'show'])->whereNumber('note')->name('show');
});
Route::prefix('sales/customer-credits')->name('sales.customer-credits.')->middleware('can:erp.customer-receivables.view')->group(function (): void {
    Route::get('/', [CustomerCreditController::class, 'index'])->name('index');
    Route::post('/allocate', [CustomerCreditController::class, 'allocate'])->middleware('can:erp.customer-credits.allocate')->name('allocate');
    Route::post('/{allocation}/reverse', [CustomerCreditController::class, 'reverse'])->whereNumber('allocation')->middleware('can:erp.customer-credits.reverse')->name('reverse');
});

Route::prefix('sales/reports')->name('sales.reports.')->middleware('can:erp.sales-reports.view')->group(function (): void {
    Route::get('/dashboard', [SalesReportController::class, 'dashboard'])->name('dashboard');
    Route::get('/export', [SalesReportController::class, 'export'])->middleware('can:erp.sales-reports.export')->name('export');
    Route::get('/', [SalesReportController::class, 'index'])->name('index');
});

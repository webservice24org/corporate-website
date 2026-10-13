<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Purchase\GoodsReceiptController;
use App\Http\Controllers\ERP\Purchase\PurchaseOrderController;
use App\Http\Controllers\ERP\Purchase\PurchaseReportController;
use App\Http\Controllers\ERP\Purchase\PurchaseRequisitionController;
use App\Http\Controllers\ERP\Purchase\PurchaseReturnController;
use App\Http\Controllers\ERP\Purchase\PurchaseRfqController;
use App\Http\Controllers\ERP\Purchase\SupplierBillController;
use App\Http\Controllers\ERP\Purchase\SupplierController;
use Illuminate\Support\Facades\Route;

// Included from the existing authenticated /erp route group.
Route::prefix('purchase/suppliers')->name('purchase.suppliers.')->group(function (): void {
    Route::get('/', [SupplierController::class, 'index'])->middleware('can:erp.suppliers.view')->name('index');
    Route::get('/create', [SupplierController::class, 'create'])->middleware('can:erp.suppliers.create')->name('create');
    Route::post('/', [SupplierController::class, 'store'])->middleware('can:erp.suppliers.create')->name('store');
    Route::get('/{supplier}', [SupplierController::class, 'show'])->whereNumber('supplier')->middleware('can:erp.suppliers.view')->name('show');
    Route::get('/{supplier}/edit', [SupplierController::class, 'edit'])->whereNumber('supplier')->middleware('can:erp.suppliers.edit')->name('edit');
    Route::put('/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier')->middleware('can:erp.suppliers.edit')->name('update');
    Route::delete('/{supplier}', [SupplierController::class, 'destroy'])->whereNumber('supplier')->middleware('can:erp.suppliers.delete')->name('destroy');
    Route::post('/{supplier}/contacts', [SupplierController::class, 'storeContact'])->whereNumber('supplier')->middleware('can:erp.suppliers.contacts.manage')->name('contacts.store');
    Route::put('/{supplier}/contacts/{contact}', [SupplierController::class, 'updateContact'])->whereNumber(['supplier', 'contact'])->middleware('can:erp.suppliers.contacts.manage')->name('contacts.update');
    Route::delete('/{supplier}/contacts/{contact}', [SupplierController::class, 'destroyContact'])->whereNumber(['supplier', 'contact'])->middleware('can:erp.suppliers.contacts.manage')->name('contacts.destroy');
    Route::post('/{supplier}/addresses', [SupplierController::class, 'storeAddress'])->whereNumber('supplier')->middleware('can:erp.suppliers.addresses.manage')->name('addresses.store');
    Route::put('/{supplier}/addresses/{address}', [SupplierController::class, 'updateAddress'])->whereNumber(['supplier', 'address'])->middleware('can:erp.suppliers.addresses.manage')->name('addresses.update');
    Route::delete('/{supplier}/addresses/{address}', [SupplierController::class, 'destroyAddress'])->whereNumber(['supplier', 'address'])->middleware('can:erp.suppliers.addresses.manage')->name('addresses.destroy');
});

Route::prefix('purchase/requisitions')->name('purchase.requisitions.')->group(function (): void {
    Route::get('/', [PurchaseRequisitionController::class, 'index'])->middleware('can:erp.purchase-requisitions.view')->name('index');
    Route::get('/create', [PurchaseRequisitionController::class, 'create'])->middleware('can:erp.purchase-requisitions.create')->name('create');
    // Catalog permissions and company scope are checked by the controller.
    Route::get('/catalog', [PurchaseRequisitionController::class, 'catalog'])->name('catalog');
    Route::post('/', [PurchaseRequisitionController::class, 'store'])->middleware('can:erp.purchase-requisitions.create')->name('store');
    Route::get('/{requisition}', [PurchaseRequisitionController::class, 'show'])->whereNumber('requisition')->middleware('can:erp.purchase-requisitions.view')->name('show');
    Route::get('/{requisition}/edit', [PurchaseRequisitionController::class, 'edit'])->whereNumber('requisition')->middleware('can:erp.purchase-requisitions.edit')->name('edit');
    Route::put('/{requisition}', [PurchaseRequisitionController::class, 'update'])->whereNumber('requisition')->middleware('can:erp.purchase-requisitions.edit')->name('update');
    Route::post('/{requisition}/submit', [PurchaseRequisitionController::class, 'submit'])->whereNumber('requisition')->middleware('can:erp.purchase-requisitions.submit')->name('submit');
    Route::post('/{requisition}/approve', [PurchaseRequisitionController::class, 'approve'])->whereNumber('requisition')->middleware('can:erp.purchase-requisitions.approve')->name('approve');
    Route::post('/{requisition}/reject', [PurchaseRequisitionController::class, 'reject'])->whereNumber('requisition')->middleware('can:erp.purchase-requisitions.reject')->name('reject');
});

Route::prefix('purchase/rfqs')->name('purchase.rfqs.')->group(function (): void {
    Route::get('/', [PurchaseRfqController::class, 'index'])->middleware('can:erp.purchase-rfqs.view')->name('index');
    Route::get('/create', [PurchaseRfqController::class, 'create'])->middleware('can:erp.purchase-rfqs.create')->name('create');
    Route::post('/', [PurchaseRfqController::class, 'store'])->middleware('can:erp.purchase-rfqs.create')->name('store');
    Route::get('/{rfq}', [PurchaseRfqController::class, 'show'])->whereNumber('rfq')->middleware('can:erp.purchase-rfqs.view')->name('show');
    Route::get('/{rfq}/edit', [PurchaseRfqController::class, 'edit'])->whereNumber('rfq')->middleware('can:erp.purchase-rfqs.edit')->name('edit');
    Route::put('/{rfq}', [PurchaseRfqController::class, 'update'])->whereNumber('rfq')->middleware('can:erp.purchase-rfqs.edit')->name('update');
    Route::post('/{rfq}/issue', [PurchaseRfqController::class, 'issue'])->whereNumber('rfq')->middleware('can:erp.purchase-rfqs.issue')->name('issue');
    Route::get('/{rfq}/quotations/create', [PurchaseRfqController::class, 'createQuotation'])->whereNumber('rfq')->middleware('can:erp.supplier-quotations.create')->name('quotations.create');
    Route::post('/{rfq}/quotations', [PurchaseRfqController::class, 'storeQuotation'])->whereNumber('rfq')->middleware('can:erp.supplier-quotations.create')->name('quotations.store');
    Route::get('/{rfq}/quotations/{quotation}/edit', [PurchaseRfqController::class, 'editQuotation'])->whereNumber(['rfq', 'quotation'])->middleware('can:erp.supplier-quotations.edit')->name('quotations.edit');
    Route::put('/{rfq}/quotations/{quotation}', [PurchaseRfqController::class, 'updateQuotation'])->whereNumber(['rfq', 'quotation'])->middleware('can:erp.supplier-quotations.edit')->name('quotations.update');
    Route::get('/{rfq}/compare', [PurchaseRfqController::class, 'compare'])->whereNumber('rfq')->middleware('can:erp.supplier-quotations.compare')->name('compare');
    Route::post('/{rfq}/award', [PurchaseRfqController::class, 'award'])->whereNumber('rfq')->middleware('can:erp.purchase-rfqs.award')->name('award');
});

Route::prefix('purchase/orders')->name('purchase.orders.')->group(function (): void {
    Route::get('/', [PurchaseOrderController::class, 'index'])->middleware('can:erp.purchase-orders.view')->name('index');
    Route::get('/create', [PurchaseOrderController::class, 'create'])->middleware('can:erp.purchase-orders.create')->name('create');
    Route::post('/', [PurchaseOrderController::class, 'store'])->middleware('can:erp.purchase-orders.create')->name('store');
    Route::get('/{order}', [PurchaseOrderController::class, 'show'])->whereNumber('order')->middleware('can:erp.purchase-orders.view')->name('show');
    Route::get('/{order}/edit', [PurchaseOrderController::class, 'edit'])->whereNumber('order')->middleware('can:erp.purchase-orders.edit')->name('edit');
    Route::put('/{order}', [PurchaseOrderController::class, 'update'])->whereNumber('order')->middleware('can:erp.purchase-orders.edit')->name('update');
    Route::post('/{order}/submit', [PurchaseOrderController::class, 'submit'])->whereNumber('order')->middleware('can:erp.purchase-orders.submit')->name('submit');
    Route::post('/{order}/approve', [PurchaseOrderController::class, 'approve'])->whereNumber('order')->middleware('can:erp.purchase-orders.approve')->name('approve');
    Route::post('/{order}/reject', [PurchaseOrderController::class, 'reject'])->whereNumber('order')->middleware('can:erp.purchase-orders.reject')->name('reject');
    Route::post('/{order}/order', [PurchaseOrderController::class, 'order'])->whereNumber('order')->middleware('can:erp.purchase-orders.order')->name('order');
    Route::post('/{order}/close', [PurchaseOrderController::class, 'close'])->whereNumber('order')->middleware('can:erp.purchase-orders.close')->name('close');
    Route::post('/{order}/revise', [PurchaseOrderController::class, 'revise'])->whereNumber('order')->middleware('can:erp.purchase-orders.edit')->name('revise');
});

Route::prefix('purchase/receipts')->name('purchase.receipts.')->group(function (): void {
    Route::get('/', [GoodsReceiptController::class, 'index'])->middleware('can:erp.goods-receipts.view')->name('index');
    Route::get('/create', [GoodsReceiptController::class, 'create'])->middleware('can:erp.goods-receipts.create')->name('create');
    Route::post('/', [GoodsReceiptController::class, 'store'])->middleware('can:erp.goods-receipts.create')->name('store');
    Route::get('/{receipt}', [GoodsReceiptController::class, 'show'])->whereNumber('receipt')->middleware('can:erp.goods-receipts.view')->name('show');
    Route::get('/{receipt}/edit', [GoodsReceiptController::class, 'edit'])->whereNumber('receipt')->middleware('can:erp.goods-receipts.edit')->name('edit');
    Route::put('/{receipt}', [GoodsReceiptController::class, 'update'])->whereNumber('receipt')->middleware('can:erp.goods-receipts.edit')->name('update');
    Route::post('/{receipt}/post', [GoodsReceiptController::class, 'post'])->whereNumber('receipt')->middleware('can:erp.goods-receipts.post')->name('post');
    Route::post('/{receipt}/cancel', [GoodsReceiptController::class, 'cancel'])->whereNumber('receipt')->middleware('can:erp.goods-receipts.cancel')->name('cancel');
});

Route::prefix('purchase/returns')->name('purchase.returns.')->group(function (): void {
    Route::get('/', [PurchaseReturnController::class, 'index'])->middleware('can:erp.purchase-returns.view')->name('index');
    Route::get('/create', [PurchaseReturnController::class, 'create'])->middleware('can:erp.purchase-returns.create')->name('create');
    Route::post('/', [PurchaseReturnController::class, 'store'])->middleware('can:erp.purchase-returns.create')->name('store');
    Route::get('/{purchaseReturn}', [PurchaseReturnController::class, 'show'])->whereNumber('purchaseReturn')->middleware('can:erp.purchase-returns.view')->name('show');
    Route::get('/{purchaseReturn}/edit', [PurchaseReturnController::class, 'edit'])->whereNumber('purchaseReturn')->middleware('can:erp.purchase-returns.edit')->name('edit');
    Route::put('/{purchaseReturn}', [PurchaseReturnController::class, 'update'])->whereNumber('purchaseReturn')->middleware('can:erp.purchase-returns.edit')->name('update');
    Route::post('/{purchaseReturn}/approve', [PurchaseReturnController::class, 'approve'])->whereNumber('purchaseReturn')->middleware('can:erp.purchase-returns.approve')->name('approve');
    Route::post('/{purchaseReturn}/post', [PurchaseReturnController::class, 'post'])->whereNumber('purchaseReturn')->middleware('can:erp.purchase-returns.post')->name('post');
    Route::post('/{purchaseReturn}/cancel', [PurchaseReturnController::class, 'cancel'])->whereNumber('purchaseReturn')->middleware('can:erp.purchase-returns.cancel')->name('cancel');
});

Route::prefix('purchase/bills')->name('purchase.bills.')->group(function (): void {
    Route::get('/', [SupplierBillController::class, 'index'])->middleware('can:erp.supplier-bills.view')->name('index');
    Route::get('/create', [SupplierBillController::class, 'create'])->middleware('can:erp.supplier-bills.create')->name('create');
    Route::get('/payables', [SupplierBillController::class, 'payables'])->middleware('can:erp.supplier-payables.view')->name('payables');
    Route::post('/', [SupplierBillController::class, 'store'])->middleware('can:erp.supplier-bills.create')->name('store');
    Route::get('/{bill}', [SupplierBillController::class, 'show'])->whereNumber('bill')->middleware('can:erp.supplier-bills.view')->name('show');
    Route::get('/{bill}/edit', [SupplierBillController::class, 'edit'])->whereNumber('bill')->middleware('can:erp.supplier-bills.edit')->name('edit');
    Route::put('/{bill}', [SupplierBillController::class, 'update'])->whereNumber('bill')->middleware('can:erp.supplier-bills.edit')->name('update');
    Route::post('/{bill}/approve', [SupplierBillController::class, 'approve'])->whereNumber('bill')->middleware('can:erp.supplier-bills.approve')->name('approve');
    Route::post('/{bill}/cancel', [SupplierBillController::class, 'cancel'])->whereNumber('bill')->middleware('can:erp.supplier-bills.cancel')->name('cancel');
});

Route::get('purchase/dashboard', [PurchaseReportController::class, 'dashboard'])->middleware('can:erp.purchase-reports.view')->name('purchase.dashboard');
Route::prefix('purchase/reports')->name('purchase.reports.')->group(function (): void {
    Route::get('/', [PurchaseReportController::class, 'index'])->middleware('can:erp.purchase-reports.view')->name('index');
    Route::get('/export', [PurchaseReportController::class, 'export'])->middleware(['can:erp.purchase-reports.view', 'can:erp.purchase-reports.export'])->name('export');
});

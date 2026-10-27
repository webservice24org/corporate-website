<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingTaxController;
use App\Http\Middleware\AccountingTaxPrivateResponse;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:erp.tax-reconciliation.view', AccountingTaxPrivateResponse::class])->group(function (): void {
    Route::get('/erp/accounting/tax-reconciliation', [AccountingTaxController::class, 'index'])->name('erp.accounting.tax-reconciliation');
    Route::get('/erp/accounting/tax-reconciliation/export', [AccountingTaxController::class, 'export'])->middleware('can:erp.tax-reconciliation.export')->name('erp.accounting.tax-reconciliation.export');
});

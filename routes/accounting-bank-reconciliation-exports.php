<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingBankReconciliationExportController;
use Illuminate\Support\Facades\Route;

Route::prefix('erp/accounting/bank-reconciliations')->name('erp.accounting.bank-reconciliations.')->middleware(['auth', 'can:erp.bank-reconciliations.view', 'can:erp.bank-statements.view', 'can:erp.cash-books.view', 'can:erp.bank-reconciliations.export'])->group(function (): void {
    Route::get('/{reconciliation}/csv', [AccountingBankReconciliationExportController::class, 'csv'])->whereNumber('reconciliation')->name('csv');
    Route::get('/{reconciliation}/print', [AccountingBankReconciliationExportController::class, 'printable'])->whereNumber('reconciliation')->name('print');
});

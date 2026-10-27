<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingBankReconciliationController;
use Illuminate\Support\Facades\Route;

// Included at top level, outside manual-journal authority groups.
Route::prefix('erp/accounting/bank-reconciliations')->name('erp.accounting.bank-reconciliations.')->middleware(['auth', 'can:erp.bank-reconciliations.view', 'can:erp.bank-statements.view', 'can:erp.cash-books.view', 'can:erp.bank-reconciliations.view'])->group(function (): void {
    Route::get('/', [AccountingBankReconciliationController::class, 'index'])->name('index');
    Route::get('/create', [AccountingBankReconciliationController::class, 'create'])->middleware('can:erp.bank-reconciliations.create')->name('create');
    Route::post('/', [AccountingBankReconciliationController::class, 'store'])->middleware('can:erp.bank-reconciliations.create')->name('store');
    Route::post('/{reconciliation}/match', [AccountingBankReconciliationController::class, 'match'])->whereNumber('reconciliation')->middleware('can:erp.bank-reconciliations.match')->name('match');
    Route::post('/{reconciliation}/unmatch', [AccountingBankReconciliationController::class, 'unmatch'])->whereNumber('reconciliation')->middleware('can:erp.bank-reconciliations.unmatch')->name('unmatch');
    Route::get('/{reconciliation}', [AccountingBankReconciliationController::class, 'show'])->whereNumber('reconciliation')->name('show');
});

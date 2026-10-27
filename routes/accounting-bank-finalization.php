<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingBankFinalizationController;
use Illuminate\Support\Facades\Route;

Route::prefix('erp/accounting/bank-reconciliations/{reconciliation}/finalization')->name('erp.accounting.bank-reconciliations.')->middleware(['auth', 'can:erp.bank-reconciliations.view', 'can:erp.bank-statements.view', 'can:erp.cash-books.view'])->group(function (): void {
    Route::get('/', [AccountingBankFinalizationController::class, 'show'])->whereNumber('reconciliation')->name('finalization');
    Route::post('/submit', [AccountingBankFinalizationController::class, 'submit'])->middleware('can:erp.bank-reconciliations.submit')->whereNumber('reconciliation')->name('finalization.submit');
    Route::post('/approve', [AccountingBankFinalizationController::class, 'approve'])->middleware('can:erp.bank-reconciliations.approve')->whereNumber('reconciliation')->name('finalization.approve');
    Route::post('/reject', [AccountingBankFinalizationController::class, 'reject'])->middleware('can:erp.bank-reconciliations.approve')->whereNumber('reconciliation')->name('finalization.reject');
    Route::post('/reopen', [AccountingBankFinalizationController::class, 'reopen'])->middleware('can:erp.bank-reconciliations.reopen')->whereNumber('reconciliation')->name('finalization.reopen');
});

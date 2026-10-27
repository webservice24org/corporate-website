<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingBankFinalizationReadinessController;
use Illuminate\Support\Facades\Route;

Route::get('/erp/accounting/bank-reconciliations/{reconciliation}/readiness', [AccountingBankFinalizationReadinessController::class, 'show'])
    ->whereNumber('reconciliation')->middleware(['auth', 'can:erp.bank-reconciliations.view', 'can:erp.bank-statements.view', 'can:erp.cash-books.view'])
    ->name('erp.accounting.bank-reconciliations.readiness');

<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingExpenseFinanceController;
use Illuminate\Support\Facades\Route;

Route::prefix('erp/accounting/expense-finance')->name('erp.accounting.expense-finance.')->middleware(['auth', 'can:erp.expenses.view', 'can:erp.expense-finance.view'])->group(function (): void {
    Route::post('/', [AccountingExpenseFinanceController::class, 'store'])->middleware('can:erp.expense-finance.create')->name('store');

    Route::get('/{plan}', [AccountingExpenseFinanceController::class, 'show'])->whereNumber('plan')->name('show');

    foreach (['approve', 'cancel', 'post'] as $action) {
        Route::post('/{plan}/'.$action, [AccountingExpenseFinanceController::class, 'transition'])->whereNumber('plan')->defaults('action', $action)->middleware('can:erp.expense-finance.'.$action)->name($action);
    }
});

Route::get('/erp/accounting/expenses/{expense}/payable', [AccountingExpenseFinanceController::class, 'payable'])->whereNumber('expense')->middleware(['auth', 'can:erp.expenses.view', 'can:erp.expense-finance.view'])->name('erp.accounting.expenses.payable');

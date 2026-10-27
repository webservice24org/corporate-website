<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingExpenseController;
use Illuminate\Support\Facades\Route;

// Foundation JSON endpoints. No Inertia/sidebar or journal-posting route until their scheduled stages.

Route::prefix('erp/accounting/expense-categories')->name('erp.accounting.expense-categories.')->middleware(['auth', 'can:erp.expense-categories.view'])->group(function (): void {
    Route::get('/', [AccountingExpenseController::class, 'categories'])->name('index');

    Route::post('/', [AccountingExpenseController::class, 'storeCategory'])->middleware('can:erp.expense-categories.manage')->name('store');

    Route::get('/{category}', [AccountingExpenseController::class, 'category'])->whereNumber('category')->name('show');

    Route::put('/{category}', [AccountingExpenseController::class, 'updateCategory'])->whereNumber('category')->middleware('can:erp.expense-categories.manage')->name('update');
});

Route::prefix('erp/accounting/expenses')->name('erp.accounting.expenses.')->middleware(['auth', 'can:erp.expenses.view'])->group(function (): void {
    Route::get('/', [AccountingExpenseController::class, 'index'])->name('index');

    Route::post('/', [AccountingExpenseController::class, 'store'])->middleware('can:erp.expenses.create')->name('store');

    Route::get('/{expense}', [AccountingExpenseController::class, 'show'])->whereNumber('expense')->name('show');

    Route::put('/{expense}', [AccountingExpenseController::class, 'update'])->whereNumber('expense')->middleware('can:erp.expenses.edit')->name('update');

    foreach (['submit' => 'submit', 'approve' => 'approve', 'reject' => 'approve', 'cancel' => 'cancel'] as $action => $permission) {
        Route::post('/{expense}/'.$action, [AccountingExpenseController::class, 'transition'])->whereNumber('expense')->defaults('action', $action)->middleware('can:erp.expenses.'.$permission)->name($action);
    }
});

// Private file endpoints retain their own authorization; mount exactly once at top level.
require __DIR__.'/accounting-expense-attachments.php';

// Explicitly approved recognition and dedicated expense payable settlements.
require __DIR__.'/accounting-expense-finance.php';

// Browser workspaces use the existing native JSON commands and private downloads.
require __DIR__.'/accounting-expense-workspace.php';

// Financial statement read workspaces; mount once at top level.
require __DIR__.'/accounting-financial-reports.php';

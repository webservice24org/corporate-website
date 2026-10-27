<?php

declare(strict_types=1);
use App\Http\Controllers\ERP\Accounting\AccountingExpenseWorkspaceController as Workspace;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('/erp/accounting/expense-options', [Workspace::class, 'options'])->name('erp.accounting.expense-options');
    Route::prefix('erp/accounting/expense-workspace')->name('erp.accounting.expense-workspace.')->middleware('can:erp.expenses.view')->group(function (): void {
        Route::get('/', [Workspace::class, 'index'])->name('index');
        Route::get('/create', [Workspace::class, 'create'])->middleware('can:erp.expenses.create')->name('create');
        Route::get('/{expense}/edit', [Workspace::class, 'edit'])->whereNumber('expense')->middleware('can:erp.expenses.edit')->name('edit');
        Route::get('/{expense}', [Workspace::class, 'show'])->whereNumber('expense')->name('show');
    });
    Route::prefix('erp/accounting/expense-categories-workspace')->name('erp.accounting.expense-categories-workspace.')->middleware('can:erp.expense-categories.view')->group(function (): void {
        Route::get('/', [Workspace::class, 'categories'])->name('index');
        Route::get('/create', [Workspace::class, 'createCategory'])->middleware('can:erp.expense-categories.manage')->name('create');
        Route::get('/{category}/edit', [Workspace::class, 'editCategory'])->whereNumber('category')->middleware('can:erp.expense-categories.manage')->name('edit');
    });
    Route::prefix('erp/accounting/expense-finance-workspace')->name('erp.accounting.expense-finance-workspace.')->middleware(['can:erp.expenses.view', 'can:erp.expense-finance.view'])->group(function (): void {
        Route::get('/', [Workspace::class, 'finance'])->name('index');
        Route::get('/create', [Workspace::class, 'createFinance'])->middleware('can:erp.expense-finance.create')->name('create');
        Route::get('/{plan}', [Workspace::class, 'showFinance'])->whereNumber('plan')->name('show');
    });
});

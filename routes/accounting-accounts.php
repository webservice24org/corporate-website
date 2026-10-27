<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingAccountController;
use Illuminate\Support\Facades\Route;

// Mount once at the TOP LEVEL of routes/web.php, outside any ERP or permission group.
Route::prefix('erp/accounting/accounts')->name('erp.accounting.accounts.')->middleware(['auth', 'can:erp.accounts.view'])->group(function (): void {
    Route::get('/', [AccountingAccountController::class, 'index'])->name('index');
    Route::get('/create', [AccountingAccountController::class, 'create'])->middleware('can:erp.accounts.create')->name('create');
    Route::post('/initialize-groups', [AccountingAccountController::class, 'initializeGroups'])->middleware('can:erp.accounts.create')->name('initialize-groups');
    Route::post('/', [AccountingAccountController::class, 'store'])->middleware('can:erp.accounts.create')->name('store');
    Route::get('/{account}/edit', [AccountingAccountController::class, 'edit'])->whereNumber('account')->middleware('can:erp.accounts.edit')->name('edit');
    Route::put('/{account}', [AccountingAccountController::class, 'update'])->whereNumber('account')->middleware('can:erp.accounts.edit')->name('update');
    Route::post('/{account}/status', [AccountingAccountController::class, 'status'])->whereNumber('account')->middleware('can:erp.accounts.deactivate')->name('status');
    Route::get('/{account}', [AccountingAccountController::class, 'show'])->whereNumber('account')->name('show');
});

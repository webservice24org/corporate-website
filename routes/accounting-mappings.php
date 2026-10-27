<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingCashAccountController;
use App\Http\Controllers\ERP\Accounting\AccountingMappingController;
use Illuminate\Support\Facades\Route;

// Mount ONCE at the TOP LEVEL of routes/web.php, outside all Route groups.
Route::prefix('erp/accounting/mappings')->name('erp.accounting.mappings.')->middleware(['auth', 'can:erp.accounting-mappings.view'])->group(function (): void {
    Route::get('/', [AccountingMappingController::class, 'index'])->name('index');
    Route::post('/', [AccountingMappingController::class, 'publish'])->middleware('can:erp.accounting-mappings.publish')->name('publish');
    Route::get('/cash-accounts', [AccountingCashAccountController::class, 'index'])->name('cash-accounts.index');
    Route::post('/cash-accounts', [AccountingCashAccountController::class, 'initialize'])->middleware('can:erp.accounting-mappings.publish')->name('cash-accounts.initialize');
    Route::post('/cash-accounts/{cash}/status', [AccountingCashAccountController::class, 'status'])->whereNumber('cash')->middleware('can:erp.accounting-mappings.publish')->name('cash-accounts.status');
    Route::get('/{mapping}', [AccountingMappingController::class, 'show'])->whereNumber('mapping')->name('show');
});

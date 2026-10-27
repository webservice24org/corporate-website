<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingCutoverController;
use Illuminate\Support\Facades\Route;

// Include ONCE at TOP LEVEL of routes/web.php, before frontend catch-all routes.
Route::prefix('erp/accounting/cutover')->name('erp.accounting.cutover.')->middleware(['auth', 'can:erp.accounting-openings.view'])->group(function (): void {
    Route::get('/', [AccountingCutoverController::class, 'index'])->name('index');
    Route::post('/', [AccountingCutoverController::class, 'store'])->middleware('can:erp.accounting-openings.manage')->name('store');
    Route::put('/{cutover}', [AccountingCutoverController::class, 'update'])->whereNumber('cutover')->middleware('can:erp.accounting-openings.manage')->name('update');
    Route::post('/{cutover}/abort', [AccountingCutoverController::class, 'abort'])->whereNumber('cutover')->middleware('can:erp.accounting-openings.manage')->name('abort');
});

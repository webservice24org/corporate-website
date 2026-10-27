<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingManifestController;
use Illuminate\Support\Facades\Route;

// Mount once at TOP LEVEL in routes/web.php before frontend catch-all routes.
Route::prefix('erp/accounting/manifest')->name('erp.accounting.manifest.')->middleware(['auth', 'can:erp.accounting-openings.view'])->group(function (): void {
    Route::get('/', [AccountingManifestController::class, 'index'])->name('index');
    Route::post('/{cutover}/capture', [AccountingManifestController::class, 'capture'])->whereNumber('cutover')->middleware('can:erp.accounting-openings.manage')->name('capture');
    Route::post('/{cutover}/invalidate', [AccountingManifestController::class, 'invalidate'])->whereNumber('cutover')->middleware('can:erp.accounting-openings.manage')->name('invalidate');
});

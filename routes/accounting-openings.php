<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingOpeningController;
use Illuminate\Support\Facades\Route;

// Mount once at TOP LEVEL in routes/web.php before catch-all routes.
Route::prefix('erp/accounting/openings')->name('erp.accounting.openings.')->middleware(['auth', 'can:erp.accounting-openings.view'])->group(function (): void {
    Route::get('/', [AccountingOpeningController::class, 'index'])->name('index');
    Route::post('/{cutover}', [AccountingOpeningController::class, 'save'])->whereNumber('cutover')->middleware('can:erp.accounting-openings.manage')->name('save');
    Route::post('/{cutover}/dry-run', [AccountingOpeningController::class, 'dryRun'])->whereNumber('cutover')->middleware('can:erp.accounting-openings.manage')->name('dry-run');
    foreach (['submit', 'approve', 'reject'] as $action) {
        Route::post('/{cutover}/'.$action, [AccountingOpeningController::class, $action])->whereNumber('cutover')->middleware('can:erp.accounting-openings.'.($action === 'approve' ? 'approve' : 'manage'))->name($action);
    }
});

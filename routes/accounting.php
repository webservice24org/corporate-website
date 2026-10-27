<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingWorkspaceController;
use Illuminate\Support\Facades\Route;

// Include ONCE inside the existing authenticated /erp, erp. route group in routes/erp.php.
Route::prefix('accounting')->name('accounting.')->middleware(['auth', 'can:erp.accounting-settings.view'])->group(function (): void {
    Route::get('/', [AccountingWorkspaceController::class, 'index'])->name('index');
    Route::get('/settings', [AccountingWorkspaceController::class, 'settings'])->name('settings');
    Route::post('/settings/initialize', [AccountingWorkspaceController::class, 'initialize'])->middleware('can:erp.accounting-settings.update')->name('initialize');
});

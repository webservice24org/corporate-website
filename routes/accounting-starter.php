<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingStarterController;
use Illuminate\Support\Facades\Route;

// Include ONCE at TOP LEVEL in routes/web.php, outside all Route groups.
Route::prefix('erp/accounting/setup-readiness')->name('erp.accounting.starter.')->middleware(['auth', 'can:erp.accounts.view'])->group(function (): void {
    Route::get('/', [AccountingStarterController::class, 'index'])->name('index');
    Route::post('/apply', [AccountingStarterController::class, 'apply'])->middleware('can:erp.accounts.create')->name('apply');
});

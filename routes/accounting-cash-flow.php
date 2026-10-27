<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingCashFlowController;
use App\Http\Middleware\AccountingCashFlowPrivateResponse;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:erp.cash-flow.view', AccountingCashFlowPrivateResponse::class])->group(function (): void {
    Route::get('/erp/accounting/cash-flow', [AccountingCashFlowController::class, 'index'])->name('erp.accounting.cash-flow');
    Route::get('/erp/accounting/cash-flow/export', [AccountingCashFlowController::class, 'export'])->middleware('can:erp.cash-flow.export')->name('erp.accounting.cash-flow.export');
});

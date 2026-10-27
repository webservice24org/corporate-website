<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingPeriodCloseController;
use App\Http\Middleware\AccountingPeriodClosePrivateResponse;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:erp.period-close.view', AccountingPeriodClosePrivateResponse::class])->group(function (): void {
    Route::get('/erp/accounting/period-close', [AccountingPeriodCloseController::class, 'index'])->name('erp.accounting.period-close');
    Route::post('/erp/accounting/period-close/{company}/{period}/prepare', [AccountingPeriodCloseController::class, 'prepare'])->whereNumber('company')->whereNumber('period')->middleware('can:erp.period-close.prepare')->name('erp.accounting.period-close.prepare');
    Route::post('/erp/accounting/period-close/{company}/{period}/approve', [AccountingPeriodCloseController::class, 'approve'])->whereNumber('company')->whereNumber('period')->middleware('can:erp.period-close.approve')->name('erp.accounting.period-close.approve');
    Route::post('/erp/accounting/period-close/{company}/{period}/hard-close', [AccountingPeriodCloseController::class, 'close'])->whereNumber('company')->whereNumber('period')->middleware('can:erp.accounting-periods.close')->name('erp.accounting.period-close.close');
});

<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingCalendarController;
use Illuminate\Support\Facades\Route;

// Mount ONCE at TOP LEVEL of routes/web.php, outside all Route groups.
Route::prefix('erp/accounting/calendar')->name('erp.accounting.calendar.')->middleware(['auth', 'can:erp.fiscal-years.view'])->group(function (): void {
    Route::get('/', [AccountingCalendarController::class, 'index'])->name('index');
    Route::get('/create', [AccountingCalendarController::class, 'create'])->middleware('can:erp.fiscal-years.manage')->name('create');
    Route::post('/', [AccountingCalendarController::class, 'store'])->middleware('can:erp.fiscal-years.manage')->name('store');
    Route::get('/{year}/edit', [AccountingCalendarController::class, 'edit'])->whereNumber('year')->middleware('can:erp.fiscal-years.manage')->name('edit');
    Route::put('/{year}', [AccountingCalendarController::class, 'update'])->whereNumber('year')->middleware('can:erp.fiscal-years.manage')->name('update');
    Route::middleware('can:erp.accounting-periods.view')->group(function (): void {
        Route::get('/{year}', [AccountingCalendarController::class, 'show'])->whereNumber('year')->name('show');
        Route::post('/{year}/periods', [AccountingCalendarController::class, 'storePeriod'])->whereNumber('year')->middleware('can:erp.accounting-periods.manage')->name('periods.store');
        Route::put('/{year}/periods/{period}', [AccountingCalendarController::class, 'updatePeriod'])->whereNumber(['year', 'period'])->middleware('can:erp.accounting-periods.manage')->name('periods.update');
        Route::post('/{year}/periods/{period}/close', [AccountingCalendarController::class, 'closePeriod'])->whereNumber(['year', 'period'])->middleware('can:erp.accounting-periods.close')->name('periods.close');
        Route::post('/{year}/periods/{period}/reopen', [AccountingCalendarController::class, 'reopenPeriod'])->whereNumber(['year', 'period'])->middleware('can:erp.accounting-periods.reopen')->name('periods.reopen');
        Route::post('/{year}/generate-periods', [AccountingCalendarController::class, 'generate'])->whereNumber('year')->middleware('can:erp.accounting-periods.manage')->name('generate');
    });
});

<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingYearEndController;
use App\Http\Middleware\AccountingYearEndPrivateResponse;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:erp.year-end.view', AccountingYearEndPrivateResponse::class])->group(function (): void {
    Route::get('/erp/accounting/year-end', [AccountingYearEndController::class, 'index'])->name('erp.accounting.year-end');
    Route::post('/erp/accounting/year-end/{company}/{year}/{mode}/prepare', [AccountingYearEndController::class, 'prepare'])->whereNumber('company')->whereNumber('year')->where('mode', 'close|reopen')->middleware('can:erp.year-end.prepare')->name('erp.accounting.year-end.prepare');
    Route::post('/erp/accounting/year-end/{company}/{year}/{mode}/approve', [AccountingYearEndController::class, 'approve'])->whereNumber('company')->whereNumber('year')->where('mode', 'close|reopen')->middleware('can:erp.year-end.approve')->name('erp.accounting.year-end.approve');
    // Mode-specific financial authority is rechecked by the lifecycle service; close authority cannot reopen.
    Route::post('/erp/accounting/year-end/{company}/{year}/{mode}/post', [AccountingYearEndController::class, 'post'])->whereNumber('company')->whereNumber('year')->where('mode', 'close|reopen')->name('erp.accounting.year-end.post');
});

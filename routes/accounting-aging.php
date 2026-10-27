<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingAgingController as Aging;
use App\Http\Middleware\AccountingAgingPrivateResponse;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', AccountingAgingPrivateResponse::class])->group(function (): void {
    Route::get('/erp/accounting/ar-aging', [Aging::class, 'ar'])->middleware('can:erp.ar-aging.view')->name('erp.accounting.ar-aging');
    Route::get('/erp/accounting/ar-aging/export', [Aging::class, 'arExport'])->middleware(['can:erp.ar-aging.view', 'can:erp.ar-aging.export'])->name('erp.accounting.ar-aging.export');
    Route::get('/erp/accounting/ap-aging', [Aging::class, 'ap'])->middleware('can:erp.ap-aging.view')->name('erp.accounting.ap-aging');
    Route::get('/erp/accounting/ap-aging/export', [Aging::class, 'apExport'])->middleware(['can:erp.ap-aging.view', 'can:erp.ap-aging.export'])->name('erp.accounting.ap-aging.export');
});

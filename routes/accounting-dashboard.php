<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingFinanceDashboardController;
use App\Http\Middleware\AccountingFinanceDashboardPrivateResponse;
use Illuminate\Support\Facades\Route;

Route::get('/erp/accounting/dashboard', [AccountingFinanceDashboardController::class, 'index'])->middleware(['auth', 'can:erp.finance-dashboard.view', AccountingFinanceDashboardPrivateResponse::class])->name('erp.accounting.dashboard');

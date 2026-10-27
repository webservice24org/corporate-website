<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingProfitLossController;
use Illuminate\Support\Facades\Route;

Route::get('/erp/accounting/profit-loss', [AccountingProfitLossController::class, 'index'])
    ->middleware(['auth', 'can:erp.profit-loss.view'])->name('erp.accounting.profit-loss');

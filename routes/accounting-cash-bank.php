<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingCashBankController;
use Illuminate\Support\Facades\Route;

// Loaded once at web.php top level through accounting-journals.php.
Route::get('/erp/accounting/cash-bank', [AccountingCashBankController::class, 'index'])
    ->middleware(['auth', 'can:erp.cash-accounts.view'])
    ->name('erp.accounting.cash-bank.index');

<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingBalanceSheetController;
use Illuminate\Support\Facades\Route;

Route::get('/erp/accounting/balance-sheet', [AccountingBalanceSheetController::class, 'index'])
    ->middleware(['auth', 'can:erp.balance-sheet.view'])->name('erp.accounting.balance-sheet');

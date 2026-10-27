<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingFinancialReportController as Reports;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::get('/erp/accounting/general-ledger', [Reports::class, 'ledger'])->middleware('can:erp.general-ledger.view')->name('erp.accounting.general-ledger');
    Route::get('/erp/accounting/general-ledger/export', [Reports::class, 'ledgerExport'])->middleware(['can:erp.general-ledger.view', 'can:erp.general-ledger.export'])->name('erp.accounting.general-ledger.export');
    Route::get('/erp/accounting/trial-balance', [Reports::class, 'trial'])->middleware('can:erp.trial-balance.view')->name('erp.accounting.trial-balance');
    Route::get('/erp/accounting/trial-balance/export', [Reports::class, 'trialExport'])->middleware(['can:erp.trial-balance.view', 'can:erp.trial-balance.export'])->name('erp.accounting.trial-balance.export');
});

// Profit and Loss keeps its own permission and company scope.
require __DIR__.'/accounting-profit-loss.php';

// Dated Balance Sheet and explicit nominal earnings bridge.
require __DIR__.'/accounting-balance-sheet.php';

// Cash-flow readiness screens and separately authorized complete exports.
require __DIR__.'/accounting-cash-flow.php';

// Dated live-trade aging, explicit coverage review and separately authorized exports.
require __DIR__.'/accounting-aging.php';

// Configured tax GL reconciliation, not statutory return filing.
require __DIR__.'/accounting-tax.php';

// Permission-aware finance dashboard; existing setup overview remains available.
require __DIR__.'/accounting-dashboard.php';

// Period-close checks are re-evaluated by the shared calendar hard-close boundary.
require __DIR__.'/accounting-period-close.php';

// Independently approved fiscal year close and exact retained-earnings compensation.
require __DIR__.'/accounting-year-end.php';

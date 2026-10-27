<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingJournalController;
use App\Http\Controllers\ERP\Accounting\AccountingLedgerController;
use App\Http\Controllers\ERP\Accounting\AccountingSourceReconciliationController;
use Illuminate\Support\Facades\Route;

// Mount once at TOP LEVEL in routes/web.php, outside all route groups.
Route::prefix('erp/accounting/journals')->name('erp.accounting.journals.')->middleware(['auth', 'can:erp.journal-entries.view'])->group(function (): void {
    Route::get('/', [AccountingJournalController::class, 'index'])->name('index');
    Route::get('/create', [AccountingJournalController::class, 'create'])->middleware('can:erp.journal-entries.create')->name('create');
    Route::post('/', [AccountingJournalController::class, 'store'])->middleware('can:erp.journal-entries.create')->name('store');
    Route::get('/{journal}/print', [AccountingJournalController::class, 'print'])->whereNumber('journal')->name('print');
    Route::get('/{journal}/edit', [AccountingJournalController::class, 'edit'])->whereNumber('journal')->middleware('can:erp.journal-entries.edit')->name('edit');
    Route::put('/{journal}', [AccountingJournalController::class, 'update'])->whereNumber('journal')->middleware('can:erp.journal-entries.edit')->name('update');
    Route::get('/{journal}', [AccountingJournalController::class, 'show'])->whereNumber('journal')->name('show');
    foreach (['submit' => 'submit', 'approve' => 'approve', 'reject' => 'approve', 'cancel' => 'edit', 'post' => 'post', 'reverse' => 'reverse'] as $action => $permission) {
        Route::post('/{journal}/'.$action, [AccountingJournalController::class, $action])->whereNumber('journal')->middleware('can:erp.journal-entries.'.$permission)->name($action);
    }
});

Route::get('/erp/accounting/ledger', [AccountingLedgerController::class, 'index'])->middleware(['auth', 'can:erp.accounting-reports.view'])->name('erp.accounting.ledger.index');

// Separate report authority; does not inherit manual journal permissions.
Route::get('/erp/accounting/source-reconciliation', [AccountingSourceReconciliationController::class, 'index'])->middleware(['auth', 'can:erp.accounting-reports.view'])->name('erp.accounting.source-reconciliation.index');

// Supplier payment screens use their own permissions, outside manual journal route groups.
require __DIR__.'/accounting-supplier-payments.php';

// Supplier reports do not inherit manual journal or supplier payment permissions.
require __DIR__.'/accounting-supplier-reports.php';

// Read-only audit of the installed finance-account bindings.
require __DIR__.'/accounting-cash-bank.php';

// Cashbook/bankbook reports retain independent view/export authority.
require __DIR__.'/accounting-cash-books.php';

// Transfer screens retain native transfer authority, outside manual journal groups.
require __DIR__.'/accounting-cash-transfers.php';

// Native reversal authority is separate from transfer and manual journal permissions.
require __DIR__.'/accounting-cash-transfer-reversals.php';

// Immutable bank statement evidence is independently authorized.
require __DIR__.'/accounting-bank-statements.php';

require __DIR__.'/accounting-bank-adjustments.php';
require __DIR__.'/accounting-bank-reconciliations.php';

// Read-only technical coverage review; no finalization command is mounted yet.
require __DIR__.'/accounting-bank-finalization-readiness.php';

require __DIR__.'/accounting-bank-finalization.php';

// Full-result exports require their own permission and verified native evidence.
require __DIR__.'/accounting-bank-reconciliation-exports.php';

// Standalone expense foundation retains separate native authority.
require __DIR__.'/accounting-expenses.php';

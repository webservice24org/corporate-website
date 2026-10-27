<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingBankStatementController;
use Illuminate\Support\Facades\Route;

// Included once at top level by accounting-journals.php; native statement authority, outside manual journal groups.
Route::prefix('erp/accounting/bank-statements')->name('erp.accounting.bank-statements.')->middleware(['auth', 'can:erp.bank-statements.view'])->group(function (): void {
    Route::get('/', [AccountingBankStatementController::class, 'index'])->name('index');
    Route::get('/create', [AccountingBankStatementController::class, 'create'])->name('create');
    Route::get('/template', [AccountingBankStatementController::class, 'template'])->name('template');
    Route::post('/preview', [AccountingBankStatementController::class, 'preview'])->name('preview');
    Route::post('/import', [AccountingBankStatementController::class, 'import'])->middleware('can:erp.bank-statements.import')->name('import');
    Route::post('/manual', [AccountingBankStatementController::class, 'manual'])->middleware('can:erp.bank-statements.create')->name('manual');
    Route::get('/{statement}/csv', [AccountingBankStatementController::class, 'csv'])->whereNumber('statement')->middleware('can:erp.bank-statements.export')->name('csv');
    Route::get('/{statement}', [AccountingBankStatementController::class, 'show'])->whereNumber('statement')->name('show');
});

<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingBankAdjustmentController;
use Illuminate\Support\Facades\Route;

// Included at top level, outside manual-journal authority groups.
Route::prefix('erp/accounting/bank-adjustments')->name('erp.accounting.bank-adjustments.')->middleware(['auth', 'can:erp.bank-adjustments.view', 'can:erp.bank-statements.view', 'can:erp.cash-books.view', 'can:erp.bank-reconciliations.view'])->group(function (): void {
    Route::get('/', [AccountingBankAdjustmentController::class, 'index'])->name('index');
    Route::get('/create', [AccountingBankAdjustmentController::class, 'create'])->middleware('can:erp.bank-adjustments.create')->name('create');
    Route::post('/', [AccountingBankAdjustmentController::class, 'store'])->middleware('can:erp.bank-adjustments.create')->name('store');
    Route::get('/{adjustment}/edit', [AccountingBankAdjustmentController::class, 'edit'])->whereNumber('adjustment')->middleware('can:erp.bank-adjustments.edit')->name('edit');
    Route::put('/{adjustment}', [AccountingBankAdjustmentController::class, 'update'])->whereNumber('adjustment')->middleware('can:erp.bank-adjustments.edit')->name('update');
    Route::get('/{adjustment}/refund', [AccountingBankAdjustmentController::class, 'refund'])->whereNumber('adjustment')->middleware('can:erp.bank-adjustments.reverse')->name('refund');
    Route::post('/{adjustment}/refund', [AccountingBankAdjustmentController::class, 'storeRefund'])->whereNumber('adjustment')->middleware('can:erp.bank-adjustments.reverse')->name('storeRefund');
    Route::post('/{adjustment}/submit', [AccountingBankAdjustmentController::class, 'submit'])->whereNumber('adjustment')->middleware('can:erp.bank-adjustments.submit')->name('submit');
    Route::post('/{adjustment}/approve', [AccountingBankAdjustmentController::class, 'approve'])->whereNumber('adjustment')->middleware('can:erp.bank-adjustments.approve')->name('approve');
    Route::post('/{adjustment}/reject', [AccountingBankAdjustmentController::class, 'reject'])->whereNumber('adjustment')->middleware('can:erp.bank-adjustments.approve')->name('reject');
    Route::post('/{adjustment}/cancel', [AccountingBankAdjustmentController::class, 'cancel'])->whereNumber('adjustment')->middleware('can:erp.bank-adjustments.cancel')->name('cancel');
    Route::post('/{adjustment}/post', [AccountingBankAdjustmentController::class, 'post'])->whereNumber('adjustment')->middleware('can:erp.bank-adjustments.post')->name('post');
    Route::get('/{adjustment}', [AccountingBankAdjustmentController::class, 'show'])->whereNumber('adjustment')->name('show');
});

<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingCashTransferReversalController;
use Illuminate\Support\Facades\Route;

// Required once at top level by accounting-journals.php, outside manual journal route groups.
Route::prefix('erp/accounting/cash-transfer-reversals')->name('erp.accounting.cash-transfer-reversals.')
    ->middleware(['auth', 'can:erp.cash-transfer-reversals.view'])->group(function (): void {
        Route::get('/', [AccountingCashTransferReversalController::class, 'index'])->name('index');
        Route::get('/create', [AccountingCashTransferReversalController::class, 'create'])->middleware('can:erp.cash-transfer-reversals.create')->name('create');
        Route::post('/', [AccountingCashTransferReversalController::class, 'store'])->middleware('can:erp.cash-transfer-reversals.create')->name('store');
        Route::get('/{transfer}/edit', [AccountingCashTransferReversalController::class, 'edit'])->whereNumber('transfer')->middleware('can:erp.cash-transfer-reversals.edit')->name('edit');
        Route::put('/{transfer}', [AccountingCashTransferReversalController::class, 'update'])->whereNumber('transfer')->middleware('can:erp.cash-transfer-reversals.edit')->name('update');
        Route::get('/{transfer}', [AccountingCashTransferReversalController::class, 'show'])->whereNumber('transfer')->name('show');
        foreach (['submit' => 'submit', 'approve' => 'approve', 'reject' => 'approve', 'cancel' => 'cancel', 'post' => 'post'] as $action => $permission) {
            Route::post('/{transfer}/'.$action, [AccountingCashTransferReversalController::class, $action])->whereNumber('transfer')
                ->middleware('can:erp.cash-transfer-reversals.'.$permission)->name($action);
        }
    });

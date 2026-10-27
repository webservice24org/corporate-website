<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingCashTransferController;
use Illuminate\Support\Facades\Route;

// Required once at top level by accounting-journals.php, outside manual journal route groups.
Route::prefix('erp/accounting/cash-transfers')->name('erp.accounting.cash-transfers.')
    ->middleware(['auth', 'can:erp.cash-transfers.view'])->group(function (): void {
        Route::get('/', [AccountingCashTransferController::class, 'index'])->name('index');
        Route::get('/create', [AccountingCashTransferController::class, 'create'])->middleware('can:erp.cash-transfers.create')->name('create');
        Route::post('/', [AccountingCashTransferController::class, 'store'])->middleware('can:erp.cash-transfers.create')->name('store');
        Route::get('/{transfer}/edit', [AccountingCashTransferController::class, 'edit'])->whereNumber('transfer')->middleware('can:erp.cash-transfers.edit')->name('edit');
        Route::put('/{transfer}', [AccountingCashTransferController::class, 'update'])->whereNumber('transfer')->middleware('can:erp.cash-transfers.edit')->name('update');
        Route::get('/{transfer}', [AccountingCashTransferController::class, 'show'])->whereNumber('transfer')->name('show');
        foreach (['submit' => 'submit', 'approve' => 'approve', 'reject' => 'approve', 'cancel' => 'cancel', 'post' => 'post'] as $action => $permission) {
            Route::post('/{transfer}/'.$action, [AccountingCashTransferController::class, $action])->whereNumber('transfer')
                ->middleware('can:erp.cash-transfers.'.$permission)->name($action);
        }
    });

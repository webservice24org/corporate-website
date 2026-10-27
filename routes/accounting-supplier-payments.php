<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingSupplierPaymentActionController;
use App\Http\Controllers\ERP\Accounting\AccountingSupplierPaymentController;
use Illuminate\Support\Facades\Route;

// Loaded at top level by accounting-journals.php. No enclosing /erp or name prefix.
Route::prefix('erp/accounting/supplier-payments')->name('erp.accounting.supplier-payments.')->middleware(['auth', 'can:erp.supplier-payments.view'])->group(function (): void {
    Route::get('/', [AccountingSupplierPaymentController::class, 'index'])->name('index');
    Route::get('/create', [AccountingSupplierPaymentController::class, 'create'])->middleware('can:erp.supplier-payments.create')->name('create');
    Route::post('/', [AccountingSupplierPaymentController::class, 'store'])->middleware('can:erp.supplier-payments.create')->name('store');
    Route::get('/{payment}/edit', [AccountingSupplierPaymentController::class, 'edit'])->whereNumber('payment')->middleware('can:erp.supplier-payments.edit')->name('edit');
    Route::put('/{payment}', [AccountingSupplierPaymentController::class, 'update'])->whereNumber('payment')->middleware('can:erp.supplier-payments.edit')->name('update');
    Route::get('/{payment}', [AccountingSupplierPaymentController::class, 'show'])->whereNumber('payment')->name('show');
    foreach (['submit' => 'submit', 'approve' => 'approve', 'reject' => 'approve', 'cancel' => 'cancel', 'post' => 'post'] as $action => $permission) {
        Route::post('/{payment}/'.$action, [AccountingSupplierPaymentController::class, $action])->whereNumber('payment')->middleware('can:erp.supplier-payments.'.$permission)->name($action);
    }
    Route::get('/{payment}/allocations/edit', [AccountingSupplierPaymentActionController::class, 'allocations'])->whereNumber('payment')->middleware('can:erp.supplier-payments.allocate')->name('allocations.edit');
    Route::post('/{payment}/allocations', [AccountingSupplierPaymentActionController::class, 'change'])->whereNumber('payment')->middleware('can:erp.supplier-payments.allocate')->name('allocations.store');
    Route::get('/{payment}/reversal-requests/create', [AccountingSupplierPaymentActionController::class, 'createReversal'])->whereNumber('payment')->middleware('can:erp.supplier-payments.reverse')->name('reversal-requests.create');
    Route::post('/{payment}/reversal-requests', [AccountingSupplierPaymentActionController::class, 'storeReversal'])->whereNumber('payment')->middleware('can:erp.supplier-payments.reverse')->name('reversal-requests.store');
    Route::get('/reversal-requests/{reversal}/edit', [AccountingSupplierPaymentActionController::class, 'editReversal'])->whereNumber('reversal')->middleware('can:erp.supplier-payments.reverse')->name('reversal-requests.edit');
    Route::put('/reversal-requests/{reversal}', [AccountingSupplierPaymentActionController::class, 'updateReversal'])->whereNumber('reversal')->middleware('can:erp.supplier-payments.reverse')->name('reversal-requests.update');
    Route::get('/reversal-requests/{reversal}', [AccountingSupplierPaymentActionController::class, 'showReversal'])->whereNumber('reversal')->name('reversal-requests.show');
    foreach (['submit' => ['submitReversal', 'reverse'], 'approve' => ['approveReversal', 'approve'], 'reject' => ['rejectReversal', 'approve'], 'cancel' => ['cancelReversal', 'reverse'], 'post' => ['postReversal', 'reverse']] as $action => [$method, $permission]) {
        Route::post('/reversal-requests/{reversal}/'.$action, [AccountingSupplierPaymentActionController::class, $method])->whereNumber('reversal')->middleware('can:erp.supplier-payments.'.$permission)->name('reversal-requests.'.$action);
    }
});

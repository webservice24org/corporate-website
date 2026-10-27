<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingExpenseAttachmentController;
use Illuminate\Support\Facades\Route;

Route::prefix('erp/accounting/expenses/{expense}/attachments')->where(['expense' => '[0-9]+'])->name('erp.accounting.expenses.attachments.')->middleware(['auth', 'can:erp.expenses.view', 'can:erp.expense-attachments.view'])->group(function (): void {
    Route::get('/', [AccountingExpenseAttachmentController::class, 'index'])->name('index');

    Route::post('/', [AccountingExpenseAttachmentController::class, 'store'])->middleware(['can:erp.expense-attachments.upload', 'can:erp.expenses.edit'])->name('store');

    Route::post('/{attachment}/withdraw', [AccountingExpenseAttachmentController::class, 'withdraw'])->whereNumber('attachment')->middleware(['can:erp.expense-attachments.withdraw', 'can:erp.expenses.edit'])->name('withdraw');

    Route::get('/{attachment}/download', [AccountingExpenseAttachmentController::class, 'download'])->whereNumber('attachment')->middleware('can:erp.expense-attachments.download')->name('download');
});

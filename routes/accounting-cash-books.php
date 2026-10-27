<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingCashBookController;
use Illuminate\Support\Facades\Route;

// Loaded once at web.php top level through accounting-journals.php.
Route::prefix('erp/accounting')->name('erp.accounting.')->middleware(['auth', 'can:erp.cash-books.view'])->group(function (): void {
    foreach (['cashbook' => 'cash', 'bankbook' => 'bank'] as $path => $action) {
        Route::get('/'.$path, [AccountingCashBookController::class, $action])->name($path.'.index');
        Route::get('/'.$path.'/csv', [AccountingCashBookController::class, $action.'Csv'])->middleware('can:erp.cash-books.export')->name($path.'.csv');
        Route::get('/'.$path.'/print', [AccountingCashBookController::class, $action.'Print'])->middleware('can:erp.cash-books.export')->name($path.'.print');
    }
});

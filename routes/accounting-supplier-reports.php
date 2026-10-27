<?php

declare(strict_types=1);

use App\Http\Controllers\ERP\Accounting\AccountingSupplierReportController;
use Illuminate\Support\Facades\Route;

// Loaded once by accounting-journals.php at web.php top level; no manual journal permission inheritance.
Route::prefix('erp/accounting')->name('erp.accounting.')->middleware(['auth', 'can:erp.supplier-statements.view'])->group(function (): void {
    foreach (['supplier-statements' => 'statement', 'payable-register' => 'payables', 'payable-reconciliation' => 'reconciliation'] as $path => $action) {
        $extra = $action === 'reconciliation' ? ['can:erp.accounting-reports.view'] : [];
        Route::get('/'.$path, [AccountingSupplierReportController::class, $action])->middleware($extra)->name($path.'.index');
        foreach (['csv', 'print'] as $format) {
            Route::get('/'.$path.'/'.$format, [AccountingSupplierReportController::class, $action.ucfirst($format)])->middleware([...$extra, 'can:erp.supplier-statements.export', ...($action === 'reconciliation' ? ['can:erp.accounting-reports.export'] : [])])->name($path.'.'.$format);
        }
    }
});

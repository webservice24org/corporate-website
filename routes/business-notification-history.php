<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\BusinessNotificationHistoryController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/notification-history')->middleware('auth')->name('admin.notification-history.')->group(function (): void {
    Route::get('/', [BusinessNotificationHistoryController::class, 'index'])->middleware('can:mail-settings.view')->name('index');
    Route::post('/{delivery}', [BusinessNotificationHistoryController::class, 'action'])->whereNumber('delivery')
        ->middleware(['can:mail-settings.edit', 'throttle:30,1'])->name('action');
});

<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\BusinessNotificationSettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/business-notifications')->middleware(['auth'])->name('admin.business-notifications.')->group(function (): void {
    Route::get('/', [BusinessNotificationSettingsController::class, 'edit'])->middleware('can:mail-settings.view')->name('edit');
    Route::put('/template', [BusinessNotificationSettingsController::class, 'updateTemplate'])->middleware('can:mail-settings.edit')->name('template');
    Route::put('/recipients', [BusinessNotificationSettingsController::class, 'updateRecipients'])->middleware('can:mail-settings.edit')->name('recipients');
});

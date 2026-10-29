<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\SmsBdSettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/sms-bd-settings')->middleware('auth')->name('admin.sms-bd-settings.')->group(function (): void {
    Route::get('/', [SmsBdSettingsController::class, 'edit'])->middleware('can:mail-settings.view')->name('edit');
    Route::put('/', [SmsBdSettingsController::class, 'update'])->middleware('can:mail-settings.edit')->name('update');
});

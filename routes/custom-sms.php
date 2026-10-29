<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\CustomSmsController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/custom-sms')->middleware('auth')->name('admin.custom-sms.')->group(function (): void {
    Route::get('/', [CustomSmsController::class, 'index'])->middleware('can:mail-settings.view')->name('index');
    Route::post('/', [CustomSmsController::class, 'send'])->middleware('can:mail-settings.edit')->name('send');
});

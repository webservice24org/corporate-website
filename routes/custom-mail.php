<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\CustomMailController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/custom-mail')->middleware('auth')->name('admin.custom-mail.')->group(function (): void {
    Route::get('/', [CustomMailController::class, 'index'])->middleware('can:mail-settings.view')->name('index');
    Route::put('/template', [CustomMailController::class, 'saveTemplate'])->middleware('can:mail-settings.edit')->name('template');
    Route::post('/send', [CustomMailController::class, 'send'])->middleware('can:mail-settings.edit')->name('send');
});

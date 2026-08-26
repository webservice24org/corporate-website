<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\Admin\PageController;
use App\Http\Controllers\Admin\PageBuilderController;
use App\Http\Controllers\ContactMessageController;

use App\Http\Controllers\Admin\MailSettingController;
use App\Http\Controllers\Admin\GlobalFooterController;

use App\Http\Controllers\Frontend\PageController as FrontendPageController;

use App\Http\Controllers\Admin\ContactMessageController as AdminContactMessageController;
use App\Http\Controllers\Admin\NewsletterSubscriberController as AdminNewsletterSubscriberController;
use App\Http\Controllers\Admin\GlobalFloatingContactController;
use App\Http\Controllers\Admin\GlobalHeaderController;

use App\Http\Controllers\NewsletterSubscriberController;


Route::get('/', function () {
    return Inertia::render('Frontend/Home');
});


Route::prefix('admin/')->name('admin.')->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('Admin/Dashboard');
    })->name('dashboard');

    Route::resource('pages', PageController::class)
    ->except(['show']);

    Route::get(
        'pages/{page}/builder',
        [PageBuilderController::class, 'edit'],
    )->name('pages.builder');

    Route::post(
        'pages/{page}/sections',
        [PageBuilderController::class, 'storeSection'],
    )->name('pages.sections.store');

    Route::post(
        '/pages/{page}/sections/reorder',
        [PageBuilderController::class, 'reorderSections'],
    )->name('pages.sections.reorder');

    Route::put(
        'pages/{page}/sections/{section}',
        [PageBuilderController::class, 'updateSection'],
    )->name('pages.sections.update');

    Route::post(
        'pages/{page}/sections/{section}/duplicate',
        [PageBuilderController::class, 'duplicateSection'],
    )->name('pages.sections.duplicate');

    Route::post(
        'pages/{page}/sections/{section}/move-up',
        [PageBuilderController::class, 'moveSectionUp'],
    )->name('pages.sections.move-up');

    Route::post(
        'pages/{page}/sections/{section}/move-down',
        [PageBuilderController::class, 'moveSectionDown'],
    )->name('pages.sections.move-down');

    Route::post(
        'page-builder/upload-image',
        [PageBuilderController::class, 'uploadImage'],
    )->name('page-builder.upload-image');

    

    Route::delete(
        'pages/{page}/sections/{section}',
        [PageBuilderController::class, 'destroySection'],
    )->name('pages.sections.destroy');

    Route::get(
            'contact-messages',
            [AdminContactMessageController::class, 'index']
        )->name('contact-messages.index');

        Route::get(
            'contact-messages/{contactMessage}',
            [AdminContactMessageController::class, 'show']
        )->name('contact-messages.show');

        Route::post(
            'contact-messages/{contactMessage}/reply',
            [AdminContactMessageController::class, 'reply']
        )->name('contact-messages.reply');

        Route::patch(
            'contact-messages/{contactMessage}/archive',
            [AdminContactMessageController::class, 'archive']
        )->name('contact-messages.archive');

        Route::delete(
            'contact-messages/{contactMessage}',
            [AdminContactMessageController::class, 'destroy']
        )->name('contact-messages.destroy');

        Route::get(
            'mail-settings',
            [MailSettingController::class, 'edit']
        )->name('mail-settings.edit');

        Route::put(
            'mail-settings',
            [MailSettingController::class, 'update']
        )->name('mail-settings.update');

        Route::post(
            'mail-settings/test',
            [MailSettingController::class, 'test']
        )->name('mail-settings.test');

        Route::get(
            'global/footer',
            [GlobalFooterController::class, 'edit']
        )->name('global.footer.edit');

        Route::put(
            'global/footer',
            [GlobalFooterController::class, 'update']
        )->name('global.footer.update');

        Route::get(
            'global/header',
            [GlobalHeaderController::class, 'edit']
        )->name('global.header.edit');

        Route::put(
            'global/header',
            [GlobalHeaderController::class, 'update']
        )->name('global.header.update');

        Route::get(
            'newsletter-subscribers',
            [AdminNewsletterSubscriberController::class, 'index']
        )->name('newsletter-subscribers.index');

        Route::patch(
            'newsletter-subscribers/{newsletterSubscriber}/unsubscribe',
            [AdminNewsletterSubscriberController::class, 'unsubscribe']
        )->name('newsletter-subscribers.unsubscribe');

        Route::patch(
            'newsletter-subscribers/{newsletterSubscriber}/reactivate',
            [AdminNewsletterSubscriberController::class, 'reactivate']
        )->name('newsletter-subscribers.reactivate');

        Route::delete(
            'newsletter-subscribers/{newsletterSubscriber}',
            [AdminNewsletterSubscriberController::class, 'destroy']
        )->name('newsletter-subscribers.destroy');

        Route::get(
            'global/floating-contact',
            [
                GlobalFloatingContactController::class,
                'edit',
            ]
        )->name(
            'global.floating-contact.edit'
        );

        Route::put(
            'global/floating-contact',
            [
                GlobalFloatingContactController::class,
                'update',
            ]
        )->name(
            'global.floating-contact.update'
        );


});





/*
Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

*/

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});



Route::post(
    '/newsletter/subscribe',
    [NewsletterSubscriberController::class, 'store']
)
    ->middleware('throttle:5,1')
    ->name('newsletter.subscribe');

Route::post(
    '/contact/messages',
    [ContactMessageController::class, 'store']
)
    ->middleware('throttle:5,1')
    ->name('contact.messages.store');

Route::get('/{slug}', [FrontendPageController::class, 'show'])
    ->name('frontend.page');

require __DIR__.'/auth.php';

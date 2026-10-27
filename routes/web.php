<?php

use App\Http\Controllers\Admin\ContactMessageController as AdminContactMessageController;
use App\Http\Controllers\Admin\GlobalFloatingContactController;
/*
|--------------------------------------------------------------------------
| Frontend Controllers
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\Admin\GlobalFooterController;
use App\Http\Controllers\Admin\GlobalHeaderController;
use App\Http\Controllers\Admin\MailSettingController;
use App\Http\Controllers\Admin\NewsletterSubscriberController as AdminNewsletterSubscriberController;
use App\Http\Controllers\Admin\PageBuilderController;
use App\Http\Controllers\Admin\PageController;
/*
|--------------------------------------------------------------------------
| Account Controllers
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\Admin\RoleController;
/*
|--------------------------------------------------------------------------
| Admin Controllers
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ContactMessageController;
use App\Http\Controllers\Frontend\DealerRegistrationController;
use App\Http\Controllers\Frontend\DepotRegistrationController;
use App\Http\Controllers\Frontend\HomeController;
use App\Http\Controllers\Frontend\PageController as FrontendPageController;
use App\Http\Controllers\NewsletterSubscriberController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Frontend Home
|--------------------------------------------------------------------------
*/

Route::get(
    '/',
    [HomeController::class, 'index']
)->name('home');

/*
|--------------------------------------------------------------------------
| Public Form Endpoints
|--------------------------------------------------------------------------
*/

Route::post(
    '/contact/messages',
    [ContactMessageController::class, 'store']
)
    ->middleware('throttle:5,1')
    ->name('contact.messages.store');

Route::post(
    '/newsletter/subscribe',
    [NewsletterSubscriberController::class, 'store']
)
    ->middleware('throttle:5,1')
    ->name('newsletter.subscribe');

/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
*/

require __DIR__.'/auth.php';

/*
|--------------------------------------------------------------------------
| Authenticated User Account
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {

    Route::get(
        '/profile',
        [ProfileController::class, 'edit']
    )->name('profile.edit');

    Route::patch(
        '/profile',
        [ProfileController::class, 'update']
    )->name('profile.update');

    Route::delete(
        '/profile',
        [ProfileController::class, 'destroy']
    )->name('profile.destroy');

});

/*
|--------------------------------------------------------------------------
| Admin Panel
|--------------------------------------------------------------------------
*/

Route::prefix('admin/')
    ->name('admin.')
    ->middleware(['auth'])
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Dashboard
        |--------------------------------------------------------------------------
        */

        Route::get(
            'dashboard',
            function () {
                return Inertia::render(
                    'Admin/Dashboard'
                );
            }
        )
            ->middleware(
                'can:dashboard.view'
            )
            ->name('dashboard');

        /*
        |--------------------------------------------------------------------------
        | Users
        |--------------------------------------------------------------------------
        */

        Route::get(
            'users',
            [UserController::class, 'index']
        )
            ->middleware(
                'can:users.view'
            )
            ->name('users.index');

        Route::get(
            'users/create',
            [UserController::class, 'create']
        )
            ->middleware(
                'can:users.create'
            )
            ->name('users.create');

        Route::post(
            'users',
            [UserController::class, 'store']
        )
            ->middleware(
                'can:users.create'
            )
            ->name('users.store');

        Route::get(
            'users/{user}/edit',
            [UserController::class, 'edit']
        )
            ->middleware(
                'can:users.edit'
            )
            ->name('users.edit');

        Route::put(
            'users/{user}',
            [UserController::class, 'update']
        )
            ->middleware(
                'can:users.edit'
            )
            ->name('users.update');

        Route::delete(
            'users/{user}',
            [UserController::class, 'destroy']
        )
            ->middleware(
                'can:users.delete'
            )
            ->name('users.destroy');

        /*
        |--------------------------------------------------------------------------
        | Roles & Permissions
        |--------------------------------------------------------------------------
        */

        Route::get(
            'roles',
            [RoleController::class, 'index']
        )
            ->middleware(
                'can:roles.view'
            )
            ->name('roles.index');

        Route::get(
            'roles/create',
            [RoleController::class, 'create']
        )
            ->middleware(
                'can:roles.create'
            )
            ->name('roles.create');

        Route::post(
            'roles',
            [RoleController::class, 'store']
        )
            ->middleware(
                'can:roles.create'
            )
            ->name('roles.store');

        Route::get(
            'roles/{role}/edit',
            [RoleController::class, 'edit']
        )
            ->middleware(
                'can:roles.edit'
            )
            ->name('roles.edit');

        Route::put(
            'roles/{role}',
            [RoleController::class, 'update']
        )
            ->middleware(
                'can:roles.edit'
            )
            ->name('roles.update');

        Route::delete(
            'roles/{role}',
            [RoleController::class, 'destroy']
        )
            ->middleware(
                'can:roles.delete'
            )
            ->name('roles.destroy');

        /*
        |--------------------------------------------------------------------------
        | Pages
        |--------------------------------------------------------------------------
        */

        Route::get(
            'pages',
            [PageController::class, 'index']
        )
            ->middleware(
                'can:pages.view'
            )
            ->name('pages.index');

        Route::get(
            'pages/create',
            [PageController::class, 'create']
        )
            ->middleware(
                'can:pages.create'
            )
            ->name('pages.create');

        Route::post(
            'pages',
            [PageController::class, 'store']
        )
            ->middleware(
                'can:pages.create'
            )
            ->name('pages.store');

        Route::get(
            'pages/{page}/edit',
            [PageController::class, 'edit']
        )
            ->middleware(
                'can:pages.edit'
            )
            ->name('pages.edit');

        Route::put(
            'pages/{page}',
            [PageController::class, 'update']
        )
            ->middleware(
                'can:pages.edit'
            )
            ->name('pages.update');

        Route::delete(
            'pages/{page}',
            [PageController::class, 'destroy']
        )
            ->middleware(
                'can:pages.delete'
            )
            ->name('pages.destroy');

        /*
        |--------------------------------------------------------------------------
        | Page Builder
        |--------------------------------------------------------------------------
        */

        Route::get(
            'pages/{page}/builder',
            [PageBuilderController::class, 'edit']
        )
            ->middleware(
                'can:page-builder.view'
            )
            ->name('pages.builder');

        Route::post(
            'pages/{page}/sections',
            [PageBuilderController::class, 'storeSection']
        )
            ->middleware(
                'can:page-builder.edit'
            )
            ->name('pages.sections.store');

        Route::post(
            'pages/{page}/sections/reorder',
            [PageBuilderController::class, 'reorderSections']
        )
            ->middleware(
                'can:page-builder.edit'
            )
            ->name('pages.sections.reorder');

        Route::put(
            'pages/{page}/sections/{section}',
            [PageBuilderController::class, 'updateSection']
        )
            ->middleware(
                'can:page-builder.edit'
            )
            ->name('pages.sections.update');

        Route::delete(
            'pages/{page}/sections/{section}',
            [PageBuilderController::class, 'destroySection']
        )
            ->middleware(
                'can:page-builder.edit'
            )
            ->name('pages.sections.destroy');

        Route::post(
            'pages/{page}/sections/{section}/duplicate',
            [PageBuilderController::class, 'duplicateSection']
        )
            ->middleware(
                'can:page-builder.edit'
            )
            ->name('pages.sections.duplicate');

        Route::post(
            'pages/{page}/sections/{section}/move-up',
            [PageBuilderController::class, 'moveSectionUp']
        )
            ->middleware(
                'can:page-builder.edit'
            )
            ->name('pages.sections.move-up');

        Route::post(
            'pages/{page}/sections/{section}/move-down',
            [PageBuilderController::class, 'moveSectionDown']
        )
            ->middleware(
                'can:page-builder.edit'
            )
            ->name('pages.sections.move-down');

        /*
        |--------------------------------------------------------------------------
        | Page Builder Media
        |--------------------------------------------------------------------------
        */

        Route::post(
            'page-builder/upload-image',
            [PageBuilderController::class, 'uploadImage']
        )
            ->middleware(
                'can:page-builder.edit'
            )
            ->name('page-builder.upload-image');

        /*
        |--------------------------------------------------------------------------
        | Contact Messages
        |--------------------------------------------------------------------------
        */

        Route::get(
            'contact-messages',
            [AdminContactMessageController::class, 'index']
        )
            ->middleware(
                'can:contact-messages.view'
            )
            ->name('contact-messages.index');

        Route::get(
            'contact-messages/{contactMessage}',
            [AdminContactMessageController::class, 'show']
        )
            ->middleware(
                'can:contact-messages.view'
            )
            ->name('contact-messages.show');

        Route::post(
            'contact-messages/{contactMessage}/reply',
            [AdminContactMessageController::class, 'reply']
        )
            ->middleware(
                'can:contact-messages.reply'
            )
            ->name('contact-messages.reply');

        Route::patch(
            'contact-messages/{contactMessage}/archive',
            [AdminContactMessageController::class, 'archive']
        )
            ->middleware(
                'can:contact-messages.archive'
            )
            ->name('contact-messages.archive');

        Route::delete(
            'contact-messages/{contactMessage}',
            [AdminContactMessageController::class, 'destroy']
        )
            ->middleware(
                'can:contact-messages.delete'
            )
            ->name('contact-messages.destroy');

        /*
        |--------------------------------------------------------------------------
        | Newsletter Subscribers
        |--------------------------------------------------------------------------
        */

        Route::get(
            'newsletter-subscribers',
            [
                AdminNewsletterSubscriberController::class,
                'index',
            ]
        )
            ->middleware(
                'can:newsletter.view'
            )
            ->name(
                'newsletter-subscribers.index'
            );

        Route::patch(
            'newsletter-subscribers/{newsletterSubscriber}/unsubscribe',
            [
                AdminNewsletterSubscriberController::class,
                'unsubscribe',
            ]
        )
            ->middleware(
                'can:newsletter.manage'
            )
            ->name(
                'newsletter-subscribers.unsubscribe'
            );

        Route::patch(
            'newsletter-subscribers/{newsletterSubscriber}/reactivate',
            [
                AdminNewsletterSubscriberController::class,
                'reactivate',
            ]
        )
            ->middleware(
                'can:newsletter.manage'
            )
            ->name(
                'newsletter-subscribers.reactivate'
            );

        Route::delete(
            'newsletter-subscribers/{newsletterSubscriber}',
            [
                AdminNewsletterSubscriberController::class,
                'destroy',
            ]
        )
            ->middleware(
                'can:newsletter.manage'
            )
            ->name(
                'newsletter-subscribers.destroy'
            );

        /*
        |--------------------------------------------------------------------------
        | Mail Settings
        |--------------------------------------------------------------------------
        */

        Route::get(
            'mail-settings',
            [MailSettingController::class, 'edit']
        )
            ->middleware(
                'can:mail-settings.view'
            )
            ->name('mail-settings.edit');

        Route::put(
            'mail-settings',
            [MailSettingController::class, 'update']
        )
            ->middleware(
                'can:mail-settings.edit'
            )
            ->name('mail-settings.update');

        Route::post(
            'mail-settings/test',
            [MailSettingController::class, 'test']
        )
            ->middleware(
                'can:mail-settings.test'
            )
            ->name('mail-settings.test');

        /*
        |--------------------------------------------------------------------------
        | Global Header
        |--------------------------------------------------------------------------
        */

        Route::get(
            'global/header',
            [GlobalHeaderController::class, 'edit']
        )
            ->middleware(
                'can:global-header.view'
            )
            ->name('global.header.edit');

        Route::put(
            'global/header',
            [GlobalHeaderController::class, 'update']
        )
            ->middleware(
                'can:global-header.edit'
            )
            ->name('global.header.update');

        /*
        |--------------------------------------------------------------------------
        | Global Footer
        |--------------------------------------------------------------------------
        */

        Route::get(
            'global/footer',
            [GlobalFooterController::class, 'edit']
        )
            ->middleware(
                'can:global-footer.view'
            )
            ->name('global.footer.edit');

        Route::put(
            'global/footer',
            [GlobalFooterController::class, 'update']
        )
            ->middleware(
                'can:global-footer.edit'
            )
            ->name('global.footer.update');

        /*
        |--------------------------------------------------------------------------
        | Global Floating Contact
        |--------------------------------------------------------------------------
        */

        Route::get(
            'global/floating-contact',
            [
                GlobalFloatingContactController::class,
                'edit',
            ]
        )
            ->middleware(
                'can:floating-contact.view'
            )
            ->name(
                'global.floating-contact.edit'
            );

        Route::put(
            'global/floating-contact',
            [
                GlobalFloatingContactController::class,
                'update',
            ]
        )
            ->middleware(
                'can:floating-contact.edit'
            )
            ->name(
                'global.floating-contact.update'
            );

    });

Route::get(
    'depot-registration',
    [
        DepotRegistrationController::class,
        'create',
    ]
)->name(
    'frontend.depot-registration.create'
);

Route::post(
    '/depot-registration',
    [
        DepotRegistrationController::class,
        'store',
    ]
)->name(
    'frontend.depot-registration.store'
);

Route::get('/dealer-registration', [DealerRegistrationController::class, 'create'])
    ->name('frontend.dealer-registration.create');

Route::post('/dealer-registration', [DealerRegistrationController::class, 'store'])
    ->name('frontend.dealer-registration.store');

/*
|--------------------------------------------------------------------------
| ERP Routes
|--------------------------------------------------------------------------
|
| ERP routes are kept in their own route file so the ERP can grow
| independently from the CMS and public website.
|
*/

require __DIR__.'/erp.php';
require __DIR__.'/accounting-accounts.php';
require __DIR__.'/accounting-mappings.php';
require __DIR__.'/accounting-starter.php';
require __DIR__.'/accounting-calendar.php';
require __DIR__.'/accounting-journals.php';
require __DIR__.'/accounting-openings.php';
require __DIR__.'/accounting-cutover.php';
require __DIR__.'/accounting-manifest.php';

/*
|--------------------------------------------------------------------------
| Dynamic Frontend Pages
|--------------------------------------------------------------------------
|
| IMPORTANT:
| Keep this route LAST.
|
*/

Route::get(
    '/{slug}',
    [FrontendPageController::class, 'show']
)->name('frontend.page');

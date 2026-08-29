<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(
            PermissionRegistrar::class
        )->forgetCachedPermissions();

        $permissions = [
            'dashboard.view',

            'pages.view',
            'pages.create',
            'pages.edit',
            'pages.delete',

            'page-builder.view',
            'page-builder.edit',

            'contact-messages.view',
            'contact-messages.reply',
            'contact-messages.archive',
            'contact-messages.delete',

            'newsletter.view',
            'newsletter.manage',

            'users.view',
            'users.create',
            'users.edit',
            'users.delete',

            'roles.view',
            'roles.create',
            'roles.edit',
            'roles.delete',

            'global-header.view',
            'global-header.edit',

            'global-footer.view',
            'global-footer.edit',

            'floating-contact.view',
            'floating-contact.edit',

            'mail-settings.view',
            'mail-settings.edit',
            'mail-settings.test',
        ];

        foreach (
            $permissions as $permission
        ) {
            Permission::findOrCreate(
                $permission,
                'web'
            );
        }

        /*
         |--------------------------------------------------------------------------
         | Super Admin
         |--------------------------------------------------------------------------
         */

        $superAdmin =
            Role::findOrCreate(
                'super-admin',
                'web'
            );

        $superAdmin->syncPermissions(
            Permission::all()
        );

        /*
         |--------------------------------------------------------------------------
         | Admin
         |--------------------------------------------------------------------------
         */

        $admin =
            Role::findOrCreate(
                'admin',
                'web'
            );

        $admin->syncPermissions([
            'dashboard.view',

            'pages.view',
            'pages.create',
            'pages.edit',
            'pages.delete',

            'page-builder.view',
            'page-builder.edit',

            'contact-messages.view',
            'contact-messages.reply',
            'contact-messages.archive',
            'contact-messages.delete',

            'newsletter.view',
            'newsletter.manage',

            'global-header.view',
            'global-header.edit',

            'global-footer.view',
            'global-footer.edit',

            'floating-contact.view',
            'floating-contact.edit',
        ]);

        /*
         |--------------------------------------------------------------------------
         | Editor
         |--------------------------------------------------------------------------
         */

        $editor =
            Role::findOrCreate(
                'editor',
                'web'
            );

        $editor->syncPermissions([
            'dashboard.view',

            'pages.view',
            'pages.create',
            'pages.edit',

            'page-builder.view',
            'page-builder.edit',
        ]);

        app(
            PermissionRegistrar::class
        )->forgetCachedPermissions();
    }
}
<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class AccountingYearEndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['erp.year-end.view', 'erp.year-end.prepare', 'erp.year-end.approve', 'erp.year-end.close', 'erp.year-end.reopen'] as $name) {
            Role::findOrCreate('super-admin', 'web')->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

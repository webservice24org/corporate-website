<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class AccountingPurchasePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = ['erp.supplier-credits.create', 'erp.supplier-credits.approve', 'erp.supplier-credits.issue'];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::findOrCreate('super-admin', 'web')->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

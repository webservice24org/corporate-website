<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\ERP\Accounting\AccountingDomainRegistry;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class AccountingPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = app(AccountingDomainRegistry::class)->permissions();
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::findOrCreate('super-admin', 'web')->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class AccountingCashFlowWorkspacePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['erp.cash-flow.view', 'erp.cash-flow.export'] as $name) {
            Role::findOrCreate('super-admin', 'web')->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

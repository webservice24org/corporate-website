<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class AccountingLedgerReadPermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['erp.general-ledger.view', 'erp.trial-balance.view'] as $name) {
            $permission = Permission::findOrCreate($name, 'web');
            Role::findOrCreate('super-admin', 'web')->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

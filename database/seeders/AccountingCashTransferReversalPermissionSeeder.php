<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\ERP\Accounting\Transfers\AccountingCashTransferReversalContract;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class AccountingCashTransferReversalPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (AccountingCashTransferReversalContract::PERMISSIONS as $name) {
            $permission = Permission::findOrCreate($name, 'web');
            Role::findOrCreate('super-admin', 'web')->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\ERP\Accounting\Transfers\AccountingCashTransferContract;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class AccountingCashTransferPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (AccountingCashTransferContract::PERMISSIONS as $name) {
            $permission = Permission::findOrCreate($name, 'web');
            Role::findOrCreate('super-admin', 'web')->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

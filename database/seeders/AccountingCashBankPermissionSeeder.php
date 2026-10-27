<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\ERP\Accounting\AccountingCashBankReadinessService;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class AccountingCashBankPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate(AccountingCashBankReadinessService::PERMISSION, 'web');
        Role::findOrCreate('super-admin', 'web')->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

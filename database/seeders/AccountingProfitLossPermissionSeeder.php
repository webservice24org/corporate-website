<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class AccountingProfitLossPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('super-admin', 'web')->givePermissionTo(Permission::findOrCreate('erp.profit-loss.view', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

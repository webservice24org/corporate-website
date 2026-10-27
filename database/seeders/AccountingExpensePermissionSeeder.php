<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\ERP\Accounting\Expenses\AccountingExpenseContract;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class AccountingExpensePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('super-admin', 'web');

        foreach (AccountingExpenseContract::PERMISSIONS as $name) {
            $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

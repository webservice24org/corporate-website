<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\ERP\Accounting\Reconciliation\AccountingBankFinalizationWorkflow;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class AccountingBankFinalizationPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::findOrCreate('super-admin', 'web');
        foreach (AccountingBankFinalizationWorkflow::PERMISSIONS as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

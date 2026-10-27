<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\ERP\Accounting\Reconciliation\AccountingBankReconciliationExportService;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class AccountingBankReconciliationExportPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('super-admin', 'web')->givePermissionTo(Permission::findOrCreate(AccountingBankReconciliationExportService::PERMISSION, 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\ERP\Accounting\Expenses\AccountingExpenseAttachmentLedger;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class AccountingExpenseAttachmentPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('super-admin', 'web');

        foreach (AccountingExpenseAttachmentLedger::PERMISSIONS as $name) {
            $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

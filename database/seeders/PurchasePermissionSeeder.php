<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class PurchasePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = [
            'erp.suppliers.view',
            'erp.suppliers.create',
            'erp.suppliers.edit',
            'erp.suppliers.delete',
            'erp.suppliers.contacts.manage',
            'erp.suppliers.addresses.manage',
            'erp.purchase-requisitions.view',
            'erp.purchase-requisitions.create',
            'erp.purchase-requisitions.edit',
            'erp.purchase-requisitions.submit',
            'erp.purchase-requisitions.approve',
            'erp.purchase-requisitions.reject',
            'erp.purchase-rfqs.view',
            'erp.purchase-rfqs.create',
            'erp.purchase-rfqs.edit',
            'erp.purchase-rfqs.issue',
            'erp.purchase-rfqs.award',
            'erp.supplier-quotations.view',
            'erp.supplier-quotations.create',
            'erp.supplier-quotations.edit',
            'erp.supplier-quotations.compare',
            'erp.purchase-orders.view',
            'erp.purchase-orders.create',
            'erp.purchase-orders.edit',
            'erp.purchase-orders.submit',
            'erp.purchase-orders.approve',
            'erp.purchase-orders.reject',
            'erp.purchase-orders.order',
            'erp.purchase-orders.close',
            'erp.goods-receipts.view',
            'erp.goods-receipts.create',
            'erp.goods-receipts.edit',
            'erp.goods-receipts.cancel',
            'erp.goods-receipts.post',
            'erp.purchase-returns.view',
            'erp.purchase-returns.create',
            'erp.purchase-returns.edit',
            'erp.purchase-returns.cancel',
            'erp.purchase-returns.approve',
            'erp.purchase-returns.post',
            'erp.supplier-bills.view',
            'erp.supplier-bills.create',
            'erp.supplier-bills.edit',
            'erp.supplier-bills.approve',
            'erp.supplier-bills.cancel',
            'erp.supplier-payables.view',
            'erp.purchase-reports.view',
            'erp.purchase-reports.export',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $superAdmin = Role::findOrCreate('super-admin', 'web');
        $superAdmin->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

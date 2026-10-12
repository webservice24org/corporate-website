<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(
            PermissionRegistrar::class
        )->forgetCachedPermissions();

        $permissions = [
            /*
            |--------------------------------------------------------------------------
            | CMS
            |--------------------------------------------------------------------------
            */

            'dashboard.view',

            'pages.view',
            'pages.create',
            'pages.edit',
            'pages.delete',

            'page-builder.view',
            'page-builder.edit',

            'contact-messages.view',
            'contact-messages.reply',
            'contact-messages.archive',
            'contact-messages.delete',

            'newsletter.view',
            'newsletter.manage',

            'users.view',
            'users.create',
            'users.edit',
            'users.delete',

            'roles.view',
            'roles.create',
            'roles.edit',
            'roles.delete',

            'global-header.view',
            'global-header.edit',

            'global-footer.view',
            'global-footer.edit',

            'floating-contact.view',
            'floating-contact.edit',

            'mail-settings.view',
            'mail-settings.edit',
            'mail-settings.test',

            /*
            |--------------------------------------------------------------------------
            | ERP
            |--------------------------------------------------------------------------
            */

            'erp.dashboard.view',
            /*
            |--------------------------------------------------------------------------
            | ERP - Organization
            |--------------------------------------------------------------------------
            */

            'erp.companies.view',
            'erp.companies.create',
            'erp.companies.edit',
            'erp.companies.delete',

            'erp.branches.view',
            'erp.branches.create',
            'erp.branches.edit',
            'erp.branches.delete',

            'erp.departments.view',
            'erp.departments.create',
            'erp.departments.edit',
            'erp.departments.delete',

            'erp.designations.view',
            'erp.designations.create',
            'erp.designations.edit',
            'erp.designations.delete',

            'erp.employees.view',
            'erp.employees.create',
            'erp.employees.edit',
            'erp.employees.delete',

            'erp.employee-documents.view',
            'erp.employee-documents.create',
            'erp.employee-documents.edit',
            'erp.employee-documents.delete',

            'erp.employee-history.view',
            'erp.employee-history.create',
            'erp.employee-history.edit',
            'erp.employee-history.delete',

            'erp.attendance-settings.view',
            'erp.attendance-settings.edit',

            'erp.shifts.view',
            'erp.shifts.create',
            'erp.shifts.edit',
            'erp.shifts.delete',

            'erp.employee-shifts.view',
            'erp.employee-shifts.create',
            'erp.employee-shifts.edit',
            'erp.employee-shifts.delete',

            'erp.attendance-devices.view',
            'erp.attendance-devices.create',
            'erp.attendance-devices.edit',
            'erp.attendance-devices.delete',

            'erp.attendance-device-users.view',
            'erp.attendance-device-users.create',
            'erp.attendance-device-users.edit',
            'erp.attendance-device-users.delete',

            'erp.attendance-raw-punches.view',
            'erp.attendance-device-users.resolve',
            'erp.attendance-records.view',
            'erp.attendance-records.process',

            'erp.attendance-holidays.view',
            'erp.attendance-holidays.create',
            'erp.attendance-holidays.edit',
            'erp.attendance-holidays.delete',

            'erp.attendance-finalization.view',
            'erp.attendance-finalization.run',

            'erp.leave-types.view',
            'erp.leave-types.create',
            'erp.leave-types.edit',
            'erp.leave-types.delete',

            'erp.leave-requests.view',
            'erp.leave-requests.create',
            'erp.leave-requests.approve',
            'erp.leave-requests.reject',
            'erp.leave-requests.cancel',

            'erp.leave-balances.view',
            'erp.leave-balances.adjust',

            'erp.attendance-reports.view',
            'erp.attendance-reports.export',

            'erp.salary-components.view',
            'erp.salary-components.create',
            'erp.salary-components.edit',
            'erp.salary-components.delete',

            'erp.salary-structures.view',
            'erp.salary-structures.create',
            'erp.salary-structures.edit',
            'erp.salary-structures.delete',

            'erp.payroll-settings.view',
            'erp.payroll-settings.edit',

            'erp.payroll-periods.view',
            'erp.payroll-periods.create',
            'erp.payroll-periods.edit',
            'erp.payroll-periods.delete',

            'erp.lead-sources.view',
            'erp.lead-sources.create',
            'erp.lead-sources.edit',
            'erp.lead-sources.delete',

            'erp.lead-statuses.view',
            'erp.lead-statuses.create',
            'erp.lead-statuses.edit',
            'erp.lead-statuses.delete',

            'erp.leads.view',
            'erp.leads.create',
            'erp.leads.edit',
            'erp.leads.delete',

            'erp.crm-activities.view',
            'erp.crm-activities.create',
            'erp.crm-activities.edit',
            'erp.crm-activities.delete',

            'erp.depots.view',
            'erp.depots.create',
            'erp.depots.edit',
            'erp.depots.delete',

            'erp.depot-applications.view',
            'erp.depot-applications.review',
            'erp.depot-applications.approve',
            'erp.depot-applications.reject',
            'erp.depot-applications.documents',

            'erp.divisions.view',
            'erp.divisions.create',
            'erp.divisions.edit',
            'erp.divisions.delete',

            'erp.districts.view',
            'erp.districts.create',
            'erp.districts.edit',
            'erp.districts.delete',

            'erp.upazilas.view',
            'erp.upazilas.create',
            'erp.upazilas.edit',
            'erp.upazilas.delete',

            'erp.dealers.view',
            'erp.dealers.create',
            'erp.dealers.edit',
            'erp.dealers.delete',

            'erp.dealer-applications.view',
            'erp.dealer-applications.review',
            'erp.dealer-applications.approve',
            'erp.dealer-applications.reject',
            'erp.dealer-applications.documents',

            'erp.sellers.view',
            'erp.sellers.create',
            'erp.sellers.edit',
            'erp.sellers.delete',

            'erp.depots.view',
            'erp.depots.create',
            'erp.depots.edit',
            'erp.depots.delete',

            'erp.depot-documents.view',
            'erp.depot-documents.create',
            'erp.depot-documents.edit',
            'erp.depot-documents.verify',
            'erp.depot-documents.delete',

            'erp.dealer-documents.view',
            'erp.dealer-documents.create',
            'erp.dealer-documents.verify',
            'erp.dealer-documents.delete',

            'erp.depots.activity.view',
            'erp.dealers.activity.view',
            'erp.customers.view',
            'erp.customers.create',
            'erp.customers.edit',
            'erp.customers.delete',
            'erp.leads.convert',

            'erp.opportunity-stages.view',
            'erp.opportunity-stages.create',
            'erp.opportunity-stages.edit',
            'erp.opportunity-stages.delete',
            'erp.opportunities.view',
            'erp.opportunities.create',
            'erp.opportunities.edit',
            'erp.opportunities.delete',
            'erp.crm-analytics.view',

            'erp.product-categories.view',
            'erp.product-categories.create',
            'erp.product-categories.edit',
            'erp.product-categories.delete',
            'erp.brands.view',
            'erp.brands.create',
            'erp.brands.edit',
            'erp.brands.delete',
            'erp.units.view',
            'erp.units.create',
            'erp.units.edit',
            'erp.units.delete',
            'erp.products.view',
            'erp.products.create',
            'erp.products.edit',
            'erp.products.delete',

            // Warehouses
            'erp.warehouses.view',
            'erp.warehouses.create',
            'erp.warehouses.edit',
            'erp.warehouses.delete',
            'erp.stock-movements.view',

            // Stock transfer permissions used by routes/erp.php
            'erp.stock-transfers.view',
            'erp.stock-transfers.create',
            'erp.stock-transfers.edit',
            'erp.stock-transfers.delete',
            'erp.stock-transfers.post',

            'erp.inventory-stock.view',
            'erp.stock-movements.view',

            'erp.opening-stocks.view',
            'erp.opening-stocks.create',
            'erp.opening-stocks.edit',
            'erp.opening-stocks.delete',
            'erp.opening-stocks.post',

        ];

        foreach (
            $permissions as $permission
        ) {
            Permission::findOrCreate(
                $permission,
                'web'
            );
        }

        /*
         |--------------------------------------------------------------------------
         | Super Admin
         |--------------------------------------------------------------------------
         */

        $superAdmin =
            Role::findOrCreate(
                'super-admin',
                'web'
            );

        $superAdmin->syncPermissions(
            Permission::all()
        );

        /*
         |--------------------------------------------------------------------------
         | Admin
         |--------------------------------------------------------------------------
         */

        $admin =
            Role::findOrCreate(
                'admin',
                'web'
            );

        $admin->syncPermissions([
            'dashboard.view',

            'pages.view',
            'pages.create',
            'pages.edit',
            'pages.delete',

            'page-builder.view',
            'page-builder.edit',

            'contact-messages.view',
            'contact-messages.reply',
            'contact-messages.archive',
            'contact-messages.delete',

            'newsletter.view',
            'newsletter.manage',

            'global-header.view',
            'global-header.edit',

            'global-footer.view',
            'global-footer.edit',

            'floating-contact.view',
            'floating-contact.edit',
        ]);

        /*
         |--------------------------------------------------------------------------
         | Editor
         |--------------------------------------------------------------------------
         */

        $editor =
            Role::findOrCreate(
                'editor',
                'web'
            );

        $editor->syncPermissions([
            'dashboard.view',

            'pages.view',
            'pages.create',
            'pages.edit',

            'page-builder.view',
            'page-builder.edit',
        ]);

        app(
            PermissionRegistrar::class
        )->forgetCachedPermissions();
    }
}

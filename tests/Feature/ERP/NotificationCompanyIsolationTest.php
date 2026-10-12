<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Inventory\LowStockCompanyAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NotificationCompanyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $code): Company
    {
        return Company::query()->create([
            'name' => 'Company '.$code,
            'code' => $code,
        ]);
    }

    private function employee(User $user, Company $company, string $number): Employee
    {
        return Employee::query()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'employee_number' => $number,
            'first_name' => 'Test',
            'last_name' => 'Employee',
        ]);
    }

    private function grantReportPermission(User $user): void
    {
        Permission::findOrCreate('erp.low-stock-reports.view', 'web');
        $user->givePermissionTo('erp.low-stock-reports.view');
    }

    public function test_employee_only_has_access_to_own_company(): void
    {
        $a = $this->company('TEST-A');
        $b = $this->company('TEST-B');
        $user = User::factory()->create();
        $this->employee($user, $a, 'EMP-TEST-A');
        $this->grantReportPermission($user);

        $access = app(LowStockCompanyAccess::class);
        $this->assertTrue($access->canView($user, (int) $a->id));
        $this->assertFalse($access->canView($user, (int) $b->id));
        $this->assertSame([(int) $a->id], $access->allowedCompanyIds($user));
    }

    public function test_company_transfer_revokes_old_company_access(): void
    {
        $a = $this->company('TEST-C');
        $b = $this->company('TEST-D');
        $user = User::factory()->create();
        $employee = $this->employee($user, $a, 'EMP-TEST-C');
        $this->grantReportPermission($user);

        $employee->update(['company_id' => $b->id]);
        $access = app(LowStockCompanyAccess::class);
        $this->assertFalse($access->canView($user, (int) $a->id));
        $this->assertTrue($access->canView($user, (int) $b->id));
    }

    public function test_permission_revocation_removes_company_access(): void
    {
        $company = $this->company('TEST-E');
        $user = User::factory()->create();
        $this->employee($user, $company, 'EMP-TEST-E');
        $this->grantReportPermission($user);

        $user->revokePermissionTo('erp.low-stock-reports.view');
        $access = app(LowStockCompanyAccess::class);
        $this->assertFalse($access->canView($user, (int) $company->id));
        $this->assertSame([], $access->allowedCompanyIds($user));
    }

    public function test_super_admin_can_access_existing_companies(): void
    {
        $a = $this->company('TEST-F');
        $b = $this->company('TEST-G');
        $user = User::factory()->create();
        Role::findOrCreate('super-admin', 'web');
        $user->assignRole('super-admin');
        $this->grantReportPermission($user);

        $access = app(LowStockCompanyAccess::class);
        $this->assertTrue($access->canView($user, (int) $a->id));
        $this->assertTrue($access->canView($user, (int) $b->id));
        $this->assertFalse($access->canView($user, 999999999));
        $this->assertEqualsCanonicalizing([(int) $a->id, (int) $b->id], $access->allowedCompanyIds($user));
    }
}

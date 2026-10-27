<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\ERP\Accounting\Close\AccountingPeriodCloseWorkflow;
use Database\Seeders\AccountingPeriodClosePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ERP\AccountingPeriodCloseTestCase;

final class AccountingPeriodCloseScreenTest extends AccountingPeriodCloseTestCase
{
    /** @param list<string> $permissions */
    private function staff(array $permissions = ['erp.period-close.view']): User
    {
        $u = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $u->getKey(), 'employee_number' => 'CLOSE-'.$u->getKey(), 'first_name' => 'Close', 'last_name' => 'Staff', 'employment_status' => 'active']);
        if ($permissions !== []) {
            $u->givePermissionTo($permissions);
        }

return $u;
    }

    private function url(): string
    {
        return '/erp/accounting/period-close?company_id='.$this->company->getKey().'&period_id='.$this->closePeriod->getKey();
    }

    private function action(string $action): string
    {
        return '/erp/accounting/period-close/'.$this->company->getKey().'/'.$this->closePeriod->getKey().'/'.$action;
    }

    public function test_guest_redirects_from_screen_and_commands(): void
    {
        $this->get($this->url())->assertRedirect('/login');
        $this->post($this->action('prepare'))->assertRedirect('/login');
    }

    public function test_report_permission_alone_does_not_grant_checklist(): void
    {
        $this->actingAs($this->staff(['erp.trial-balance.view']))->get($this->url())->assertForbidden();
    }

    public function test_checklist_only_staff_can_read_blockers_but_not_source_amounts(): void
    {
        $this->prepareClose();
        $this->approveClose();
        $this->actingAs($this->staff())->get($this->url())->assertOk()->assertInertia(fn (Assert $p) => $p->component('ERP/Accounting/PeriodClose/Index')->where('review.automatedReady', false)->where('review.approvedReview', null)->where('review.inventoryGl.inventory', null)->where('review.canPrepare', false)->where('review.canApprove', false)->where('review.canClose', false));
    }

    public function test_staff_defaults_to_their_company(): void
    {
        $this->actingAs($this->staff())->get('/erp/accounting/period-close')->assertOk()->assertInertia(fn (Assert $p) => $p->where('company.id', (int) $this->company->getKey())->has('companies', 1));
    }

    public function test_super_admin_without_company_gets_selector_only(): void
    {
        $this->actingAs($this->maker)->get('/erp/accounting/period-close')->assertOk()->assertInertia(fn (Assert $p) => $p->where('company', null)->where('review', null));
    }

    public function test_foreign_company_and_period_scope_cannot_be_read(): void
    {
        $b = Company::query()->create(['code' => 'CLOSE-B', 'name' => 'Other', 'status' => 'active', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka']);
        $this->actingAs($this->staff())->get('/erp/accounting/period-close?company_id='.$b->getKey().'&period_id='.$this->closePeriod->getKey())->assertForbidden();
    }

    public function test_view_permission_cannot_prepare_approve_or_close(): void
    {
        $this->actingAs($this->staff());
        foreach (['prepare', 'approve', 'hard-close'] as $action) {
            $this->post($this->action($action), $this->closeCommand())->assertForbidden();
        }
    }

    public function test_screen_is_private_and_does_not_prepare_or_post_on_get(): void
    {
        $before = DB::table('journal_entries')->count();
        $response = $this->actingAs($this->maker)->get($this->url())->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame($before, DB::table('journal_entries')->count());
        $this->assertDatabaseCount('accounting_period_close_events', 0);
        $response->assertInertia(fn (Assert $p) => $p->missing('review.snapshot_hash')->missing('review.state_fingerprint'));
    }

    public function test_preparer_does_not_get_self_approval_capability(): void
    {
        $this->prepareClose();
        $this->actingAs($this->maker)->get($this->url())->assertOk()->assertInertia(fn (Assert $p) => $p->where('review.canApprove', false)->where('review.canClose', false));
        $this->actingAs($this->reviewer)->get($this->url())->assertOk()->assertInertia(fn (Assert $p) => $p->where('review.canApprove', true));
    }

    public function test_prepare_endpoint_redirects_and_records_one_event(): void
    {
        $this->actingAs($this->maker)->post($this->action('prepare'), $this->closeCommand())->assertRedirect($this->url());
        $this->assertDatabaseCount('accounting_period_close_events', 1);
    }

    public function test_new_permissions_are_repeatable_and_do_not_grant_reports_to_staff(): void
    {
        $this->seed(AccountingPeriodClosePermissionSeeder::class);
        $u = $this->staff();
        $this->assertFalse($u->can('erp.trial-balance.view'));
        $this->assertFalse($u->can('erp.accounting-periods.close'));
        $this->assertSame(1, DB::table('permissions')->where('name', AccountingPeriodCloseWorkflow::VIEW)->count());
    }
}

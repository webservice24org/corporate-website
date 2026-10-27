<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\AccountingCutoverPreparation;
use App\Services\ERP\Accounting\AccountingJournalDraftService;
use App\Services\ERP\Accounting\AccountingJournalPostingService;
use App\Services\ERP\Accounting\AccountingJournalWorkflowService;
use App\Services\ERP\Accounting\AccountingOpeningService;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\AccountingStarterService;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AccountingOpeningWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private FiscalYear $year;

    private AccountingPeriod $period;

    private AccountingJournalDraftService $service;

    private AccountingCutover $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingPermissionSeeder::class);
        $this->company = $this->newCompany('JV-A');
        $this->actor = User::factory()->create();
        $this->actor->assignRole('super-admin');
        app(AccountingSetupService::class)->initialize($this->actor, (int) $this->company->getKey());
        app(AccountingStarterService::class)->apply($this->actor, ['company_id' => $this->company->getKey(), 'template_id' => 'general_v1', 'reason' => 'Create journal fixture chart']);
        $calendar = app(AccountingCalendarService::class);
        $this->year = $calendar->saveYear($this->actor, ['company_id' => $this->company->getKey(), 'code' => 'FY26.01', 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'reason' => 'Create year']);
        $this->period = $calendar->savePeriod($this->actor, (int) $this->year->getKey(), ['company_id' => $this->company->getKey(), 'code' => 'OCT26', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'reason' => 'Create period']);
        $this->service = app(AccountingJournalDraftService::class);
        $group = AccountGroup::query()->where('company_id', $this->company->getKey())->where('category', 'asset')->firstOrFail();
        $parent = Account::query()->where('company_id', $this->company->getKey())->where('account_group_id', $group->getKey())->whereNull('parent_id')->firstOrFail();
        Account::query()->create(['company_id' => $this->company->getKey(), 'account_group_id' => $group->getKey(), 'parent_id' => $parent->getKey(), 'code' => '1599', 'name' => 'Noncash opening asset fixture', 'normal_side' => 'debit', 'is_postable' => true, 'status' => 'active', 'control_role' => null, 'statement_role' => 'current_asset', 'cash_flow_role' => 'noncash', 'revision' => 1]);
        $this->plan = $this->savePlan();
    }

    private function newCompany(string $code): Company
    {
        return Company::query()->create(['name' => $code, 'code' => $code, 'status' => 'active', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka']);
    }

    private function account(string $code): Account
    {
        return Account::query()->where('company_id', $this->company->getKey())->where('code', $code)->firstOrFail();
    }

    /** @param list<string> $permissions */
    private function staff(array $permissions): User
    {
        $user = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $user->getKey(), 'employee_number' => 'JV-'.$user->getKey(), 'first_name' => 'Journal', 'last_name' => 'User']);
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

return $user;
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function data(array $overrides = []): array
    {
        return ['company_id' => (int) $this->company->getKey(), 'accounting_date' => '2026-10-15', 'document_date' => '2026-10-10', 'currency_code' => 'BDT', 'description' => 'Reviewed noncash manual draft', 'reason' => 'Create draft for review', 'lines' => [
            ['account_id' => $this->account('5500')->getKey(), 'debit' => '100.10', 'credit' => '0', 'description' => 'Expense'],
            ['account_id' => $this->account('3200')->getKey(), 'debit' => '0', 'credit' => '100.10', 'description' => 'Noncash capital contribution'],
        ], ...$overrides];
    }

    /** @param array<string, mixed> $overrides */
    private function createDraft(array $overrides = []): JournalEntry
    {
        return $this->service->save($this->actor, $this->data($overrides));
    }

    private function invalid(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected journal validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function reviewer(): User
    {
        $reviewer = User::factory()->create();
        $reviewer->assignRole('super-admin');

        return $reviewer;
    }

    private function act(string $action, JournalEntry $journal, ?User $actor = null, array $input = []): JournalEntry
    {
        return app(AccountingJournalWorkflowService::class)->{$action}($actor ?? $this->actor, (int) $this->company->getKey(), (int) $journal->getKey(), ['revision' => $journal->getAttribute('revision'), 'reason' => 'Workflow review reason', ...$input]);
    }

    private function submitted(): JournalEntry
    {
        return $this->act('submit', $this->createDraft());
    }

    private function approved(): JournalEntry
    {
        return $this->act('approve', $this->submitted(), $this->reviewer());
    }

    private function planData(array $overrides = []): array
    {
        return ['company_id' => (int) $this->company->getKey(), 'cutover_date' => '2026-10-16', 'strategy' => 'snapshot', 'reason' => 'Prepare reviewed company snapshot', ...$overrides];
    }

    private function savePlan(array $overrides = [], ?int $id = null, ?User $actor = null): AccountingCutover
    {
        return app(AccountingCutoverPreparation::class)->save($actor ?? $this->actor, $this->planData($overrides), $id);
    }

    private function abortPlan(AccountingCutover $row, array $input = []): AccountingCutover
    {
        return app(AccountingCutoverPreparation::class)->abort($this->actor, (int) $this->company->getKey(), (int) $row->getKey(), ['revision' => $row->getAttribute('revision'), 'reason' => 'Withdraw untouched draft plan', ...$input]);
    }

    private function openingData(array $overrides = []): array
    {
        return ['cutover_revision' => $this->plan->getAttribute('revision'), 'description' => 'Reviewed opening asset/capital snapshot', 'supporting_reference' => 'Approved working-paper TB-2026-10', 'reason' => 'Prepare financial opening for review', 'lines' => [
            ['account_id' => $this->account('1599')->getKey(), 'debit' => '100.10', 'credit' => '0.00'],
            ['account_id' => $this->account('3200')->getKey(), 'debit' => '0.00', 'credit' => '100.10'],
        ], ...$overrides];
    }

    private function opening(array $overrides = [], ?User $actor = null): JournalEntry
    {
        return app(AccountingOpeningService::class)->save($actor ?? $this->actor, (int) $this->plan->getKey(), $this->openingData($overrides));
    }

    private function openingAction(string $action, JournalEntry $row, ?User $actor = null, array $input = []): JournalEntry
    {
        return app(AccountingOpeningService::class)->transition($actor ?? $this->actor, (int) $this->plan->getKey(), $action, ['revision' => $row->getAttribute('revision'), 'cutover_revision' => $this->plan->getAttribute('revision'), 'reason' => 'Financial review action', ...$input]);
    }

    private function submittedOpening(): JournalEntry
    {
        return $this->openingAction('submit', $this->opening());
    }

    private function approvedOpening(): JournalEntry
    {
        return $this->openingAction('approve', $this->submittedOpening(), $this->reviewer());
    }

    public function test_dry_run_is_exact_and_writes_nothing(): void
    {
        $count = DB::table('accounting_audit_events')->count();
        $preview = app(AccountingOpeningService::class)->dryRun($this->actor, (int) $this->plan->getKey(), $this->openingData());
        $this->assertSame('100.10', $preview['total_debit']);
        $this->assertSame('100.10', $preview['total_credit']);
        $this->assertTrue($preview['eligibleForFinancialApproval']);
        $this->assertFalse($preview['cutoverReconciled']);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('accounting_opening_workspaces', 0);
        $this->assertDatabaseCount('accounting_document_sequences', 0);
        $this->assertSame($count, DB::table('accounting_audit_events')->count());
    }

    public function test_saved_opening_has_server_date_currency_identity_and_link(): void
    {
        $row = $this->opening();
        $this->assertSame('opening', $row->getAttribute('entry_kind'));
        $this->assertSame('draft', $row->getAttribute('status'));
        $this->assertSame('OB-FY26.01-000001', $row->getAttribute('number'));
        $this->assertSame('2026-10-16', $row->getAttribute('accounting_date')->format('Y-m-d'));
        $this->assertSame('BDT', $row->getAttribute('currency_code'));
        $this->assertDatabaseHas('accounting_opening_workspaces', ['cutover_id' => $this->plan->getKey(), 'journal_entry_id' => $row->getKey()]);
        $this->assertSame('draft', $this->plan->fresh()->getAttribute('status'));
        $this->assertNull($this->plan->fresh()->getAttribute('reconciliation_hash'));
    }

    public function test_independent_financial_approval_does_not_approve_or_activate_cutover(): void
    {
        $row = $this->approvedOpening();
        $this->assertSame('approved', $row->getAttribute('status'));
        $this->assertNotNull($row->getAttribute('approved_at'));
        $this->assertNull($row->getAttribute('posted_at'));
        $this->assertSame('draft', $this->plan->fresh()->getAttribute('status'));
        $this->assertNull($this->plan->fresh()->getAttribute('approved_by'));
        $this->assertDatabaseHas('accounting_company_settings', ['company_id' => $this->company->getKey(), 'state' => 'disabled']);
    }

    public function test_creator_cannot_approve_even_as_super_admin(): void
    {
        $row = $this->submittedOpening();
        $this->expectException(AuthorizationException::class);
        $this->openingAction('approve', $row);
    }

    public function test_last_editor_cannot_approve(): void
    {
        $row = $this->opening();
        $editor = $this->reviewer();
        $row = $this->opening(['revision' => 1], $editor);
        $row = $this->openingAction('submit', $row);
        $this->expectException(AuthorizationException::class);
        $this->openingAction('approve', $row, $editor);
    }

    public function test_approved_edit_including_unchanged_save_resets_review(): void
    {
        $row = $this->approvedOpening();
        $number = $row->getAttribute('number');
        $row = $this->opening(['revision' => 3]);
        $this->assertSame('draft', $row->getAttribute('status'));
        $this->assertSame(4, $row->getAttribute('revision'));
        $this->assertSame($number, $row->getAttribute('number'));
        $this->assertNull($row->getAttribute('approved_at'));
        $this->assertNull($row->getAttribute('submitted_at'));
        $this->assertDatabaseCount('accounting_opening_workspaces', 1);
    }

    public function test_failed_edit_preserves_previous_approval(): void
    {
        $row = $this->approvedOpening();
        $before = $row->getAttributes();
        $data = $this->openingData(['revision' => 3]);
        $data['lines'][0]['debit'] = '999';
        $this->invalid(fn () => app(AccountingOpeningService::class)->save($this->actor, (int) $this->plan->getKey(), $data), 'lines');
        $this->assertSame($before, $row->fresh()->getAttributes());
    }

    public function test_reject_returns_to_draft_and_keeps_last_content_editor(): void
    {
        $row = $this->openingAction('reject', $this->submittedOpening(), $this->reviewer());
        $this->assertSame('draft', $row->getAttribute('status'));
        $this->assertNull($row->getAttribute('submitted_at'));
        $this->assertSame((int) $this->actor->getKey(), (int) $row->getAttribute('updated_by'));
    }

    public function test_stale_opening_or_cutover_revision_is_rejected(): void
    {
        $row = $this->opening();
        $this->invalid(fn () => $this->opening(['revision' => 99]), 'revision');
        $this->invalid(fn () => $this->opening(['revision' => 1, 'cutover_revision' => 99]), 'cutover_revision');
        $this->invalid(fn () => $this->openingAction('submit', $row, null, ['revision' => 99]), 'revision');
    }

    public function test_client_cannot_assign_authority_or_date(): void
    {
        foreach (['accounting_date', 'status', 'approved_by', 'company_id', 'payload_hash'] as $field) {
            $this->invalid(fn () => $this->opening([$field => 'forged']), 'opening');
        }
    }

    public function test_revenue_and_expense_openings_are_rejected(): void
    {
        $data = $this->openingData();
        $data['lines'][0]['account_id'] = $this->account('5500')->getKey();
        $this->invalid(fn () => app(AccountingOpeningService::class)->save($this->actor, (int) $this->plan->getKey(), $data), 'lines.0.account_id');
    }

    public function test_inactive_account_blocks_save_and_approval(): void
    {
        $row = $this->submittedOpening();
        $this->account('1599')->update(['status' => 'inactive']);
        $this->invalid(fn () => $this->openingAction('approve', $row, $this->reviewer()), 'lines.0.account_id');
    }

    public function test_closed_period_blocks_submission_but_not_rejection(): void
    {
        $row = $this->submittedOpening();
        DB::table('accounting_periods')->where('id', $this->period->getKey())->update(['state' => 'hard_closed']);
        $this->invalid(fn () => $this->openingAction('approve', $row, $this->reviewer()), 'cutover');
        $row = $this->openingAction('reject', $row);
        $this->assertSame('draft', $row->getAttribute('status'));
    }

    public function test_inventory_draft_has_explicit_exceptions_and_cannot_be_approved(): void
    {
        $data = $this->openingData();
        $data['lines'][0]['account_id'] = $this->account('1200')->getKey();
        $preview = app(AccountingOpeningService::class)->dryRun($this->actor, (int) $this->plan->getKey(), $data);
        $this->assertNotEmpty($preview['exceptions']);
        $this->assertFalse($preview['eligibleForFinancialApproval']);
        $row = app(AccountingOpeningService::class)->save($this->actor, (int) $this->plan->getKey(), $data);
        $row = $this->openingAction('submit', $row);
        $this->invalid(fn () => $this->openingAction('approve', $row, $this->reviewer()), 'reconciliation');
    }

    public function test_ar_without_party_open_document_is_rejected(): void
    {
        $data = $this->openingData();
        $data['lines'][0]['account_id'] = $this->account('1100')->getKey();
        $this->invalid(fn () => app(AccountingOpeningService::class)->save($this->actor, (int) $this->plan->getKey(), $data), 'lines.0');
    }

    public function test_foreign_or_missing_party_is_rejected(): void
    {
        $data = $this->openingData();
        $data['lines'][0]['party_type'] = 'customer';
        $data['lines'][0]['party_id'] = 999999;
        $this->invalid(fn () => app(AccountingOpeningService::class)->save($this->actor, (int) $this->plan->getKey(), $data), 'lines.0.party_id');
    }

    public function test_supporting_reference_tampering_is_detected(): void
    {
        $row = $this->opening();
        DB::table('accounting_opening_workspaces')->where('journal_entry_id', $row->getKey())->update(['supporting_reference' => 'Changed evidence']);
        $this->invalid(fn () => $this->openingAction('submit', $row), 'opening');
    }

    public function test_financial_line_tampering_is_detected(): void
    {
        $row = $this->opening();
        DB::table('journal_entry_lines')->where('journal_entry_id', $row->getKey())->where('line_number', 1)->update(['debit' => '200.20']);
        DB::table('journal_entry_lines')->where('journal_entry_id', $row->getKey())->where('line_number', 2)->update(['credit' => '200.20']);
        $this->invalid(fn () => $this->openingAction('submit', $row), 'opening');
    }

    public function test_opening_blocks_cutover_date_edit_or_abort(): void
    {
        $this->opening();
        $this->invalid(fn () => $this->savePlan(['revision' => 1, 'cutover_date' => '2026-10-17'], (int) $this->plan->getKey()), 'company_id');
        $this->invalid(fn () => $this->abortPlan($this->plan), 'company_id');
    }

    public function test_permissions_are_separate_from_manual_journal_permissions(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->opening([], $this->staff(['erp.journal-entries.create', 'erp.journal-entries.approve']));
    }

    public function test_opening_approve_permission_does_not_grant_manage(): void
    {
        $reviewer = $this->staff(['erp.accounting-openings.approve']);
        $row = $this->openingAction('approve', $this->submittedOpening(), $reviewer);
        $this->assertSame('approved', $row->getAttribute('status'));
        $this->expectException(AuthorizationException::class);
        $this->opening(['revision' => 3], $reviewer);
    }

    public function test_outer_transaction_restores_opening_and_numbering(): void
    {
        $count = DB::table('accounting_audit_events')->count();
        DB::beginTransaction();
        try {
            $this->opening();
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('accounting_opening_workspaces', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('accounting_document_sequences', 0);
        $this->assertSame($count, DB::table('accounting_audit_events')->count());
    }

    public function test_workspace_auth_scope_and_page_resolution(): void
    {
        $this->assertTrue(Route::has('erp.accounting.openings.index'), 'Mount accounting-openings.php once at top level in web.php.');
        $this->get('/erp/accounting/openings')->assertRedirect('/login');
        $this->actingAs($this->staff(['erp.accounting-openings.view']))->get('/erp/accounting/openings')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Openings/Index')->where('canEdit', false)->where('opening', null));
        $other = $this->newCompany('OPEN-B');
        $this->get('/erp/accounting/openings?company_id='.$other->getKey())->assertForbidden();
    }

    public function test_http_dry_run_and_save_then_review(): void
    {
        $id = $this->plan->getKey();
        $this->actingAs($this->actor)->post('/erp/accounting/openings/'.$id.'/dry-run', $this->openingData())->assertOk()->assertInertia(fn (Assert $page) => $page->where('preview.total_debit', '100.10')->where('preview.cutoverReconciled', false));
        $this->assertDatabaseCount('journal_entries', 0);
        $this->post('/erp/accounting/openings/'.$id, $this->openingData())->assertRedirect();
        $this->post('/erp/accounting/openings/'.$id.'/submit', ['cutover_revision' => 1, 'revision' => 1, 'reason' => 'Submit opening'])->assertRedirect();
        $this->actingAs($this->reviewer())->post('/erp/accounting/openings/'.$id.'/approve', ['cutover_revision' => 1, 'revision' => 2, 'reason' => 'Independent review'])->assertRedirect();
        $this->get('/erp/accounting/openings?cutover_id='.$id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('opening.status', 'approved')->where('plan.status', 'draft'));
    }

    public function test_audit_privacy_and_no_generic_posting_route(): void
    {
        $this->opening();
        $this->actingAs($this->staff(['erp.accounting-openings.view']))->get('/erp/accounting/openings')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canViewAudit', false)->has('audit', 0));
        $this->actingAs($this->actor)->get('/erp/accounting/openings?cutover_id='.$this->plan->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->where('canViewAudit', true)->has('audit', 1)->missing('audit.0.before_payload'));
        $this->assertFalse(Route::has('erp.accounting.openings.post'));
    }

    public function test_generic_manual_posting_engine_rejects_approved_opening(): void
    {
        $row = $this->approvedOpening();
        $this->invalid(fn () => app(AccountingJournalPostingService::class)->post($this->actor, (int) $this->company->getKey(), (int) $row->getKey(), ['revision' => 3, 'reason' => 'Cannot bypass opening posting contract']), 'entry_kind');
    }

    public function test_foreign_cutover_cannot_be_used_by_staff(): void
    {
        $other = $this->newCompany('FOREIGN-OPEN');
        $actor = User::factory()->create();
        Employee::query()->create(['company_id' => $other->getKey(), 'user_id' => $actor->getKey(), 'employee_number' => 'OPEN-'.$actor->getKey(), 'first_name' => 'Other', 'last_name' => 'Company']);
        $actor->givePermissionTo('erp.accounting-openings.manage');
        $this->expectException(ModelNotFoundException::class);
        $this->opening([], $actor);
    }
}

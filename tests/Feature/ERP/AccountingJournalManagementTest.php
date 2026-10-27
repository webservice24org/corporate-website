<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\AccountingJournalDraftService;
use App\Services\ERP\Accounting\AccountingJournalWorkflowService;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\AccountingStarterService;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AccountingJournalManagementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private FiscalYear $year;

    private AccountingPeriod $period;

    private AccountingJournalDraftService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(Route::has('erp.accounting.journals.index'), 'Mount routes/accounting-journals.php ONCE at top level in routes/web.php before running these tests.');
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

    private function journalPath(JournalEntry $row, string $suffix = ''): string
    {
        return '/erp/accounting/journals/'.$row->getKey().$suffix;
    }

    public function test_guest_redirects_to_login_and_staff_need_view_permission(): void
    {
        $this->get('/erp/accounting/journals')->assertRedirect('/login');
        $this->actingAs($this->staff([]))->get('/erp/accounting/journals')->assertForbidden();
    }

    public function test_staff_register_is_scoped_and_returns_exact_totals(): void
    {
        $row = $this->createDraft();
        $this->actingAs($this->staff(['erp.journal-entries.view']))->get('/erp/accounting/journals')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Journals/Index')->has('companies', 1)->has('journals.data', 1)->where('journals.data.0.id', $row->getKey())->where('journals.data.0.total_debit', '100.10')->where('journals.data.0.total_credit', '100.10'));
    }

    public function test_explicit_foreign_company_is_forbidden(): void
    {
        $other = $this->newCompany('SCREEN-B');
        $this->actingAs($this->staff(['erp.journal-entries.view']))->get('/erp/accounting/journals?company_id='.$other->getKey())->assertForbidden();
    }

    public function test_foreign_header_is_not_found_in_staff_scope(): void
    {
        $row = $this->createDraft();
        $other = $this->newCompany('SCREEN-B');
        $user = User::factory()->create();
        Employee::query()->create(['company_id' => $other->getKey(), 'user_id' => $user->getKey(), 'employee_number' => 'OTHER-'.$user->getKey(), 'first_name' => 'Other', 'last_name' => 'Company']);
        $user->givePermissionTo('erp.journal-entries.view');
        $this->actingAs($user)->get($this->journalPath($row))->assertNotFound();
    }

    public function test_register_filters_and_pagination_are_validated(): void
    {
        $draft = $this->createDraft();
        $approved = $this->approved();
        $this->actingAs($this->actor)->get('/erp/accounting/journals?company_id='.$this->company->getKey().'&status=approved')->assertOk()->assertInertia(fn (Assert $page) => $page->has('journals.data', 1)->where('journals.data.0.id', $approved->getKey()));
        $this->get('/erp/accounting/journals?company_id='.$this->company->getKey().'&q='.urlencode($draft->getAttribute('number')))->assertOk()->assertInertia(fn (Assert $page) => $page->has('journals.data', 1)->where('journals.data.0.id', $draft->getKey()));
        $this->get('/erp/accounting/journals?status=unsafe&page=0')->assertSessionHasErrors(['status', 'page']);
        $this->get('/erp/accounting/journals?from=2026-10-20&to=2026-10-01')->assertSessionHasErrors('to');
    }

    public function test_to_date_filter_works_without_from_date(): void
    {
        $this->createDraft();
        $this->actingAs($this->actor)->get('/erp/accounting/journals?company_id='.$this->company->getKey().'&to=2026-10-31')->assertOk()->assertInertia(fn (Assert $page) => $page->has('journals.data', 1));
    }

    public function test_create_edit_show_components_exist(): void
    {
        $row = $this->createDraft();
        $this->actingAs($this->actor);
        $this->get('/erp/accounting/journals/create?company_id='.$this->company->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Journals/Form')->where('journal', null));
        $this->get($this->journalPath($row, '/edit'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Journals/Form')->where('journal.id', $row->getKey())->has('lines', 2));
        $this->get($this->journalPath($row))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Journals/Show')->where('journal.id', $row->getKey())->where('total_debit', '100.10'));
    }

    public function test_view_only_staff_cannot_create_edit_or_write(): void
    {
        $row = $this->createDraft();
        $this->actingAs($this->staff(['erp.journal-entries.view']));
        $this->get('/erp/accounting/journals/create')->assertForbidden();
        $this->get($this->journalPath($row, '/edit'))->assertForbidden();
        $this->post('/erp/accounting/journals', $this->data())->assertForbidden();
        $this->put($this->journalPath($row), $this->data(['revision' => 1]))->assertForbidden();
        foreach (['submit', 'approve', 'reject', 'cancel', 'post', 'reverse'] as $action) {
            $this->post($this->journalPath($row, '/'.$action), ['revision' => 1, 'reason' => 'No authority'])->assertForbidden();
        }
    }

    public function test_store_and_update_delegate_to_validated_draft_service(): void
    {
        $this->actingAs($this->actor)->post('/erp/accounting/journals', $this->data())->assertRedirect();
        $row = JournalEntry::query()->firstOrFail();
        $this->put($this->journalPath($row), $this->data(['revision' => 1, 'description' => 'Changed through form']))->assertRedirect($this->journalPath($row));
        $this->assertSame('Changed through form', $row->fresh()->getAttribute('description'));
        $this->assertSame(2, $row->fresh()->getAttribute('revision'));
    }

    public function test_client_state_and_unbalanced_lines_are_rejected_without_writes(): void
    {
        $this->actingAs($this->actor)->post('/erp/accounting/journals', $this->data(['status' => 'posted']))->assertSessionHasErrors('journal');
        $data = $this->data();
        $data['lines'][0]['debit'] = '200.00';
        $this->post('/erp/accounting/journals', $data)->assertSessionHasErrors('lines');
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_http_submission_and_independent_approval(): void
    {
        $row = $this->createDraft();
        $this->actingAs($this->actor)->post($this->journalPath($row, '/submit'), ['revision' => 1, 'reason' => 'Submit from detail'])->assertRedirect();
        $this->post($this->journalPath($row, '/approve'), ['revision' => 2, 'reason' => 'Cannot self approve'])->assertForbidden();
        $this->actingAs($this->reviewer())->post($this->journalPath($row, '/approve'), ['revision' => 2, 'reason' => 'Independent review'])->assertRedirect();
        $this->assertSame('approved', $row->fresh()->getAttribute('status'));
    }

    public function test_approved_edit_clears_approval_and_updates_revision(): void
    {
        $row = $this->approved();
        $this->actingAs($this->actor)->put($this->journalPath($row), $this->data(['revision' => 3]))->assertRedirect();
        $this->assertSame('draft', $row->fresh()->getAttribute('status'));
        $this->assertNull($row->fresh()->getAttribute('approved_by'));
    }

    public function test_submitted_journal_does_not_expose_an_edit_form(): void
    {
        $row = $this->submitted();
        $this->actingAs($this->actor)->get($this->journalPath($row, '/edit'))->assertForbidden();
    }

    public function test_stale_action_revision_retains_current_state(): void
    {
        $row = $this->submitted();
        $this->actingAs($this->reviewer())->post($this->journalPath($row, '/reject'), ['revision' => 1, 'reason' => 'Stale request'])->assertSessionHasErrors('revision');
        $this->assertSame('submitted', $row->fresh()->getAttribute('status'));
    }

    public function test_reject_and_cancel_routes_follow_service_states(): void
    {
        $row = $this->submitted();
        $this->actingAs($this->reviewer())->post($this->journalPath($row, '/reject'), ['revision' => 2, 'reason' => 'Return for correction'])->assertRedirect();
        $this->actingAs($this->actor)->post($this->journalPath($row, '/cancel'), ['revision' => 3, 'reason' => 'Withdraw document'])->assertRedirect();
        $this->assertSame('cancelled', $row->fresh()->getAttribute('status'));
    }

    public function test_posting_block_reason_is_visible_but_direct_post_is_also_blocked(): void
    {
        $row = $this->approved();
        $this->actingAs($this->actor)->get($this->journalPath($row))->assertOk()->assertInertia(fn (Assert $page) => $page->where('capabilities.post', false)->has('postingIssues', 1));
        $this->post($this->journalPath($row, '/post'), ['revision' => 3, 'reason' => 'Attempt before activation'])->assertSessionHasErrors('company_id');
        $this->assertSame('approved', $row->fresh()->getAttribute('status'));
    }

    public function test_audit_requires_separate_permission_and_hides_raw_snapshots(): void
    {
        $row = $this->createDraft();
        $this->actingAs($this->staff(['erp.journal-entries.view']))->get($this->journalPath($row))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canViewAudit', false)->has('audit', 0)->missing('journal.actor_snapshot'));
        $this->actingAs($this->actor)->get($this->journalPath($row))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canViewAudit', true)->has('audit', 1)->missing('audit.0.before_payload')->missing('audit.0.after_payload'));
    }

    public function test_form_options_are_company_scoped_and_controls_are_not_selectable(): void
    {
        $foreign = $this->newCompany('OPTIONS-B');
        $branch = Branch::query()->create(['company_id' => $foreign->getKey(), 'code' => 'OTHER', 'name' => 'Foreign branch', 'status' => 'active']);
        $this->actingAs($this->actor)->get('/erp/accounting/journals/create?company_id='.$this->company->getKey())->assertOk()->assertInertia(function (Assert $page) use ($branch): void {
            $page->where('accounts', function ($accounts): bool {
                return collect($accounts)->contains(fn ($account) => (int) $account['id'] === (int) $this->account('5500')->getKey() && $account['selectable']) && collect($accounts)->contains(fn ($account) => (int) $account['id'] === (int) $this->account('1200')->getKey() && ! $account['selectable']);
            })->where('branches', fn ($branches): bool => ! collect($branches)->contains('id', $branch->getKey()));
        });
    }

    public function test_update_cannot_reassign_company(): void
    {
        $row = $this->createDraft();
        $other = $this->newCompany('UPDATE-B');
        $this->actingAs($this->actor)->put($this->journalPath($row), $this->data(['revision' => 1, 'company_id' => $other->getKey()]))->assertForbidden();
        $this->assertSame((int) $this->company->getKey(), (int) $row->fresh()->getAttribute('company_id'));
    }

    public function test_reverse_route_rejects_nonposted_source(): void
    {
        $row = $this->approved();
        $this->actingAs($this->actor)->post($this->journalPath($row, '/reverse'), ['revision' => 3, 'accounting_date' => '2026-10-16', 'reason' => 'Invalid original'])->assertSessionHasErrors('journal');
    }

    /** TEST FIXTURE ONLY: production activation remains a later independently reconciled workflow. */
    private function enablePosting(): void
    {
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['state' => 'enabled', 'activation_date' => '2026-10-01', 'activated_by' => $this->actor->getKey(), 'activated_at' => now()]);
        if (! AccountingCutover::query()->where('company_id', $this->company->getKey())->exists()) {
            AccountingCutover::query()->create(['company_id' => $this->company->getKey(), 'cutover_date' => '2026-10-01', 'strategy' => 'snapshot', 'status' => 'activated', 'created_by' => $this->actor->getKey(), 'approved_by' => $this->reviewer()->getKey(), 'approved_at' => now(), 'activated_by' => $this->actor->getKey(), 'activated_at' => now(), 'manifest_hash' => str_repeat('a', 64), 'reconciliation_hash' => str_repeat('b', 64), 'revision' => 1]);
        }
    }

    public function test_http_post_and_reversal_bridge_preserve_original_and_render_linked_draft(): void
    {
        $this->enablePosting();
        $row = $this->approved();
        $this->actingAs($this->actor)->post($this->journalPath($row, '/post'), ['revision' => 3, 'reason' => 'Post through authorized route'])->assertRedirect($this->journalPath($row));
        $this->assertSame('posted', $row->fresh()->getAttribute('status'));
        $this->post($this->journalPath($row, '/reverse'), ['revision' => 4, 'accounting_date' => '2026-10-16', 'reason' => 'Prepare compensation through route'])->assertRedirect();
        $reverse = JournalEntry::query()->where('reverses_journal_id', $row->getKey())->firstOrFail();
        $this->get($this->journalPath($row))->assertOk()->assertInertia(fn (Assert $page) => $page->where('linkedReversal.id', $reverse->getKey()));
        $this->get($this->journalPath($reverse))->assertOk()->assertInertia(fn (Assert $page) => $page->where('journal.entry_kind', 'reversal')->where('capabilities.edit', false)->where('lines.0.credit', '100.10'));
        $this->get($this->journalPath($reverse, '/edit'))->assertForbidden();
    }

    public function test_unavailable_selected_account_stays_visible_in_edit_options(): void
    {
        $row = $this->createDraft();
        $this->account('5500')->update(['status' => 'inactive']);
        $this->actingAs($this->actor)->get($this->journalPath($row, '/edit'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('accounts', fn ($accounts): bool => collect($accounts)->contains(fn ($account) => (int) $account['id'] === (int) $this->account('5500')->getKey() && ! $account['selectable'])));
    }
}

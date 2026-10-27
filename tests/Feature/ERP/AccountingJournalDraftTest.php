<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingAccountService;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\AccountingJournalDraftService;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\AccountingStarterService;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AccountingJournalDraftTest extends TestCase
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

    private function assertNoDraft(): void
    {
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_entry_lines', 0);
        $this->assertDatabaseCount('accounting_document_sequences', 0);
    }

    public function test_balanced_draft_has_server_number_state_identity_and_exact_totals(): void
    {
        $draft = $this->createDraft();
        $read = $this->service->read($this->actor, (int) $draft->getKey());
        $this->assertSame('JV-FY26.01-000001', $draft->getAttribute('number'));
        $this->assertSame('draft', $draft->getAttribute('status'));
        $this->assertSame('manual', $draft->getAttribute('entry_kind'));
        $this->assertSame('100.10', $read['total_debit']);
        $this->assertSame('100.10', $read['total_credit']);
        $this->assertSame([1, 2], array_column($read['lines'], 'line_number'));
        $this->assertNull($draft->getAttribute('posted_at'));
        $this->assertNull($draft->getAttribute('approved_by'));
        $this->assertSame(1, $draft->getAttribute('revision'));
    }

    public function test_sequence_advances_for_two_drafts_without_reusing_numbers(): void
    {
        $first = $this->createDraft();
        $second = $this->createDraft();
        $this->assertSame('JV-FY26.01-000001', $first->getAttribute('number'));
        $this->assertSame('JV-FY26.01-000002', $second->getAttribute('number'));
    }

    public function test_edit_keeps_number_updates_revision_replaces_owned_lines_and_audits(): void
    {
        $draft = $this->createDraft();
        $data = $this->data(['revision' => 1, 'description' => 'Updated draft']);
        $data['lines'][0]['debit'] = '125.25';
        $data['lines'][1]['credit'] = '125.25';
        $saved = $this->service->save($this->actor, $data, (int) $draft->getKey());
        $this->assertSame($draft->getAttribute('number'), $saved->getAttribute('number'));
        $this->assertSame(2, $saved->getAttribute('revision'));
        $this->assertDatabaseCount('journal_entry_lines', 2);
        $read = $this->service->read($this->actor, (int) $saved->getKey());
        $this->assertSame('125.25', $read['total_debit']);
        $this->assertSame(2, DB::table('accounting_audit_events')->where('entity_kind', 'journal')->count());
        $this->assertDatabaseHas('accounting_document_sequences', ['last_number' => 1]);
    }

    public function test_failed_edit_preserves_old_lines_revision_hash_and_audit(): void
    {
        $draft = $this->createDraft();
        $before = $this->service->read($this->actor, (int) $draft->getKey());
        $data = $this->data(['revision' => 1]);
        $data['lines'][0]['debit'] = '999';
        $this->invalid(fn () => $this->service->save($this->actor, $data, (int) $draft->getKey()), 'lines');
        $this->assertSame($before, $this->service->read($this->actor, (int) $draft->getKey()));
        $this->assertSame(1, DB::table('accounting_audit_events')->where('entity_kind', 'journal')->count());
    }

    public function test_stale_revision_is_rejected(): void
    {
        $draft = $this->createDraft();
        $this->invalid(fn () => $this->service->save($this->actor, $this->data(['revision' => 99]), (int) $draft->getKey()), 'revision');
    }

    public function test_creator_without_create_permission_is_rejected(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service->save($this->staff(['erp.journal-entries.view']), $this->data());
    }

    public function test_view_permission_is_required_by_reader(): void
    {
        $draft = $this->createDraft();
        $this->expectException(AuthorizationException::class);
        $this->service->read($this->staff([]), (int) $draft->getKey());
    }

    public function test_create_permission_does_not_grant_edit_permission(): void
    {
        $draft = $this->createDraft();
        $this->expectException(AuthorizationException::class);
        $this->service->save($this->staff(['erp.journal-entries.create']), $this->data(['revision' => 1]), (int) $draft->getKey());
    }

    public function test_foreign_company_is_forbidden_for_writer(): void
    {
        $other = $this->newCompany('JV-B');
        $this->expectException(AuthorizationException::class);
        $this->service->save($this->staff(['erp.journal-entries.create']), $this->data(['company_id' => $other->getKey()]));
    }

    public function test_foreign_journal_is_not_found_by_reader(): void
    {
        $other = $this->newCompany('JV-B');
        $draft = $this->createDraft();
        $user = User::factory()->create();
        Employee::query()->create(['company_id' => $other->getKey(), 'user_id' => $user->getKey(), 'employee_number' => 'OTHER', 'first_name' => 'Other', 'last_name' => 'User']);
        $user->givePermissionTo('erp.journal-entries.view');
        $this->expectException(ModelNotFoundException::class);
        $this->service->read($user, (int) $draft->getKey());
    }

    public function test_foreign_account_is_rejected(): void
    {
        $other = $this->newCompany('JV-B');
        app(AccountingSetupService::class)->initialize($this->actor, (int) $other->getKey());
        app(AccountingStarterService::class)->apply($this->actor, ['company_id' => $other->getKey(), 'template_id' => 'general_v1', 'reason' => 'Other chart']);
        $foreign = Account::query()->where('company_id', $other->getKey())->where('code', '5500')->firstOrFail();
        $data = $this->data();
        $data['lines'][0]['account_id'] = $foreign->getKey();
        $this->invalid(fn () => $this->service->save($this->actor, $data), 'lines.0.account_id');
        $this->assertNoDraft();
    }

    public function test_control_and_cash_accounts_cannot_be_used_in_manual_drafts(): void
    {
        foreach (['1100', '1200', '1210', '2200', '2400', '3100', '1010', '1020'] as $code) {
            $data = $this->data();
            $data['lines'][0]['account_id'] = $this->account($code)->getKey();
            $this->invalid(fn () => $this->service->save($this->actor, $data), 'lines.0.account_id');
        }
        $this->assertNoDraft();
    }

    public function test_group_and_inactive_accounts_are_rejected(): void
    {
        $data = $this->data();
        $data['lines'][0]['account_id'] = $this->account('5000')->getKey();
        $this->invalid(fn () => $this->service->save($this->actor, $data), 'lines.0.account_id');
        $this->account('5500')->update(['status' => 'inactive']);
        $this->invalid(fn () => $this->createDraft(), 'lines.0.account_id');
    }

    public function test_inactive_parent_blocks_manual_account(): void
    {
        $this->account('5000')->update(['status' => 'inactive']);
        $this->invalid(fn () => $this->createDraft(), 'lines.0.account_id');
    }

    public function test_unbalanced_and_zero_sided_lines_are_rejected(): void
    {
        $data = $this->data();
        $data['lines'][0]['debit'] = '100.11';
        $this->invalid(fn () => $this->service->save($this->actor, $data), 'lines');
        $data['lines'][0]['debit'] = '0';
        $this->invalid(fn () => $this->service->save($this->actor, $data), 'lines.0');
        $data['lines'][0]['debit'] = '100.10';
        $data['lines'][0]['credit'] = '1';
        $this->invalid(fn () => $this->service->save($this->actor, $data), 'lines.0');
        $this->assertNoDraft();
    }

    public function test_float_negative_exponent_locale_and_excess_precision_are_rejected(): void
    {
        foreach ([100.10, '-1', '+1', '1e2', '1,000', '1.001', ' 1.00', '1.00 '] as $amount) {
            $data = $this->data();
            $data['lines'][0]['debit'] = $amount;
            $this->invalid(fn () => $this->service->save($this->actor, $data), 'lines.0.debit');
        }
        $this->assertNoDraft();
    }

    public function test_line_count_and_list_shape_are_enforced(): void
    {
        $data = $this->data();
        $this->invalid(fn () => $this->service->save($this->actor, [...$data, 'lines' => [$data['lines'][0]]]), 'lines');
        $this->invalid(fn () => $this->service->save($this->actor, [...$data, 'lines' => ['first' => $data['lines'][0], 'second' => $data['lines'][1]]]), 'lines');
        $this->invalid(fn () => $this->service->save($this->actor, [...$data, 'lines' => array_fill(0, 201, $data['lines'][0])]), 'lines');
    }

    public function test_currency_must_match_functional_currency(): void
    {
        $this->invalid(fn () => $this->createDraft(['currency_code' => 'USD']), 'currency_code');
        $this->assertNoDraft();
    }

    public function test_reserved_header_metadata_cannot_be_assigned(): void
    {
        foreach (['status' => 'posted', 'number' => 'FORGED', 'created_by' => $this->actor->getKey(), 'posted_at' => now()->toDateTimeString(), 'entry_kind' => 'opening', 'total_debit' => '1.00', 'mapping_version_id' => 1, 'reverses_journal_id' => 1, 'payload_hash' => str_repeat('a', 64)] as $field => $value) {
            $this->invalid(fn () => $this->createDraft([$field => $value]), 'journal');
        }
        $this->assertNoDraft();
    }

    public function test_reserved_line_metadata_cannot_be_assigned_even_as_null(): void
    {
        foreach (['id', 'company_id', 'line_number', 'party_type', 'party_id', 'source_line_type', 'source_line_id', 'cash_account_id'] as $field) {
            $data = $this->data();
            $data['lines'][0][$field] = null;
            $this->invalid(fn () => $this->service->save($this->actor, $data), 'lines.0');
        }
    }

    public function test_closed_year_and_periods_block_new_manual_drafts(): void
    {
        $this->period->update(['state' => 'soft_closed']);
        $this->invalid(fn () => $this->createDraft(), 'accounting_date');
        $this->period->update(['state' => 'hard_closed']);
        $this->invalid(fn () => $this->createDraft(), 'accounting_date');
        $this->period->update(['state' => 'open']);
        $this->year->update(['state' => 'closed']);
        $this->invalid(fn () => $this->createDraft(), 'accounting_date');
    }

    public function test_missing_year_or_period_never_shifts_the_date(): void
    {
        foreach (['2026-11-01', '2027-01-01'] as $date) {
            $this->invalid(fn () => $this->createDraft(['accounting_date' => $date]), 'accounting_date');
        } $this->assertNoDraft();
    }

    public function test_invalid_calendar_date_is_rejected(): void
    {
        $this->invalid(fn () => $this->createDraft(['accounting_date' => '2026-02-30']), 'accounting_date');
    }

    public function test_overlapping_raw_periods_fail_closed(): void
    {
        AccountingPeriod::query()->create(['company_id' => $this->company->getKey(), 'fiscal_year_id' => $this->year->getKey(), 'code' => 'RAW', 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-20']);
        $this->invalid(fn () => $this->createDraft(), 'accounting_date');
    }

    public function test_edit_can_move_within_year_but_preserves_original_number(): void
    {
        $draft = $this->createDraft();
        $calendar = app(AccountingCalendarService::class);
        $calendar->savePeriod($this->actor, (int) $this->year->getKey(), ['company_id' => $this->company->getKey(), 'code' => 'NOV26', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'reason' => 'Next month']);
        $saved = $this->service->save($this->actor, $this->data(['revision' => 1, 'accounting_date' => '2026-11-01']), (int) $draft->getKey());
        $this->assertSame($draft->getAttribute('number'), $saved->getAttribute('number'));
        $this->assertSame('2026-11-01', $saved->getAttribute('accounting_date')->format('Y-m-d'));
    }

    public function test_edit_cannot_escape_closed_original_period(): void
    {
        $draft = $this->createDraft();
        $calendar = app(AccountingCalendarService::class);
        $calendar->savePeriod($this->actor, (int) $this->year->getKey(), ['company_id' => $this->company->getKey(), 'code' => 'NOV26', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'reason' => 'Next month']);
        $this->period->update(['state' => 'hard_closed']);
        $this->invalid(fn () => $this->service->save($this->actor, $this->data(['revision' => 1, 'accounting_date' => '2026-11-01']), (int) $draft->getKey()), 'accounting_date');
    }

    public function test_edit_cannot_change_numbering_year(): void
    {
        $draft = $this->createDraft();
        $calendar = app(AccountingCalendarService::class);
        $year = $calendar->saveYear($this->actor, ['company_id' => $this->company->getKey(), 'code' => 'FY27', 'name' => '2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'reason' => 'Next year']);
        $calendar->savePeriod($this->actor, (int) $year->getKey(), ['company_id' => $this->company->getKey(), 'code' => 'JAN27', 'starts_on' => '2027-01-01', 'ends_on' => '2027-01-31', 'reason' => 'Next year month']);
        $this->invalid(fn () => $this->service->save($this->actor, $this->data(['revision' => 1, 'accounting_date' => '2027-01-01']), (int) $draft->getKey()), 'accounting_date');
    }

    public function test_submitted_cancelled_posted_and_nonmanual_headers_cannot_be_edited(): void
    {
        foreach (['submitted', 'cancelled', 'posted'] as $state) {
            $draft = $this->createDraft();
            $draft->update(['status' => $state]);
            $this->invalid(fn () => $this->service->save($this->actor, $this->data(['revision' => 1]), (int) $draft->getKey()), 'journal');
        }
        $draft = $this->createDraft();
        $draft->update(['entry_kind' => 'opening']);
        $this->invalid(fn () => $this->service->save($this->actor, $this->data(['revision' => 1]), (int) $draft->getKey()), 'journal');
    }

    public function test_cutover_and_inactive_company_block_saving(): void
    {
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['cutover_locked_at' => now()]);
        $this->invalid(fn () => $this->createDraft(), 'company_id');
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['cutover_locked_at' => null]);
        $this->company->update(['status' => 'inactive']);
        $this->invalid(fn () => $this->createDraft(), 'company_id');
    }

    public function test_missing_setup_cannot_be_bypassed(): void
    {
        $other = $this->newCompany('JV-B');
        $this->invalid(fn () => $this->createDraft(['company_id' => $other->getKey()]), 'company_id');
    }

    public function test_blank_description_and_reason_are_rejected(): void
    {
        $this->invalid(fn () => $this->createDraft(['description' => '  ']), 'description');
        $this->invalid(fn () => $this->createDraft(['reason' => '  ']), 'reason');
    }

    public function test_outer_rollback_removes_header_lines_number_and_journal_audit(): void
    {
        try {
            DB::transaction(function (): void {
                $this->createDraft();
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }
        $this->assertNoDraft();
        $this->assertSame(0, DB::table('accounting_audit_events')->where('entity_kind', 'journal')->count());
        $this->assertSame('JV-FY26.01-000001', $this->createDraft()->getAttribute('number'));
    }

    public function test_reader_detects_header_or_line_evidence_change(): void
    {
        $draft = $this->createDraft();
        DB::table('journal_entries')->where('id', $draft->getKey())->update(['description' => 'Changed outside writer']);
        $this->invalid(fn () => $this->service->read($this->actor, (int) $draft->getKey()), 'journal');
    }

    public function test_reader_detects_balanced_raw_line_changes(): void
    {
        $draft = $this->createDraft();
        DB::table('journal_entry_lines')->where('journal_entry_id', $draft->getKey())->where('line_number', 1)->update(['debit' => '200']);
        DB::table('journal_entry_lines')->where('journal_entry_id', $draft->getKey())->where('line_number', 2)->update(['credit' => '200']);
        $this->invalid(fn () => $this->service->read($this->actor, (int) $draft->getKey()), 'journal');
    }

    public function test_active_company_branch_and_matching_department_are_accepted(): void
    {
        $branch = Branch::query()->create(['company_id' => $this->company->getKey(), 'code' => 'MAIN', 'name' => 'Main branch', 'status' => 'active']);
        $department = Department::query()->create(['company_id' => $this->company->getKey(), 'branch_id' => $branch->getKey(), 'code' => 'FIN', 'name' => 'Finance', 'status' => 'active']);
        $data = $this->data();
        $data['lines'][0]['branch_id'] = $branch->getKey();
        $data['lines'][0]['department_id'] = $department->getKey();
        $draft = $this->service->save($this->actor, $data);
        $read = $this->service->read($this->actor, (int) $draft->getKey());
        $this->assertSame($department->getKey(), $read['lines'][0]['department_id']);
    }

    public function test_branch_specific_department_requires_matching_branch(): void
    {
        $branch = Branch::query()->create(['company_id' => $this->company->getKey(), 'code' => 'MAIN', 'name' => 'Main', 'status' => 'active']);
        $department = Department::query()->create(['company_id' => $this->company->getKey(), 'branch_id' => $branch->getKey(), 'code' => 'FIN', 'name' => 'Finance', 'status' => 'active']);
        $data = $this->data();
        $data['lines'][0]['department_id'] = $department->getKey();
        $this->invalid(fn () => $this->service->save($this->actor, $data), 'lines.0.department_id');
    }

    public function test_foreign_and_inactive_organization_dimensions_are_rejected(): void
    {
        $other = $this->newCompany('JV-B');
        $foreign = Branch::query()->create(['company_id' => $other->getKey(), 'code' => 'MAIN', 'name' => 'Foreign', 'status' => 'active']);
        $data = $this->data();
        $data['lines'][0]['branch_id'] = $foreign->getKey();
        $this->invalid(fn () => $this->service->save($this->actor, $data), 'lines.0.branch_id');
        $department = Department::query()->create(['company_id' => $this->company->getKey(), 'code' => 'OLD', 'name' => 'Inactive', 'status' => 'inactive']);
        $data = $this->data();
        $data['lines'][0]['department_id'] = $department->getKey();
        $this->invalid(fn () => $this->service->save($this->actor, $data), 'lines.0.department_id');
    }

    public function test_draft_lines_protect_used_account_semantics_via_existing_services(): void
    {
        $this->createDraft();
        $account = $this->account('5500');
        $input = [...$account->only(['company_id', 'account_group_id', 'parent_id', 'code', 'name', 'normal_side', 'is_postable', 'control_role', 'statement_role', 'cash_flow_role', 'revision']), 'code' => 'CHANGED', 'reason' => 'Attempt reclassification'];
        $this->invalid(fn () => app(AccountingAccountService::class)->save($this->actor, $input, (int) $account->getKey()), 'code');
    }

    public function test_draft_preparation_does_not_activate_accounting_or_create_source_effects(): void
    {
        $this->createDraft();
        $this->assertDatabaseHas('accounting_company_settings', ['company_id' => $this->company->getKey(), 'state' => 'disabled']);
        $this->assertDatabaseCount('accounting_source_effects', 0);
    }

    public function test_number_collision_fails_without_consuming_another_sequence(): void
    {
        $draft = $this->createDraft();
        DB::table('accounting_document_sequences')->update(['last_number' => 0]);
        $this->invalid(fn () => $this->createDraft(), 'number');
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('journal_entry_lines', 2);
        $this->assertDatabaseHas('accounting_document_sequences', ['last_number' => 0]);
        $this->assertSame('draft', $draft->fresh()->getAttribute('status'));
    }

    public function test_database_round_trip_is_exact_or_the_entire_save_is_rejected(): void
    {
        $data = $this->data();
        $data['lines'][0]['debit'] = '999999999999999999.99';
        $data['lines'][1]['credit'] = '999999999999999999.99';
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->invalid(fn () => $this->service->save($this->actor, $data), 'lines');
            $this->assertNoDraft();
        } else {
            $draft = $this->service->save($this->actor, $data);
            $this->assertSame('999999999999999999.99', $this->service->read($this->actor, (int) $draft->getKey())['total_debit']);
        }
    }

    public function test_deactivating_a_draft_account_does_not_hide_saved_evidence(): void
    {
        $draft = $this->createDraft();
        $this->account('5500')->update(['status' => 'inactive']);
        $this->assertSame('100.10', $this->service->read($this->actor, (int) $draft->getKey())['total_debit']);
    }

    public function test_permission_without_employee_company_does_not_grant_scope(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('erp.journal-entries.create');
        $this->expectException(AuthorizationException::class);
        $this->service->save($user, $this->data());
    }
}

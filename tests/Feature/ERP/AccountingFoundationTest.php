<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingAccountMapping;
use App\Models\Accounting\AccountingAuditEvent;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingCutoverSource;
use App\Models\Accounting\AccountingMappingVersion;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingAuditService;
use App\Services\ERP\Accounting\AccountingCompanyAccess;
use App\Services\ERP\Accounting\AccountingDocumentNumberService;
use App\Services\ERP\Accounting\AccountingDomainRegistry;
use App\Services\ERP\Accounting\AccountingSetupService;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AccountingFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingPermissionSeeder::class);
    }

    private function company(string $code = 'FIN-A'): Company
    {
        return Company::query()->create(['name' => $code, 'code' => $code, 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka', 'status' => 'active']);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        return $user;
    }

    /** @param list<string> $permissions */
    private function staff(Company $company, array $permissions): User
    {
        $user = User::factory()->create();
        Employee::query()->create(['company_id' => $company->getKey(), 'user_id' => $user->getKey(), 'employee_number' => 'FIN-'.$user->getKey(), 'first_name' => 'Finance', 'last_name' => 'Staff']);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function year(Company $company): FiscalYear
    {
        return FiscalYear::query()->create(['company_id' => $company->getKey(), 'code' => 'FY2026', 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'state' => 'open']);
    }

    private function period(Company $company, FiscalYear $year): AccountingPeriod
    {
        return AccountingPeriod::query()->create(['company_id' => $company->getKey(), 'fiscal_year_id' => $year->getKey(), 'code' => '2026-10', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
    }

    private function account(Company $company): Account
    {
        $group = AccountGroup::query()->create(['company_id' => $company->getKey(), 'code' => 'ASSET', 'name' => 'Assets', 'category' => 'asset', 'normal_side' => 'debit']);

        return Account::query()->create(['company_id' => $company->getKey(), 'account_group_id' => $group->getKey(), 'code' => '1000', 'name' => 'Cash', 'normal_side' => 'debit', 'statement_role' => 'current_asset']);
    }

    private function journal(Company $company, User $actor): JournalEntry
    {
        $period = $this->period($company, $this->year($company));

        return JournalEntry::query()->create(['company_id' => $company->getKey(), 'accounting_period_id' => $period->getKey(), 'number' => 'JV-FY2026-000001', 'accounting_date' => '2026-10-15', 'currency_code' => 'BDT', 'description' => 'Foundation fixture, not a posted financial transaction', 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey()]);
    }

    private function mapping(Company $company): AccountingMappingVersion
    {
        return AccountingMappingVersion::query()->create(['company_id' => $company->getKey(), 'version' => 1, 'effective_from' => '2026-01-01']);
    }

    /** @return array<string, mixed> */
    private function effect(Company $company): array
    {
        return ['company_id' => $company->getKey(), 'source_kind' => 'sales_delivery', 'source_id' => 1, 'event_kind' => 'post', 'event_token' => 'initial', 'effect_kind' => 'stock_dispatched', 'source_payload_hash' => str_repeat('a', 64), 'outcome' => 'verified_no_journal', 'no_journal_reason' => 'zero_source_cost', 'accounting_date' => '2026-10-15', 'source_snapshot' => ['total_cost' => '0.0000']];
    }

    public function test_all_fourteen_tables_exist_without_activated_settings(): void
    {
        foreach (['accounting_company_settings', 'fiscal_years', 'accounting_periods', 'account_groups', 'accounts', 'accounting_mapping_versions', 'accounting_account_mappings', 'journal_entries', 'journal_entry_lines', 'accounting_source_effects', 'accounting_document_sequences', 'accounting_audit_events', 'accounting_cutovers', 'accounting_cutover_sources'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertDatabaseCount('accounting_company_settings', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_permissions_are_repeatable_and_do_not_grant_every_user_finance_access(): void
    {
        $before = Permission::query()->where('name', 'like', 'erp.accounting-%')->count();
        $this->seed(AccountingPermissionSeeder::class);
        $this->assertSame($before, Permission::query()->where('name', 'like', 'erp.accounting-%')->count());
        $registry = app(AccountingDomainRegistry::class);
        $this->assertCount(29, $registry->permissions());
        $user = User::factory()->create();
        $admin = $this->admin();
        foreach ($registry->permissions() as $permission) {
            $this->assertFalse($user->can($permission));
            $this->assertTrue($admin->can($permission));
        }
    }

    public function test_initialization_is_disabled_and_retry_does_not_duplicate_audit(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $service = app(AccountingSetupService::class);
        $first = $service->initialize($actor, (int) $company->getKey());
        $again = $service->initialize($actor, (int) $company->getKey());
        $this->assertSame($first->getKey(), $again->getKey());
        $this->assertSame('disabled', $first->getAttribute('state'));
        $this->assertSame('BDT', $first->getAttribute('functional_currency'));
        $this->assertSame(2, $first->getAttribute('currency_precision'));
        $this->assertDatabaseCount('accounting_audit_events', 1);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_read_permission_cannot_initialize_settings(): void
    {
        $company = $this->company();
        $user = $this->staff($company, ['erp.accounting-settings.view']);
        $this->expectException(AuthorizationException::class);
        app(AccountingSetupService::class)->initialize($user, (int) $company->getKey());
    }

    public function test_foreign_company_access_is_forbidden(): void
    {
        $own = $this->company();
        $other = $this->company('FIN-B');
        $user = $this->staff($own, ['erp.accounting-settings.update']);
        $this->expectException(AuthorizationException::class);
        app(AccountingSetupService::class)->initialize($user, (int) $other->getKey());
    }

    public function test_employee_company_is_required_for_non_admin(): void
    {
        $company = $this->company();
        $actor = User::factory()->create();
        $actor->givePermissionTo('erp.accounts.view');
        $this->expectException(AuthorizationException::class);
        app(AccountingCompanyAccess::class)->authorize($actor, (int) $company->getKey(), 'erp.accounts.view');
    }

    public function test_inactive_company_blocks_setup_but_allows_authorized_read(): void
    {
        $company = $this->company();
        $company->update(['status' => 'inactive']);
        $actor = $this->admin();
        $this->assertSame($company->getKey(), app(AccountingCompanyAccess::class)->authorize($actor, (int) $company->getKey(), 'erp.accounts.view')->getKey());
        $this->expectException(ValidationException::class);
        app(AccountingSetupService::class)->initialize($actor, (int) $company->getKey());
    }

    public function test_unknown_permission_fails_closed(): void
    {
        $company = $this->company();
        $this->expectException(\InvalidArgumentException::class);
        app(AccountingCompanyAccess::class)->authorize($this->admin(), (int) $company->getKey(), 'erp.unknown.view');
    }

    public function test_setup_rejects_unsupported_precision_currency(): void
    {
        $company = $this->company();
        $company->update(['currency_code' => 'JPY']);
        $this->expectException(ValidationException::class);
        app(AccountingSetupService::class)->initialize($this->admin(), (int) $company->getKey());
    }

    public function test_setup_and_audit_roll_back_with_caller(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        try {
            DB::transaction(function () use ($company, $actor): void {
                app(AccountingSetupService::class)->initialize($actor, (int) $company->getKey());
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('rollback', $e->getMessage());
        }
        $this->assertDatabaseCount('accounting_company_settings', 0);
        $this->assertDatabaseCount('accounting_audit_events', 0);
    }

    public function test_audit_rejects_private_payload_and_unknown_action(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $setting = app(AccountingSetupService::class)->initialize($actor, (int) $company->getKey());
        try {
            DB::transaction(fn () => app(AccountingAuditService::class)->record($actor, (int) $company->getKey(), 'settings', (int) $setting->getKey(), 'configure', [], ['notes' => 'PRIVATE'], 'Test'));
            $this->fail('Expected whitelist rejection.');
        } catch (\InvalidArgumentException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertDatabaseCount('accounting_audit_events', 1);
        $this->expectException(\InvalidArgumentException::class);
        app(AccountingDomainRegistry::class)->permission('settings', 'view');
    }

    public function test_audit_cannot_reference_foreign_entity(): void
    {
        $a = $this->company();
        $b = $this->company('FIN-B');
        $actor = $this->admin();
        $setting = app(AccountingSetupService::class)->initialize($actor, (int) $b->getKey());
        $this->expectException(ModelNotFoundException::class);
        DB::transaction(fn () => app(AccountingAuditService::class)->record($actor, (int) $a->getKey(), 'settings', (int) $setting->getKey(), 'configure', [], [], 'Foreign entity test'));
    }

    public function test_audit_is_append_only(): void
    {
        $company = $this->company();
        app(AccountingSetupService::class)->initialize($this->admin(), (int) $company->getKey());
        $event = AccountingAuditEvent::query()->firstOrFail();
        $this->expectException(\LogicException::class);
        $event->delete();
    }

    public function test_numbering_is_scoped_by_kind_and_year(): void
    {
        $company = $this->company();
        $year = $this->year($company);
        $service = app(AccountingDocumentNumberService::class);
        $numbers = DB::transaction(fn () => [$service->next((int) $company->getKey(), (int) $year->getKey(), 'journal', '2026-10-15'), $service->next((int) $company->getKey(), (int) $year->getKey(), 'journal', '2026-10-15'), $service->next((int) $company->getKey(), (int) $year->getKey(), 'opening', '2026-10-15')]);
        $this->assertSame(['JV-FY2026-000001', 'JV-FY2026-000002', 'OB-FY2026-000001'], $numbers);
    }

    public function test_numbering_rolls_back_with_document_transaction(): void
    {
        $company = $this->company();
        $year = $this->year($company);
        $service = app(AccountingDocumentNumberService::class);
        try {
            DB::transaction(function () use ($company, $year, $service): void {
                $service->next((int) $company->getKey(), (int) $year->getKey(), 'journal', '2026-10-15');
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('rollback', $e->getMessage());
        }
        $this->assertDatabaseCount('accounting_document_sequences', 0);
        $this->assertSame('JV-FY2026-000001', DB::transaction(fn () => $service->next((int) $company->getKey(), (int) $year->getKey(), 'journal', '2026-10-15')));
    }

    public function test_numbering_rejects_foreign_year(): void
    {
        $a = $this->company();
        $b = $this->company('FIN-B');
        $year = $this->year($b);
        $this->expectException(ModelNotFoundException::class);
        DB::transaction(fn () => app(AccountingDocumentNumberService::class)->next((int) $a->getKey(), (int) $year->getKey(), 'journal', '2026-10-15'));
    }

    public function test_numbering_rejects_closed_year(): void
    {
        $company = $this->company();
        $year = $this->year($company);
        $year->update(['state' => 'closed']);
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(AccountingDocumentNumberService::class)->next((int) $company->getKey(), (int) $year->getKey(), 'journal', '2026-10-15'));
    }

    public function test_numbering_rejects_outside_year_and_invalid_date(): void
    {
        $company = $this->company();
        $year = $this->year($company);
        $service = app(AccountingDocumentNumberService::class);
        try {
            DB::transaction(fn () => $service->next((int) $company->getKey(), (int) $year->getKey(), 'journal', '2027-01-01'));
            $this->fail('Expected year range failure.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
        $this->expectException(\InvalidArgumentException::class);
        DB::transaction(fn () => $service->next((int) $company->getKey(), (int) $year->getKey(), 'journal', '2026-02-30'));
    }

    public function test_cross_company_account_group_is_blocked_by_database(): void
    {
        $a = $this->company();
        $b = $this->company('FIN-B');
        $foreign = $this->account($b);
        $this->expectException(QueryException::class);
        Account::query()->create(['company_id' => $a->getKey(), 'account_group_id' => $foreign->getAttribute('account_group_id'), 'code' => '1000', 'name' => 'Injected', 'normal_side' => 'debit', 'statement_role' => 'current_asset']);
    }

    public function test_cross_company_period_is_blocked_by_database(): void
    {
        $a = $this->company();
        $b = $this->company('FIN-B');
        $foreignYear = $this->year($b);
        $this->expectException(QueryException::class);
        $this->period($a, $foreignYear);
    }

    public function test_journal_line_amounts_are_strings_and_one_sided(): void
    {
        $company = $this->company();
        $journal = $this->journal($company, $this->admin());
        $account = $this->account($company);
        $line = JournalEntryLine::query()->create(['company_id' => $company->getKey(), 'journal_entry_id' => $journal->getKey(), 'account_id' => $account->getKey(), 'line_number' => 1, 'debit' => '123.45', 'credit' => '0']);
        $this->assertSame('123.45', $line->getAttribute('debit'));
        $this->assertSame('0.00', $line->getAttribute('credit'));
        $this->expectException(ValidationException::class);
        $line->update(['credit' => '1.00']);
    }

    public function test_foreign_account_cannot_be_injected_into_journal_line(): void
    {
        $a = $this->company();
        $b = $this->company('FIN-B');
        $journal = $this->journal($a, $this->admin());
        $foreign = $this->account($b);
        $this->expectException(QueryException::class);
        JournalEntryLine::query()->create(['company_id' => $a->getKey(), 'journal_entry_id' => $journal->getKey(), 'account_id' => $foreign->getKey(), 'line_number' => 1, 'debit' => '1', 'credit' => '0']);
    }

    public function test_posted_journal_header_cannot_be_edited(): void
    {
        $company = $this->company();
        $journal = $this->journal($company, $this->admin());
        DB::table('journal_entries')->where('id', $journal->getKey())->update(['status' => 'posted', 'posted_at' => now()->toDateTimeString()]);
        $this->expectException(\LogicException::class);
        $journal->refresh()->update(['description' => 'Attempted rewrite']);
    }

    public function test_new_lines_cannot_be_added_to_posted_journal(): void
    {
        $company = $this->company();
        $journal = $this->journal($company, $this->admin());
        $account = $this->account($company);
        DB::table('journal_entries')->where('id', $journal->getKey())->update(['status' => 'posted', 'posted_at' => now()->toDateTimeString()]);
        $this->expectException(\LogicException::class);
        JournalEntryLine::query()->create(['company_id' => $company->getKey(), 'journal_entry_id' => $journal->getKey(), 'account_id' => $account->getKey(), 'line_number' => 1, 'debit' => '1', 'credit' => '0']);
    }

    public function test_published_mapping_version_cannot_be_rewritten(): void
    {
        $company = $this->company();
        $version = $this->mapping($company);
        $version->update(['state' => 'published']);
        $this->expectException(\LogicException::class);
        $version->update(['effective_from' => '2026-02-01']);
    }

    public function test_mapping_rows_cannot_be_added_after_publication(): void
    {
        $company = $this->company();
        $version = $this->mapping($company);
        $account = $this->account($company);
        $version->update(['state' => 'published']);
        $this->expectException(\LogicException::class);
        AccountingAccountMapping::query()->create(['company_id' => $company->getKey(), 'mapping_version_id' => $version->getKey(), 'account_id' => $account->getKey(), 'role' => 'inventory']);
    }

    public function test_source_effect_event_tuple_is_unique(): void
    {
        $company = $this->company();
        $data = $this->effect($company);
        AccountingSourceEffect::query()->create($data);
        $this->expectException(QueryException::class);
        AccountingSourceEffect::query()->create($data);
    }

    public function test_source_effect_cannot_be_updated(): void
    {
        $company = $this->company();
        $effect = AccountingSourceEffect::query()->create($this->effect($company));
        $this->expectException(\LogicException::class);
        $effect->update(['source_payload_hash' => str_repeat('b', 64)]);
    }

    public function test_activated_cutover_sources_are_immutable(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $cutover = AccountingCutover::query()->create(['company_id' => $company->getKey(), 'cutover_date' => '2026-10-15', 'created_by' => $actor->getKey()]);
        $source = AccountingCutoverSource::query()->create(['company_id' => $company->getKey(), 'cutover_id' => $cutover->getKey(), 'source_kind' => 'sales_invoice', 'source_id' => 1, 'coverage_kind' => 'opening', 'source_payload_hash' => str_repeat('a', 64), 'opening_state' => ['outstanding' => '20.00']]);
        $cutover->update(['status' => 'activated']);
        $this->expectException(\LogicException::class);
        $source->update(['opening_state' => ['outstanding' => '0.00']]);
    }

    public function test_creator_cannot_record_self_approval(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $journal = $this->journal($company, $actor);
        $this->expectException(AuthorizationException::class);
        DB::transaction(fn () => app(AccountingAuditService::class)->record($actor, (int) $company->getKey(), 'journal', (int) $journal->getKey(), 'approve', [], ['status' => 'approved'], 'Approval test'));
    }

    public function test_migration_collision_is_reported_before_any_creation(): void
    {
        $paths = glob(database_path('migrations/*_create_accounting_domain_foundation.php'));
        $this->assertIsArray($paths);
        $this->assertCount(1, $paths, 'Keep exactly one accounting foundation migration file.');
        $migration = require $paths[0];
        try {
            $migration->up();
            $this->fail('Expected collision report.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('collision', $e->getMessage());
            $this->assertStringContainsString('fiscal_years', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('accounting_company_settings'));
        $this->assertDatabaseCount('accounting_company_settings', 0);
    }

    public function test_start_of_fiscal_year_is_valid_for_numbering(): void
    {
        $company = $this->company();
        $year = $this->year($company);
        $this->assertSame('JV-FY2026-000001', DB::transaction(fn () => app(AccountingDocumentNumberService::class)->next((int) $company->getKey(), (int) $year->getKey(), 'journal', '2026-01-01')));
    }

    public function test_stale_header_instance_cannot_rewrite_newly_posted_journal(): void
    {
        $company = $this->company();
        $journal = $this->journal($company, $this->admin());
        DB::table('journal_entries')->where('id', $journal->getKey())->update(['status' => 'posted', 'posted_at' => now()->toDateTimeString()]);
        $this->expectException(\LogicException::class);
        $journal->update(['description' => 'Stale edit']);
    }

    public function test_existing_line_cannot_be_reparented_out_of_posted_journal(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $journal = $this->journal($company, $actor);
        $account = $this->account($company);
        $line = JournalEntryLine::query()->create(['company_id' => $company->getKey(), 'journal_entry_id' => $journal->getKey(), 'account_id' => $account->getKey(), 'line_number' => 1, 'debit' => '1', 'credit' => '0']);
        $new = JournalEntry::query()->create([...$journal->only(['company_id', 'accounting_period_id', 'accounting_date', 'currency_code', 'description', 'created_by', 'updated_by']), 'number' => 'JV-FY2026-000002']);
        DB::table('journal_entries')->where('id', $journal->getKey())->update(['status' => 'posted', 'posted_at' => now()->toDateTimeString()]);
        $this->expectException(\LogicException::class);
        $line->update(['journal_entry_id' => $new->getKey()]);
    }

    public function test_audit_update_is_blocked(): void
    {
        $company = $this->company();
        app(AccountingSetupService::class)->initialize($this->admin(), (int) $company->getKey());
        $event = AccountingAuditEvent::query()->firstOrFail();
        $this->expectException(\LogicException::class);
        $event->update(['reason' => 'Altered evidence']);
    }

    public function test_foreign_journal_cannot_be_linked_to_source_effect(): void
    {
        $a = $this->company();
        $b = $this->company('FIN-B');
        $journal = $this->journal($b, $this->admin());
        $this->expectException(QueryException::class);
        AccountingSourceEffect::query()->create([...$this->effect($a), 'journal_entry_id' => $journal->getKey(), 'outcome' => 'posted', 'no_journal_reason' => null]);
    }

    public function test_mapping_row_cannot_be_reparented_out_of_published_version(): void
    {
        $company = $this->company();
        $version = $this->mapping($company);
        $account = $this->account($company);
        $row = AccountingAccountMapping::query()->create(['company_id' => $company->getKey(), 'mapping_version_id' => $version->getKey(), 'account_id' => $account->getKey(), 'role' => 'inventory']);
        $other = AccountingMappingVersion::query()->create(['company_id' => $company->getKey(), 'version' => 2, 'effective_from' => '2026-02-01']);
        $version->update(['state' => 'published']);
        $this->expectException(\LogicException::class);
        $row->update(['mapping_version_id' => $other->getKey()]);
    }
}

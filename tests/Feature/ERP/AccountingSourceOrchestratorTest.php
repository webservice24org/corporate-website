<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingMappingVersion;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingAccountService;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\AccountingMappingCatalog;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\Source\AccountingPreparedSourceEvent;
use App\Services\ERP\Accounting\Source\AccountingSourceAdapter;
use App\Services\ERP\Accounting\Source\AccountingSourceEffectPlan;
use App\Services\ERP\Accounting\Source\AccountingSourceEventRegistry;
use App\Services\ERP\Accounting\Source\AccountingSourceOrchestrator;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AccountingSourceOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private AccountingMappingService $service;

    /** @var array<string, int> */
    private array $mapping;

    private AccountingSourceOrchestrator $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingPermissionSeeder::class);
        $this->company = $this->newCompany('MAP-A');
        $this->actor = User::factory()->create();
        $this->actor->assignRole('super-admin');
        app(AccountingSetupService::class)->initialize($this->actor, (int) $this->company->getKey());
        $this->service = app(AccountingMappingService::class);
        $this->mapping = $this->chart($this->company);
        $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00', 'Asia/Dhaka'));
        $this->publish();
        $calendar = app(AccountingCalendarService::class);
        $year = $calendar->saveYear($this->actor, ['company_id' => $this->company->getKey(), 'code' => 'FY26', 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'reason' => 'Contract fixture year']);
        $calendar->savePeriod($this->actor, (int) $year->getKey(), ['company_id' => $this->company->getKey(), 'code' => 'OCT26', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'reason' => 'Contract fixture period']);
        // Synthetic activation shape only; this is not a production reconciliation workflow.
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['state' => 'enabled', 'activation_date' => '2026-10-01', 'activated_by' => $this->actor->getKey(), 'activated_at' => now()]);
        $reviewer = User::factory()->create();
        AccountingCutover::query()->create(['company_id' => $this->company->getKey(), 'cutover_date' => '2026-10-01', 'strategy' => 'snapshot', 'status' => 'activated', 'created_by' => $this->actor->getKey(), 'approved_by' => $reviewer->getKey(), 'approved_at' => now(), 'activated_by' => $this->actor->getKey(), 'activated_at' => now(), 'manifest_hash' => str_repeat('a', 64), 'reconciliation_hash' => str_repeat('b', 64), 'revision' => 1]);
        $registry = new AccountingSourceEventRegistry(['supplier_bill.approved' => OrchestratorContractFixtureAdapter::class, 'sales_invoice.issued' => OrchestratorContractFixtureAdapter::class, 'sales_delivery.posted' => OrchestratorContractFixtureAdapter::class]);
        $this->engine = app()->makeWith(AccountingSourceOrchestrator::class, ['registry' => $registry]);
        OrchestratorContractFixtureAdapter::$event = $this->event();
    }

    private function newCompany(string $code): Company
    {
        return Company::query()->create(['name' => $code, 'code' => $code, 'status' => 'active', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka']);
    }

    /** @return array<string, int> */
    private function chart(Company $company): array
    {
        app(AccountingAccountService::class)->initializeGroups($this->actor, (int) $company->getKey());
        $catalog = app(AccountingMappingCatalog::class);
        $map = [];
        $number = 1000;
        foreach ([...$catalog->roles(), 'cash:MAIN' => 'asset'] as $role => $category) {
            $group = AccountGroup::query()->where('company_id', $company->getKey())->where('category', $category)->firstOrFail();
            $account = Account::query()->create(['company_id' => $company->getKey(), 'account_group_id' => $group->getKey(), 'code' => (string) $number++, 'name' => $role, 'normal_side' => $role === 'sales_allowances' ? 'debit' : $group->getAttribute('normal_side'), 'is_postable' => true, 'control_role' => $catalog->control($role), 'statement_role' => $catalog->statements($role)[0], 'cash_flow_role' => $catalog->cashRole($role) ? 'cash' : null, 'status' => 'active', 'revision' => 1]);
            $map[$role] = (int) $account->getKey();
        }

        return $map;
    }

    /** @param list<string> $permissions */
    private function staff(array $permissions): User
    {
        $user = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $user->getKey(), 'employee_number' => 'MAP-'.$user->getKey(), 'first_name' => 'Finance', 'last_name' => 'User']);
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function data(array $overrides = []): array
    {
        return ['company_id' => (int) $this->company->getKey(), 'base_version_id' => null, 'effective_from' => '2026-10-01', 'mappings' => $this->mapping, 'reason' => 'Approve initial company posting profile', ...$overrides];
    }

    /** @param array<string, mixed> $overrides */
    private function publish(array $overrides = []): AccountingMappingVersion
    {
        return $this->service->publish($this->actor, $this->data($overrides));
    }

    /** Synthetic templates test orchestration contracts, not real Purchase/Sales recognition policy. */
    private function effect(string $kind = 'commercial', string $amount = '100.10', ?string $reason = null): AccountingSourceEffectPlan
    {
        if ($reason !== null) {
            return new AccountingSourceEffectPlan($kind, [], ['gl_amount' => '0.00', 'source_cost' => '0.0000'], $reason);
        }

        return new AccountingSourceEffectPlan($kind, [
            ['role' => 'general_expense', 'debit' => $amount, 'credit' => '0.00', 'source_line_type' => 'contract_fixture', 'source_line_id' => 1],
            ['role' => 'retained_earnings', 'debit' => '0.00', 'credit' => $amount, 'source_line_type' => 'contract_fixture', 'source_line_id' => 1],
        ], ['gl_amount' => $amount]);
    }

    private function event(array $overrides = []): AccountingPreparedSourceEvent
    {
        $data = ['companyId' => (int) $this->company->getKey(), 'sourceKind' => 'supplier_bill', 'sourceId' => 1, 'eventKind' => 'approved', 'eventToken' => 'approved_1', 'currency' => 'BDT', 'documentDate' => '2026-10-16', 'accountingDate' => '2026-10-16', 'originDate' => '2026-10-16', 'facts' => ['source_number' => 'SYNTHETIC-CONTRACT-FIXTURE', 'amount' => '100.10'], 'effects' => [$this->effect()], 'references' => [], ...$overrides];

        return new AccountingPreparedSourceEvent(...$data);
    }

    private function runEvent(?User $actor = null): array
    {
        $event = OrchestratorContractFixtureAdapter::$event;
        $this->assertNotNull($event);

        return DB::transaction(fn () => $this->engine->execute($actor ?? $this->actor, $event->companyId, $event->sourceKind, $event->sourceId, $event->eventKind, $event->eventToken));
    }

    private function invalid(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected source contract error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function assertNoEffects(): void
    {
        $this->assertDatabaseCount('accounting_source_effects', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('accounting_document_sequences', 0);
    }

    public function test_default_registry_rejects_still_uninstalled_inventory_adjustment_adapter(): void
    {
        $registry = app(AccountingSourceEventRegistry::class);
        $this->invalid(fn () => $registry->adapter('inventory_adjustment', 'posted'), 'source_adapter');
        $this->assertNoEffects();
    }

    public function test_unknown_event_fails_before_any_financial_write(): void
    {
        $this->invalid(fn () => DB::transaction(fn () => $this->engine->execute($this->actor, (int) $this->company->getKey(), 'quotation', 1, 'accepted', '1')), 'source_event');
        $this->assertNoEffects();
    }

    public function test_caller_owned_transaction_is_required(): void
    {
        $manager = DB::getFacadeRoot();
        try {
            DB::shouldReceive('transactionLevel')->once()->andReturn(0);
            $this->expectException(\LogicException::class);
            $this->engine->execute($this->actor, (int) $this->company->getKey(), 'supplier_bill', 1, 'approved', 'approved_1');
        } finally {
            DB::swap($manager);
        }
    }

    public function test_nonzero_effect_posts_exact_source_link_and_server_journal(): void
    {
        $result = $this->runEvent();
        $this->assertSame('recorded', $result['mode']);
        $this->assertCount(1, $result['effect_ids']);
        $row = AccountingSourceEffect::query()->firstOrFail();
        $journal = JournalEntry::query()->firstOrFail();
        $this->assertSame('posted', $row->getAttribute('outcome'));
        $this->assertSame('automatic', $journal->getAttribute('entry_kind'));
        $this->assertSame('posted', $journal->getAttribute('status'));
        $this->assertSame((int) $journal->getKey(), (int) $row->getAttribute('journal_entry_id'));
        $this->assertNotNull($journal->getAttribute('posted_at'));
        $this->assertNull($journal->getAttribute('approved_by'));
        $this->assertDatabaseCount('journal_entry_lines', 2);
    }

    public function test_same_identity_and_facts_replay_without_new_write(): void
    {
        $first = $this->runEvent();
        $count = DB::table('accounting_audit_events')->count();
        $second = $this->runEvent();
        $this->assertSame('replayed', $second['mode']);
        $this->assertSame($first['effect_ids'], $second['effect_ids']);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertSame($count, DB::table('accounting_audit_events')->count());
    }

    public function test_conflicting_facts_under_same_identity_are_rejected(): void
    {
        $this->runEvent();
        OrchestratorContractFixtureAdapter::$event = $this->event(['facts' => ['amount' => '200.20']]);
        $this->invalid(fn () => $this->runEvent(), 'source_conflict');
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_retry_precedes_current_period_settings_and_account_validation(): void
    {
        $first = $this->runEvent();
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->update(['state' => 'hard_closed']);
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['state' => 'disabled', 'cutover_locked_at' => now()]);
        Account::query()->whereKey($this->mapping['general_expense'])->update(['status' => 'inactive']);
        $this->assertSame($first['effect_ids'], $this->runEvent()['effect_ids']);
    }

    public function test_retry_keeps_historical_mapping_after_new_profile(): void
    {
        $this->runEvent();
        $old = AccountingSourceEffect::query()->firstOrFail()->getAttribute('mapping_version_id');
        $latest = AccountingMappingVersion::query()->where('company_id', $this->company->getKey())->firstOrFail();
        $this->publish(['base_version_id' => $latest->getKey(), 'effective_from' => '2026-10-17']);
        $this->assertSame('replayed', $this->runEvent()['mode']);
        $this->assertSame((int) $old, (int) AccountingSourceEffect::query()->firstOrFail()->getAttribute('mapping_version_id'));
    }

    public function test_complete_multi_effect_bundle_is_atomic_and_replayable(): void
    {
        OrchestratorContractFixtureAdapter::$event = $this->event(['sourceKind' => 'sales_invoice', 'eventKind' => 'issued', 'eventToken' => 'issued_1', 'effects' => [$this->effect('cost', '20.00'), $this->effect('commercial')]]);
        $first = $this->runEvent();
        $this->assertCount(2, $first['effect_ids']);
        $this->assertSame($first['effect_ids'], $this->runEvent()['effect_ids']);
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_failure_in_second_effect_rolls_back_first_journal_and_source_mutation(): void
    {
        $bad = $this->effect('cost', '20.00');
        $lines = $bad->lines;
        $lines[1]['credit'] = '19.99';
        OrchestratorContractFixtureAdapter::$event = $this->event(['sourceKind' => 'sales_invoice', 'eventKind' => 'issued', 'eventToken' => 'issued_1', 'effects' => [$this->effect('commercial'), new AccountingSourceEffectPlan('cost', $lines, ['gl_amount' => '20.00'])]]);
        $original = $this->company->getAttribute('name');
        $this->invalid(fn () => DB::transaction(function (): void {
            $this->company->update(['name' => 'Representatively mutated source transaction marker']);
            $this->runEvent();
        }), 'lines');
        $this->assertSame($original, $this->company->fresh()->getAttribute('name'));
        $this->assertNoEffects();
    }

    public function test_partial_historical_bundle_is_not_completed_on_retry(): void
    {
        OrchestratorContractFixtureAdapter::$event = $this->event(['sourceKind' => 'sales_invoice', 'eventKind' => 'issued', 'eventToken' => 'issued_1', 'effects' => [$this->effect('commercial'), $this->effect('cost', '20.00')]]);
        $this->runEvent();
        DB::table('accounting_source_effects')->where('effect_kind', 'cost')->delete();
        $this->invalid(fn () => $this->runEvent(), 'source_bundle');
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_disabled_or_ready_accounting_bypasses_without_recognition_rows(): void
    {
        foreach (['disabled', 'ready'] as $state) {
            DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['state' => $state]);
            $this->assertSame('bypassed', $this->runEvent()['mode']);
            $this->assertNoEffects();
        }
    }

    public function test_cutover_lock_blocks_new_source_even_when_disabled(): void
    {
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['state' => 'disabled', 'cutover_locked_at' => now()]);
        $this->invalid(fn () => $this->runEvent(), 'cutover');
        $this->assertNoEffects();
    }

    public function test_source_authority_is_required_separately_from_manual_post_permission(): void
    {
        $actor = $this->staff(['erp.journal-entries.post']);
        $this->expectException(AuthorizationException::class);
        $this->runEvent($actor);
    }

    public function test_source_authorized_actor_does_not_need_manual_journal_permission(): void
    {
        $actor = $this->staff(['erp.accounting-openings.manage']);
        $this->assertFalse($actor->can('erp.journal-entries.post'));
        $this->assertSame('recorded', $this->runEvent($actor)['mode']);
    }

    public function test_foreign_company_staff_is_denied_before_source_prepare(): void
    {
        $other = $this->newCompany('SOURCE-B');
        $actor = User::factory()->create();
        Employee::query()->create(['company_id' => $other->getKey(), 'user_id' => $actor->getKey(), 'employee_number' => 'SRC-'.$actor->getKey(), 'first_name' => 'Other', 'last_name' => 'Company']);
        $actor->givePermissionTo('erp.accounting-openings.manage');
        $this->expectException(AuthorizationException::class);
        $this->runEvent($actor);
    }

    public function test_closed_period_blocks_new_event_even_for_super_admin(): void
    {
        foreach (['soft_closed', 'hard_closed'] as $state) {
            DB::table('accounting_periods')->where('company_id', $this->company->getKey())->update(['state' => $state]);
            $this->invalid(fn () => $this->runEvent(), 'accounting_date');
            $this->assertNoEffects();
        }
    }

    public function test_missing_effective_profile_blocks_new_event(): void
    {
        DB::table('accounting_mapping_versions')->where('company_id', $this->company->getKey())->update(['state' => 'draft']);
        $this->invalid(fn () => $this->runEvent(), 'mappings');
        $this->assertNoEffects();
    }

    public function test_inactive_account_blocks_new_event(): void
    {
        Account::query()->whereKey($this->mapping['general_expense'])->update(['status' => 'inactive']);
        $this->invalid(fn () => $this->runEvent(), 'mappings');
        $this->assertNoEffects();
    }

    public function test_currency_and_legacy_origin_fail_closed(): void
    {
        OrchestratorContractFixtureAdapter::$event = $this->event(['currency' => 'USD']);
        $this->invalid(fn () => $this->runEvent(), 'currency_code');
        OrchestratorContractFixtureAdapter::$event = $this->event(['originDate' => '2026-09-30']);
        $this->invalid(fn () => $this->runEvent(), 'coverage');
        $this->assertNoEffects();
    }

    public function test_pre_cutover_parent_requires_explicit_coverage_adapter(): void
    {
        OrchestratorContractFixtureAdapter::$event = $this->event(['references' => [['source_kind' => 'supplier_bill', 'source_id' => 99, 'origin_date' => '2026-09-30']]]);
        $this->invalid(fn () => $this->runEvent(), 'coverage');
    }

    public function test_private_fields_and_float_facts_are_rejected(): void
    {
        OrchestratorContractFixtureAdapter::$event = $this->event(['facts' => ['notes' => 'PRIVATE']]);
        $this->invalid(fn () => $this->runEvent(), 'source_facts');
        OrchestratorContractFixtureAdapter::$event = $this->event(['facts' => ['amount' => 100.1]]);
        $this->invalid(fn () => $this->runEvent(), 'source_facts');
        $this->assertNoEffects();
    }

    public function test_zero_cost_proof_records_verified_no_journal_and_replays(): void
    {
        OrchestratorContractFixtureAdapter::$event = $this->event(['sourceKind' => 'sales_delivery', 'eventKind' => 'posted', 'eventToken' => 'posted_1', 'effects' => [$this->effect('valuation', '0.00', 'zero_source_cost')]]);
        $first = $this->runEvent();
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertSame('verified_no_journal', AccountingSourceEffect::query()->firstOrFail()->getAttribute('outcome'));
        $this->assertSame($first['effect_ids'], $this->runEvent()['effect_ids']);
    }

    public function test_nonzero_or_unregistered_skip_proof_is_rejected(): void
    {
        OrchestratorContractFixtureAdapter::$event = $this->event(['sourceKind' => 'sales_delivery', 'eventKind' => 'posted', 'eventToken' => 'posted_1', 'effects' => [new AccountingSourceEffectPlan('valuation', [], ['gl_amount' => '1.00', 'source_cost' => '0.0000'], 'zero_source_cost')]]);
        $this->invalid(fn () => $this->runEvent(), 'no_journal');
        OrchestratorContractFixtureAdapter::$event = $this->event(['effects' => [$this->effect('commercial', '0.00', 'zero_source_cost')]]);
        $this->invalid(fn () => $this->runEvent(), 'no_journal');
        $this->assertNoEffects();
    }

    public function test_duplicate_or_missing_bundle_effects_are_rejected(): void
    {
        OrchestratorContractFixtureAdapter::$event = $this->event(['effects' => [$this->effect(), $this->effect()]]);
        $this->invalid(fn () => $this->runEvent(), 'source_bundle');
        OrchestratorContractFixtureAdapter::$event = $this->event(['effects' => []]);
        $this->invalid(fn () => $this->runEvent(), 'source_bundle');
    }

    public function test_replay_detects_raw_source_snapshot_and_journal_tampering(): void
    {
        $this->runEvent();
        DB::table('journal_entries')->where('entry_kind', 'automatic')->update(['description' => 'Tampered']);
        $this->invalid(fn () => $this->runEvent(), 'source_integrity');
    }

    public function test_posted_source_journals_and_effects_are_immutable(): void
    {
        $this->runEvent();
        $journal = JournalEntry::query()->firstOrFail();
        try {
            $journal->update(['description' => 'Rewrite']);
            $this->fail('Expected immutable journal');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
        $this->expectException(\LogicException::class);
        AccountingSourceEffect::query()->firstOrFail()->delete();
    }

    public function test_source_snapshot_tampering_is_detected_without_extra_effect(): void
    {
        $this->runEvent();
        $row = AccountingSourceEffect::query()->firstOrFail();
        $snapshot = $row->getAttribute('source_snapshot');
        $snapshot['evidence']['proof']['gl_amount'] = '999.00';
        DB::table('accounting_source_effects')->where('id', $row->getKey())->update(['source_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
        $this->invalid(fn () => $this->runEvent(), 'source_integrity');
        $this->assertDatabaseCount('accounting_source_effects', 1);
    }

    public function test_unknown_role_is_rejected_without_partial_effect(): void
    {
        $effect = $this->effect();
        $lines = $effect->lines;
        $lines[0]['role'] = 'unknown_role';
        OrchestratorContractFixtureAdapter::$event = $this->event(['effects' => [new AccountingSourceEffectPlan('commercial', $lines, $effect->proof)]]);
        $this->invalid(fn () => $this->runEvent(), 'mappings');
        $this->assertNoEffects();
    }

    public function test_source_plan_cannot_supply_raw_account_id(): void
    {
        $effect = $this->effect();
        $lines = $effect->lines;
        $lines[0]['account_id'] = $this->mapping['general_expense'];
        OrchestratorContractFixtureAdapter::$event = $this->event(['effects' => [new AccountingSourceEffectPlan('commercial', $lines, $effect->proof)]]);
        $this->invalid(fn () => $this->runEvent(), 'lines.0');
        $this->assertNoEffects();
    }

    public function test_ap_control_role_requires_a_company_owned_supplier(): void
    {
        $effect = $this->effect();
        $lines = $effect->lines;
        $lines[1]['role'] = 'trade_ap';
        OrchestratorContractFixtureAdapter::$event = $this->event(['effects' => [new AccountingSourceEffectPlan('commercial', $lines, $effect->proof)]]);
        $this->invalid(fn () => $this->runEvent(), 'source_party');
        $this->assertNoEffects();
    }

    public function test_cash_roles_require_the_future_cash_binding_contract(): void
    {
        $effect = $this->effect();
        $lines = $effect->lines;
        $lines[0]['role'] = 'cash:MAIN';
        OrchestratorContractFixtureAdapter::$event = $this->event(['effects' => [new AccountingSourceEffectPlan('commercial', $lines, $effect->proof)]]);
        $this->invalid(fn () => $this->runEvent(), 'lines.0.account_id');
        $this->assertNoEffects();
    }

    public function test_adapter_cannot_return_a_different_event_identity(): void
    {
        $this->invalid(fn () => DB::transaction(fn () => $this->engine->execute($this->actor, (int) $this->company->getKey(), 'supplier_bill', 2, 'approved', 'approved_1')), 'source_identity');
        $this->assertNoEffects();
    }

    public function test_invalid_persisted_event_token_shape_is_rejected(): void
    {
        OrchestratorContractFixtureAdapter::$event = $this->event(['eventToken' => 'invalid:token']);
        $this->invalid(fn () => $this->runEvent(), 'token');
        $this->assertNoEffects();
    }

    public function test_historical_mapping_rows_are_verified_without_current_account_rules(): void
    {
        $this->runEvent();
        $version = AccountingSourceEffect::query()->firstOrFail()->getAttribute('mapping_version_id');
        DB::table('accounting_account_mappings')->where('mapping_version_id', $version)->where('role', 'general_expense')->update(['account_id' => $this->mapping['rounding']]);
        $this->invalid(fn () => $this->runEvent(), 'source_integrity');
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_retired_historical_profile_remains_readable_on_retry(): void
    {
        $first = $this->runEvent();
        DB::table('accounting_mapping_versions')->where('company_id', $this->company->getKey())->update(['state' => 'retired']);
        $this->assertSame($first['effect_ids'], $this->runEvent()['effect_ids']);
    }
}

/** Test-only trusted producer. No business adapter is installed or claimed by these synthetic templates. */
final class OrchestratorContractFixtureAdapter implements AccountingSourceAdapter
{
    public static ?AccountingPreparedSourceEvent $event = null;

    public function authorize(User $actor, Company $company, int $sourceId, string $eventKind, string $eventToken): void
    {
        // Existing finance permission stands in for a source-module permission in these isolated contract fixtures.
        if (! $actor->can('erp.accounting-openings.manage')) {
            throw new AuthorizationException('Synthetic source operation authority required.');
        }
    }

    public function prepare(User $actor, Company $company, int $sourceId, string $eventKind, string $eventToken): AccountingPreparedSourceEvent
    {
        return self::$event ?? throw new \LogicException('Configure the synthetic source fixture first.');
    }
}

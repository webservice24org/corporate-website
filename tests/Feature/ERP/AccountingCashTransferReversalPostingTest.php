<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingCashTransfer;
use App\Models\Accounting\AccountingCashTransferReversalAuditEvent;
use App\Models\Accounting\AccountingCashTransferReversalOperation;
use App\Models\Accounting\AccountingCashTransferReversalRequest;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingMappingVersion;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingAccountService;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingCashAccountService;
use App\Services\ERP\Accounting\AccountingMappingCatalog;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\Books\AccountingCashBookReader;
use App\Services\ERP\Accounting\Source\AccountingSourceReconciliation;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferEvidence;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferPosting;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferReversalEvidence;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferReversalPosting;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferReversalWorkflow;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferWorkflow;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingCashBankPermissionSeeder;
use Database\Seeders\AccountingCashBookPermissionSeeder;
use Database\Seeders\AccountingCashTransferPermissionSeeder;
use Database\Seeders\AccountingCashTransferReversalPermissionSeeder;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\AccountingPurchasePermissionSeeder;
use Database\Seeders\AccountingSupplierPaymentPermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AccountingCashTransferReversalPostingTest extends TestCase
{
    use RefreshDatabase;

    private string $originalPostingKey;

    private AccountingCashAccount $cash;

    private AccountingCashAccount $bank;

    private Company $company;

    private User $actor;

    private User $reviewer;

    private AccountingMappingService $service;

    /** @var array<string, int> */
    private array $mapping;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(AccountingPermissionSeeder::class);
        $this->seed(PurchasePermissionSeeder::class);
        $this->seed(AccountingPurchasePermissionSeeder::class);
        $this->seed(AccountingSupplierPaymentPermissionSeeder::class);
        $this->seed(AccountingCashBankPermissionSeeder::class);
        $this->seed(AccountingCashTransferPermissionSeeder::class);
        $this->seed(AccountingCashTransferReversalPermissionSeeder::class);
        $this->seed(AccountingCashBookPermissionSeeder::class);
        $this->seed(SalesPermissionSeeder::class);
        Permission::findOrCreate('erp.stock-transfers.post', 'web');
        $this->company = $this->newCompany('AUTO-P');
        $this->actor = User::factory()->create();
        $this->actor->assignRole('super-admin');
        $this->actor->givePermissionTo('erp.stock-transfers.post');
        $this->reviewer = User::factory()->create();
        $this->reviewer->assignRole('super-admin');
        $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00', 'Asia/Dhaka'));
        app(AccountingSetupService::class)->initialize($this->actor, (int) $this->company->getKey());
        $this->service = app(AccountingMappingService::class);
        $this->mapping = $this->chart($this->company);
        $this->publish();
        app(AccountingCashAccountService::class)->initialize($this->actor, (int) $this->company->getKey(), 'Cash transfer reversal fixture');
        $this->cash = AccountingCashAccount::query()->where('company_id', $this->company->getKey())->where('role', 'cash:MAIN')->firstOrFail();
        $this->bank = AccountingCashAccount::query()->where('company_id', $this->company->getKey())->where('role', 'bank:CURRENT')->firstOrFail();
        $calendar = app(AccountingCalendarService::class);
        $year = $calendar->saveYear($this->actor, ['company_id' => $this->company->getKey(), 'code' => 'FY26', 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'reason' => 'Cash transfer reversal fixture']);
        $calendar->savePeriod($this->actor, (int) $year->getKey(), ['company_id' => $this->company->getKey(), 'code' => 'OCT', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'reason' => 'Cash transfer reversal fixture']);
        // Isolated synthetic activation prerequisite; production activation is still reserved for 6.13.
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['state' => 'enabled', 'activation_date' => '2026-10-01', 'activated_by' => $this->actor->getKey(), 'activated_at' => now()]);
        AccountingCutover::query()->create(['company_id' => $this->company->getKey(), 'cutover_date' => '2026-10-01', 'strategy' => 'snapshot', 'status' => 'activated', 'created_by' => $this->actor->getKey(), 'approved_by' => $this->reviewer->getKey(), 'approved_at' => now(), 'activated_by' => $this->actor->getKey(), 'activated_at' => now(), 'manifest_hash' => str_repeat('a', 64), 'reconciliation_hash' => str_repeat('b', 64), 'revision' => 1]);
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
        foreach ([...$catalog->roles(), 'cash:MAIN' => 'asset', 'bank:CURRENT' => 'asset'] as $role => $category) {
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

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed> */
    private function data(array $overrides = []): array
    {
        return ['company_id' => (int) $this->company->getKey(), 'base_version_id' => null, 'effective_from' => '2026-10-01', 'mappings' => $this->mapping, 'reason' => 'Approve initial company posting profile', ...$overrides];
    }

    /** @param array<string, mixed> $overrides */
    private function publish(array $overrides = []): AccountingMappingVersion
    {
        return $this->service->publish($this->actor, $this->data($overrides));
    }

    private function workflow(): AccountingCashTransferReversalWorkflow
    {
        return app(AccountingCashTransferReversalWorkflow::class);
    }

    /** @param array<string, mixed> $overrides */
    private function original(array $overrides = []): AccountingCashTransfer
    {
        $service = app(AccountingCashTransferWorkflow::class);
        $row = $service->save($this->actor, ['company_id' => (int) $this->company->getKey(), 'document_date' => '2026-10-16',
            'source_cash_account_id' => (int) $this->cash->getKey(), 'destination_cash_account_id' => (int) $this->bank->getKey(),
            'amount' => '100.00', 'purpose' => 'PRIVATE-ORIGINAL-PURPOSE', 'notes' => 'PRIVATE-ORIGINAL-NOTES', 'key' => (string) Str::uuid(), ...$overrides]);
        $row = $service->submit($this->actor, (int) $row->getKey(), 1);
        $row = $service->approve($this->reviewer, (int) $row->getKey(), 2);

        return app(AccountingCashTransferPosting::class)->post($this->actor, (int) $row->getKey(), 3, $this->originalPostingKey = (string) Str::uuid());
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function requestData(array $overrides = []): array
    {
        return ['document_date' => now('Asia/Dhaka')->toDateString(), 'reason' => 'PRIVATE-REVERSAL-REASON', 'key' => (string) Str::uuid(), ...$overrides];
    }

    /** @param array<string, mixed> $overrides */
    private function draft(AccountingCashTransfer $original, array $overrides = [], ?User $actor = null): AccountingCashTransferReversalRequest
    {
        return $this->workflow()->prepare($actor ?? $this->actor, (int) $original->getKey(), $this->requestData($overrides));
    }

    private function submitted(AccountingCashTransfer $original): AccountingCashTransferReversalRequest
    {
        $row = $this->draft($original);

        return $this->workflow()->submit($this->actor, (int) $row->getKey(), 1);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function editData(AccountingCashTransferReversalRequest $row, array $overrides = []): array
    {
        return ['document_date' => $row->getAttribute('document_date')->format('Y-m-d'), 'reason' => $row->getAttribute('reason'), 'revision' => (int) $row->getAttribute('revision'), ...$overrides];
    }

    private function financialDigest(): string
    {
        $parts = [];
        foreach (['accounting_cash_transfer_reversal_operations', 'accounting_cash_transfers', 'accounting_cash_transfer_audit_events', 'accounting_cash_transfer_sequences', 'accounting_cash_transfer_operations', 'accounting_source_effects', 'journal_entries', 'journal_entry_lines', 'accounting_document_sequences', 'accounting_audit_events', 'accounting_cash_accounts', 'accounting_company_settings', 'accounting_mapping_versions', 'accounting_account_mappings', 'accounts', 'accounting_periods', 'fiscal_years'] as $table) {
            $parts[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    private function digest(): string
    {
        return hash('sha256', json_encode(['finance' => $this->financialDigest(), 'requests' => DB::table('accounting_cash_transfer_reversal_requests')->orderBy('id')->get()->toArray(), 'audit' => DB::table('accounting_cash_transfer_reversal_audit_events')->orderBy('id')->get()->toArray()], JSON_THROW_ON_ERROR));
    }

    private function rejected(callable $action): void
    {
        $before = $this->digest();
        try {
            $action();
            $this->fail('Expected rejected reversal operation.');
        } catch (ValidationException|AuthorizationException|ModelNotFoundException|\LogicException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertSame($before, $this->digest());
    }

    /** @param array<string, mixed> $changes */
    private function settings(array $changes): void
    {
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update($changes);
    }

    /** @param array<string, mixed> $overrides */
    private function ready(AccountingCashTransfer $original, array $overrides = []): AccountingCashTransferReversalRequest
    {
        $row = $this->draft($original, $overrides);
        $row = $this->workflow()->submit($this->actor, (int) $row->getKey(), 1);

        return $this->workflow()->approve($this->reviewer, (int) $row->getKey(), 2);
    }

    private function reverse(AccountingCashTransferReversalRequest $row, string $key = 'reversal-post-key-0001', ?User $actor = null, int $revision = 3): AccountingCashTransferReversalRequest
    {
        return app(AccountingCashTransferReversalPosting::class)->post($actor ?? $this->actor, (int) $row->getKey(), $revision, $key);
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function book(AccountingCashAccount $binding, array $filters = [], ?User $actor = null): array
    {
        return app(AccountingCashBookReader::class)->book($actor ?? $this->actor, (int) $this->company->getKey(), (int) $binding->getKey(), $filters);
    }

    /** @return array{request: AccountingCashTransferReversalRequest, effect: AccountingSourceEffect, journal: JournalEntry} */
    private function proof(AccountingCashTransferReversalRequest $row): array
    {
        return app(AccountingCashTransferReversalEvidence::class)->verify((int) $this->company->getKey(), (int) $row->getKey());
    }

    private function markNativePosting(AccountingCashTransferReversalRequest $row): void
    {
        $row->forceFill(['status' => 'posted', 'revision' => 4, 'posting_revision' => 3, 'posting_key_hash' => hash('sha256', 'reversal-post-key-0001'), 'posted_by' => (int) $this->actor->getKey(), 'posted_at' => now('UTC'), 'updated_by' => (int) $this->actor->getKey()])->save();
    }

    private function parentJournal(AccountingCashTransferReversalRequest $row, string $date = '2026-10-16'): JournalEntry
    {
        $snapshot = $row->getAttribute('original_snapshot');

        return JournalEntry::query()->create(['company_id' => $this->company->getKey(), 'accounting_period_id' => DB::table('accounting_periods')->where('company_id', $this->company->getKey())->value('id'), 'mapping_version_id' => $snapshot['mapping_version_id'], 'number' => 'TEST-'.Str::uuid(), 'entry_kind' => 'automatic', 'status' => 'draft', 'accounting_date' => $date, 'document_date' => $date, 'currency_code' => 'BDT', 'description' => 'Test parent', 'created_by' => $this->actor->getKey(), 'updated_by' => $this->actor->getKey(), 'revision' => 1, 'contract_version' => '1.0.0']);
    }

    public function test_post_creates_one_linked_compensating_journal_and_preserves_original(): void
    {
        $original = $this->original();
        $before = $original->fresh()->getAttributes();
        $row = $this->reverse($this->ready($original));
        $proof = $this->proof($row);
        $this->assertSame('posted', $row->getAttribute('status'));
        $this->assertSame(4, $row->getAttribute('revision'));
        $this->assertSame($before, $original->fresh()->getAttributes());
        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertDatabaseCount('journal_entry_lines', 4);
        $op = AccountingCashTransferReversalOperation::query()->firstOrFail();
        $this->assertSame((int) $row->getAttribute('original_journal_id'), (int) $op->getAttribute('original_journal_id'));
        $this->assertNotSame((int) $op->getAttribute('original_journal_id'), (int) $proof['journal']->getKey());
    }

    public function test_both_books_restore_original_positions_without_hiding_gross_movements(): void
    {
        $row = $this->reverse($this->ready($this->original()));
        foreach ([$this->cash, $this->bank] as $binding) {
            $book = $this->book($binding);
            $this->assertTrue($book['complete']);
            $this->assertSame('0.00', $book['source_verified']['closing']);
            $this->assertSame('100.00', $book['source_verified']['debits']);
            $this->assertSame('100.00', $book['source_verified']['credits']);
            $this->assertCount(2, $book['rows']);
        }
        $this->assertSame('cash_transfer_reversal_received', $this->book($this->cash)['rows'][1]['event']);
        $this->assertSame('cash_transfer_reversal_sent', $this->book($this->bank)['rows'][1]['event']);
        $this->proof($row);
    }

    public function test_fractional_cents_reverse_exactly_after_database_reload(): void
    {
        foreach (['0.01', '25.25'] as $index => $amount) {
            $row = $this->reverse($this->ready($this->original(['amount' => $amount]))->fresh(), 'fractional-reversal-key-'.$index);
            $this->assertSame($amount, $row->fresh()->getAttribute('amount'));
            $this->proof($row);
        }
        $this->assertSame('0.00', $this->book($this->cash)['source_verified']['closing']);
    }

    public function test_same_request_replays_without_new_writes(): void
    {
        $row = $this->reverse($this->ready($this->original()));
        $before = $this->digest();
        $again = $this->reverse($row);
        $this->assertSame($row->getKey(), $again->getKey());
        $this->assertSame($before, $this->digest());
    }

    public function test_key_revision_and_actor_conflicts_do_not_repost(): void
    {
        $row = $this->reverse($this->ready($this->original()));
        $this->rejected(fn () => $this->reverse($row, 'different-reversal-key-002'));
        $this->rejected(fn () => $this->reverse($row, revision: 4));
        $this->rejected(fn () => $this->reverse($row, actor: $this->reviewer));
    }

    public function test_post_requires_the_separate_native_permission(): void
    {
        $row = $this->ready($this->original());
        $viewer = $this->staff(['erp.cash-transfer-reversals.view']);
        $this->rejected(fn () => $this->reverse($row, actor: $viewer));
    }

    public function test_post_only_staff_needs_no_manual_journal_or_original_post_permission(): void
    {
        $row = $this->ready($this->original());
        $user = $this->staff(['erp.cash-transfer-reversals.post']);
        $this->assertFalse($user->can('erp.journal-entries.post'));
        $this->assertFalse($user->can('erp.cash-transfers.post'));
        $this->assertSame('posted', $this->reverse($row, actor: $user)->getAttribute('status'));
    }

    public function test_foreign_company_actor_cannot_post(): void
    {
        $row = $this->ready($this->original());
        $user = $this->staff(['erp.cash-transfer-reversals.post']);
        $other = $this->newCompany('REVERSAL-POST-FOREIGN');
        DB::table('employees')->where('user_id', $user->getKey())->update(['company_id' => $other->getKey()]);
        $this->rejected(fn () => $this->reverse($row, actor: $user));
    }

    public function test_draft_submitted_cancelled_and_stale_requests_cannot_post(): void
    {
        $row = $this->draft($this->original());
        $this->rejected(fn () => $this->reverse($row, revision: 1));
        $row = $this->workflow()->submit($this->actor, (int) $row->getKey(), 1);
        $this->rejected(fn () => $this->reverse($row, revision: 2));
        $row = $this->workflow()->approve($this->reviewer, (int) $row->getKey(), 2);
        $this->rejected(fn () => $this->reverse($row, revision: 2));
        $row = $this->workflow()->cancel($this->actor, (int) $row->getKey(), 3, 'Not required');
        $this->rejected(fn () => $this->reverse($row, revision: 4));
    }

    public function test_posted_reversal_cannot_be_cancelled_edited_or_replaced(): void
    {
        $original = $this->original();
        $row = $this->reverse($this->ready($original));
        $this->rejected(fn () => $this->workflow()->cancel($this->actor, (int) $row->getKey(), 4, 'Invalid cancel'));
        $this->rejected(fn () => $this->draft($original));
        $this->rejected(fn () => DB::transaction(fn () => $row->forceFill(['revision' => 5, 'reason' => 'Changed'])->save()));
    }

    public function test_disabled_accounting_and_cutover_lock_block_new_posting(): void
    {
        $row = $this->ready($this->original());
        $this->settings(['state' => 'disabled']);
        $this->rejected(fn () => $this->reverse($row));
        $this->settings(['state' => 'enabled', 'cutover_locked_at' => now()]);
        $this->rejected(fn () => $this->reverse($row));
    }

    public function test_missing_activation_evidence_blocks_new_posting(): void
    {
        $row = $this->ready($this->original());
        $this->settings(['activated_at' => null]);
        $this->rejected(fn () => $this->reverse($row));
    }

    public function test_closed_reversal_period_blocks_posting_and_rolls_back_native_state(): void
    {
        $row = $this->ready($this->original());
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->update(['state' => 'closed']);
        $this->rejected(fn () => $this->reverse($row));
    }

    public function test_closed_original_period_is_reversed_in_current_open_period(): void
    {
        $original = $this->original();
        $year = (int) DB::table('fiscal_years')->where('company_id', $this->company->getKey())->value('id');
        app(AccountingCalendarService::class)->savePeriod($this->actor, $year, ['company_id' => (int) $this->company->getKey(), 'code' => 'NOV', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'reason' => 'Open correction period']);
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->where('code', 'OCT')->update(['state' => 'closed']);
        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00', 'Asia/Dhaka'));
        $row = $this->reverse($this->ready($original));
        $this->assertSame('2026-11-01', $this->proof($row)['journal']->getAttribute('accounting_date')->format('Y-m-d'));
        $old = $this->book($this->bank, ['to' => '2026-10-31']);
        $current = $this->book($this->bank, ['from' => '2026-11-01']);
        $this->assertSame('100.00', $old['source_verified']['closing']);
        $this->assertSame('100.00', $current['source_verified']['opening']);
        $this->assertSame('0.00', $current['source_verified']['closing']);
    }

    public function test_backdated_post_requires_current_actor_adjust_authority(): void
    {
        $row = $this->ready($this->original());
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $user = $this->staff(['erp.cash-transfer-reversals.post']);
        $this->rejected(fn () => $this->reverse($row, actor: $user));
        $user->givePermissionTo('erp.accounting-periods.adjust');
        $this->assertSame('posted', $this->reverse($row, actor: $user)->getAttribute('status'));
    }

    public function test_inactive_original_bindings_are_allowed_only_for_exact_native_reversal(): void
    {
        $row = $this->ready($this->original());
        $this->cash->update(['status' => 'inactive']);
        $this->bank->update(['status' => 'inactive']);
        $this->reverse($row);
        $this->assertTrue($this->book($this->cash)['complete']);
        $this->assertTrue($this->book($this->bank)['complete']);
        $this->assertSame('0.00', $this->book($this->bank)['source_verified']['closing']);
    }

    public function test_inactive_original_gl_is_still_blocked_for_a_new_reversal(): void
    {
        $row = $this->ready($this->original());
        DB::table('accounts')->where('id', $this->cash->getAttribute('account_id'))->update(['status' => 'inactive']);
        $this->rejected(fn () => $this->reverse($row));
    }

    public function test_later_profile_remap_cannot_redirect_compensating_accounts(): void
    {
        $original = $this->original();
        $previous = AccountingMappingVersion::query()->where('company_id', $this->company->getKey())->firstOrFail();
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $map = $this->mapping;
        [$map['cash:MAIN'], $map['bank:CURRENT']] = [$map['bank:CURRENT'], $map['cash:MAIN']];
        $this->publish(['base_version_id' => (int) $previous->getKey(), 'effective_from' => '2026-10-17', 'mappings' => $map]);
        $row = $this->reverse($this->ready($original));
        $journal = $this->proof($row)['journal'];
        $this->assertSame((int) $previous->getKey(), (int) $journal->getAttribute('mapping_version_id'));
        $lines = JournalEntryLine::query()->where('journal_entry_id', $journal->getKey())->orderBy('line_number')->get();
        $this->assertSame((int) $this->cash->getAttribute('account_id'), (int) $lines[0]->getAttribute('account_id'));
        $this->assertSame('100.00', $lines[0]->getAttribute('debit'));
    }

    public function test_historical_replay_survives_disabled_settings_closed_period_and_inactive_accounts(): void
    {
        $row = $this->reverse($this->ready($this->original()));
        $this->settings(['state' => 'disabled', 'cutover_locked_at' => now()]);
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->update(['state' => 'closed']);
        $this->cash->update(['status' => 'inactive']);
        $this->bank->update(['status' => 'inactive']);
        DB::table('accounts')->where('id', $this->cash->getAttribute('account_id'))->update(['status' => 'inactive']);
        $before = $this->digest();
        $this->reverse($row);
        $this->assertSame($before, $this->digest());
    }

    public function test_missing_reversal_operation_is_not_recreated_on_retry(): void
    {
        $row = $this->reverse($this->ready($this->original()));
        DB::table('accounting_cash_transfer_reversal_operations')->delete();
        $this->rejected(fn () => $this->reverse($row));
    }

    public function test_corrupt_operation_hash_is_not_repaired(): void
    {
        $row = $this->reverse($this->ready($this->original()));
        DB::table('accounting_cash_transfer_reversal_operations')->update(['payload_hash' => str_repeat('0', 64)]);
        $this->rejected(fn () => $this->reverse($row));
    }

    public function test_rehashed_operation_still_must_match_native_identity(): void
    {
        $row = $this->reverse($this->ready($this->original()));
        $op = AccountingCashTransferReversalOperation::query()->firstOrFail();
        $payload = $op->getAttribute('payload');
        $payload['revision'] = 99;
        DB::table('accounting_cash_transfer_reversal_operations')->where('id', $op->getKey())->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'payload_hash' => app(AccountingCanonicalPayload::class)->hash($payload)]);
        $this->rejected(fn () => $this->reverse($row));
    }

    public function test_missing_reversal_effect_is_not_reposted(): void
    {
        $row = $this->reverse($this->ready($this->original()));
        DB::table('accounting_cash_transfer_reversal_operations')->delete();
        DB::table('accounting_source_effects')->where('source_kind', 'cash_transfer_reversal')->delete();
        $this->rejected(fn () => $this->reverse($row));
    }

    public function test_missing_reversal_cash_leg_invalidates_both_books(): void
    {
        $row = $this->reverse($this->ready($this->original()));
        DB::table('journal_entry_lines')->where('source_line_type', 'cash_transfer_reversal')->where('cash_account_id', $this->cash->getKey())->delete();
        $this->rejected(fn () => $this->reverse($row));
        $this->assertFalse($this->book($this->cash)['complete']);
        $this->assertFalse($this->book($this->bank)['complete']);
    }

    public function test_missing_both_reversal_legs_cannot_produce_a_clean_original_only_book(): void
    {
        $this->reverse($this->ready($this->original()));
        DB::table('journal_entry_lines')->where('source_line_type', 'cash_transfer_reversal')->delete();
        foreach ([$this->cash, $this->bank] as $binding) {
            $book = $this->book($binding);
            $this->assertFalse($book['complete']);
            $this->assertContains('native_cash_transfer_reversal_evidence_review', array_column($book['exceptions'], 'code'));
        }
    }

    public function test_original_evidence_damage_blocks_new_reversal_without_repair(): void
    {
        $row = $this->ready($this->original());
        DB::table('accounting_cash_transfer_operations')->delete();
        $this->rejected(fn () => $this->reverse($row));
    }

    public function test_tampered_approved_reason_is_rejected(): void
    {
        $row = $this->ready($this->original());
        DB::table('accounting_cash_transfer_reversal_requests')->where('id', $row->getKey())->update(['reason' => 'Changed after approval']);
        $this->rejected(fn () => $this->reverse($row));
    }

    public function test_operation_failure_rolls_back_journal_number_native_post_and_all_effects(): void
    {
        $row = $this->ready($this->original());
        $before = $this->digest();
        $breakOperation = true;
        Event::listen('eloquent.creating: '.AccountingCashTransferReversalOperation::class, function () use (&$breakOperation): void {
            if ($breakOperation) {
                throw new \RuntimeException('Synthetic reversal operation failure');
            }
        });
        try {
            $this->reverse($row);
            $this->fail('Expected operation failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic reversal operation failure', $exception->getMessage());
        } finally {
            $breakOperation = false;
        }
        $this->assertSame($before, $this->digest());
        $this->assertSame('posted', $this->reverse($row)->getAttribute('status'));
    }

    public function test_second_line_failure_rolls_back_first_line_and_entire_native_transition(): void
    {
        $row = $this->ready($this->original());
        $before = $this->digest();
        $breakLine = true;
        $count = 0;
        Event::listen('eloquent.creating: '.JournalEntryLine::class, function ($line) use (&$breakLine, &$count): void {
            if ($breakLine && $line->getAttribute('source_line_type') === 'cash_transfer_reversal' && ++$count === 2) {
                throw new \RuntimeException('Synthetic second cash leg failure');
            }
        });
        try {
            $this->reverse($row);
            $this->fail('Expected second-leg failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic second cash leg failure', $exception->getMessage());
        } finally {
            $breakLine = false;
        }
        $this->assertSame($before, $this->digest());
        $this->assertSame('posted', $this->reverse($row)->getAttribute('status'));
    }

    public function test_final_audit_failure_rolls_back_complete_posting_operation(): void
    {
        $row = $this->ready($this->original());
        $before = $this->digest();
        $breakAudit = true;
        Event::listen('eloquent.creating: '.AccountingCashTransferReversalAuditEvent::class, function ($event) use (&$breakAudit): void {
            if ($breakAudit && $event->getAttribute('action') === 'posted') {
                throw new \RuntimeException('Synthetic final reversal audit failure');
            }
        });
        try {
            $this->reverse($row);
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic final reversal audit failure', $exception->getMessage());
        } finally {
            $breakAudit = false;
        }
        $this->assertSame($before, $this->digest());
        $this->assertSame('posted', $this->reverse($row)->getAttribute('status'));
    }

    public function test_operation_and_posted_journal_lines_are_append_only(): void
    {
        $row = $this->reverse($this->ready($this->original()));
        $op = AccountingCashTransferReversalOperation::query()->firstOrFail();
        $this->rejected(fn () => $op->update(['key_hash' => str_repeat('0', 64)]));
        $this->rejected(fn () => $op->delete());
        $line = JournalEntryLine::query()->where('source_line_type', 'cash_transfer_reversal')->firstOrFail();
        $this->rejected(fn () => $line->update(['debit' => '1.00']));
        $this->proof($row);
    }

    public function test_forged_source_label_cannot_use_inactive_binding_without_native_posted_proof(): void
    {
        $row = $this->ready($this->original());
        $this->cash->update(['status' => 'inactive']);
        $parent = $this->parentJournal($row);
        $this->rejected(fn () => JournalEntryLine::query()->create(['company_id' => $this->company->getKey(), 'journal_entry_id' => $parent->getKey(), 'line_number' => 1, 'account_id' => $this->cash->getAttribute('account_id'), 'cash_account_id' => $this->cash->getKey(), 'source_line_type' => 'cash_transfer_reversal', 'source_line_id' => $row->getKey(), 'debit' => '100.00', 'credit' => '0.00']));
    }

    public function test_inactive_binding_exception_rejects_wrong_side_even_with_native_posted_intent(): void
    {
        $row = $this->ready($this->original());
        $this->cash->update(['status' => 'inactive']);
        $this->rejected(fn () => DB::transaction(function () use ($row): void {
            $this->markNativePosting($row);
            $parent = $this->parentJournal($row);
            JournalEntryLine::query()->create(['company_id' => $this->company->getKey(), 'journal_entry_id' => $parent->getKey(), 'line_number' => 1, 'account_id' => $this->cash->getAttribute('account_id'), 'cash_account_id' => $this->cash->getKey(), 'source_line_type' => 'cash_transfer_reversal', 'source_line_id' => $row->getKey(), 'debit' => '0.00', 'credit' => '100.00']);
        }));
    }

    public function test_inactive_binding_exception_requires_correct_parent_journal_date(): void
    {
        $row = $this->ready($this->original());
        $this->cash->update(['status' => 'inactive']);
        $this->rejected(fn () => DB::transaction(function () use ($row): void {
            $this->markNativePosting($row);
            $parent = $this->parentJournal($row, '2026-10-15');
            JournalEntryLine::query()->create(['company_id' => $this->company->getKey(), 'journal_entry_id' => $parent->getKey(), 'line_number' => 1, 'account_id' => $this->cash->getAttribute('account_id'), 'cash_account_id' => $this->cash->getKey(), 'source_line_type' => 'cash_transfer_reversal', 'source_line_id' => $row->getKey(), 'debit' => '100.00', 'credit' => '0.00']);
        }));
    }

    public function test_completed_reversal_proof_cannot_authorize_another_inactive_cash_line(): void
    {
        $row = $this->reverse($this->ready($this->original()));
        $this->cash->update(['status' => 'inactive']);
        $parent = $this->parentJournal($row);
        $this->rejected(fn () => JournalEntryLine::query()->create(['company_id' => $this->company->getKey(), 'journal_entry_id' => $parent->getKey(), 'line_number' => 1, 'account_id' => $this->cash->getAttribute('account_id'), 'cash_account_id' => $this->cash->getKey(), 'source_line_type' => 'cash_transfer_reversal', 'source_line_id' => $row->getKey(), 'debit' => '100.00', 'credit' => '0.00']));
    }

    public function test_books_exports_and_reconciliation_only_read_complete_finance(): void
    {
        $row = $this->reverse($this->ready($this->original()));
        $before = $this->digest();
        $this->proof($row);
        $this->book($this->cash);
        $this->book($this->bank);
        app(AccountingCashBookReader::class)->bookExport($this->actor, (int) $this->company->getKey(), (int) $this->bank->getKey());
        $report = app(AccountingSourceReconciliation::class)->page($this->actor, (int) $this->company->getKey(), ['kind' => 'cash_transfer_reversal']);
        $this->assertSame('verified_live', $report['rows'][0]['status']);
        $this->assertSame(0, $report['rows'][0]['admission_count']);
        $this->assertSame('/erp/accounting/cash-transfer-reversals/'.$row->getKey().'?company_id='.$this->company->getKey(), $report['rows'][0]['source_url']);
        $this->assertSame($before, $this->digest());
    }

    public function test_reconciliation_reviews_original_and_reversal_when_member_is_missing(): void
    {
        $this->reverse($this->ready($this->original()));
        DB::table('accounting_cash_transfer_reversal_operations')->delete();
        foreach (['cash_transfer', 'cash_transfer_reversal'] as $kind) {
            $page = app(AccountingSourceReconciliation::class)->page($this->actor, (int) $this->company->getKey(), ['kind' => $kind]);
            $this->assertSame('integrity_error', $page['rows'][0]['status']);
        }
    }

    public function test_private_reasons_notes_and_raw_post_key_are_not_copied_to_financial_evidence(): void
    {
        $this->reverse($this->ready($this->original()));
        $json = json_encode([DB::table('accounting_source_effects')->where('source_kind', 'cash_transfer_reversal')->get(), DB::table('journal_entries')->get(), DB::table('journal_entry_lines')->get(), $this->book($this->bank)], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PRIVATE-', $json);
        $this->assertStringNotContainsString('reversal-post-key-0001', $json);
    }

    public function test_json_key_reordering_does_not_break_replay(): void
    {
        $row = $this->reverse($this->ready($this->original()));
        $op = AccountingCashTransferReversalOperation::query()->firstOrFail();
        DB::table('accounting_cash_transfer_reversal_operations')->where('id', $op->getKey())->update(['payload' => json_encode(array_reverse($op->getAttribute('payload'), true), JSON_THROW_ON_ERROR)]);
        $before = $this->digest();
        $this->reverse($row);
        $this->assertSame($before, $this->digest());
    }

    public function test_creation_retry_of_posted_request_verifies_complete_operation(): void
    {
        $original = $this->original();
        $input = $this->requestData();
        $row = $this->workflow()->prepare($this->actor, (int) $original->getKey(), $input);
        $row = $this->workflow()->submit($this->actor, (int) $row->getKey(), 1);
        $row = $this->workflow()->approve($this->reviewer, (int) $row->getKey(), 2);
        $this->reverse($row);
        $before = $this->digest();
        $this->assertSame('posted', $this->workflow()->prepare($this->actor, (int) $original->getKey(), $input)->getAttribute('status'));
        $this->assertSame($before, $this->digest());
        DB::table('accounting_cash_transfer_reversal_operations')->delete();
        $this->rejected(fn () => $this->workflow()->prepare($this->actor, (int) $original->getKey(), $input));
    }

    public function test_orphan_reversal_effect_cannot_disappear_from_both_books(): void
    {
        $row = $this->reverse($this->ready($this->original()));
        DB::table('accounting_cash_transfer_reversal_operations')->delete();
        DB::table('accounting_cash_transfer_reversal_audit_events')->where('reversal_request_id', $row->getKey())->delete();
        DB::table('accounting_cash_transfer_reversal_requests')->where('id', $row->getKey())->delete();
        DB::table('journal_entry_lines')->where('source_line_type', 'cash_transfer_reversal')->delete();
        $this->assertFalse($this->book($this->cash)['complete']);
        $this->assertFalse($this->book($this->bank)['complete']);
    }

    public function test_missing_manifest_is_rejected_even_when_accounting_is_now_disabled(): void
    {
        $row = $this->reverse($this->ready($this->original()));
        DB::table('accounting_cash_transfer_reversal_operations')->delete();
        $this->settings(['state' => 'disabled']);
        $this->rejected(fn () => $this->reverse($row));
    }

    public function test_original_post_request_still_replays_after_its_native_reversal(): void
    {
        $original = $this->original();
        $beforeOriginal = $original->fresh()->getAttributes();
        $this->reverse($this->ready($original));
        $before = $this->digest();
        app(AccountingCashTransferPosting::class)->post($this->actor, (int) $original->getKey(), 3, $this->originalPostingKey);
        $this->assertSame($before, $this->digest());
        $this->assertSame($beforeOriginal, $original->fresh()->getAttributes());
        $this->assertDatabaseCount('accounting_cash_transfer_operations', 1);
    }

    public function test_migration_collision_is_detected_before_ddl(): void
    {
        $files = glob(database_path('migrations/*add_cash_transfer_reversal_posting.php'));
        $this->assertIsArray($files);
        $this->assertCount(1, $files);
        $before = Schema::getTableListing();
        $migration = require $files[0];
        try {
            $migration->up();
            $this->fail('Expected migration collision.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('No DDL was attempted', $exception->getMessage());
        }
        $this->assertSame($before, Schema::getTableListing());
    }

    public function test_bank_to_cash_reversal_restores_both_bindings(): void
    {
        $original = $this->original(['source_cash_account_id' => (int) $this->bank->getKey(), 'destination_cash_account_id' => (int) $this->cash->getKey()]);
        $row = $this->reverse($this->ready($original));
        $journal = $this->proof($row)['journal'];
        $lines = JournalEntryLine::query()->where('journal_entry_id', $journal->getKey())->orderBy('line_number')->get();
        $this->assertSame((int) $this->bank->getKey(), (int) $lines[0]->getAttribute('cash_account_id'));
        $this->assertSame('100.00', $lines[0]->getAttribute('debit'));
        $this->assertSame('0.00', $this->book($this->bank)['source_verified']['closing']);
        $this->assertSame('0.00', $this->book($this->cash)['source_verified']['closing']);
    }

    public function test_future_company_date_is_rejected_at_post_time(): void
    {
        $row = $this->ready($this->original());
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'Asia/Dhaka'));
        $this->rejected(fn () => $this->reverse($row));
    }

    public function test_post_key_and_revision_validation_cannot_write_finance(): void
    {
        $row = $this->ready($this->original());
        $this->rejected(fn () => $this->reverse($row, 'short'));
        $this->rejected(fn () => $this->reverse($row, revision: 0));
    }

    public function test_book_viewer_needs_no_reversal_or_journal_view_permission(): void
    {
        $this->reverse($this->ready($this->original()));
        $viewer = $this->staff(['erp.cash-books.view']);
        $book = $this->book($this->cash, actor: $viewer);
        $this->assertTrue($book['complete']);
        foreach ($book['rows'] as $row) {
            $this->assertNull($row['source_url']);
            $this->assertNull($row['journal_url']);
        }
    }

    public function test_database_blocks_second_operation_for_original_even_with_other_valid_member_ids(): void
    {
        $original = $this->original();
        $cancelled = $this->draft($original);
        $this->workflow()->cancel($this->actor, (int) $cancelled->getKey(), 1, 'Use a fresh review');
        $this->reverse($this->ready($original));
        $otherOriginal = $this->original();
        $otherProof = app(AccountingCashTransferEvidence::class)->verify((int) $this->company->getKey(), (int) $otherOriginal->getKey());
        $op = AccountingCashTransferReversalOperation::query()->firstOrFail();
        $attributes = $op->getAttributes();
        unset($attributes['id']);
        $attributes['reversal_request_id'] = (int) $cancelled->getKey();
        $attributes['source_effect_id'] = (int) $otherProof['effect']->getKey();
        $attributes['journal_entry_id'] = (int) $otherProof['journal']->getKey();
        $before = $this->digest();
        try {
            DB::transaction(fn () => DB::table('accounting_cash_transfer_reversal_operations')->insert($attributes));
            $this->fail('Expected original operation uniqueness.');
        } catch (QueryException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertSame($before, $this->digest());
    }
}

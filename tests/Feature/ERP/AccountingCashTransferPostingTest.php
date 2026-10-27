<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingCashTransfer;
use App\Models\Accounting\AccountingCashTransferAuditEvent;
use App\Models\Accounting\AccountingCashTransferOperation;
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
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentService;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferEvidence;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferPosting;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferWorkflow;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingCashBankPermissionSeeder;
use Database\Seeders\AccountingCashBookPermissionSeeder;
use Database\Seeders\AccountingCashTransferPermissionSeeder;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\AccountingPurchasePermissionSeeder;
use Database\Seeders\AccountingSupplierPaymentPermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AccountingCashTransferPostingTest extends TestCase
{
    use RefreshDatabase;

    private AccountingCashAccount $cash;

    private AccountingCashAccount $bank;

    private AccountingSupplierPaymentService $payments;

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
        app(AccountingCashAccountService::class)->initialize($this->actor, (int) $this->company->getKey(), 'Supplier payment integration fixture');
        $this->cash = AccountingCashAccount::query()->where('company_id', $this->company->getKey())->where('role', 'cash:MAIN')->firstOrFail();
        $this->bank = AccountingCashAccount::query()->where('company_id', $this->company->getKey())->where('role', 'bank:CURRENT')->firstOrFail();
        $this->payments = app(AccountingSupplierPaymentService::class);
        $calendar = app(AccountingCalendarService::class);
        $year = $calendar->saveYear($this->actor, ['company_id' => $this->company->getKey(), 'code' => 'FY26', 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'reason' => 'Source integration fixture']);
        $calendar->savePeriod($this->actor, (int) $year->getKey(), ['company_id' => $this->company->getKey(), 'code' => 'OCT', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'reason' => 'Source integration fixture']);
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

    private function workflow(): AccountingCashTransferWorkflow
    {
        return app(AccountingCashTransferWorkflow::class);
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private function transferInput(array $changes = []): array
    {
        return ['company_id' => (int) $this->company->getKey(), 'document_date' => '2026-10-16',
            'source_cash_account_id' => (int) $this->cash->getKey(), 'destination_cash_account_id' => (int) $this->bank->getKey(),
            'amount' => '100.00', 'purpose' => 'PRIVATE-TRANSFER-PURPOSE', 'notes' => 'PRIVATE-TRANSFER-NOTES',
            'key' => (string) Str::uuid(), ...$changes];
    }

    /** @param array<string, mixed> $changes */
    private function approved(array $changes = []): AccountingCashTransfer
    {
        $row = $this->workflow()->save($this->actor, $this->transferInput($changes));
        $row = $this->workflow()->submit($this->actor, (int) $row->getKey(), 1);

        return $this->workflow()->approve($this->reviewer, (int) $row->getKey(), 2);
    }

    private function commit(AccountingCashTransfer $row, string $key = 'transfer-post-key-0001', ?User $actor = null, int $revision = 3): AccountingCashTransfer
    {
        return app(AccountingCashTransferPosting::class)->post($actor ?? $this->actor, (int) $row->getKey(), $revision, $key);
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function book(AccountingCashAccount $binding, array $filters = [], ?User $actor = null): array
    {
        return app(AccountingCashBookReader::class)->book($actor ?? $this->actor, (int) $this->company->getKey(), (int) $binding->getKey(), $filters);
    }

    /** @return array{transfer: AccountingCashTransfer, effect: AccountingSourceEffect, journal: JournalEntry} */
    private function proof(AccountingCashTransfer $row): array
    {
        return app(AccountingCashTransferEvidence::class)->verify((int) $this->company->getKey(), (int) $row->getKey());
    }

    private function stateDigest(): string
    {
        $parts = [];
        foreach (['accounting_cash_transfers', 'accounting_cash_transfer_sequences', 'accounting_cash_transfer_audit_events', 'accounting_cash_transfer_operations',
            'accounting_source_effects', 'journal_entries', 'journal_entry_lines', 'accounting_document_sequences', 'accounting_audit_events'] as $table) {
            $parts[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    private function rejected(callable $action): void
    {
        $before = $this->stateDigest();
        try {
            $action();
            $this->fail('Expected rejected transfer posting.');
        } catch (ValidationException|AuthorizationException|\Illuminate\Database\Eloquent\ModelNotFoundException|\LogicException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertSame($before, $this->stateDigest());
    }

    /** @param array<string, mixed> $changes */
    private function settings(array $changes): void
    {
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update($changes);
    }

    private function closePeriod(): void
    {
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->update(['state' => 'closed']);
    }

    public function test_post_records_one_balanced_journal_with_both_fixed_cash_identities(): void
    {
        $row = $this->commit($this->approved());
        $this->assertSame('posted', $row->getAttribute('status'));
        $this->assertSame(4, $row->getAttribute('revision'));
        $this->assertDatabaseCount('accounting_cash_transfer_operations', 1);
        $this->assertDatabaseCount('accounting_source_effects', 1);
        $this->assertDatabaseCount('journal_entries', 1);
        $lines = JournalEntryLine::query()->orderBy('line_number')->get();
        $this->assertCount(2, $lines);
        $this->assertSame((int) $this->bank->getKey(), (int) $lines[0]->getAttribute('cash_account_id'));
        $this->assertSame('100.00', $lines[0]->getAttribute('debit'));
        $this->assertSame((int) $this->cash->getKey(), (int) $lines[1]->getAttribute('cash_account_id'));
        $this->assertSame('100.00', $lines[1]->getAttribute('credit'));
        $this->proof($row);
    }

    public function test_both_books_share_the_journal_and_company_cash_net_is_zero(): void
    {
        $this->commit($this->approved());
        $cash = $this->book($this->cash);
        $bank = $this->book($this->bank);
        $this->assertTrue($cash['complete']);
        $this->assertTrue($bank['complete']);
        $this->assertSame('-100.00', $cash['source_verified']['closing']);
        $this->assertSame('100.00', $bank['source_verified']['closing']);
        $this->assertSame('0.00', bcadd($cash['source_verified']['closing'], $bank['source_verified']['closing'], 2));
        $this->assertSame($cash['rows'][0]['journal_id'], $bank['rows'][0]['journal_id']);
        $this->assertSame('cash_transfer_sent', $cash['rows'][0]['event']);
        $this->assertSame('cash_transfer_received', $bank['rows'][0]['event']);
    }

    public function test_reverse_direction_credits_bank_and_debits_cash_without_income_or_expense(): void
    {
        $this->commit($this->approved(['source_cash_account_id' => (int) $this->bank->getKey(), 'destination_cash_account_id' => (int) $this->cash->getKey()]));
        $this->assertSame('100.00', $this->book($this->cash)['source_verified']['closing']);
        $this->assertSame('-100.00', $this->book($this->bank)['source_verified']['closing']);
        $ids = JournalEntryLine::query()->pluck('account_id')->map(static fn ($id): int => (int) $id)->all();
        sort($ids);
        $expected = [(int) $this->cash->getAttribute('account_id'), (int) $this->bank->getAttribute('account_id')];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function test_exact_cents_survive_posting_and_book_projection(): void
    {
        $this->commit($this->approved(['amount' => '0.01']));
        $this->assertSame('0.01', $this->book($this->bank)['source_verified']['closing']);
    }

    public function test_same_request_replays_without_any_new_writes(): void
    {
        $row = $this->commit($this->approved());
        $before = $this->stateDigest();
        $again = $this->commit($row);
        $this->assertSame($row->getKey(), $again->getKey());
        $this->assertSame($before, $this->stateDigest());
    }

    public function test_different_key_does_not_repost(): void
    {
        $row = $this->commit($this->approved());
        $this->rejected(fn () => $this->commit($row, 'different-transfer-key-002'));
    }

    public function test_different_request_revision_does_not_replay(): void
    {
        $row = $this->commit($this->approved());
        $this->rejected(fn () => $this->commit($row, revision: 4));
    }

    public function test_another_authorized_actor_cannot_claim_original_post_request(): void
    {
        $row = $this->commit($this->approved());
        $this->rejected(fn () => $this->commit($row, actor: $this->reviewer));
    }

    public function test_replay_survives_disabled_accounting_closed_period_and_inactive_bindings(): void
    {
        $row = $this->commit($this->approved());
        $this->settings(['state' => 'disabled', 'cutover_locked_at' => now()]);
        $this->closePeriod();
        $this->cash->update(['status' => 'inactive']);
        $this->bank->update(['status' => 'inactive']);
        $before = $this->stateDigest();
        $this->commit($row);
        $this->assertSame($before, $this->stateDigest());
        $this->assertTrue($this->book($this->cash)['complete']);
        $this->assertTrue($this->book($this->bank)['complete']);
    }

    public function test_post_permission_alone_is_sufficient_for_scoped_current_date_post(): void
    {
        $row = $this->approved();
        $user = $this->staff(['erp.cash-transfers.post']);
        $this->assertFalse($user->can('erp.journal-entries.create'));
        $this->assertFalse($user->can('erp.journal-entries.post'));
        $this->assertSame('posted', $this->commit($row, actor: $user)->getAttribute('status'));
    }

    public function test_view_permission_cannot_post(): void
    {
        $row = $this->approved();
        $user = $this->staff(['erp.cash-transfers.view']);
        $this->rejected(fn () => $this->commit($row, actor: $user));
    }

    public function test_foreign_company_post_actor_is_rejected(): void
    {
        $row = $this->approved();
        $user = $this->staff(['erp.cash-transfers.post']);
        $foreign = $this->newCompany('CASH-FOREIGN');
        DB::table('employees')->where('user_id', $user->getKey())->update(['company_id' => $foreign->getKey()]);
        $this->rejected(fn () => $this->commit($row, actor: $user));
    }

    public function test_draft_and_submitted_transfers_cannot_post(): void
    {
        $row = $this->workflow()->save($this->actor, $this->transferInput());
        $this->rejected(fn () => $this->commit($row, revision: 1));
        $row = $this->workflow()->submit($this->actor, (int) $row->getKey(), 1);
        $this->rejected(fn () => $this->commit($row, revision: 2));
    }

    public function test_cancelled_transfer_cannot_post(): void
    {
        $row = $this->approved();
        $row = $this->workflow()->cancel($this->actor, (int) $row->getKey(), 3, 'Not required');
        $this->rejected(fn () => $this->commit($row, revision: 4));
    }

    public function test_stale_approved_revision_cannot_post(): void
    {
        $row = $this->approved();
        $this->rejected(fn () => $this->commit($row, revision: 2));
    }

    public function test_new_post_requires_enabled_accounting(): void
    {
        $row = $this->approved();
        foreach (['disabled', 'ready'] as $state) {
            $this->settings(['state' => $state]);
            $this->rejected(fn () => $this->commit($row));
        }
    }

    public function test_new_post_requires_activated_cutover_evidence(): void
    {
        $row = $this->approved();
        $this->settings(['activated_at' => null]);
        $this->rejected(fn () => $this->commit($row));
    }

    public function test_cutover_lock_blocks_new_post_without_native_transition(): void
    {
        $row = $this->approved();
        $this->settings(['cutover_locked_at' => now()]);
        $this->rejected(fn () => $this->commit($row));
    }

    public function test_closed_period_rolls_back_native_post_and_finance(): void
    {
        $row = $this->approved();
        $this->closePeriod();
        $this->rejected(fn () => $this->commit($row));
    }

    public function test_future_company_date_is_rejected_at_post_time(): void
    {
        $row = $this->approved();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'Asia/Dhaka'));
        $this->rejected(fn () => $this->commit($row));
    }

    public function test_backdated_post_requires_period_adjust_permission(): void
    {
        $row = $this->approved(['document_date' => '2026-10-15']);
        $user = $this->staff(['erp.cash-transfers.post']);
        $this->rejected(fn () => $this->commit($row, actor: $user));
    }

    public function test_inactive_source_and_destination_each_block_new_post(): void
    {
        $row = $this->approved();
        $this->cash->update(['status' => 'inactive']);
        $this->rejected(fn () => $this->commit($row));
        $this->cash->update(['status' => 'active']);
        $this->bank->update(['status' => 'inactive']);
        $this->rejected(fn () => $this->commit($row));
    }

    public function test_inactive_gl_blocks_new_post(): void
    {
        $row = $this->approved();
        DB::table('accounts')->where('id', $this->bank->getAttribute('account_id'))->update(['status' => 'inactive']);
        $this->rejected(fn () => $this->commit($row));
    }

    public function test_binding_currency_damage_is_rejected(): void
    {
        $row = $this->approved();
        DB::table('accounting_cash_accounts')->where('id', $this->bank->getKey())->update(['currency_code' => 'USD']);
        $this->rejected(fn () => $this->commit($row));
    }

    public function test_private_intent_damage_is_rejected_before_posting(): void
    {
        $row = $this->approved();
        DB::table('accounting_cash_transfers')->where('id', $row->getKey())->update(['notes' => 'Changed after approval']);
        $this->rejected(fn () => $this->commit($row));
    }

    public function test_changed_effective_mapping_requires_fresh_review(): void
    {
        $row = $this->approved();
        $previous = AccountingMappingVersion::query()->where('company_id', $this->company->getKey())->firstOrFail();
        $map = $this->mapping;
        [$map['cash:MAIN'], $map['bank:CURRENT']] = [$map['bank:CURRENT'], $map['cash:MAIN']];
        $this->publish(['base_version_id' => (int) $previous->getKey(), 'effective_from' => '2026-10-16', 'mappings' => $map]);
        $this->rejected(fn () => $this->commit($row));
    }

    public function test_missing_operation_is_not_recreated_by_replay_even_if_disabled(): void
    {
        $row = $this->commit($this->approved());
        DB::table('accounting_cash_transfer_operations')->delete();
        $this->settings(['state' => 'disabled']);
        $this->rejected(fn () => $this->commit($row));
    }

    public function test_corrupt_operation_hash_is_rejected_without_repair(): void
    {
        $row = $this->commit($this->approved());
        DB::table('accounting_cash_transfer_operations')->update(['payload_hash' => str_repeat('0', 64)]);
        $this->rejected(fn () => $this->commit($row));
    }

    public function test_missing_effect_is_not_reposted(): void
    {
        $row = $this->commit($this->approved());
        DB::table('accounting_cash_transfer_operations')->delete();
        DB::table('accounting_source_effects')->where('source_kind', 'cash_transfer')->delete();
        $this->rejected(fn () => $this->commit($row));
    }

    public function test_deleted_cash_line_damages_both_books_and_replay(): void
    {
        $row = $this->commit($this->approved());
        JournalEntryLine::query()->where('cash_account_id', $this->cash->getKey())->toBase()->delete();
        $this->rejected(fn () => $this->commit($row));
        $this->assertFalse($this->book($this->cash)['complete']);
        $this->assertFalse($this->book($this->bank)['complete']);
    }

    public function test_deleted_both_lines_cannot_produce_clean_empty_books(): void
    {
        $this->commit($this->approved());
        DB::table('journal_entry_lines')->delete();
        foreach ([$this->cash, $this->bank] as $binding) {
            $book = $this->book($binding);
            $this->assertSame([], $book['rows']);
            $this->assertFalse($book['complete']);
            $this->assertContains('native_cash_transfer_evidence_review', array_column($book['exceptions'], 'code'));
        }
    }

    public function test_posted_header_and_operation_cannot_be_changed_or_deleted(): void
    {
        $row = $this->commit($this->approved());
        $this->rejected(fn () => DB::transaction(fn () => $row->forceFill(['revision' => 5, 'notes' => 'Changed'])->save()));
        $op = AccountingCashTransferOperation::query()->firstOrFail();
        $this->rejected(fn () => $op->update(['key_hash' => str_repeat('0', 64)]));
        $this->rejected(fn () => $op->delete());
        $this->rejected(fn () => $this->workflow()->cancel($this->actor, (int) $row->getKey(), 4, 'Invalid cancel'));
    }

    public function test_operation_failure_rolls_back_journal_number_native_post_and_audits(): void
    {
        $row = $this->approved();
        $before = $this->stateDigest();
        $breakOperation = true;
        Event::listen('eloquent.creating: '.AccountingCashTransferOperation::class, function () use (&$breakOperation): void {
            if ($breakOperation) {
                throw new \RuntimeException('Synthetic transfer operation failure');
            }
        });
        try {
            $this->commit($row);
            $this->fail('Expected synthetic failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic transfer operation failure', $exception->getMessage());
        } finally {
            $breakOperation = false;
        }
        $this->assertSame($before, $this->stateDigest());
        $this->assertSame('posted', $this->commit($row)->getAttribute('status'));
    }

    public function test_readers_and_exports_do_not_write_or_repost(): void
    {
        $row = $this->commit($this->approved());
        $before = $this->stateDigest();
        $this->proof($row);
        $this->book($this->cash);
        $this->book($this->bank);
        app(AccountingCashBookReader::class)->bookExport($this->actor, (int) $this->company->getKey(), (int) $this->bank->getKey());
        $this->assertSame($before, $this->stateDigest());
    }

    public function test_private_context_and_request_key_are_not_copied_to_financial_or_book_data(): void
    {
        $this->commit($this->approved());
        $json = json_encode([DB::table('accounting_source_effects')->get(), DB::table('journal_entries')->get(), DB::table('journal_entry_lines')->get(), $this->book($this->cash)], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PRIVATE-TRANSFER', $json);
        $this->assertStringNotContainsString('transfer-post-key-0001', $json);
    }

    public function test_book_viewer_does_not_need_transfer_or_journal_permission(): void
    {
        $this->commit($this->approved());
        $viewer = $this->staff(['erp.cash-books.view']);
        $book = $this->book($this->bank, actor: $viewer);
        $this->assertTrue($book['complete']);
        $this->assertNull($book['rows'][0]['source_url']);
        $this->assertNull($book['rows'][0]['journal_url']);
    }

    public function test_dated_opening_and_cutoff_preserve_transfer_balance(): void
    {
        $this->commit($this->approved());
        $before = $this->book($this->bank, ['to' => '2026-10-15']);
        $this->assertSame('0.00', $before['source_verified']['closing']);
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $after = $this->book($this->bank, ['from' => '2026-10-17']);
        $this->assertSame('100.00', $after['source_verified']['opening']);
        $this->assertSame('0.00', $after['source_verified']['debits']);
        $this->assertSame([], $after['rows']);
    }

    public function test_two_transfers_paginate_without_duplicating_or_narrowing_totals(): void
    {
        $this->commit($this->approved());
        $this->commit($this->approved(['amount' => '25.25']), 'transfer-post-key-0002');
        $a = $this->book($this->bank, ['per_page' => 1]);
        $b = $this->book($this->bank, ['per_page' => 1, 'page' => 2]);
        $this->assertSame('125.25', $a['source_verified']['closing']);
        $this->assertSame($a['source_verified'], $b['source_verified']);
        $this->assertNotSame($a['rows'][0]['journal_line_id'], $b['rows'][0]['journal_line_id']);
    }

    public function test_creation_retry_returns_posted_record_only_after_complete_finance_verifies(): void
    {
        $data = $this->transferInput();
        $row = $this->workflow()->save($this->actor, $data);
        $row = $this->workflow()->submit($this->actor, (int) $row->getKey(), 1);
        $row = $this->workflow()->approve($this->reviewer, (int) $row->getKey(), 2);
        $this->commit($row);
        $before = $this->stateDigest();
        $this->assertSame('posted', $this->workflow()->save($this->actor, $data)->getAttribute('status'));
        $this->assertSame($before, $this->stateDigest());
        DB::table('accounting_cash_transfer_operations')->delete();
        $this->rejected(fn () => $this->workflow()->save($this->actor, $data));
    }

    public function test_source_reconciliation_verifies_native_transfer_without_purchase_or_sales_admission(): void
    {
        $row = $this->commit($this->approved());
        $before = $this->stateDigest();
        $page = app(AccountingSourceReconciliation::class)->page($this->actor, (int) $this->company->getKey(), ['kind' => 'cash_transfer']);
        $this->assertCount(1, $page['rows']);
        $this->assertSame('verified_live', $page['rows'][0]['status']);
        $this->assertSame((int) $row->getKey(), $page['rows'][0]['source_id']);
        $this->assertSame(0, $page['rows'][0]['admission_count']);
        $this->assertSame($before, $this->stateDigest());
    }

    public function test_source_reconciliation_reports_missing_transfer_operation(): void
    {
        $this->commit($this->approved());
        DB::table('accounting_cash_transfer_operations')->delete();
        $before = $this->stateDigest();
        $page = app(AccountingSourceReconciliation::class)->page($this->actor, (int) $this->company->getKey(), ['kind' => 'cash_transfer']);
        $this->assertSame('integrity_error', $page['rows'][0]['status']);
        $this->assertSame($before, $this->stateDigest());
    }

    public function test_journal_state_damage_is_reviewed_and_never_reposted(): void
    {
        $row = $this->commit($this->approved());
        DB::table('journal_entries')->update(['status' => 'draft']);
        $this->rejected(fn () => $this->commit($row));
        $this->assertFalse($this->book($this->bank)['complete']);
    }

    public function test_posting_key_and_revision_validation_do_not_create_finance(): void
    {
        $row = $this->approved();
        $this->rejected(fn () => $this->commit($row, 'short'));
        $this->rejected(fn () => $this->commit($row, revision: 0));
    }

    public function test_migration_collision_is_reported_before_any_additional_ddl(): void
    {
        $files = glob(database_path('migrations/*add_cash_transfer_posting_foundation.php'));
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

    public function test_replay_verifies_retired_original_profile_after_later_cash_remap(): void
    {
        $row = $this->commit($this->approved());
        $previous = AccountingMappingVersion::query()->where('company_id', $this->company->getKey())->firstOrFail();
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $map = $this->mapping;
        [$map['cash:MAIN'], $map['bank:CURRENT']] = [$map['bank:CURRENT'], $map['cash:MAIN']];
        $this->publish(['base_version_id' => (int) $previous->getKey(), 'effective_from' => '2026-10-17', 'mappings' => $map]);
        $before = $this->stateDigest();
        $this->commit($row);
        $this->assertSame($before, $this->stateDigest());
        $this->assertTrue($this->book($this->bank)['complete']);
    }

    public function test_rehashed_operation_payload_still_must_match_native_operation(): void
    {
        $row = $this->commit($this->approved());
        $op = AccountingCashTransferOperation::query()->firstOrFail();
        $payload = $op->getAttribute('payload');
        $payload['revision'] = 99;
        DB::table('accounting_cash_transfer_operations')->where('id', $op->getKey())->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'payload_hash' => app(AccountingCanonicalPayload::class)->hash($payload)]);
        $this->rejected(fn () => $this->commit($row));
    }

    public function test_json_object_key_order_does_not_break_historical_evidence(): void
    {
        $row = $this->commit($this->approved());
        $op = AccountingCashTransferOperation::query()->firstOrFail();
        DB::table('accounting_cash_transfer_operations')->where('id', $op->getKey())->update(['payload' => json_encode(array_reverse($op->getAttribute('payload'), true), JSON_THROW_ON_ERROR)]);
        $effect = AccountingSourceEffect::query()->firstOrFail();
        DB::table('accounting_source_effects')->where('id', $effect->getKey())->update(['source_snapshot' => json_encode(array_reverse($effect->getAttribute('source_snapshot'), true), JSON_THROW_ON_ERROR)]);
        $before = $this->stateDigest();
        $this->commit($row);
        $this->assertSame($before, $this->stateDigest());
    }

    public function test_source_snapshot_damage_is_not_repaired_by_replay_or_books(): void
    {
        $row = $this->commit($this->approved());
        $effect = AccountingSourceEffect::query()->firstOrFail();
        $snapshot = $effect->getAttribute('source_snapshot');
        $snapshot['evidence_hash'] = str_repeat('0', 64);
        DB::table('accounting_source_effects')->where('id', $effect->getKey())->update(['source_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
        $this->rejected(fn () => $this->commit($row));
        $this->assertFalse($this->book($this->bank)['complete']);
    }

    public function test_final_native_audit_failure_rolls_back_the_complete_operation(): void
    {
        $row = $this->approved();
        $before = $this->stateDigest();
        $breakAudit = true;
        Event::listen('eloquent.creating: '.AccountingCashTransferAuditEvent::class, function ($event) use (&$breakAudit): void {
            if ($breakAudit && $event->getAttribute('action') === 'posted') {
                throw new \RuntimeException('Synthetic final audit failure');
            }
        });
        try {
            $this->commit($row);
            $this->fail('Expected final audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic final audit failure', $exception->getMessage());
        } finally {
            $breakAudit = false;
        }
        $this->assertSame($before, $this->stateDigest());
        $this->assertSame('posted', $this->commit($row)->getAttribute('status'));
    }

    private function additionalBinding(string $role): AccountingCashAccount
    {
        $group = AccountGroup::query()->where('company_id', $this->company->getKey())->where('category', 'asset')->firstOrFail();
        $account = Account::query()->create(['company_id' => $this->company->getKey(), 'account_group_id' => $group->getKey(), 'code' => '9900', 'name' => $role, 'normal_side' => 'debit', 'is_postable' => true, 'control_role' => null, 'statement_role' => 'current_asset', 'cash_flow_role' => 'cash', 'status' => 'active', 'revision' => 1]);
        $previous = AccountingMappingVersion::query()->where('company_id', $this->company->getKey())->firstOrFail();
        $map = [...$this->mapping, $role => (int) $account->getKey()];
        $this->publish(['base_version_id' => (int) $previous->getKey(), 'effective_from' => '2026-10-15', 'mappings' => $map]);
        app(AccountingCashAccountService::class)->initialize($this->actor, (int) $this->company->getKey(), 'Additional transfer binding');

        return AccountingCashAccount::query()->where('company_id', $this->company->getKey())->where('role', $role)->firstOrFail();
    }

    public function test_bank_to_bank_uses_the_same_two_leg_contract(): void
    {
        $second = $this->additionalBinding('bank:SECOND');
        $this->commit($this->approved(['source_cash_account_id' => (int) $this->bank->getKey(), 'destination_cash_account_id' => (int) $second->getKey()]));
        $this->assertSame('-100.00', $this->book($this->bank)['source_verified']['closing']);
        $this->assertSame('100.00', $this->book($second)['source_verified']['closing']);
        $this->assertSame('0.00', $this->book($this->cash)['source_verified']['closing']);
    }

    public function test_cash_to_cash_uses_the_same_two_leg_contract(): void
    {
        $second = $this->additionalBinding('cash:SECOND');
        $this->commit($this->approved(['destination_cash_account_id' => (int) $second->getKey()]));
        $this->assertSame('-100.00', $this->book($this->cash)['source_verified']['closing']);
        $this->assertSame('100.00', $this->book($second)['source_verified']['closing']);
    }

    public function test_reloaded_fractional_draft_keeps_exact_amount_through_review_and_post(): void
    {
        $row = $this->workflow()->save($this->actor, $this->transferInput(['amount' => '25.25']));
        $row = $row->fresh();
        $this->assertSame('25.25', $row->getAttribute('amount'));
        $row = $this->workflow()->submit($this->actor, (int) $row->getKey(), 1);
        $row = $row->fresh();
        $row = $this->workflow()->approve($this->reviewer, (int) $row->getKey(), 2);
        $row = $this->commit($row->fresh());
        $this->assertSame('25.25', $row->fresh()->getAttribute('amount'));
        $this->assertSame('25.25', $this->book($this->bank)['source_verified']['closing']);
    }

    public function test_model_guard_still_rejects_a_new_float_amount_on_an_existing_draft(): void
    {
        $row = $this->workflow()->save($this->actor, $this->transferInput());
        $this->rejected(fn () => DB::transaction(fn () => $row->forceFill(['amount' => 25.25, 'revision' => 2])->save()));
    }

    public function test_model_guard_does_not_hide_excess_decimals_behind_cast_equivalence(): void
    {
        $row = $this->workflow()->save($this->actor, $this->transferInput());
        $this->rejected(fn () => DB::transaction(fn () => $row->forceFill(['amount' => '100.000', 'revision' => 2])->save()));
    }
}

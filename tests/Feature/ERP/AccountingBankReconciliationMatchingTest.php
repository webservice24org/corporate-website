<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingBankReconciliation;
use App\Models\Accounting\AccountingBankReconciliationLeg;
use App\Models\Accounting\AccountingBankReconciliationOperation;
use App\Models\Accounting\AccountingBankStatement;
use App\Models\Accounting\AccountingBankStatementLine;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingCashTransfer;
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
use App\Services\ERP\Accounting\Reconciliation\AccountingBankReconciliationContract;
use App\Services\ERP\Accounting\Reconciliation\AccountingBankReconciliationService;
use App\Services\ERP\Accounting\Statements\AccountingBankStatementContract;
use App\Services\ERP\Accounting\Statements\AccountingBankStatementService;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferPosting;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferReversalEvidence;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferReversalPosting;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferReversalWorkflow;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferWorkflow;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingBankReconciliationPermissionSeeder;
use Database\Seeders\AccountingBankStatementPermissionSeeder;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AccountingBankReconciliationMatchingTest extends TestCase
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
        $this->seed(AccountingBankStatementPermissionSeeder::class);
        $this->seed(AccountingBankReconciliationPermissionSeeder::class);
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

    private function nativeDigest(): string
    {
        return hash('sha256', json_encode(['finance' => $this->financialDigest(), 'requests' => DB::table('accounting_cash_transfer_reversal_requests')->orderBy('id')->get()->toArray(), 'audit' => DB::table('accounting_cash_transfer_reversal_audit_events')->orderBy('id')->get()->toArray()], JSON_THROW_ON_ERROR));
    }

    private function nativeRejected(callable $action): void
    {
        $before = $this->nativeDigest();
        try {
            $action();
            $this->fail('Expected rejected reversal operation.');
        } catch (ValidationException|AuthorizationException|\Illuminate\Database\Eloquent\ModelNotFoundException|\LogicException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertSame($before, $this->nativeDigest());
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

    private function matcher(): AccountingBankReconciliationService
    {
        return app(AccountingBankReconciliationService::class);
    }

    /** @param list<array<string, mixed>>|null $rows @param array<string, mixed> $changes */
    private function statement(?array $rows = null, array $changes = []): AccountingBankStatement
    {
        $metadata = ['company_id' => (int) $this->company->getKey(), 'cash_account_id' => (int) $this->bank->getKey(), 'statement_reference' => 'REC-'.Str::uuid(), 'currency_code' => 'BDT', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-16', 'opening_balance' => '0.00', 'closing_balance' => '100.00', ...$changes];
        $rows ??= [$this->statementRow()];
        $payload = app(AccountingBankStatementContract::class)->normalize($metadata, $rows);

        return app(AccountingBankStatementService::class)->record($this->actor, 'manual', $metadata, $rows, null, (string) Str::uuid(), app(AccountingCanonicalPayload::class)->hash($payload));
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private function statementRow(array $changes = []): array
    {
        return ['date' => '2026-10-16', 'value_date' => '', 'transaction_id' => (string) Str::uuid(), 'reference' => 'PRIVATE-BANK-REF', 'description' => 'PRIVATE-BANK-TEXT', 'money_in' => '100.00', 'money_out' => '', 'balance' => '', ...$changes];
    }

    private function start(AccountingBankStatement $statement, ?User $actor = null, ?string $key = null): AccountingBankReconciliation
    {
        return $this->matcher()->open($actor ?? $this->actor, (int) $statement->getKey(), 'PRIVATE-RECON-REASON', $key ?? (string) Str::uuid());
    }

    /** @return array{AccountingBankReconciliation, AccountingCashTransfer, AccountingBankStatement, int, int} */
    private function fixture(): array
    {
        $transfer = $this->original();
        $statement = $this->statement();
        $root = $this->start($statement);
        $statementId = (int) AccountingBankStatementLine::query()->where('statement_id', $statement->getKey())->value('id');
        $bookId = (int) JournalEntryLine::query()->where('cash_account_id', $this->bank->getKey())->where('source_line_type', 'cash_transfer')->where('source_line_id', $transfer->getKey())->value('id');

        return [$root, $transfer, $statement, $statementId, $bookId];
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private function group(AccountingBankReconciliation $root, int $statementId, int $bookId, array $changes = []): array
    {
        return ['revision' => (int) $root->fresh()->getAttribute('revision'), 'key' => (string) Str::uuid(), 'reason' => 'PRIVATE-MATCH-REASON', 'statement_allocations' => [['id' => $statementId, 'amount' => '100.00']], 'book_allocations' => [['id' => $bookId, 'amount' => '100.00']], ...$changes];
    }

    private function undo(AccountingBankReconciliation $root, AccountingBankReconciliationOperation $match, ?string $key = null): AccountingBankReconciliationOperation
    {
        return $this->matcher()->unmatch($this->actor, (int) $root->getKey(), ['revision' => (int) $root->fresh()->getAttribute('revision'), 'key' => $key ?? (string) Str::uuid(), 'reason' => 'PRIVATE-UNDO-REASON', 'target_match_id' => (int) $match->getKey()]);
    }

    private function digest(): string
    {
        $data = ['native' => $this->nativeDigest()];
        foreach (['accounting_bank_statements', 'accounting_bank_statement_lines', 'accounting_bank_statement_evidence', 'accounting_bank_reconciliations', 'accounting_bank_reconciliation_operations', 'accounting_bank_reconciliation_legs'] as $table) {
            $data[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function untouched(): string
    {
        return hash('sha256', json_encode([$this->nativeDigest(), DB::table('accounting_bank_statements')->orderBy('id')->get(), DB::table('accounting_bank_statement_lines')->orderBy('id')->get(), DB::table('accounting_bank_statement_evidence')->orderBy('id')->get()], JSON_THROW_ON_ERROR));
    }

    private function rejected(callable $action): void
    {
        $before = $this->digest();
        try {
            $action();
            $this->fail('Expected rejected matching operation.');
        } catch (ValidationException|AuthorizationException|\Illuminate\Database\Eloquent\ModelNotFoundException|\LogicException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, $this->digest());
    }

    public function test_open_captures_one_verified_statement_and_initial_head(): void
    {
        [$root] = $this->fixture();
        $this->assertSame(1, $root->getAttribute('revision'));
        $this->assertNull($root->getAttribute('last_operation_id'));
        $this->assertSame($root->getAttribute('snapshot_hash'), $root->getAttribute('last_operation_hash'));
        $report = $this->matcher()->review($this->actor, (int) $root->getKey());
        $this->assertFalse($report['finalized']);
        $this->assertSame('100.00', $report['statement_rows'][0]['remaining']);
    }

    public function test_opening_replays_original_creation_key_without_writes(): void
    {
        $this->original();
        $statement = $this->statement();
        $key = (string) Str::uuid();
        $root = $this->start($statement, key: $key);
        $before = $this->digest();
        $again = $this->start($statement, key: $key);
        $this->assertSame($root->getKey(), $again->getKey());
        $this->assertSame($before, $this->digest());
    }

    public function test_second_creation_key_cannot_open_another_root_for_same_statement(): void
    {
        [, , $statement] = $this->fixture();
        $this->rejected(fn () => $this->start($statement));
    }

    public function test_full_match_links_two_legs_and_advances_head_without_financial_changes(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $before = $this->untouched();
        $op = $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl));
        $this->assertSame(2, $root->fresh()->getAttribute('revision'));
        $this->assertSame((int) $op->getKey(), (int) $root->fresh()->getAttribute('last_operation_id'));
        $this->assertDatabaseCount('accounting_bank_reconciliation_legs', 2);
        $r = $this->matcher()->review($this->actor, (int) $root->getKey());
        $this->assertSame('0.00', $r['statement_rows'][0]['remaining']);
        $this->assertSame('0.00', $r['book_rows'][0]['currently_available']);
        $this->assertSame($before, $this->untouched());
    }

    public function test_partial_match_preserves_exact_remaining_cents(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $input = $this->group($root, $sl, $bl, ['statement_allocations' => [['id' => $sl, 'amount' => '25.25']], 'book_allocations' => [['id' => $bl, 'amount' => '25.25']]]);
        $this->matcher()->match($this->actor, (int) $root->getKey(), $input);
        $r = $this->matcher()->review($this->actor, (int) $root->getKey());
        $this->assertSame('74.75', $r['statement_rows'][0]['remaining']);
        $this->assertSame('74.75', $r['book_rows'][0]['currently_available']);
    }

    public function test_one_book_entry_can_match_several_statement_rows_in_one_group(): void
    {
        $transfer = $this->original();
        $statement = $this->statement([$this->statementRow(['money_in' => '40.00']), $this->statementRow(['money_in' => '60.00'])]);
        $root = $this->start($statement);
        $ids = AccountingBankStatementLine::query()->where('statement_id', $statement->getKey())->orderBy('line_number')->pluck('id')->all();
        $book = (int) JournalEntryLine::query()->where('cash_account_id', $this->bank->getKey())->value('id');
        $input = $this->group($root, (int) $ids[0], $book, ['statement_allocations' => [['id' => (int) $ids[1], 'amount' => '60.00'], ['id' => (int) $ids[0], 'amount' => '40.00']]]);
        $this->matcher()->match($this->actor, (int) $root->getKey(), $input);
        $this->assertDatabaseCount('accounting_bank_reconciliation_legs', 3);
    }

    public function test_several_book_entries_can_match_one_statement_movement(): void
    {
        $this->original();
        $this->original();
        $statement = $this->statement([$this->statementRow(['money_in' => '200.00'])], ['closing_balance' => '200.00']);
        $root = $this->start($statement);
        $sl = (int) AccountingBankStatementLine::query()->where('statement_id', $statement->getKey())->value('id');
        $ids = JournalEntryLine::query()->where('cash_account_id', $this->bank->getKey())->orderBy('id')->pluck('id')->all();
        $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, (int) $ids[0], ['statement_allocations' => [['id' => $sl, 'amount' => '200.00']], 'book_allocations' => [['id' => (int) $ids[0], 'amount' => '100.00'], ['id' => (int) $ids[1], 'amount' => '100.00']]]));
        $this->assertDatabaseCount('accounting_bank_reconciliation_legs', 3);
    }

    public function test_zero_difference_is_always_provisional_until_separate_finalization(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl));
        $r = $this->matcher()->review($this->actor, (int) $root->getKey());
        $this->assertSame('0.00', $r['bridge']['statement_minus_book']);
        $this->assertSame('0.00', $r['bridge']['explained_difference']);
        $this->assertFalse($r['finalized']);
        $this->assertSame('working', $r['status']);
    }

    public function test_supplied_opening_balance_is_not_certified_as_gl_opening(): void
    {
        $this->original();
        $statement = $this->statement(changes: ['opening_balance' => '50.00', 'closing_balance' => '150.00']);
        $root = $this->start($statement);
        $r = $this->matcher()->review($this->actor, (int) $root->getKey());
        $this->assertSame('50.00', $r['bridge']['unverified_opening_prior_statement_residual']);
        $this->assertSame($r['bridge']['statement_minus_book'], $r['bridge']['explained_difference']);
    }

    public function test_matching_amounts_cannot_exceed_remaining_capacity(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl));
        $this->rejected(fn () => $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl)));
    }

    public function test_unequal_statement_and_book_totals_are_rejected(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $this->rejected(fn () => $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl, ['book_allocations' => [['id' => $bl, 'amount' => '99.00']]])));
    }

    public function test_cash_or_foreign_bank_line_cannot_be_matched_to_this_bank(): void
    {
        [$root, , , $sl] = $this->fixture();
        $cash = (int) JournalEntryLine::query()->where('cash_account_id', $this->cash->getKey())->value('id');
        $this->rejected(fn () => $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $cash)));
    }

    public function test_duplicate_selection_zero_float_and_excess_decimals_are_rejected(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        foreach ([0, 0.01, '0.00', '1.001', '-1.00', '1e2'] as $bad) {
            $this->rejected(fn () => $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl, ['statement_allocations' => [['id' => $sl, 'amount' => $bad]]])));
        } $this->rejected(fn () => $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl, ['statement_allocations' => [['id' => $sl, 'amount' => '50.00'], ['id' => $sl, 'amount' => '50.00']]])));
    }

    public function test_stale_revision_and_extra_fields_cannot_change_matching(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $this->rejected(fn () => $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl, ['revision' => 99])));
        $this->rejected(fn () => $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl, ['journal_entry_id' => 1])));
    }

    public function test_same_match_key_replays_without_new_legs_or_head_changes(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $input = $this->group($root, $sl, $bl);
        $op = $this->matcher()->match($this->actor, (int) $root->getKey(), $input);
        $before = $this->digest();
        $again = $this->matcher()->match($this->actor, (int) $root->getKey(), $input);
        $this->assertSame($op->getKey(), $again->getKey());
        $this->assertSame($before, $this->digest());
    }

    public function test_same_key_with_changed_reason_amount_or_actor_is_rejected(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $input = $this->group($root, $sl, $bl);
        $this->matcher()->match($this->actor, (int) $root->getKey(), $input);
        $this->rejected(fn () => $this->matcher()->match($this->actor, (int) $root->getKey(), [...$input, 'reason' => 'Changed']));
        $this->rejected(fn () => $this->matcher()->match($this->reviewer, (int) $root->getKey(), $input));
    }

    public function test_whole_group_undo_restores_capacity_without_deleting_original(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $before = $this->untouched();
        $op = $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl));
        $undo = $this->undo($root, $op);
        $r = $this->matcher()->review($this->actor, (int) $root->getKey());
        $this->assertSame('100.00', $r['statement_rows'][0]['remaining']);
        $this->assertSame('100.00', $r['book_rows'][0]['currently_available']);
        $this->assertDatabaseCount('accounting_bank_reconciliation_operations', 2);
        $this->assertDatabaseCount('accounting_bank_reconciliation_legs', 4);
        $this->assertSame((int) $op->getKey(), (int) $undo->getAttribute('target_match_id'));
        $this->assertSame($before, $this->untouched());
    }

    public function test_undo_request_replay_does_not_restore_capacity_twice(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $op = $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl));
        $input = ['revision' => 2, 'key' => (string) Str::uuid(), 'reason' => 'Undo', 'target_match_id' => (int) $op->getKey()];
        $undo = $this->matcher()->unmatch($this->actor, (int) $root->getKey(), $input);
        $before = $this->digest();
        $again = $this->matcher()->unmatch($this->actor, (int) $root->getKey(), $input);
        $this->assertSame($undo->getKey(), $again->getKey());
        $this->assertSame($before, $this->digest());
    }

    public function test_original_match_can_replay_after_undo_without_reactivating_it(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $input = $this->group($root, $sl, $bl);
        $op = $this->matcher()->match($this->actor, (int) $root->getKey(), $input);
        $this->undo($root, $op);
        $before = $this->digest();
        $this->matcher()->match($this->actor, (int) $root->getKey(), $input);
        $this->assertSame($before, $this->digest());
        $r = $this->matcher()->review($this->actor, (int) $root->getKey());
        $this->assertSame('100.00', $r['statement_rows'][0]['remaining']);
    }

    public function test_a_match_group_can_be_undone_only_once(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $op = $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl));
        $this->undo($root, $op);
        $this->rejected(fn () => $this->undo($root, $op));
    }

    public function test_match_again_after_undo_uses_new_operation_and_current_revision(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $op = $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl));
        $this->undo($root, $op);
        $new = $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl));
        $this->assertNotSame($op->getKey(), $new->getKey());
        $this->assertSame(4, $root->fresh()->getAttribute('revision'));
    }

    public function test_view_only_user_has_no_match_or_undo_authority(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $user = $this->staff(['erp.bank-reconciliations.view', 'erp.bank-statements.view', 'erp.cash-books.view']);
        $this->matcher()->review($user, (int) $root->getKey());
        $this->rejected(fn () => $this->matcher()->match($user, (int) $root->getKey(), $this->group($root, $sl, $bl)));
    }

    public function test_native_match_and_undo_permissions_are_separate(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $user = $this->staff(['erp.bank-reconciliations.view', 'erp.bank-reconciliations.match', 'erp.bank-statements.view', 'erp.cash-books.view']);
        $op = $this->matcher()->match($user, (int) $root->getKey(), $this->group($root, $sl, $bl));
        $this->rejected(fn () => $this->matcher()->unmatch($user, (int) $root->getKey(), ['revision' => 2, 'key' => (string) Str::uuid(), 'reason' => 'Undo', 'target_match_id' => (int) $op->getKey()]));
    }

    public function test_bankbook_and_statement_view_dependencies_are_required(): void
    {
        [$root] = $this->fixture();
        foreach ([['erp.bank-reconciliations.view', 'erp.cash-books.view'], ['erp.bank-reconciliations.view', 'erp.bank-statements.view']] as $permissions) {
            $user = $this->staff($permissions);
            $this->rejected(fn () => $this->matcher()->review($user, (int) $root->getKey()));
        }
    }

    public function test_matching_needs_no_manual_journal_transfer_or_export_permission(): void
    {
        $this->original();
        $statement = $this->statement();
        $user = $this->staff(['erp.bank-reconciliations.view', 'erp.bank-reconciliations.create', 'erp.bank-reconciliations.match', 'erp.bank-statements.view', 'erp.cash-books.view']);
        $root = $this->start($statement, $user);
        $sl = (int) AccountingBankStatementLine::query()->where('statement_id', $statement->getKey())->value('id');
        $bl = (int) JournalEntryLine::query()->where('cash_account_id', $this->bank->getKey())->value('id');
        $this->matcher()->match($user, (int) $root->getKey(), $this->group($root, $sl, $bl));
        $this->assertFalse($user->can('erp.cash-books.export'));
    }

    public function test_public_bankbook_export_gate_is_preserved(): void
    {
        [$root] = $this->fixture();
        $user = $this->staff(['erp.bank-reconciliations.view', 'erp.bank-statements.view', 'erp.cash-books.view']);
        $this->rejected(fn () => app(AccountingCashBookReader::class)->bookExport($user, (int) $this->company->getKey(), (int) $this->bank->getKey()));
    }

    public function test_foreign_root_and_statement_are_not_accessible(): void
    {
        [$root, , $statement] = $this->fixture();
        $user = $this->staff(['erp.bank-reconciliations.view', 'erp.bank-reconciliations.create', 'erp.bank-statements.view', 'erp.cash-books.view']);
        $other = $this->newCompany('REC-FOREIGN');
        DB::table('employees')->where('user_id', $user->getKey())->update(['company_id' => $other->getKey()]);
        $this->rejected(fn () => $this->matcher()->review($user, (int) $root->getKey()));
        $this->rejected(fn () => $this->start($statement, $user));
    }

    public function test_historical_reads_and_replays_survive_disabled_settings_closed_period_and_inactive_binding(): void
    {
        [$root, , $statement, $sl, $bl] = $this->fixture();
        $input = $this->group($root, $sl, $bl);
        $op = $this->matcher()->match($this->actor, (int) $root->getKey(), $input);
        $this->settings(['state' => 'disabled']);
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->update(['state' => 'hard_closed']);
        $this->bank->update(['status' => 'inactive']);
        $this->company->update(['status' => 'inactive']);
        $before = $this->digest();
        $this->matcher()->review($this->actor, (int) $root->getKey());
        $this->matcher()->match($this->actor, (int) $root->getKey(), $input);
        $this->assertSame($before, $this->digest());
    }

    public function test_new_matching_work_for_inactive_company_is_blocked(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $this->company->update(['status' => 'inactive']);
        $this->rejected(fn () => $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl)));
    }

    public function test_missing_relational_leg_cannot_be_repaired_by_replay_or_read(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $input = $this->group($root, $sl, $bl);
        $op = $this->matcher()->match($this->actor, (int) $root->getKey(), $input);
        DB::table('accounting_bank_reconciliation_legs')->where('operation_id', $op->getKey())->where('side', 'book')->delete();
        $this->rejected(fn () => $this->matcher()->match($this->actor, (int) $root->getKey(), $input));
        $this->rejected(fn () => $this->matcher()->review($this->actor, (int) $root->getKey()));
    }

    public function test_damaged_operation_or_history_head_rejects_new_matches(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl));
        DB::table('accounting_bank_reconciliations')->where('id', $root->getKey())->update(['last_operation_hash' => str_repeat('f', 64)]);
        $this->rejected(fn () => $this->matcher()->review($this->actor, (int) $root->getKey()));
    }

    public function test_missing_tail_operation_is_detected_by_protected_revision_and_head(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $op = $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl));
        DB::table('accounting_bank_reconciliations')->where('id', $root->getKey())->update(['last_operation_id' => null]);
        DB::table('accounting_bank_reconciliation_legs')->where('operation_id', $op->getKey())->delete();
        DB::table('accounting_bank_reconciliation_operations')->where('id', $op->getKey())->delete();
        $this->rejected(fn () => $this->matcher()->review($this->actor, (int) $root->getKey()));
    }

    public function test_changed_statement_or_native_financial_evidence_blocks_read_and_matching(): void
    {
        [$root, $transfer, , $sl, $bl] = $this->fixture();
        DB::table('accounting_cash_transfer_operations')->where('cash_transfer_id', $transfer->getKey())->delete();
        $this->rejected(fn () => $this->matcher()->review($this->actor, (int) $root->getKey()));
        $this->rejected(fn () => $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl)));
    }

    public function test_root_identity_and_operations_and_legs_are_not_editable_or_deletable(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $op = $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl));
        $leg = AccountingBankReconciliationLeg::query()->firstOrFail();
        $this->rejected(fn () => $root->fresh()->update(['reason' => 'Changed']));
        $this->rejected(fn () => $root->delete());
        $this->rejected(fn () => $op->update(['reason' => 'Changed']));
        $this->rejected(fn () => $op->delete());
        $this->rejected(fn () => $leg->update(['amount' => '1.00']));
        $this->rejected(fn () => $leg->delete());
    }

    public function test_failure_on_second_leg_rolls_back_operation_and_head(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $before = $this->digest();
        $count = 0;
        AccountingBankReconciliationLeg::creating(static function () use (&$count): void {
            if (++$count === 2) {
                throw new \RuntimeException('Synthetic matching leg failure');
            }
        });
        try {
            $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl));
            $this->fail('Expected failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic matching leg failure', $e->getMessage());
        } finally {
            AccountingBankReconciliationLeg::flushEventListeners();
        } $this->assertSame($before, $this->digest());
    }

    public function test_failure_on_head_update_rolls_back_complete_operation(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $before = $this->digest();
        AccountingBankReconciliation::updating(static function (): void {
            throw new \RuntimeException('Synthetic head failure');
        });
        try {
            $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl));
            $this->fail('Expected failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic head failure', $e->getMessage());
        } finally {
            AccountingBankReconciliation::flushEventListeners();
        } $this->assertSame($before, $this->digest());
    }

    public function test_reordered_payload_object_keys_do_not_change_verified_evidence(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $op = $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl));
        DB::table('accounting_bank_reconciliation_operations')->where('id', $op->getKey())->update(['payload' => json_encode(array_reverse($op->getAttribute('payload'), true), JSON_THROW_ON_ERROR)]);
        $report = $this->matcher()->review($this->actor, (int) $root->getKey());
        $this->assertSame(2, $report['revision']);
        $this->assertSame('0.00', $report['statement_rows'][0]['remaining']);
    }

    public function test_matching_proof_does_not_copy_private_statement_or_transfer_text(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $op = $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl));
        $body = json_encode($op->getAttribute('payload'), JSON_THROW_ON_ERROR);
        foreach (['PRIVATE-BANK-REF', 'PRIVATE-BANK-TEXT', 'PRIVATE-ORIGINAL-PURPOSE', 'PRIVATE-ORIGINAL-NOTES', 'PRIVATE-MATCH-REASON'] as $private) {
            $this->assertStringNotContainsString($private, $body);
        } $r = $this->matcher()->review($this->actor, (int) $root->getKey());
        $this->assertArrayNotHasKey('proof_hash', $r['statement_rows'][0]);
        $this->assertArrayNotHasKey('payload_hash', $r['history'][0]);
    }

    public function test_seeder_is_idempotent_and_only_foundation_permissions_exist(): void
    {
        $this->seed(AccountingBankReconciliationPermissionSeeder::class);
        $this->seed(AccountingBankReconciliationPermissionSeeder::class);
        $this->assertSame(4, Permission::query()->whereIn('name', AccountingBankReconciliationContract::PERMISSIONS)->count());
        $this->assertNotContains('erp.bank-reconciliations.finalize', AccountingBankReconciliationContract::PERMISSIONS);
    }

    public function test_migration_collision_is_detected_before_ddl(): void
    {
        $files = glob(database_path('migrations/*create_bank_reconciliation_matching_foundation.php'));
        $this->assertIsArray($files);
        $this->assertCount(1, $files);
        $migration = require $files[0];
        $before = $this->digest();
        try {
            $migration->up();
            $this->fail('Expected collision.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No DDL was attempted', $e->getMessage());
        } $this->assertSame($before, $this->digest());
    }

    public function test_money_out_matches_only_money_out(): void
    {
        $this->original(['source_cash_account_id' => (int) $this->bank->getKey(), 'destination_cash_account_id' => (int) $this->cash->getKey()]);
        $statement = $this->statement([$this->statementRow(['money_in' => '', 'money_out' => '100.00'])], ['opening_balance' => '100.00', 'closing_balance' => '0.00']);
        $root = $this->start($statement);
        $sl = (int) AccountingBankStatementLine::query()->where('statement_id', $statement->getKey())->value('id');
        $bl = (int) JournalEntryLine::query()->where('cash_account_id', $this->bank->getKey())->value('id');
        $op = $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl));
        $this->assertSame('out', $op->getAttribute('direction'));
    }

    public function test_opposite_direction_cannot_be_matched_even_when_amounts_agree(): void
    {
        $this->original(['source_cash_account_id' => (int) $this->bank->getKey(), 'destination_cash_account_id' => (int) $this->cash->getKey()]);
        $statement = $this->statement();
        $root = $this->start($statement);
        $sl = (int) AccountingBankStatementLine::query()->where('statement_id', $statement->getKey())->value('id');
        $bl = (int) JournalEntryLine::query()->where('cash_account_id', $this->bank->getKey())->value('id');
        $this->rejected(fn () => $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl)));
    }

    public function test_netting_bank_charges_against_incoming_book_cash_is_rejected(): void
    {
        $this->original();
        $this->original(['amount' => '20.00', 'source_cash_account_id' => (int) $this->bank->getKey(), 'destination_cash_account_id' => (int) $this->cash->getKey()]);
        $statement = $this->statement([$this->statementRow(['money_in' => '80.00'])], ['closing_balance' => '80.00']);
        $root = $this->start($statement);
        $sl = (int) AccountingBankStatementLine::query()->where('statement_id', $statement->getKey())->value('id');
        $ids = JournalEntryLine::query()->where('cash_account_id', $this->bank->getKey())->orderBy('id')->pluck('id')->all();
        $input = $this->group($root, $sl, (int) $ids[0], ['statement_allocations' => [['id' => $sl, 'amount' => '80.00']], 'book_allocations' => [['id' => (int) $ids[0], 'amount' => '100.00'], ['id' => (int) $ids[1], 'amount' => '20.00']]]);
        $this->rejected(fn () => $this->matcher()->match($this->actor, (int) $root->getKey(), $input));
    }

    public function test_book_capacity_is_shared_across_statement_periods_and_future_allocations_are_explicit(): void
    {
        $this->original();
        $first = $this->statement([$this->statementRow(['money_in' => '40.00'])], ['closing_balance' => '40.00']);
        $r1 = $this->start($first);
        $sl1 = (int) AccountingBankStatementLine::query()->where('statement_id', $first->getKey())->value('id');
        $bl = (int) JournalEntryLine::query()->where('cash_account_id', $this->bank->getKey())->value('id');
        $this->matcher()->match($this->actor, (int) $r1->getKey(), $this->group($r1, $sl1, $bl, ['statement_allocations' => [['id' => $sl1, 'amount' => '40.00']], 'book_allocations' => [['id' => $bl, 'amount' => '40.00']]]));
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $second = $this->statement([$this->statementRow(['date' => '2026-10-17', 'money_in' => '60.00'])], ['starts_on' => '2026-10-17', 'ends_on' => '2026-10-17', 'opening_balance' => '40.00', 'closing_balance' => '100.00']);
        $r2 = $this->start($second);
        $sl2 = (int) AccountingBankStatementLine::query()->where('statement_id', $second->getKey())->value('id');
        $input = $this->group($r2, $sl2, $bl, ['statement_allocations' => [['id' => $sl2, 'amount' => '60.00']], 'book_allocations' => [['id' => $bl, 'amount' => '60.00']]]);
        $this->matcher()->match($this->actor, (int) $r2->getKey(), $input);
        $earlier = $this->matcher()->review($this->actor, (int) $r1->getKey());
        $this->assertSame('60.00', $earlier['book_rows'][0]['outstanding_through_cutoff']);
        $this->assertSame('0.00', $earlier['book_rows'][0]['currently_available']);
        $this->assertSame('60.00', $earlier['book_rows'][0]['allocated_to_later_statements']);
        $later = $this->matcher()->review($this->actor, (int) $r2->getKey());
        $this->assertSame('0.00', $later['bridge']['unverified_opening_prior_statement_residual']);
        $this->assertSame('0.00', $later['bridge']['statement_minus_book']);
        $this->rejected(fn () => $this->matcher()->match($this->actor, (int) $r2->getKey(), $this->group($r2, $sl2, $bl)));
    }

    public function test_other_statement_rows_and_other_reconciliation_undo_targets_are_rejected(): void
    {
        [$r1, , , $sl1, $bl] = $this->fixture();
        $op = $this->matcher()->match($this->actor, (int) $r1->getKey(), $this->group($r1, $sl1, $bl));
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $second = $this->statement([$this->statementRow(['date' => '2026-10-17', 'money_in' => '1.00'])], ['starts_on' => '2026-10-17', 'ends_on' => '2026-10-17', 'opening_balance' => '100.00', 'closing_balance' => '101.00']);
        $r2 = $this->start($second);
        $this->rejected(fn () => $this->matcher()->match($this->actor, (int) $r2->getKey(), $this->group($r2, $sl1, $bl)));
        $this->rejected(fn () => $this->undo($r2, $op));
    }

    public function test_later_book_journal_cannot_match_an_earlier_statement_cutoff(): void
    {
        [$r1, , , $sl1, $bl1] = $this->fixture();
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $transfer = $this->original(['document_date' => '2026-10-17']);
        $bl2 = (int) JournalEntryLine::query()->where('cash_account_id', $this->bank->getKey())->where('source_line_id', $transfer->getKey())->where('source_line_type', 'cash_transfer')->value('id');
        $statement = $this->statement([], ['starts_on' => '2026-10-17', 'ends_on' => '2026-10-17', 'opening_balance' => '100.00', 'closing_balance' => '100.00']);
        $this->start($statement);
        $this->rejected(fn () => $this->matcher()->match($this->actor, (int) $r1->getKey(), $this->group($r1, $sl1, $bl2)));
    }

    public function test_a_real_transfer_reversal_does_not_erase_its_cleared_original_match(): void
    {
        [$root, $transfer, , $sl, $bl] = $this->fixture();
        $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl));
        $this->reverse($this->ready($transfer));
        $report = $this->matcher()->review($this->actor, (int) $root->getKey());
        $this->assertSame('0.00', $report['statement_rows'][0]['remaining']);
        $this->assertCount(2, $report['book_rows']);
        $this->assertSame('100.00', $report['book_rows'][1]['currently_available']);
        $this->assertSame('out', $report['book_rows'][1]['direction']);
    }

    public function test_single_cent_matching_survives_reload_and_undo(): void
    {
        $this->original(['amount' => '0.01']);
        $statement = $this->statement([$this->statementRow(['money_in' => '0.01'])], ['closing_balance' => '0.01']);
        $root = $this->start($statement);
        $sl = (int) AccountingBankStatementLine::query()->where('statement_id', $statement->getKey())->value('id');
        $bl = (int) JournalEntryLine::query()->where('cash_account_id', $this->bank->getKey())->value('id');
        $input = $this->group($root, $sl, $bl, ['statement_allocations' => [['id' => $sl, 'amount' => '0.01']], 'book_allocations' => [['id' => $bl, 'amount' => '0.01']]]);
        $op = $this->matcher()->match($this->actor, (int) $root->getKey(), $input);
        $this->assertSame('0.01', $op->fresh()->getAttribute('total'));
        $this->undo($root, $op);
        $this->assertSame('0.01', $this->matcher()->review($this->actor, (int) $root->getKey())['book_rows'][0]['currently_available']);
    }

    public function test_review_is_read_only_and_full_book_reader_is_not_limited_to_first_page(): void
    {
        [$root] = $this->fixture();
        $before = $this->digest();
        $this->matcher()->review($this->actor, (int) $root->getKey());
        $this->assertSame($before, $this->digest());
        $this->original();
        $reader = app(AccountingCashBookReader::class);
        $full = $reader->reconciliationBook($this->actor, (int) $this->company->getKey(), (int) $this->bank->getKey(), ['per_page' => 1]);
        $this->assertSame(2, $full['pagination']['total']);
        $this->assertCount(2, $full['rows']);
    }

    public function test_changed_statement_evidence_blocks_replay_even_if_matching_tables_are_intact(): void
    {
        [$root, , $statement, $sl, $bl] = $this->fixture();
        $input = $this->group($root, $sl, $bl);
        $this->matcher()->match($this->actor, (int) $root->getKey(), $input);
        DB::table('accounting_bank_statement_evidence')->where('statement_id', $statement->getKey())->delete();
        $this->rejected(fn () => $this->matcher()->match($this->actor, (int) $root->getKey(), $input));
    }

    public function test_both_sides_have_bounded_group_sizes_and_malformed_row_lists_are_rejected(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $input = $this->group($root, $sl, $bl);
        $input['statement_allocations'] = array_fill(0, 101, ['id' => $sl, 'amount' => '1.00']);
        $this->rejected(fn () => $this->matcher()->match($this->actor, (int) $root->getKey(), $input));
        $input = $this->group($root, $sl, $bl);
        $input['book_allocations'] = ['bad' => ['id' => $bl, 'amount' => '100.00']];
        $this->rejected(fn () => $this->matcher()->match($this->actor, (int) $root->getKey(), $input));
    }

    public function test_direct_head_jump_without_a_complete_owned_operation_is_rejected(): void
    {
        [$root] = $this->fixture();
        $this->rejected(fn () => $root->update(['revision' => 9, 'last_operation_hash' => str_repeat('f', 64)]));
    }

    public function test_new_matching_itself_needs_no_open_period_or_enabled_posting(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $this->settings(['state' => 'disabled']);
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->update(['state' => 'hard_closed']);
        $this->bank->update(['status' => 'inactive']);
        $before = $this->untouched();
        $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl));
        $this->assertSame($before, $this->untouched());
    }
}

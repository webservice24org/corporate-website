<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingBankAdjustment;
use App\Models\Accounting\AccountingBankReconciliation;
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
use App\Services\ERP\Accounting\Adjustments\AccountingBankAdjustmentEvidence;
use App\Services\ERP\Accounting\Adjustments\AccountingBankAdjustmentWorkflow;
use App\Services\ERP\Accounting\Books\AccountingCashBookReader;
use App\Services\ERP\Accounting\Reconciliation\AccountingBankReconciliationService;
use App\Services\ERP\Accounting\Statements\AccountingBankStatementContract;
use App\Services\ERP\Accounting\Statements\AccountingBankStatementService;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferPosting;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferReversalEvidence;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferReversalPosting;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferReversalWorkflow;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferWorkflow;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingBankAdjustmentPermissionSeeder;
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
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AccountingBankingWorkspaceScreenTest extends TestCase
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
        $this->seed(AccountingBankAdjustmentPermissionSeeder::class);
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

    private function reconciliationDigest(): string
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

    private function reconciliationRejected(callable $action): void
    {
        $before = $this->reconciliationDigest();
        try {
            $action();
            $this->fail('Expected rejected matching operation.');
        } catch (ValidationException|AuthorizationException|\Illuminate\Database\Eloquent\ModelNotFoundException|\LogicException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, $this->reconciliationDigest());
    }

    private function adjustments(): AccountingBankAdjustmentWorkflow
    {
        return app(AccountingBankAdjustmentWorkflow::class);
    }

    private function counter(string $kind): Account
    {
        $group = AccountGroup::query()->where('company_id', $this->company->getKey())->where('category', $kind === 'charge' ? 'expense' : 'revenue')->firstOrFail();

        return Account::query()->create(['company_id' => $this->company->getKey(), 'account_group_id' => $group->getKey(), 'code' => 'B'.Str::uuid(), 'name' => 'PRIVATE BANK COUNTER', 'normal_side' => $kind === 'charge' ? 'debit' : 'credit', 'is_postable' => true, 'control_role' => null, 'statement_role' => $kind === 'charge' ? 'operating_expense' : 'other_revenue', 'cash_flow_role' => 'operating', 'status' => 'active', 'revision' => 1]);
    }

    /** @return array{AccountingBankStatement, int} */
    private function bankStatement(string $kind = 'charge', string $amount = '25.25'): array
    {
        $incoming = $kind === 'interest';
        $statement = $this->statement([$this->statementRow(['money_in' => $incoming ? $amount : '', 'money_out' => $incoming ? '' : $amount])], ['closing_balance' => $incoming ? $amount : '-'.$amount]);

        return [$statement, (int) AccountingBankStatementLine::query()->where('statement_id', $statement->getKey())->value('id')];
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private function adjustmentInput(int $line, int $counter, string $kind = 'charge', array $changes = []): array
    {
        return ['statement_line_id' => $line, 'counter_account_id' => $counter, 'kind' => $kind, 'document_date' => '2026-10-16', 'reason' => 'PRIVATE-ADJUSTMENT-REASON', 'key' => (string) Str::uuid(), ...$changes];
    }

    /** @return array{AccountingBankAdjustment, AccountingBankStatement, int, Account} */
    private function adjustmentDraft(string $kind = 'charge', string $amount = '25.25'): array
    {
        [$statement, $line] = $this->bankStatement($kind, $amount);
        $counter = $this->counter($kind);
        $row = $this->adjustments()->prepare($this->actor, $this->adjustmentInput($line, (int) $counter->getKey(), $kind));

        return [$row, $statement, $line, $counter];
    }

    private function approvedAdjustment(AccountingBankAdjustment $row): AccountingBankAdjustment
    {
        $row = $this->adjustments()->submit($this->actor, (int) $row->getKey(), (int) $row->getAttribute('revision'));

        return $this->adjustments()->approve($this->reviewer, (int) $row->getKey(), (int) $row->getAttribute('revision'));
    }

    private function postAdjustment(AccountingBankAdjustment $row, string $key = 'adjustment-post-key-0001', ?User $actor = null): AccountingBankAdjustment
    {
        return $this->adjustments()->post($actor ?? $this->actor, (int) $row->getKey(), (int) $row->getAttribute('revision'), $key);
    }

    /** @return array<string, mixed> */
    private function verifiedAdjustment(AccountingBankAdjustment $row): array
    {
        return app(AccountingBankAdjustmentEvidence::class)->verify((int) $this->company->getKey(), (int) $row->getKey());
    }

    /** @return array{AccountingBankStatement, int} */
    private function refundStatement(AccountingBankAdjustment $original, string $amount = '25.25'): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $incoming = $original->getAttribute('kind') === 'charge';
        $opening = $incoming ? '-25.25' : '25.25';
        $closing = $incoming ? bcadd($opening, $amount, 2) : bcsub($opening, $amount, 2);
        $statement = $this->statement([$this->statementRow(['date' => '2026-10-17', 'money_in' => $incoming ? $amount : '', 'money_out' => $incoming ? '' : $amount])], ['starts_on' => '2026-10-17', 'ends_on' => '2026-10-17', 'opening_balance' => $opening, 'closing_balance' => $closing]);

        return [$statement, (int) AccountingBankStatementLine::query()->where('statement_id', $statement->getKey())->value('id')];
    }

    /** @return array<string, mixed> */
    private function refundInput(int $line, array $changes = []): array
    {
        return ['statement_line_id' => $line, 'document_date' => '2026-10-17', 'reason' => 'PRIVATE-REFUND-REASON', 'key' => (string) Str::uuid(), ...$changes];
    }

    private function digest(): string
    {
        $data = [$this->reconciliationDigest()];
        foreach (['accounting_bank_adjustments', 'accounting_bank_adjustment_operations', 'accounting_bank_adjustment_audit_events'] as $table) {
            $data[] = DB::table($table)->orderBy('id')->get()->toArray();
        }

return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function rejected(callable $action): void
    {
        $before = $this->digest();
        try {
            $action();
            $this->fail('Expected rejected bank adjustment.');
        } catch (ValidationException|AuthorizationException|\Illuminate\Database\Eloquent\ModelNotFoundException|\LogicException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, $this->digest());
    }

    /** @return list<string> */
    private function adjustmentPermissions(string ...$actions): array
    {
        return ['erp.bank-adjustments.view', 'erp.bank-statements.view', 'erp.cash-books.view', 'erp.bank-reconciliations.view', ...array_map(static fn (string $action): string => 'erp.bank-adjustments.'.$action, $actions)];
    }

    /** @return list<string> */
    private function views(): array
    {
        return $this->adjustmentPermissions();
    }

    private function bankUrl(string $module, string $suffix = ''): string
    {
        return '/erp/accounting/bank-'.$module.$suffix;
    }

    public function test_all_registers_require_authentication(): void
    {
        foreach (['adjustments', 'reconciliations'] as $module) {
            $this->get($this->bankUrl($module))->assertRedirect('/login');
        }

    }

    public function test_registers_require_native_and_source_view_permissions(): void
    {
        foreach ($this->views() as $missing) {
            $actor = $this->staff(array_values(array_diff($this->views(), [$missing])));
            $this->actingAs($actor)->get($this->bankUrl('adjustments'))->assertForbidden();
            if ($missing !== 'erp.bank-adjustments.view') {
                $this->get($this->bankUrl('reconciliations'))->assertForbidden();
            }
        }
    }

    public function test_adjustments_register_resolves_without_financial_writes(): void
    {
        [$row, $statement] = $this->adjustmentDraft();
        $this->start($statement);
        $before = $this->digest();
        $this->actingAs($this->actor)->get($this->bankUrl('adjustments').'?company_id='.$this->company->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/BankAdjustments/Index')->has('records.data', 1)->missing('records.data.0.source_snapshot')->missing('records.data.0.creation_key_hash'));
        $this->assertSame($before, $this->digest());
    }

    public function test_adjustments_register_rejects_foreign_company(): void
    {
        $other = $this->newCompany('FOREIGN-WORKSPACE');
        $actor = $this->staff($this->views());
        $this->actingAs($actor)->get($this->bankUrl('adjustments').'?company_id='.$other->getKey())->assertForbidden();
    }

    public function test_adjustments_create_requires_action_permission(): void
    {
        $this->actingAs($this->staff($this->views()))->get($this->bankUrl('adjustments', '/create').'?company_id='.$this->company->getKey())->assertForbidden();
    }

    public function test_adjustments_create_form_resolves_and_does_not_create_native_rows(): void
    {
        $statement = $this->statement();
        $before = $this->digest();
        $this->actingAs($this->actor)->get($this->bankUrl('adjustments', '/create').'?company_id='.$this->company->getKey().'&statement_id='.$statement->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/BankAdjustments/Form')->has('sources', 1)->missing('sources.0.proof_hash')->missing('source_snapshot'));
        $this->assertSame($before, $this->digest());
    }

    public function test_adjustments_rejects_foreign_bank_selector(): void
    {
        $this->actingAs($this->staff($this->views()))->get($this->bankUrl('adjustments').'?company_id='.$this->company->getKey().'&cash_account_id=999999')->assertNotFound();
    }

    public function test_reconciliations_register_resolves_without_financial_writes(): void
    {
        [$row, $statement] = $this->adjustmentDraft();
        $this->start($statement);
        $before = $this->digest();
        $this->actingAs($this->actor)->get($this->bankUrl('reconciliations').'?company_id='.$this->company->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/BankReconciliations/Index')->has('records.data', 1)->missing('records.data.0.source_snapshot')->missing('records.data.0.creation_key_hash'));
        $this->assertSame($before, $this->digest());
    }

    public function test_reconciliations_register_rejects_foreign_company(): void
    {
        $other = $this->newCompany('FOREIGN-WORKSPACE');
        $actor = $this->staff($this->views());
        $this->actingAs($actor)->get($this->bankUrl('reconciliations').'?company_id='.$other->getKey())->assertForbidden();
    }

    public function test_reconciliations_create_requires_action_permission(): void
    {
        $this->actingAs($this->staff($this->views()))->get($this->bankUrl('reconciliations', '/create').'?company_id='.$this->company->getKey())->assertForbidden();
    }

    public function test_reconciliations_create_form_resolves_and_does_not_create_native_rows(): void
    {
        $statement = $this->statement();
        $before = $this->digest();
        $this->actingAs($this->actor)->get($this->bankUrl('reconciliations', '/create').'?company_id='.$this->company->getKey().'&statement_id='.$statement->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/BankReconciliations/Form')->has('sources', 1)->missing('sources.0.proof_hash')->missing('source_snapshot'));
        $this->assertSame($before, $this->digest());
    }

    public function test_reconciliations_rejects_foreign_bank_selector(): void
    {
        $this->actingAs($this->staff($this->views()))->get($this->bankUrl('reconciliations').'?company_id='.$this->company->getKey().'&cash_account_id=999999')->assertNotFound();
    }

    public function test_adjustment_detail_and_edit_resolve_safe_props(): void
    {
        [$row] = $this->adjustmentDraft();
        $before = $this->digest();
        $this->actingAs($this->actor);
        $this->get($this->bankUrl('adjustments', '/'.$row->getKey()))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/BankAdjustments/Show')->where('record.amount', '25.25')->where('financeState', 'unposted')->has('preview', 2)->missing('record.source_snapshot')->missing('record.mapping_hash')->where('capabilities.approve', false));
        $this->get($this->bankUrl('adjustments', '/'.$row->getKey().'/edit'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/BankAdjustments/Form')->where('mode', 'edit'));
        $this->assertSame($before, $this->digest());
    }

    public function test_detail_company_mismatch_is_forbidden(): void
    {
        [$row, $statement] = $this->adjustmentDraft();
        $root = $this->start($statement);
        $other = $this->newCompany('WRONG-CONTEXT');
        $this->actingAs($this->actor);
        $this->get($this->bankUrl('adjustments', '/'.$row->getKey()).'?company_id='.$other->getKey())->assertForbidden();
        $this->get($this->bankUrl('reconciliations', '/'.$root->getKey()).'?company_id='.$other->getKey())->assertForbidden();
    }

    public function test_foreign_detail_ids_are_not_found_for_staff(): void
    {
        [$row, $statement] = $this->adjustmentDraft();
        $root = $this->start($statement);
        $other = $this->newCompany('OTHER-STAFF');
        $user = $this->staff($this->views());
        Employee::query()->where('user_id', $user->getKey())->update(['company_id' => $other->getKey()]);
        $this->actingAs($user)->get($this->bankUrl('adjustments', '/'.$row->getKey()))->assertNotFound();
        $this->get($this->bankUrl('reconciliations', '/'.$root->getKey()))->assertNotFound();
    }

    public function test_http_preparation_uses_source_amount_and_replays_same_key(): void
    {
        [$statement, $line] = $this->bankStatement();
        $counter = $this->counter('charge');
        $input = $this->adjustmentInput($line, (int) $counter->getKey());
        $this->actingAs($this->actor)->post($this->bankUrl('adjustments'), $input)->assertRedirect();
        $before = $this->digest();
        $this->post($this->bankUrl('adjustments'), $input)->assertRedirect();
        $this->assertSame($before, $this->digest());
        $this->assertDatabaseCount('accounting_bank_adjustments', 1);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_client_cannot_override_adjustment_amount_company_or_bank(): void
    {
        [$statement, $line] = $this->bankStatement();
        $counter = $this->counter('charge');
        $base = $this->adjustmentInput($line, (int) $counter->getKey());
        $before = $this->digest();
        $this->actingAs($this->actor);
        foreach (['amount' => '0.01', 'company_id' => $this->company->getKey(), 'cash_account_id' => $this->cash->getKey()] as $field => $value) {
            $this->post($this->bankUrl('adjustments'), [...$base, $field => $value])->assertSessionHasErrors('workspace');
        }
        $this->assertSame($before, $this->digest());
    }

    public function test_http_edit_changes_only_draft_date_and_reason(): void
    {
        [$row] = $this->adjustmentDraft();
        $this->actingAs($this->actor)->put($this->bankUrl('adjustments', '/'.$row->getKey()), ['revision' => 1, 'document_date' => '2026-10-16', 'reason' => 'Reviewed bank charge'])->assertRedirect();
        $this->assertSame(2, $row->fresh()->getAttribute('revision'));
        $this->assertSame('25.25', $row->fresh()->getAttribute('amount'));
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_maker_cannot_approve_through_http_or_screen(): void
    {
        [$row] = $this->adjustmentDraft();
        $this->actingAs($this->actor)->post($this->bankUrl('adjustments', '/'.$row->getKey().'/submit'), ['revision' => 1])->assertRedirect();
        $this->get($this->bankUrl('adjustments', '/'.$row->getKey()))->assertInertia(fn (Assert $page) => $page->where('capabilities.approve', false));
        $this->post($this->bankUrl('adjustments', '/'.$row->getKey().'/approve'), ['revision' => 2])->assertForbidden();
        $this->assertSame('submitted', $row->fresh()->getAttribute('status'));
    }

    public function test_http_independent_approval_and_post_create_exactly_one_journal(): void
    {
        [$row] = $this->adjustmentDraft();
        $this->actingAs($this->actor)->post($this->bankUrl('adjustments', '/'.$row->getKey().'/submit'), ['revision' => 1])->assertRedirect();
        $this->actingAs($this->reviewer)->post($this->bankUrl('adjustments', '/'.$row->getKey().'/approve'), ['revision' => 2])->assertRedirect();
        $input = ['revision' => 3, 'key' => (string) Str::uuid()];
        $this->actingAs($this->actor)->post($this->bankUrl('adjustments', '/'.$row->getKey().'/post'), $input)->assertRedirect();
        $before = $this->digest();
        $this->post($this->bankUrl('adjustments', '/'.$row->getKey().'/post'), $input)->assertRedirect();
        $this->assertSame($before, $this->digest());
        $this->assertDatabaseCount('journal_entries', 1);
        $this->get($this->bankUrl('adjustments', '/'.$row->getKey()))->assertInertia(fn (Assert $page) => $page->where('financeState', 'verified')->where('capabilities.post', false)->has('refundUrl'));

    }

    public function test_native_post_permission_does_not_grant_manual_journal_links(): void
    {
        [$row] = $this->adjustmentDraft();
        $row = $this->postAdjustment($this->approvedAdjustment($row));
        $user = $this->staff($this->adjustmentPermissions('post'));
        $this->actingAs($user)->get($this->bankUrl('adjustments', '/'.$row->getKey()))->assertOk()->assertInertia(fn (Assert $page) => $page->where('journalUrl', null)->where('financeState', 'verified'));
    }

    public function test_closed_period_disables_submit_and_post_without_disabling_matching(): void
    {
        [$row, $statement] = $this->adjustmentDraft();
        $root = $this->start($statement);
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->update(['state' => 'hard_closed']);
        $this->actingAs($this->actor);
        $this->get($this->bankUrl('adjustments', '/'.$row->getKey()))->assertInertia(fn (Assert $page) => $page->where('capabilities.submit', false)->where('capabilities.post', false));
        $this->get($this->bankUrl('reconciliations', '/'.$root->getKey()))->assertInertia(fn (Assert $page) => $page->where('capabilities.match', true));
    }

    public function test_http_reject_returns_to_draft_and_requires_reason(): void
    {
        [$row] = $this->adjustmentDraft();
        $this->adjustments()->submit($this->actor, (int) $row->getKey(), 1);
        $this->actingAs($this->reviewer);
        $this->post($this->bankUrl('adjustments', '/'.$row->getKey().'/reject'), ['revision' => 2, 'reason' => ''])->assertSessionHasErrors('reason');
        $this->post($this->bankUrl('adjustments', '/'.$row->getKey().'/reject'), ['revision' => 2, 'reason' => 'Check classification'])->assertRedirect();
        $this->assertSame('draft', $row->fresh()->getAttribute('status'));
    }

    public function test_cancel_releases_source_and_keeps_history(): void
    {
        [$row] = $this->adjustmentDraft();
        $this->actingAs($this->actor)->post($this->bankUrl('adjustments', '/'.$row->getKey().'/cancel'), ['revision' => 1, 'reason' => 'Withdraw request'])->assertRedirect();
        $this->assertNull($row->fresh()->getAttribute('active_statement_line_id'));
        $this->assertDatabaseCount('accounting_bank_adjustment_audit_events', 2);
    }

    public function test_refund_form_requires_posted_original_and_separate_opposite_movement(): void
    {
        [$row] = $this->adjustmentDraft();
        $posted = $this->postAdjustment($this->approvedAdjustment($row));
        [$statement, $line] = $this->refundStatement($posted);
        $before = $this->digest();
        $this->actingAs($this->actor)->get($this->bankUrl('adjustments', '/'.$row->getKey().'/refund').'?statement_id='.$statement->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/BankAdjustments/Form')->where('mode', 'refund')->where('sources.0.selectable', true));
        $this->assertSame($before, $this->digest());
        $this->post($this->bankUrl('adjustments', '/'.$row->getKey().'/refund'), $this->refundInput($line))->assertRedirect();
        $this->assertDatabaseCount('accounting_bank_adjustments', 2);
    }

    public function test_damaged_adjustment_is_review_only_and_read_does_not_repair(): void
    {
        [$row] = $this->adjustmentDraft();
        $posted = $this->postAdjustment($this->approvedAdjustment($row));
        DB::table('accounting_bank_adjustment_operations')->delete();
        $before = $this->digest();
        $this->actingAs($this->actor)->get($this->bankUrl('adjustments', '/'.$row->getKey()))->assertOk()->assertInertia(fn (Assert $page) => $page->where('financeState', 'review')->where('capabilities.post', false)->where('refundUrl', null)->has('preview', 0));
        $this->assertSame($before, $this->digest());
    }

    public function test_reconciliation_open_is_explicit_post_and_reuses_existing_workspace_link(): void
    {
        $statement = $this->statement();
        $input = ['statement_id' => $statement->getKey(), 'reason' => 'Review statement', 'key' => (string) Str::uuid()];
        $this->actingAs($this->actor)->post($this->bankUrl('reconciliations'), $input)->assertRedirect();
        $before = $this->digest();
        $this->post($this->bankUrl('reconciliations'), $input)->assertRedirect();
        $this->assertSame($before, $this->digest());
        $root = AccountingBankReconciliation::query()->firstOrFail();
        $this->get($this->bankUrl('reconciliations', '/create').'?company_id='.$this->company->getKey().'&statement_id='.$statement->getKey())->assertInertia(fn (Assert $page) => $page->where('existingUrl', $this->bankUrl('reconciliations', '/'.$root->getKey())));
    }

    public function test_reconciliation_detail_resolves_verified_provisional_safe_props(): void
    {
        [$root] = $this->fixture();
        $before = $this->digest();
        $this->actingAs($this->actor)->get($this->bankUrl('reconciliations', '/'.$root->getKey()))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/BankReconciliations/Show')->where('report.status', 'working')->where('report.finalized', false)->has('report.statement_rows', 1)->has('report.book_rows', 1)->missing('report.statement_rows.0.proof_hash')->missing('record.snapshot')->missing('record.reason')->where('report.bridge.statement_minus_book', '0.00'));
        $this->assertSame($before, $this->digest());
    }

    public function test_http_match_and_undo_preserve_financial_digest(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $before = $this->financialDigest();
        $this->actingAs($this->actor)->post($this->bankUrl('reconciliations', '/'.$root->getKey().'/match'), $this->group($root, $sl, $bl))->assertRedirect();
        $op = AccountingBankReconciliationOperation::query()->firstOrFail();
        $this->assertSame($before, $this->financialDigest());
        $this->post($this->bankUrl('reconciliations', '/'.$root->getKey().'/unmatch'), ['revision' => 2, 'key' => (string) Str::uuid(), 'reason' => 'Wrong grouping', 'target_match_id' => $op->getKey()])->assertRedirect();
        $this->assertSame($before, $this->financialDigest());
        $report = $this->matcher()->review($this->actor, (int) $root->getKey());
        $this->assertSame('100.00', $report['statement_rows'][0]['remaining']);
        $this->assertCount(2, $report['history']);
    }

    public function test_read_only_reconciliation_user_cannot_match_or_undo(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $before = $this->digest();
        $this->actingAs($this->staff($this->views()));
        $this->get($this->bankUrl('reconciliations', '/'.$root->getKey()))->assertInertia(fn (Assert $page) => $page->where('capabilities.match', false)->where('capabilities.unmatch', false));
        $this->post($this->bankUrl('reconciliations', '/'.$root->getKey().'/match'), $this->group($root, $sl, $bl))->assertForbidden();
        $this->post($this->bankUrl('reconciliations', '/'.$root->getKey().'/unmatch'), [])->assertForbidden();
        $this->assertSame($before, $this->digest());
    }

    public function test_stale_revision_match_is_rejected_atomically(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $input = $this->group($root, $sl, $bl);
        $this->matcher()->match($this->actor, (int) $root->getKey(), $input);
        $before = $this->digest();
        $input['key'] = (string) Str::uuid();
        $this->actingAs($this->actor)->post($this->bankUrl('reconciliations', '/'.$root->getKey().'/match'), $input)->assertSessionHasErrors();
        $this->assertSame($before, $this->digest());
    }

    public function test_damaged_reconciliation_is_review_only_without_fabricated_totals(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl));
        DB::table('accounting_bank_reconciliation_legs')->delete();
        $before = $this->digest();
        $this->actingAs($this->actor)->get($this->bankUrl('reconciliations', '/'.$root->getKey()))->assertOk()->assertInertia(fn (Assert $page) => $page->where('report', null)->where('capabilities.match', false)->where('capabilities.unmatch', false)->has('issues', 1));
        $this->assertSame($before, $this->digest());
    }

    public function test_statement_links_are_dependency_aware(): void
    {
        [$statement] = $this->bankStatement();
        $this->actingAs($this->actor)->get('/erp/accounting/bank-statements/'.$statement->getKey())->assertInertia(fn (Assert $page) => $page->has('workflowLinks', 2));
        $user = $this->staff(['erp.bank-statements.view']);
        $this->actingAs($user)->get('/erp/accounting/bank-statements/'.$statement->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->has('workflowLinks', 0));
    }

    public function test_bankbook_links_hide_unreadable_adjustment_workspace(): void
    {
        [$row] = $this->adjustmentDraft();
        $this->postAdjustment($this->approvedAdjustment($row));
        $user = $this->staff(['erp.cash-books.view']);
        $book = $this->book($this->bank, actor: $user);
        $this->assertTrue($book['complete']);
        $this->assertNull($book['rows'][0]['source_url']);
    }

    public function test_claimed_statement_rows_are_not_selectable_again(): void
    {
        [$row, $statement] = $this->adjustmentDraft();
        $this->actingAs($this->actor)->get($this->bankUrl('adjustments', '/create').'?company_id='.$this->company->getKey().'&statement_id='.$statement->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->where('sources.0.selectable', false));
    }

    public function test_history_contains_allocations_without_operation_payloads(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl));
        $this->actingAs($this->actor)->get($this->bankUrl('reconciliations', '/'.$root->getKey()))->assertInertia(fn (Assert $page) => $page->has('report.history.0.legs', 2)->missing('report.history.0.payload')->missing('report.history.0.payload_hash')->missing('report.history.0.legs.0.proof_hash'));
    }

    public function test_row_filters_preserve_complete_bridge_totals(): void
    {
        [$root] = $this->fixture();
        $this->actingAs($this->actor)->get($this->bankUrl('reconciliations', '/'.$root->getKey()).'?direction=out&outstanding_only=1')->assertInertia(fn (Assert $page) => $page->has('report.statement_rows', 0)->has('report.book_rows', 0)->where('report.bridge.statement_closing', '100.00')->where('report.bridge.book_closing', '100.00'));
    }

    public function test_unknown_input_and_invalid_filters_are_rejected(): void
    {
        $this->actingAs($this->actor)->get($this->bankUrl('adjustments').'?company_id='.$this->company->getKey().'&payload=private')->assertSessionHasErrors('workspace');
        $this->get($this->bankUrl('reconciliations').'?direction=both')->assertSessionHasErrors('direction');
    }

    public function test_inactive_company_blocks_new_matching_screen_actions(): void
    {
        [$root] = $this->fixture();
        $this->company->update(['status' => 'inactive']);
        $before = $this->digest();
        $this->actingAs($this->actor)->get($this->bankUrl('reconciliations', '/'.$root->getKey()))->assertOk()->assertInertia(fn (Assert $page) => $page->where('capabilities.match', false)->where('capabilities.unmatch', false));
        $this->assertSame($before, $this->digest());
    }
}

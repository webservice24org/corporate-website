<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingBankAdjustment;
use App\Models\Accounting\AccountingBankAdjustmentAuditEvent;
use App\Models\Accounting\AccountingBankAdjustmentOperation;
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
use App\Services\ERP\Accounting\Adjustments\AccountingBankAdjustmentContract;
use App\Services\ERP\Accounting\Adjustments\AccountingBankAdjustmentCoverageAudit;
use App\Services\ERP\Accounting\Adjustments\AccountingBankAdjustmentEvidence;
use App\Services\ERP\Accounting\Adjustments\AccountingBankAdjustmentWorkflow;
use App\Services\ERP\Accounting\Books\AccountingCashBookReader;
use App\Services\ERP\Accounting\Reconciliation\AccountingBankReconciliationService;
use App\Services\ERP\Accounting\Source\AccountingSourceEventRegistry;
use App\Services\ERP\Accounting\Source\AccountingSourceOrchestrator;
use App\Services\ERP\Accounting\Source\AccountingSourceReconciliation;
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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AccountingBankAdjustmentPostingTest extends TestCase
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
        } catch (ValidationException|AuthorizationException|ModelNotFoundException|\LogicException $exception) {
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
        } catch (ValidationException|AuthorizationException|ModelNotFoundException|\LogicException $e) {
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
        } catch (ValidationException|AuthorizationException|ModelNotFoundException|\LogicException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, $this->digest());
    }

    /** @return list<string> */
    private function adjustmentPermissions(string ...$actions): array
    {
        return ['erp.bank-adjustments.view', 'erp.bank-statements.view', 'erp.cash-books.view', 'erp.bank-reconciliations.view', ...array_map(static fn (string $action): string => 'erp.bank-adjustments.'.$action, $actions)];
    }

    public function test_charge_posts_bank_credit_and_selected_expense_debit(): void
    {
        [$row, , , $counter] = $this->adjustmentDraft();
        $row = $this->postAdjustment($this->approvedAdjustment($row));
        $proof = $this->verifiedAdjustment($row);
        $lines = JournalEntryLine::query()->where('journal_entry_id', $proof['journal']->getKey())->orderBy('line_number')->get();
        $this->assertSame('25.25', $lines[0]->getAttribute('credit'));
        $this->assertSame((int) $this->bank->getKey(), (int) $lines[0]->getAttribute('cash_account_id'));
        $this->assertSame('25.25', $lines[1]->getAttribute('debit'));
        $this->assertSame((int) $counter->getKey(), (int) $lines[1]->getAttribute('account_id'));
        $this->assertNull($lines[1]->getAttribute('cash_account_id'));
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_interest_posts_bank_debit_and_other_revenue_credit(): void
    {
        [$row, , , $counter] = $this->adjustmentDraft('interest');
        $row = $this->postAdjustment($this->approvedAdjustment($row));
        $proof = $this->verifiedAdjustment($row);
        $lines = JournalEntryLine::query()->where('journal_entry_id', $proof['journal']->getKey())->orderBy('line_number')->get();
        $this->assertSame('25.25', $lines[0]->getAttribute('debit'));
        $this->assertSame('25.25', $lines[1]->getAttribute('credit'));
        $this->assertSame((int) $counter->getKey(), (int) $lines[1]->getAttribute('account_id'));
    }

    public function test_draft_and_approval_create_no_gl_or_matching_entries(): void
    {
        [$row] = $this->adjustmentDraft();
        $this->approvedAdjustment($row);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('accounting_source_effects', 0);
        $this->assertDatabaseCount('accounting_bank_reconciliation_operations', 0);
    }

    public function test_posting_never_changes_statement_rows_or_matches_automatically(): void
    {
        [$row, $statement] = $this->adjustmentDraft();
        $before = json_encode([DB::table('accounting_bank_statements')->get(), DB::table('accounting_bank_statement_lines')->get(), DB::table('accounting_bank_statement_evidence')->get()], JSON_THROW_ON_ERROR);
        $this->postAdjustment($this->approvedAdjustment($row));
        $this->assertSame($before, json_encode([DB::table('accounting_bank_statements')->get(), DB::table('accounting_bank_statement_lines')->get(), DB::table('accounting_bank_statement_evidence')->get()], JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('accounting_bank_reconciliation_operations', 0);
    }

    public function test_bankbook_and_source_reconciliation_verify_new_adjustment(): void
    {
        [$row] = $this->adjustmentDraft();
        $row = $this->postAdjustment($this->approvedAdjustment($row));
        $book = $this->book($this->bank);
        $this->assertTrue($book['complete']);
        $this->assertSame('-25.25', $book['source_verified']['closing']);
        $this->assertSame('bank_charge', $book['rows'][0]['event']);
        $this->assertSame('/erp/accounting/bank-adjustments/'.$row->getKey(), $book['rows'][0]['source_url']);
        $r = app(AccountingSourceReconciliation::class)->page($this->actor, (int) $this->company->getKey(), ['kind' => 'bank_adjustment']);
        $this->assertSame('verified_live', $r['rows'][0]['status']);
    }

    public function test_charge_can_be_matched_separately_without_second_cash_or_expense(): void
    {
        [$row, $statement, $sl] = $this->adjustmentDraft();
        $row = $this->postAdjustment($this->approvedAdjustment($row));
        $root = $this->start($statement);
        $bl = (int) JournalEntryLine::query()->where('cash_account_id', $this->bank->getKey())->value('id');
        $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl, ['statement_allocations' => [['id' => $sl, 'amount' => '25.25']], 'book_allocations' => [['id' => $bl, 'amount' => '25.25']]]));
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('journal_entry_lines', 2);
        $this->assertSame('0.00', $this->matcher()->review($this->actor, (int) $root->getKey())['bridge']['statement_minus_book']);
    }

    public function test_maker_editor_and_submitter_cannot_approve_own_intent(): void
    {
        [$row] = $this->adjustmentDraft();
        $row = $this->adjustments()->submit($this->actor, (int) $row->getKey(), 1);
        $this->rejected(fn () => $this->adjustments()->approve($this->actor, (int) $row->getKey(), 2));
    }

    public function test_independent_submitter_is_also_excluded_from_approval(): void
    {
        [$row] = $this->adjustmentDraft();
        $row = $this->adjustments()->submit($this->reviewer, (int) $row->getKey(), 1);
        $this->rejected(fn () => $this->adjustments()->approve($this->reviewer, (int) $row->getKey(), 2));
    }

    public function test_draft_edit_reason_is_hashed_and_rejection_requires_resubmission(): void
    {
        [$row] = $this->adjustmentDraft();
        $row = $this->adjustments()->edit($this->actor, (int) $row->getKey(), 1, '2026-10-16', 'Revised fee evidence');
        $row = $this->adjustments()->submit($this->actor, (int) $row->getKey(), 2);
        $row = $this->adjustments()->reject($this->reviewer, (int) $row->getKey(), 3, 'Review evidence');
        $this->assertSame('draft', $row->getAttribute('status'));
        $this->assertNull($row->getAttribute('submitted_hash'));
        $this->rejected(fn () => $this->adjustments()->approve($this->reviewer, (int) $row->getKey(), 4));
    }

    public function test_cancelled_draft_releases_statement_claim_without_erasing_history(): void
    {
        [$row, , $line, $counter] = $this->adjustmentDraft();
        $this->adjustments()->cancel($this->actor, (int) $row->getKey(), 1, 'Withdraw');
        $new = $this->adjustments()->prepare($this->actor, $this->adjustmentInput($line, (int) $counter->getKey()));
        $this->assertNotSame($row->getKey(), $new->getKey());
        $this->assertDatabaseCount('accounting_bank_adjustments', 2);
    }

    public function test_duplicate_adjustment_for_same_statement_row_is_rejected(): void
    {
        [$row, , $line, $counter] = $this->adjustmentDraft();
        $this->rejected(fn () => $this->adjustments()->prepare($this->actor, $this->adjustmentInput($line, (int) $counter->getKey())));
    }

    public function test_same_creation_key_replays_the_same_reviewed_body(): void
    {
        [$statement, $line] = $this->bankStatement();
        $counter = $this->counter('charge');
        $input = $this->adjustmentInput($line, (int) $counter->getKey());
        $row = $this->adjustments()->prepare($this->actor, $input);
        $before = $this->digest();
        $again = $this->adjustments()->prepare($this->actor, $input);
        $this->assertSame($row->getKey(), $again->getKey());
        $this->assertSame($before, $this->digest());
    }

    public function test_changed_creation_body_or_actor_under_same_key_is_rejected(): void
    {
        [$statement, $line] = $this->bankStatement();
        $counter = $this->counter('charge');
        $input = $this->adjustmentInput($line, (int) $counter->getKey());
        $this->adjustments()->prepare($this->actor, $input);
        $this->rejected(fn () => $this->adjustments()->prepare($this->actor, [...$input, 'reason' => 'Changed']));
        $this->rejected(fn () => $this->adjustments()->prepare($this->reviewer, $input));
    }

    public function test_exact_post_retry_replays_without_new_effects_or_journals(): void
    {
        [$row] = $this->adjustmentDraft();
        $approved = $this->approvedAdjustment($row);
        $this->postAdjustment($approved);
        $before = $this->digest();
        $this->postAdjustment($approved);
        $this->assertSame($before, $this->digest());
    }

    public function test_post_key_or_revision_or_actor_conflicts_do_not_write(): void
    {
        [$row] = $this->adjustmentDraft();
        $approved = $this->approvedAdjustment($row);
        $this->postAdjustment($approved);
        $this->rejected(fn () => $this->postAdjustment($approved, 'different-post-key-0002'));
        $this->rejected(fn () => $this->adjustments()->post($this->actor, (int) $row->getKey(), 2, 'adjustment-post-key-0001'));
        $this->rejected(fn () => $this->postAdjustment($approved, actor: $this->reviewer));
    }

    public function test_only_current_independently_approved_state_can_post(): void
    {
        [$row] = $this->adjustmentDraft();
        $this->rejected(fn () => $this->postAdjustment($row));
        $row = $this->adjustments()->submit($this->actor, (int) $row->getKey(), 1);
        $this->rejected(fn () => $this->postAdjustment($row));
    }

    public function test_posting_requires_enabled_activation_and_an_open_period(): void
    {
        [$row] = $this->adjustmentDraft();
        $row = $this->approvedAdjustment($row);
        $this->settings(['state' => 'disabled']);
        $this->rejected(fn () => $this->postAdjustment($row));
        $this->settings(['state' => 'enabled']);
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->update(['state' => 'hard_closed']);
        $this->rejected(fn () => $this->postAdjustment($row));
    }

    public function test_replay_survives_disabled_settings_closed_period_and_inactive_bank(): void
    {
        [$row] = $this->adjustmentDraft();
        $approved = $this->approvedAdjustment($row);
        $this->postAdjustment($approved);
        $this->settings(['state' => 'disabled']);
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->update(['state' => 'hard_closed']);
        $this->bank->update(['status' => 'inactive']);
        $this->company->update(['status' => 'inactive']);
        $before = $this->digest();
        $this->postAdjustment($approved);
        $this->assertSame($before, $this->digest());
    }

    public function test_new_charge_rejects_an_inactive_bank(): void
    {
        [$statement, $line] = $this->bankStatement();
        $counter = $this->counter('charge');
        $this->bank->update(['status' => 'inactive']);
        $this->rejected(fn () => $this->adjustments()->prepare($this->actor, $this->adjustmentInput($line, (int) $counter->getKey())));
    }

    public function test_future_or_out_of_statement_posting_dates_are_rejected(): void
    {
        [$statement, $line] = $this->bankStatement();
        $counter = $this->counter('charge');
        foreach (['2026-10-15', '2026-10-17'] as $date) {
            $this->rejected(fn () => $this->adjustments()->prepare($this->actor, $this->adjustmentInput($line, (int) $counter->getKey(), changes: ['document_date' => $date])));
        }
    }

    public function test_amount_overrides_and_party_or_gl_payload_injection_are_rejected(): void
    {
        [$statement, $line] = $this->bankStatement();
        $counter = $this->counter('charge');
        foreach (['amount' => '0.01', 'party_id' => 1, 'cash_account_id' => $this->cash->getKey(), 'journal_lines' => []] as $field => $value) {
            $this->rejected(fn () => $this->adjustments()->prepare($this->actor, [...$this->adjustmentInput($line, (int) $counter->getKey()), $field => $value]));
        }
    }

    public function test_charge_and_interest_require_corresponding_statement_directions(): void
    {
        [$statement, $line] = $this->bankStatement('interest');
        $counter = $this->counter('charge');
        $this->rejected(fn () => $this->adjustments()->prepare($this->actor, $this->adjustmentInput($line, (int) $counter->getKey(), 'charge')));
    }

    public function test_control_cash_sales_and_wrong_category_counter_accounts_are_rejected(): void
    {
        [$statement, $line] = $this->bankStatement();
        foreach ([$this->mapping['trade_ap'], $this->mapping['cash:MAIN'], $this->mapping['sales_revenue']] as $id) {
            $this->rejected(fn () => $this->adjustments()->prepare($this->actor, $this->adjustmentInput($line, $id)));
        }
    }

    public function test_counter_deactivation_after_approval_blocks_new_post(): void
    {
        [$row, , , $counter] = $this->adjustmentDraft();
        $row = $this->approvedAdjustment($row);
        $counter->update(['status' => 'inactive']);
        $this->rejected(fn () => $this->postAdjustment($row));
    }

    public function test_counter_name_changes_do_not_rewrite_reviewed_gl_identity(): void
    {
        [$row, , , $counter] = $this->adjustmentDraft();
        $row = $this->approvedAdjustment($row);
        $counter->update(['name' => 'Renamed bank fee GL']);
        $row = $this->postAdjustment($row);
        $verified = $this->verifiedAdjustment($row);
        $this->assertSame((int) $row->getKey(), (int) $verified['adjustment']->getKey());
    }

    public function test_full_charge_refund_uses_same_bank_expense_and_original_profile(): void
    {
        [$row] = $this->adjustmentDraft();
        $original = $this->postAdjustment($this->approvedAdjustment($row));
        $before = $original->fresh()->getAttributes();
        [, $refundLine] = $this->refundStatement($original);
        $refund = $this->adjustments()->prepareReversal($this->actor, (int) $original->getKey(), $this->refundInput($refundLine));
        $refund = $this->postAdjustment($this->approvedAdjustment($refund), 'refund-post-key-0001');
        $this->verifiedAdjustment($refund);
        $this->assertSame($before, $original->fresh()->getAttributes());
        $book = $this->book($this->bank);
        $this->assertTrue($book['complete']);
        $this->assertSame('0.00', $book['source_verified']['closing']);
        $this->assertCount(2, $book['rows']);
    }

    public function test_interest_refund_reverses_original_other_revenue_without_new_sales(): void
    {
        [$row] = $this->adjustmentDraft('interest');
        $original = $this->postAdjustment($this->approvedAdjustment($row));
        [, $line] = $this->refundStatement($original);
        $refund = $this->adjustments()->prepareReversal($this->actor, (int) $original->getKey(), $this->refundInput($line));
        $refund = $this->postAdjustment($this->approvedAdjustment($refund), 'refund-post-key-0001');
        $proof = $this->verifiedAdjustment($refund);
        $lines = JournalEntryLine::query()->where('journal_entry_id', $proof['journal']->getKey())->orderBy('line_number')->get();
        $this->assertSame('25.25', $lines[0]->getAttribute('credit'));
        $this->assertSame('25.25', $lines[1]->getAttribute('debit'));
    }

    public function test_full_refund_can_use_original_inactive_binding_but_not_inactive_gl(): void
    {
        [$row] = $this->adjustmentDraft();
        $original = $this->postAdjustment($this->approvedAdjustment($row));
        [, $line] = $this->refundStatement($original);
        $this->bank->update(['status' => 'inactive']);
        $refund = $this->adjustments()->prepareReversal($this->actor, (int) $original->getKey(), $this->refundInput($line));
        $refund = $this->postAdjustment($this->approvedAdjustment($refund), 'refund-post-key-0001');
        $verified = $this->verifiedAdjustment($refund);
        $this->assertSame((int) $refund->getKey(), (int) $verified['adjustment']->getKey());
    }

    public function test_partial_refund_and_same_direction_refund_are_rejected(): void
    {
        [$row] = $this->adjustmentDraft();
        $original = $this->postAdjustment($this->approvedAdjustment($row));
        [, $line] = $this->refundStatement($original, '0.01');
        $this->rejected(fn () => $this->adjustments()->prepareReversal($this->actor, (int) $original->getKey(), $this->refundInput($line)));
    }

    public function test_one_active_refund_per_original_and_no_reverse_of_reverse(): void
    {
        [$row] = $this->adjustmentDraft();
        $original = $this->postAdjustment($this->approvedAdjustment($row));
        [, $line] = $this->refundStatement($original);
        $refund = $this->adjustments()->prepareReversal($this->actor, (int) $original->getKey(), $this->refundInput($line));
        $this->rejected(fn () => $this->adjustments()->prepareReversal($this->actor, (int) $original->getKey(), $this->refundInput($line)));
    }

    public function test_cancelled_refund_releases_both_source_and_original_claims(): void
    {
        [$row] = $this->adjustmentDraft();
        $original = $this->postAdjustment($this->approvedAdjustment($row));
        [, $line] = $this->refundStatement($original);
        $refund = $this->adjustments()->prepareReversal($this->actor, (int) $original->getKey(), $this->refundInput($line));
        $this->adjustments()->cancel($this->actor, (int) $refund->getKey(), 1, 'Withdraw refund');
        $new = $this->adjustments()->prepareReversal($this->actor, (int) $original->getKey(), $this->refundInput($line));
        $this->assertNotSame($refund->getKey(), $new->getKey());
    }

    public function test_missing_operation_is_not_repaired_by_native_retry(): void
    {
        [$row] = $this->adjustmentDraft();
        $approved = $this->approvedAdjustment($row);
        $posted = $this->postAdjustment($approved);
        DB::table('accounting_bank_adjustment_operations')->where('adjustment_id', $posted->getKey())->delete();
        $this->rejected(fn () => $this->postAdjustment($approved));
    }

    public function test_missing_bank_leg_keeps_book_coverage_in_review_even_with_no_rows(): void
    {
        [$row] = $this->adjustmentDraft();
        $posted = $this->postAdjustment($this->approvedAdjustment($row));
        DB::table('journal_entry_lines')->where('source_line_type', 'bank_adjustment')->where('source_line_id', $posted->getKey())->where('cash_account_id', $this->bank->getKey())->delete();
        $book = $this->book($this->bank);
        $this->assertFalse($book['complete']);
        $this->assertContains('native_bank_adjustment_evidence_review', array_column($book['exceptions'], 'code'));
    }

    public function test_missing_statement_evidence_blocks_post_without_partial_writes(): void
    {
        [$row, $statement] = $this->adjustmentDraft();
        $row = $this->approvedAdjustment($row);
        DB::table('accounting_bank_statement_evidence')->where('statement_id', $statement->getKey())->delete();
        $this->rejected(fn () => $this->postAdjustment($row));
    }

    public function test_rehashed_corrupt_operation_still_fails_semantic_verification(): void
    {
        [$row] = $this->adjustmentDraft();
        $posted = $this->postAdjustment($this->approvedAdjustment($row));
        $op = AccountingBankAdjustmentOperation::query()->firstOrFail();
        $payload = $op->getAttribute('payload');
        $payload['actor_id'] = (int) $this->reviewer->getKey();
        DB::table('accounting_bank_adjustment_operations')->where('id', $op->getKey())->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'payload_hash' => app(AccountingCanonicalPayload::class)->hash($payload)]);
        $this->rejected(fn () => $this->verifiedAdjustment($posted));
    }

    public function test_json_object_key_reordering_keeps_evidence_stable(): void
    {
        [$row] = $this->adjustmentDraft();
        $posted = $this->postAdjustment($this->approvedAdjustment($row));
        $op = AccountingBankAdjustmentOperation::query()->firstOrFail();
        DB::table('accounting_bank_adjustment_operations')->where('id', $op->getKey())->update(['payload' => json_encode(array_reverse($op->getAttribute('payload'), true), JSON_THROW_ON_ERROR)]);
        $verified = $this->verifiedAdjustment($posted);
        $this->assertSame((int) $posted->getKey(), (int) $verified['adjustment']->getKey());
    }

    public function test_posted_adjustment_operation_audit_and_gl_are_append_only(): void
    {
        [$row] = $this->adjustmentDraft();
        $posted = $this->postAdjustment($this->approvedAdjustment($row));
        $op = AccountingBankAdjustmentOperation::query()->firstOrFail();
        $audit = AccountingBankAdjustmentAuditEvent::query()->firstOrFail();
        $this->rejected(fn () => $posted->update(['reason' => 'Changed']));
        $this->rejected(fn () => $posted->delete());
        $this->rejected(fn () => $op->update(['key_hash' => str_repeat('f', 64)]));
        $this->rejected(fn () => $audit->delete());
        $line = JournalEntryLine::query()->firstOrFail();
        $this->rejected(fn () => $line->update(['debit' => '0.01']));
    }

    public function test_second_journal_leg_failure_rolls_back_native_post_and_all_finance(): void
    {
        [$row] = $this->adjustmentDraft();
        $row = $this->approvedAdjustment($row);
        $before = $this->digest();
        $count = 0;
        JournalEntryLine::creating(static function ($line) use (&$count): void {
            if ($line->getAttribute('source_line_type') === 'bank_adjustment' && ++$count === 2) {
                throw new \RuntimeException('Synthetic bank leg failure');
            }
        });
        try {
            $this->postAdjustment($row);
            $this->fail('Expected failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic bank leg failure', $e->getMessage());
        } finally {
            JournalEntryLine::flushEventListeners();
        } $this->assertSame($before, $this->digest());
    }

    public function test_operation_or_final_audit_failure_rolls_back_complete_post(): void
    {
        [$row] = $this->adjustmentDraft();
        $row = $this->approvedAdjustment($row);
        $before = $this->digest();
        AccountingBankAdjustmentOperation::creating(static function (): void {
            throw new \RuntimeException('Synthetic bank operation failure');
        });
        try {
            $this->postAdjustment($row);
            $this->fail('Expected failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic bank operation failure', $e->getMessage());
        } finally {
            AccountingBankAdjustmentOperation::flushEventListeners();
        } $this->assertSame($before, $this->digest());
    }

    public function test_native_post_permissions_do_not_require_manual_journal_authority(): void
    {
        [$row] = $this->adjustmentDraft();
        $row = $this->approvedAdjustment($row);
        $poster = $this->staff($this->adjustmentPermissions('post'));
        $row = $this->postAdjustment($row, actor: $poster);
        $this->verifiedAdjustment($row);
        $this->assertFalse($poster->can('erp.journal-entries.post'));
    }

    public function test_view_only_or_missing_source_view_permissions_cannot_mutate(): void
    {
        [$row] = $this->adjustmentDraft();
        $viewer = $this->staff($this->adjustmentPermissions());
        $this->rejected(fn () => $this->adjustments()->submit($viewer, (int) $row->getKey(), 1));
        $user = $this->staff(['erp.bank-adjustments.view', 'erp.bank-adjustments.submit']);
        $this->rejected(fn () => $this->adjustments()->submit($user, (int) $row->getKey(), 1));
    }

    public function test_foreign_adjustment_id_and_statement_id_are_not_accessible(): void
    {
        [$row, , $line, $counter] = $this->adjustmentDraft();
        $user = $this->staff($this->adjustmentPermissions('create', 'submit'));
        $other = $this->newCompany('BA-FOREIGN');
        DB::table('employees')->where('user_id', $user->getKey())->update(['company_id' => $other->getKey()]);
        $this->rejected(fn () => $this->adjustments()->find($user, (int) $row->getKey()));
        $this->rejected(fn () => $this->adjustments()->prepare($user, $this->adjustmentInput($line, (int) $counter->getKey())));
    }

    public function test_private_reasons_bank_text_and_counter_names_are_absent_from_financial_proof(): void
    {
        [$row] = $this->adjustmentDraft();
        $posted = $this->postAdjustment($this->approvedAdjustment($row));
        $data = json_encode([DB::table('accounting_source_effects')->get(), DB::table('journal_entries')->get(), DB::table('journal_entry_lines')->get(), DB::table('accounting_bank_adjustment_operations')->get()], JSON_THROW_ON_ERROR);
        foreach (['PRIVATE-ADJUSTMENT-REASON', 'PRIVATE-BANK-REF', 'PRIVATE-BANK-TEXT', 'PRIVATE BANK COUNTER'] as $text) {
            $this->assertStringNotContainsString($text, $data);
        }
    }

    public function test_counter_precision_and_stable_cents_roundtrip(): void
    {
        [$row] = $this->adjustmentDraft(amount: '0.01');
        $posted = $this->postAdjustment($this->approvedAdjustment($row));
        $this->assertSame('0.01', $posted->fresh()->getAttribute('amount'));
        $this->verifiedAdjustment($posted);
    }

    public function test_direct_new_float_or_excess_precision_amount_is_rejected(): void
    {
        [$row] = $this->adjustmentDraft();
        $this->rejected(fn () => DB::transaction(fn () => $row->update(['amount' => 25.5, 'revision' => 2])));
        $row = $row->fresh();
        $this->rejected(fn () => DB::transaction(fn () => $row->update(['amount' => '25.250', 'revision' => 2])));
    }

    public function test_seeder_and_registered_source_contract_are_exact_and_idempotent(): void
    {
        $this->seed(AccountingBankAdjustmentPermissionSeeder::class);
        $this->seed(AccountingBankAdjustmentPermissionSeeder::class);
        $this->assertSame(8, Permission::query()->whereIn('name', AccountingBankAdjustmentContract::PERMISSIONS)->count());
        $this->assertSame(['cash' => []], app(AccountingSourceEventRegistry::class)->definition('bank_adjustment', 'posted'));
    }

    public function test_migration_collisions_are_reported_before_ddl(): void
    {
        $files = glob(database_path('migrations/*create_bank_adjustment_posting_foundation.php'));
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

    public function test_already_matched_statement_row_cannot_book_a_second_cash_effect(): void
    {
        $this->original(['amount' => '25.25', 'source_cash_account_id' => (int) $this->bank->getKey(), 'destination_cash_account_id' => (int) $this->cash->getKey()]);
        [$statement, $sl] = $this->bankStatement();
        $root = $this->start($statement);
        $bl = (int) JournalEntryLine::query()->where('cash_account_id', $this->bank->getKey())->value('id');
        $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl, ['statement_allocations' => [['id' => $sl, 'amount' => '25.25']], 'book_allocations' => [['id' => $bl, 'amount' => '25.25']]]));
        $counter = $this->counter('charge');
        $this->rejected(fn () => $this->adjustments()->prepare($this->actor, $this->adjustmentInput($sl, (int) $counter->getKey())));
    }

    public function test_matching_statement_after_approval_blocks_new_adjustment_post(): void
    {
        [$row, $statement, $sl] = $this->adjustmentDraft();
        $row = $this->approvedAdjustment($row);
        $this->original(['amount' => '25.25', 'source_cash_account_id' => (int) $this->bank->getKey(), 'destination_cash_account_id' => (int) $this->cash->getKey()]);
        $root = $this->start($statement);
        $bl = (int) JournalEntryLine::query()->where('cash_account_id', $this->bank->getKey())->value('id');
        $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl, ['statement_allocations' => [['id' => $sl, 'amount' => '25.25']], 'book_allocations' => [['id' => $bl, 'amount' => '25.25']]]));
        $this->rejected(fn () => $this->postAdjustment($row));
    }

    public function test_recognized_adjustment_statement_row_cannot_match_an_unrelated_book_entry(): void
    {
        [$row, $statement, $sl] = $this->adjustmentDraft();
        $this->postAdjustment($this->approvedAdjustment($row));
        $transfer = $this->original(['amount' => '25.25', 'source_cash_account_id' => (int) $this->bank->getKey(), 'destination_cash_account_id' => (int) $this->cash->getKey()]);
        $root = $this->start($statement);
        $bl = (int) JournalEntryLine::query()->where('cash_account_id', $this->bank->getKey())->where('source_line_type', 'cash_transfer')->where('source_line_id', $transfer->getKey())->value('id');
        $this->rejected(fn () => $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl, ['statement_allocations' => [['id' => $sl, 'amount' => '25.25']], 'book_allocations' => [['id' => $bl, 'amount' => '25.25']]])));
    }

    public function test_recognized_adjustment_book_line_cannot_match_another_statement_row(): void
    {
        $statement = $this->statement([$this->statementRow(['money_in' => '', 'money_out' => '25.25']), $this->statementRow(['money_in' => '', 'money_out' => '25.25'])], ['closing_balance' => '-50.50']);
        $ids = AccountingBankStatementLine::query()->where('statement_id', $statement->getKey())->orderBy('line_number')->pluck('id')->all();
        $counter = $this->counter('charge');
        $row = $this->adjustments()->prepare($this->actor, $this->adjustmentInput((int) $ids[0], (int) $counter->getKey()));
        $this->postAdjustment($this->approvedAdjustment($row));
        $root = $this->start($statement);
        $bl = (int) JournalEntryLine::query()->where('cash_account_id', $this->bank->getKey())->value('id');
        $this->rejected(fn () => $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, (int) $ids[1], $bl, ['statement_allocations' => [['id' => (int) $ids[1], 'amount' => '25.25']], 'book_allocations' => [['id' => $bl, 'amount' => '25.25']]])));
    }

    public function test_compensated_earlier_match_remains_valid_after_correct_bank_adjustment_is_posted(): void
    {
        $this->original(['amount' => '25.25', 'source_cash_account_id' => (int) $this->bank->getKey(), 'destination_cash_account_id' => (int) $this->cash->getKey()]);
        [$statement, $sl] = $this->bankStatement();
        $root = $this->start($statement);
        $bl = (int) JournalEntryLine::query()->where('cash_account_id', $this->bank->getKey())->value('id');
        $input = $this->group($root, $sl, $bl, ['statement_allocations' => [['id' => $sl, 'amount' => '25.25']], 'book_allocations' => [['id' => $bl, 'amount' => '25.25']]]);
        $op = $this->matcher()->match($this->actor, (int) $root->getKey(), $input);
        $this->undo($root, $op);
        $counter = $this->counter('charge');
        $row = $this->adjustments()->prepare($this->actor, $this->adjustmentInput($sl, (int) $counter->getKey()));
        $this->postAdjustment($this->approvedAdjustment($row));
        $report = $this->matcher()->review($this->actor, (int) $root->getKey());
        $this->assertCount(2, $report['history']);
        $this->assertSame('25.25', $report['statement_rows'][0]['remaining']);
    }

    public function test_refund_preserves_retired_original_profile_after_bank_role_remap(): void
    {
        [$row] = $this->adjustmentDraft();
        $original = $this->postAdjustment($this->approvedAdjustment($row));
        [, $sl] = $this->refundStatement($original);
        $group = AccountGroup::query()->where('company_id', $this->company->getKey())->where('category', 'asset')->firstOrFail();
        $newBankGl = Account::query()->create(['company_id' => $this->company->getKey(), 'account_group_id' => $group->getKey(), 'code' => 'R'.Str::uuid(), 'name' => 'Replacement bank mapping', 'normal_side' => 'debit', 'is_postable' => true, 'control_role' => null, 'statement_role' => 'current_asset', 'cash_flow_role' => 'cash', 'status' => 'active', 'revision' => 1]);
        $map = $this->mapping;
        $map['bank:CURRENT'] = (int) $newBankGl->getKey();
        $this->publish(['base_version_id' => (int) $original->getAttribute('mapping_version_id'), 'effective_from' => '2026-10-17', 'mappings' => $map]);
        $this->bank->update(['status' => 'inactive']);
        $refund = $this->adjustments()->prepareReversal($this->actor, (int) $original->getKey(), $this->refundInput($sl));
        $posted = $this->postAdjustment($this->approvedAdjustment($refund), 'refund-post-key-0001');
        $proof = $this->verifiedAdjustment($posted);
        $this->assertSame((int) $original->getAttribute('mapping_version_id'), (int) $proof['journal']->getAttribute('mapping_version_id'));
        $this->assertNotSame((int) $newBankGl->getKey(), (int) JournalEntryLine::query()->where('journal_entry_id', $proof['journal']->getKey())->where('cash_account_id', $this->bank->getKey())->value('account_id'));
    }

    public function test_profile_change_after_review_blocks_new_original_post(): void
    {
        [$row] = $this->adjustmentDraft();
        $row = $this->approvedAdjustment($row);
        $this->publish(['base_version_id' => (int) $row->getAttribute('mapping_version_id'), 'effective_from' => '2026-10-16']);
        $this->rejected(fn () => $this->postAdjustment($row));
    }

    public function test_inactive_original_bank_gl_cannot_be_used_even_by_a_refund(): void
    {
        [$row] = $this->adjustmentDraft();
        $original = $this->postAdjustment($this->approvedAdjustment($row));
        [, $sl] = $this->refundStatement($original);
        $refund = $this->adjustments()->prepareReversal($this->actor, (int) $original->getKey(), $this->refundInput($sl));
        $refund = $this->approvedAdjustment($refund);
        DB::table('accounts')->where('id', $this->bank->getAttribute('account_id'))->update(['status' => 'inactive']);
        $this->rejected(fn () => $this->postAdjustment($refund, 'refund-post-key-0001'));
    }

    public function test_backdated_post_requires_explicit_period_adjust_authority(): void
    {
        [$row] = $this->adjustmentDraft();
        $row = $this->approvedAdjustment($row);
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $user = $this->staff($this->adjustmentPermissions('post'));
        $this->rejected(fn () => $this->postAdjustment($row, actor: $user));
        $user->givePermissionTo('erp.accounting-periods.adjust');
        $this->verifiedAdjustment($this->postAdjustment($row, actor: $user));
    }

    public function test_final_native_audit_failure_rolls_back_the_whole_financial_operation(): void
    {
        [$row] = $this->adjustmentDraft();
        $row = $this->approvedAdjustment($row);
        $before = $this->digest();
        AccountingBankAdjustmentAuditEvent::creating(static function ($audit): void {
            if ($audit->getAttribute('action') === 'posted') {
                throw new \RuntimeException('Synthetic final audit failure');
            }
        });
        try {
            $this->postAdjustment($row);
            $this->fail('Expected failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic final audit failure', $e->getMessage());
        } finally {
            AccountingBankAdjustmentAuditEvent::flushEventListeners();
        } $this->assertSame($before, $this->digest());
    }

    public function test_missing_refund_operation_marks_both_original_and_refund_bank_evidence_for_review(): void
    {
        [$row] = $this->adjustmentDraft();
        $original = $this->postAdjustment($this->approvedAdjustment($row));
        [, $sl] = $this->refundStatement($original);
        $refund = $this->adjustments()->prepareReversal($this->actor, (int) $original->getKey(), $this->refundInput($sl));
        $refund = $this->postAdjustment($this->approvedAdjustment($refund), 'refund-post-key-0001');
        DB::table('accounting_bank_adjustment_operations')->where('adjustment_id', $refund->getKey())->delete();
        $book = $this->book($this->bank);
        $this->assertFalse($book['complete']);
        $this->assertContains('native_bank_adjustment_evidence_review', array_column($book['exceptions'], 'code'));
    }

    public function test_creation_replay_after_post_and_match_does_not_create_new_native_or_gl_records(): void
    {
        [$statement, $sl] = $this->bankStatement();
        $counter = $this->counter('charge');
        $input = $this->adjustmentInput($sl, (int) $counter->getKey());
        $row = $this->adjustments()->prepare($this->actor, $input);
        $this->postAdjustment($this->approvedAdjustment($row));
        $root = $this->start($statement);
        $bl = (int) JournalEntryLine::query()->where('cash_account_id', $this->bank->getKey())->value('id');
        $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl, ['statement_allocations' => [['id' => $sl, 'amount' => '25.25']], 'book_allocations' => [['id' => $bl, 'amount' => '25.25']]]));
        $before = $this->digest();
        $again = $this->adjustments()->prepare($this->actor, $input);
        $this->assertSame($row->getKey(), $again->getKey());
        $this->assertSame($before, $this->digest());
    }

    public function test_same_direction_bank_movement_cannot_refund_a_charge(): void
    {
        [$row] = $this->adjustmentDraft();
        $original = $this->postAdjustment($this->approvedAdjustment($row));
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $statement = $this->statement([$this->statementRow(['date' => '2026-10-17', 'money_in' => '', 'money_out' => '25.25'])], ['starts_on' => '2026-10-17', 'ends_on' => '2026-10-17', 'opening_balance' => '-25.25', 'closing_balance' => '-50.50']);
        $sl = (int) AccountingBankStatementLine::query()->where('statement_id', $statement->getKey())->value('id');
        $this->rejected(fn () => $this->adjustments()->prepareReversal($this->actor, (int) $original->getKey(), $this->refundInput($sl)));
    }

    public function test_wrong_reversal_counter_or_amount_inputs_are_not_accepted(): void
    {
        [$row] = $this->adjustmentDraft();
        $original = $this->postAdjustment($this->approvedAdjustment($row));
        [, $sl] = $this->refundStatement($original);
        foreach (['amount' => '0.01', 'counter_account_id' => $this->mapping['rounding']] as $field => $value) {
            $this->rejected(fn () => $this->adjustments()->prepareReversal($this->actor, (int) $original->getKey(), [...$this->refundInput($sl), $field => $value]));
        }
    }

    public function test_a_posted_refund_cannot_itself_be_reversed_by_this_contract(): void
    {
        [$row] = $this->adjustmentDraft();
        $original = $this->postAdjustment($this->approvedAdjustment($row));
        [, $sl] = $this->refundStatement($original);
        $refund = $this->adjustments()->prepareReversal($this->actor, (int) $original->getKey(), $this->refundInput($sl));
        $refund = $this->postAdjustment($this->approvedAdjustment($refund), 'refund-post-key-0001');
        $this->travelTo(CarbonImmutable::parse('2026-10-18 12:00:00', 'Asia/Dhaka'));
        $statement = $this->statement([$this->statementRow(['date' => '2026-10-18', 'money_in' => '', 'money_out' => '25.25'])], ['starts_on' => '2026-10-18', 'ends_on' => '2026-10-18', 'opening_balance' => '0.00', 'closing_balance' => '-25.25']);
        $line = (int) AccountingBankStatementLine::query()->where('statement_id', $statement->getKey())->value('id');
        $this->rejected(fn () => $this->adjustments()->prepareReversal($this->actor, (int) $refund->getKey(), $this->refundInput($line, ['document_date' => '2026-10-18'])));
    }

    public function test_coverage_audit_is_read_only_and_keeps_finalization_separate(): void
    {
        [$row] = $this->adjustmentDraft();
        $this->postAdjustment($this->approvedAdjustment($row));
        $before = $this->digest();
        $report = app(AccountingBankAdjustmentCoverageAudit::class)->review($this->actor, (int) $this->company->getKey(), (int) $this->bank->getKey());
        $this->assertTrue($report['bankbook_sources_complete']);
        $this->assertFalse($report['finalized']);
        $this->assertSame(0, $report['opening_cash_lines']);
        $this->assertSame($before, $this->digest());
    }

    public function test_legacy_unbound_opening_is_reported_instead_of_being_silently_verified(): void
    {
        [$row] = $this->adjustmentDraft();
        $posted = $this->postAdjustment($this->approvedAdjustment($row));
        $proof = $this->verifiedAdjustment($posted);
        $header = $proof['journal']->getAttributes();
        unset($header['id']);
        $header['number'] = 'LEGACY-'.Str::uuid();
        $header['entry_kind'] = 'opening';
        $header['payload_hash'] = null;
        $header['mapping_version_id'] = null;
        $id = DB::table('journal_entries')->insertGetId($header);
        $line = JournalEntryLine::query()->where('journal_entry_id', $proof['journal']->getKey())->where('cash_account_id', $this->bank->getKey())->firstOrFail()->getAttributes();
        unset($line['id']);
        $line['journal_entry_id'] = $id;
        $line['cash_account_id'] = null;
        $line['source_line_type'] = null;
        $line['source_line_id'] = null;
        DB::table('journal_entry_lines')->insert($line);
        $before = $this->digest();
        $report = app(AccountingBankAdjustmentCoverageAudit::class)->review($this->actor, (int) $this->company->getKey(), (int) $this->bank->getKey());
        $this->assertFalse($report['bankbook_sources_complete']);
        $this->assertSame(1, $report['opening_cash_lines']);
        $this->assertSame(1, $report['unbound_cash_lines']);
        $this->assertFalse($report['finalized']);
        $this->assertSame($before, $this->digest());
    }

    public function test_orphan_effect_keeps_bank_coverage_in_review_after_native_and_cash_leg_damage(): void
    {
        [$row] = $this->adjustmentDraft();
        $posted = $this->postAdjustment($this->approvedAdjustment($row));
        DB::table('accounting_bank_adjustment_operations')->where('adjustment_id', $posted->getKey())->delete();
        DB::table('accounting_bank_adjustment_audit_events')->where('adjustment_id', $posted->getKey())->delete();
        DB::table('journal_entry_lines')->where('source_line_type', 'bank_adjustment')->where('source_line_id', $posted->getKey())->where('cash_account_id', $this->bank->getKey())->delete();
        DB::table('accounting_bank_adjustments')->where('id', $posted->getKey())->delete();
        $before = $this->digest();
        $book = $this->book($this->bank);
        $this->assertFalse($book['complete']);
        $this->assertSame([], $book['rows']);
        $this->assertContains('native_bank_adjustment_evidence_review', array_column($book['exceptions'], 'code'));
        $this->assertSame($before, $this->digest());
    }

    public function test_refund_of_closed_original_posts_in_its_new_open_statement_period(): void
    {
        [$row] = $this->adjustmentDraft();
        $original = $this->postAdjustment($this->approvedAdjustment($row));
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->where('code', 'OCT')->update(['state' => 'hard_closed']);
        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00', 'Asia/Dhaka'));
        $year = (int) DB::table('fiscal_years')->where('company_id', $this->company->getKey())->value('id');
        app(AccountingCalendarService::class)->savePeriod($this->actor, $year, ['company_id' => $this->company->getKey(), 'code' => 'NOV', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'reason' => 'Current refund period']);
        $statement = $this->statement([$this->statementRow(['date' => '2026-11-01', 'money_in' => '25.25', 'money_out' => ''])], ['starts_on' => '2026-11-01', 'ends_on' => '2026-11-01', 'opening_balance' => '-25.25', 'closing_balance' => '0.00']);
        $line = (int) AccountingBankStatementLine::query()->where('statement_id', $statement->getKey())->value('id');
        $refund = $this->adjustments()->prepareReversal($this->actor, (int) $original->getKey(), $this->refundInput($line, ['document_date' => '2026-11-01']));
        $refund = $this->postAdjustment($this->approvedAdjustment($refund), 'refund-post-key-0001');
        $this->assertSame('2026-11-01', $this->verifiedAdjustment($refund)['journal']->getAttribute('accounting_date')->format('Y-m-d'));
    }

    public function test_completed_refund_proof_cannot_authorize_another_inactive_bank_cash_line(): void
    {
        [$row] = $this->adjustmentDraft();
        $original = $this->postAdjustment($this->approvedAdjustment($row));
        [, $sl] = $this->refundStatement($original);
        $refund = $this->adjustments()->prepareReversal($this->actor, (int) $original->getKey(), $this->refundInput($sl));
        $refund = $this->postAdjustment($this->approvedAdjustment($refund), 'refund-post-key-0001');
        $this->bank->update(['status' => 'inactive']);
        $parent = JournalEntry::query()->create(['company_id' => $this->company->getKey(), 'accounting_period_id' => DB::table('accounting_periods')->where('company_id', $this->company->getKey())->value('id'), 'mapping_version_id' => $original->getAttribute('mapping_version_id'), 'number' => 'FAKE-'.Str::uuid(), 'entry_kind' => 'automatic', 'status' => 'draft', 'accounting_date' => '2026-10-17', 'document_date' => '2026-10-17', 'currency_code' => 'BDT', 'description' => 'Unrelated draft', 'created_by' => $this->actor->getKey(), 'updated_by' => $this->actor->getKey(), 'revision' => 1, 'contract_version' => '1.0.0']);
        $this->rejected(fn () => JournalEntryLine::query()->create(['company_id' => $this->company->getKey(), 'journal_entry_id' => $parent->getKey(), 'line_number' => 1, 'account_id' => $this->bank->getAttribute('account_id'), 'cash_account_id' => $this->bank->getKey(), 'source_line_type' => 'bank_adjustment', 'source_line_id' => $refund->getKey(), 'debit' => '25.25', 'credit' => '0.00']));
    }

    public function test_direct_source_orchestrator_cannot_rebuild_missing_native_financial_members(): void
    {
        [$row] = $this->adjustmentDraft();
        $posted = $this->postAdjustment($this->approvedAdjustment($row));
        DB::table('accounting_bank_adjustment_operations')->where('adjustment_id', $posted->getKey())->delete();
        DB::table('accounting_source_effects')->where('source_kind', 'bank_adjustment')->where('source_id', $posted->getKey())->delete();
        $this->rejected(fn () => DB::transaction(fn () => app(AccountingSourceOrchestrator::class)->execute($this->actor, (int) $this->company->getKey(), 'bank_adjustment', (int) $posted->getKey(), 'posted', (string) $posted->getAttribute('posting_key_hash'))));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingBankAdjustment;
use App\Models\Accounting\AccountingBankFinalizationEvent;
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
use App\Services\ERP\Accounting\Reconciliation\AccountingBankFinalizationEvidence;
use App\Services\ERP\Accounting\Reconciliation\AccountingBankFinalizationGuard;
use App\Services\ERP\Accounting\Reconciliation\AccountingBankFinalizationReadiness;
use App\Services\ERP\Accounting\Reconciliation\AccountingBankFinalizationWorkflow;
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
use Database\Seeders\AccountingBankFinalizationPermissionSeeder;
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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AccountingBankFinalizationWorkflowTest extends TestCase
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
        $this->seed(AccountingBankFinalizationPermissionSeeder::class);
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

    private function reviewUrl(AccountingBankReconciliation $root): string
    {
        return '/erp/accounting/bank-reconciliations/'.$root->getKey().'/readiness';
    }

    /** @return array<string, mixed> */
    private function readiness(AccountingBankReconciliation $root, ?User $actor = null): array
    {
        return app(AccountingBankFinalizationReadiness::class)->review($actor ?? $this->actor, (int) $root->getKey());
    }

    /** @return array{AccountingBankReconciliation, AccountingBankReconciliationOperation} */
    private function matchedRoot(): array
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $op = $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl));

        return [$root, $op];
    }

    /** @param array<string, mixed> $review */
    private function checkState(array $review, string $code): string
    {
        foreach ($review['checks'] as $check) {
            if ($check['code'] === $code) {
                return $check['state'];
            }
        }
        $this->fail('Missing readiness gate: '.$code);
    }

    private function finals(): AccountingBankFinalizationWorkflow
    {
        return app(AccountingBankFinalizationWorkflow::class);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function finalInput(AccountingBankReconciliation $root, array $overrides = []): array
    {
        $current = $root->fresh();

        return ['revision' => (int) $current->getAttribute('revision'), 'finalization_revision' => (int) $current->getAttribute('finalization_revision'), 'reason' => 'Independent bank reconciliation review', 'key' => (string) Str::uuid(), ...$overrides];
    }

    private function submitFinal(AccountingBankReconciliation $root, ?User $actor = null): AccountingBankFinalizationEvent
    {
        return $this->finals()->execute($actor ?? $this->actor, (int) $root->getKey(), 'submit', $this->finalInput($root));
    }

    private function finalize(AccountingBankReconciliation $root): AccountingBankFinalizationEvent
    {
        $this->submitFinal($root);

        return $this->finals()->execute($this->reviewer, (int) $root->getKey(), 'approve', $this->finalInput($root));
    }

    private function finalDigest(): string
    {
        return hash('sha256', json_encode([$this->digest(), DB::table('accounting_bank_finalization_events')->orderBy('id')->get()], JSON_THROW_ON_ERROR));
    }

    private function finalRejected(callable $callback): void
    {
        $before = $this->finalDigest();
        try {
            $callback();
            $this->fail('Expected rejected finalization change.');
        } catch (ValidationException|AuthorizationException|\Illuminate\Database\Eloquent\ModelNotFoundException|\LogicException $error) {
            $this->assertNotSame('', $error->getMessage());
        }
        $this->assertSame($before, $this->finalDigest());
    }

    private function finalUrl(AccountingBankReconciliation $root): string
    {
        return '/erp/accounting/bank-reconciliations/'.$root->getKey().'/finalization';
    }

    /** @return list<string> */
    private function finalPermissions(string ...$actions): array
    {
        return ['erp.bank-reconciliations.view', 'erp.bank-statements.view', 'erp.cash-books.view', ...array_map(static fn (string $action): string => 'erp.bank-reconciliations.'.$action, $actions)];
    }

    private function nextMatchedRoot(): AccountingBankReconciliation
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $transfer = $this->original(['document_date' => '2026-10-17']);
        $statement = $this->statement([$this->statementRow(['date' => '2026-10-17'])], ['starts_on' => '2026-10-17', 'ends_on' => '2026-10-17', 'opening_balance' => '100.00', 'closing_balance' => '200.00']);
        $root = $this->start($statement);
        $sl = (int) AccountingBankStatementLine::query()->where('statement_id', $statement->getKey())->value('id');
        $bl = (int) JournalEntryLine::query()->where('cash_account_id', $this->bank->getKey())->where('source_line_type', 'cash_transfer')->where('source_line_id', $transfer->getKey())->value('id');
        $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl));

        return $root;
    }

    public function test_submit_and_independent_approval_freeze_without_financial_or_matching_writes(): void
    {
        [$root] = $this->matchedRoot();
        $financial = $this->financialDigest();
        $nativeRevision = $root->fresh()->getAttribute('revision');
        $matches = DB::table('accounting_bank_reconciliation_operations')->count();
        $this->finalize($root);
        $view = $this->finals()->review($this->actor, (int) $root->getKey());
        $this->assertSame('finalized', $view['state']);
        $this->assertTrue($view['frozen']);
        $this->assertSame(2, $view['finalization_revision']);
        $this->assertSame($nativeRevision, $root->fresh()->getAttribute('revision'));
        $this->assertSame($financial, $this->financialDigest());
        $this->assertSame($matches, DB::table('accounting_bank_reconciliation_operations')->count());
    }

    public function test_maker_cannot_approve_even_with_super_admin_authority(): void
    {
        [$root] = $this->matchedRoot();
        $this->submitFinal($root);
        $this->finalRejected(fn () => $this->finals()->execute($this->actor, (int) $root->getKey(), 'approve', $this->finalInput($root)));
    }

    public function test_different_submitter_cannot_approve_own_submission(): void
    {
        [$root] = $this->matchedRoot();
        $this->submitFinal($root, $this->reviewer);
        $this->finalRejected(fn () => $this->finals()->execute($this->reviewer, (int) $root->getKey(), 'approve', $this->finalInput($root)));
    }

    public function test_matching_participant_cannot_approve_another_users_submission(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $matcher = $this->staff($this->finalPermissions('match', 'approve'));
        $this->matcher()->match($matcher, (int) $root->getKey(), $this->group($root, $sl, $bl));
        $this->submitFinal($root);
        $this->finalRejected(fn () => $this->finals()->execute($matcher, (int) $root->getKey(), 'approve', $this->finalInput($root)));
    }

    public function test_unmatched_statement_blocks_submission_even_with_zero_final_difference(): void
    {
        [$root] = $this->fixture();
        $this->finalRejected(fn () => $this->submitFinal($root));
        $this->assertDatabaseCount('accounting_bank_finalization_events', 0);
    }

    public function test_outstanding_book_item_is_preserved_in_approved_evidence(): void
    {
        [$root] = $this->matchedRoot();
        $this->original();
        $this->finalize($root);
        $event = AccountingBankFinalizationEvent::query()->where('event_kind', 'submit')->firstOrFail();
        $snapshot = $event->getAttribute('payload')['subject']['snapshot'];
        $this->assertSame('-100.00', $snapshot['bridge']['statement_minus_book']);
        $this->assertSame('100.00', $snapshot['bridge']['outstanding_book_net']);
    }

    public function test_rejection_preserves_submission_and_allows_fresh_review(): void
    {
        [$root] = $this->matchedRoot();
        $this->submitFinal($root);
        $this->finals()->execute($this->reviewer, (int) $root->getKey(), 'reject', $this->finalInput($root));
        $this->assertSame('rejected', $this->finals()->review($this->actor, (int) $root->getKey())['state']);
        $this->finalize($root);
        $this->assertDatabaseCount('accounting_bank_finalization_events', 4);
    }

    public function test_matching_change_after_submission_requires_resubmission(): void
    {
        [$root, $op] = $this->matchedRoot();
        $this->submitFinal($root);
        $this->undo($root, $op);
        $this->finalRejected(fn () => $this->finals()->execute($this->reviewer, (int) $root->getKey(), 'approve', $this->finalInput($root)));
        $view = $this->finals()->review($this->reviewer, (int) $root->getKey());
        $this->assertTrue($view['stale']);
        $this->assertFalse($view['capabilities']['approve']);
    }

    public function test_new_book_evidence_after_submission_is_not_silently_approved(): void
    {
        [$root] = $this->matchedRoot();
        $this->submitFinal($root);
        $this->original();
        $this->finalRejected(fn () => $this->finals()->execute($this->reviewer, (int) $root->getKey(), 'approve', $this->finalInput($root)));
        $this->submitFinal($root);
        $this->finals()->execute($this->reviewer, (int) $root->getKey(), 'approve', $this->finalInput($root));
        $this->assertSame('finalized', $this->finals()->review($this->actor, (int) $root->getKey())['state']);
    }

    public function test_submit_retry_replays_without_new_evidence(): void
    {
        [$root] = $this->matchedRoot();
        $input = $this->finalInput($root);
        $first = $this->finals()->execute($this->actor, (int) $root->getKey(), 'submit', $input);
        $before = $this->finalDigest();
        $retry = $this->finals()->execute($this->actor, (int) $root->getKey(), 'submit', $input);
        $this->assertSame($first->getKey(), $retry->getKey());
        $this->assertSame($before, $this->finalDigest());
    }

    public function test_approve_retry_replays_without_new_evidence(): void
    {
        [$root] = $this->matchedRoot();
        $this->submitFinal($root);
        $input = $this->finalInput($root);
        $first = $this->finals()->execute($this->reviewer, (int) $root->getKey(), 'approve', $input);
        $before = $this->finalDigest();
        $retry = $this->finals()->execute($this->reviewer, (int) $root->getKey(), 'approve', $input);
        $this->assertSame($first->getKey(), $retry->getKey());
        $this->assertSame($before, $this->finalDigest());
    }

    public function test_reopen_retry_replays_without_new_evidence(): void
    {
        [$root] = $this->matchedRoot();
        $this->finalize($root);
        $input = $this->finalInput($root);
        $first = $this->finals()->execute($this->actor, (int) $root->getKey(), 'reopen', $input);
        $before = $this->finalDigest();
        $retry = $this->finals()->execute($this->actor, (int) $root->getKey(), 'reopen', $input);
        $this->assertSame($first->getKey(), $retry->getKey());
        $this->assertSame($before, $this->finalDigest());
    }

    public function test_same_key_conflicting_actor_reason_or_action_is_rejected(): void
    {
        [$root] = $this->matchedRoot();
        $input = $this->finalInput($root);
        $this->finals()->execute($this->actor, (int) $root->getKey(), 'submit', $input);
        $this->finalRejected(fn () => $this->finals()->execute($this->reviewer, (int) $root->getKey(), 'submit', $input));
        $this->finalRejected(fn () => $this->finals()->execute($this->actor, (int) $root->getKey(), 'submit', [...$input, 'reason' => 'Changed intent']));
        $this->finalRejected(fn () => $this->finals()->execute($this->reviewer, (int) $root->getKey(), 'approve', $input));
    }

    public function test_stale_native_or_finalization_revisions_do_not_write(): void
    {
        [$root] = $this->matchedRoot();
        $this->finalRejected(fn () => $this->finals()->execute($this->actor, (int) $root->getKey(), 'submit', $this->finalInput($root, ['revision' => 1])));
        $this->submitFinal($root);
        $this->finalRejected(fn () => $this->finals()->execute($this->reviewer, (int) $root->getKey(), 'approve', $this->finalInput($root, ['finalization_revision' => 0])));
    }

    public function test_client_cannot_supply_snapshots_hashes_or_approval_identity(): void
    {
        [$root] = $this->matchedRoot();
        foreach (['snapshot' => [], 'snapshot_hash' => str_repeat('a', 64), 'approved_by' => $this->reviewer->getKey()] as $field => $value) {
            $this->finalRejected(fn () => $this->finals()->execute($this->actor, (int) $root->getKey(), 'submit', $this->finalInput($root, [$field => $value])));
        }
    }

    public function test_finalized_root_cannot_match_or_undo(): void
    {
        [$root, $op] = $this->matchedRoot();
        $this->finalize($root);
        $this->finalRejected(fn () => $this->undo($root, $op));
        $sl = (int) AccountingBankStatementLine::query()->where('statement_id', $root->getAttribute('statement_id'))->value('id');
        $bl = (int) JournalEntryLine::query()->where('cash_account_id', $this->bank->getKey())->value('id');
        $this->finalRejected(fn () => $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root->fresh(), $sl, $bl)));
    }

    public function test_original_match_replay_is_read_only_after_finalization(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $input = $this->group($root, $sl, $bl);
        $op = $this->matcher()->match($this->actor, (int) $root->getKey(), $input);
        $this->finalize($root);
        $before = $this->finalDigest();
        $retry = $this->matcher()->match($this->actor, (int) $root->getKey(), $input);
        $this->assertSame($op->getKey(), $retry->getKey());
        $this->assertSame($before, $this->finalDigest());
    }

    public function test_backdated_native_bank_post_rolls_back_every_financial_member(): void
    {
        [$root] = $this->matchedRoot();
        $this->finalize($root);
        $this->finalRejected(fn () => DB::transaction(fn () => $this->original()));
    }

    public function test_later_bank_post_and_matching_do_not_invalidate_earlier_finalization(): void
    {
        [$root] = $this->matchedRoot();
        $this->finalize($root);
        $next = $this->nextMatchedRoot();
        $this->assertSame('finalized', $this->finals()->review($this->actor, (int) $root->getKey())['state']);
        $this->assertSame('working', $this->finals()->review($this->actor, (int) $next->getKey())['state']);
        $this->finalize($next);
        $this->assertSame('finalized', $this->finals()->review($this->actor, (int) $root->getKey())['state']);
    }

    public function test_earlier_statement_import_is_blocked_after_finalization(): void
    {
        [$root] = $this->matchedRoot();
        $this->finalize($root);
        $this->finalRejected(fn () => $this->statement([$this->statementRow(['date' => '2026-09-29'])], ['starts_on' => '2026-09-29', 'ends_on' => '2026-09-29']));
    }

    public function test_earlier_statement_cannot_gain_a_workspace_after_later_finalization(): void
    {
        [$root] = $this->matchedRoot();
        $this->finalize($root);
        $this->finalRejected(fn () => DB::transaction(function (): void {
            app(AccountingBankFinalizationGuard::class)->assertDate((int) $this->company->getKey(), (int) $this->bank->getKey(), '2026-10-01');
        }));
    }

    public function test_later_reconciliation_requires_earlier_finalization(): void
    {
        [$root] = $this->matchedRoot();
        $next = $this->nextMatchedRoot();
        $this->finalRejected(fn () => $this->submitFinal($next));
        $this->finalize($root);
        $this->submitFinal($next);
        $this->assertSame('submitted', $this->finals()->review($this->actor, (int) $next->getKey())['state']);
    }

    public function test_reopen_requires_reverse_dependency_order_and_preserves_approval(): void
    {
        [$root] = $this->matchedRoot();
        $this->finalize($root);
        $next = $this->nextMatchedRoot();
        $this->finalize($next);
        $this->finalRejected(fn () => $this->finals()->execute($this->actor, (int) $root->getKey(), 'reopen', $this->finalInput($root)));
        $this->finals()->execute($this->actor, (int) $next->getKey(), 'reopen', $this->finalInput($next));
        $this->finals()->execute($this->actor, (int) $root->getKey(), 'reopen', $this->finalInput($root));
        $this->assertSame(2, DB::table('accounting_bank_finalization_events')->where('event_kind', 'approve')->count());
        $this->assertSame('reopened', $this->finals()->review($this->actor, (int) $root->getKey())['state']);
    }

    public function test_reopen_restores_native_matching_authority_without_changing_finance(): void
    {
        [$root, $op] = $this->matchedRoot();
        $this->finalize($root);
        $financial = $this->financialDigest();
        $this->finals()->execute($this->actor, (int) $root->getKey(), 'reopen', $this->finalInput($root));
        $this->undo($root, $op);
        $this->assertSame($financial, $this->financialDigest());
        $this->assertFalse($this->readiness($root)['review']['technical_ready']);
    }

    public function test_reopen_does_not_grant_match_or_journal_permissions(): void
    {
        [$root] = $this->matchedRoot();
        $this->finalize($root);
        $user = $this->staff($this->finalPermissions('reopen'));
        $this->finals()->execute($user, (int) $root->getKey(), 'reopen', $this->finalInput($root));
        $this->assertFalse($user->can('erp.bank-reconciliations.match'));
        $this->assertFalse($user->can('erp.journal-entries.post'));
        $this->assertSame('reopened', $this->finals()->review($user, (int) $root->getKey())['state']);
    }

    public function test_ordinary_save_and_delete_cannot_change_finalization_evidence(): void
    {
        [$root] = $this->matchedRoot();
        $event = $this->finalize($root);
        $this->finalRejected(fn () => $event->update(['reason' => 'Rewrite']));
        $this->finalRejected(fn () => $event->delete());
    }

    public function test_ordinary_root_save_cannot_rewrite_finalization_anchor(): void
    {
        [$root] = $this->matchedRoot();
        $this->finalize($root);
        $this->finalRejected(fn () => $root->fresh()->forceFill(['finalization_revision' => 0, 'finalization_head_id' => null, 'finalization_hash' => null])->save());
    }

    public function test_missing_final_event_is_not_rebuilt_by_retry(): void
    {
        [$root] = $this->matchedRoot();
        $this->submitFinal($root);
        $input = $this->finalInput($root);
        $event = $this->finals()->execute($this->reviewer, (int) $root->getKey(), 'approve', $input);
        DB::table('accounting_bank_reconciliations')->where('id', $root->getKey())->update(['finalization_head_id' => null]);
        DB::table('accounting_bank_finalization_events')->where('id', $event->getKey())->delete();
        $this->finalRejected(fn () => $this->finals()->execute($this->reviewer, (int) $root->getKey(), 'approve', $input));
        $this->assertSame('review', $this->finals()->review($this->actor, (int) $root->getKey())['state']);
    }

    public function test_changed_payload_or_head_blocks_all_actions_without_repair(): void
    {
        [$root] = $this->matchedRoot();
        $this->finalize($root);
        DB::table('accounting_bank_reconciliations')->where('id', $root->getKey())->update(['finalization_hash' => str_repeat('0', 64)]);
        $before = $this->finalDigest();
        $view = $this->finals()->review($this->actor, (int) $root->getKey());
        $this->assertSame('review', $view['state']);
        $this->assertSame(['submit' => false, 'approve' => false, 'reject' => false, 'reopen' => false], $view['capabilities']);
        $this->assertSame($before, $this->finalDigest());
    }

    public function test_rehashed_approval_by_a_maker_still_fails_semantic_evidence(): void
    {
        [$root] = $this->matchedRoot();
        $event = $this->finalize($root);
        $payload = $event->getAttribute('payload');
        $payload['actor_id'] = (int) $this->actor->getKey();
        $evidence = app(AccountingBankFinalizationEvidence::class);
        $payload['request_hash'] = $evidence->request((int) $this->actor->getKey(), (int) $root->getKey(), 'approve', (int) $payload['request_revision'], (int) $payload['sequence'] - 1, $payload['reason']);
        $hash = app(AccountingCanonicalPayload::class)->hash($payload);
        DB::table('accounting_bank_finalization_events')->where('id', $event->getKey())->update(['actor_id' => $this->actor->getKey(), 'request_hash' => $payload['request_hash'], 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'payload_hash' => $hash]);
        DB::table('accounting_bank_reconciliations')->where('id', $root->getKey())->update(['finalization_hash' => $hash]);
        $this->assertSame('review', $this->finals()->review($this->actor, (int) $root->getKey())['state']);
    }

    public function test_json_object_key_reordering_preserves_evidence(): void
    {
        [$root] = $this->matchedRoot();
        $event = $this->finalize($root);
        DB::table('accounting_bank_finalization_events')->where('id', $event->getKey())->update(['payload' => json_encode(array_reverse($event->getAttribute('payload'), true), JSON_THROW_ON_ERROR)]);
        $this->assertSame('finalized', $this->finals()->review($this->actor, (int) $root->getKey())['state']);
    }

    public function test_new_event_failure_leaves_no_partial_head_or_financial_write(): void
    {
        [$root] = $this->matchedRoot();
        AccountingBankFinalizationEvent::creating(static function (): void {
            throw ValidationException::withMessages(['test' => 'Injected finalization failure']);
        });
        $this->finalRejected(fn () => $this->submitFinal($root));
        $this->assertSame(0, (int) $root->fresh()->getAttribute('finalization_revision'));
    }

    public function test_head_update_failure_rolls_back_appended_event(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite trigger failure injection; use the documented MySQL concurrency checks.');
        }
        [$root] = $this->matchedRoot();
        DB::statement("CREATE TRIGGER reject_final_head BEFORE UPDATE OF finalization_revision ON accounting_bank_reconciliations BEGIN SELECT RAISE(ABORT, 'fixture final head failure'); END");
        $before = $this->finalDigest();
        try {
            $this->submitFinal($root);
            $this->fail('Expected injected database failure');
        } catch (QueryException) {
            $this->assertSame($before, $this->finalDigest());
        } finally {
            DB::statement('DROP TRIGGER reject_final_head');
        }
    }

    public function test_closed_period_disabled_settings_and_inactive_binding_allow_verified_replay(): void
    {
        [$root] = $this->matchedRoot();
        $this->submitFinal($root);
        $input = $this->finalInput($root);
        $this->finals()->execute($this->reviewer, (int) $root->getKey(), 'approve', $input);
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->update(['state' => 'hard_closed']);
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['state' => 'disabled']);
        $this->bank->update(['status' => 'inactive']);
        $before = $this->finalDigest();
        $this->finals()->execute($this->reviewer, (int) $root->getKey(), 'approve', $input);
        $this->assertSame($before, $this->finalDigest());
    }

    public function test_view_and_source_permissions_are_required_for_http(): void
    {
        [$root] = $this->matchedRoot();
        $this->get($this->finalUrl($root))->assertRedirect('/login');
        $permissions = $this->finalPermissions();
        foreach ($permissions as $missing) {
            $user = $this->staff(array_values(array_diff($permissions, [$missing])));
            $this->actingAs($user)->get($this->finalUrl($root))->assertForbidden();
        }
    }

    public function test_read_only_user_cannot_mutate_any_finalization_action(): void
    {
        [$root] = $this->matchedRoot();
        $before = $this->finalDigest();
        $user = $this->staff($this->finalPermissions());
        $this->actingAs($user);
        foreach (['submit', 'approve', 'reject', 'reopen'] as $action) {
            $this->post($this->finalUrl($root).'/'.$action, $this->finalInput($root))->assertForbidden();
        } $this->assertSame($before, $this->finalDigest());
    }

    public function test_foreign_native_id_and_company_mismatch_do_not_expose_evidence(): void
    {
        [$root] = $this->matchedRoot();
        $other = $this->newCompany('FINAL-FOREIGN');
        $user = $this->staff($this->finalPermissions('submit'));
        Employee::query()->where('user_id', $user->getKey())->update(['company_id' => $other->getKey()]);
        $this->actingAs($user)->get($this->finalUrl($root))->assertNotFound();
        $this->actingAs($this->actor)->get($this->finalUrl($root).'?company_id='.$other->getKey())->assertForbidden();
    }

    public function test_finalization_page_and_working_screens_resolve_safe_locked_state(): void
    {
        [$root] = $this->matchedRoot();
        $this->finalize($root);
        $before = $this->finalDigest();
        $this->actingAs($this->actor)->get($this->finalUrl($root))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/BankReconciliations/Finalization')->where('state', 'finalized')->has('history', 2)->missing('snapshot')->missing('payload_hash')->missing('history.0.payload'));
        $this->get('/erp/accounting/bank-reconciliations/'.$root->getKey())->assertInertia(fn (Assert $page) => $page->where('finalization.state', 'finalized')->where('capabilities.match', false)->where('capabilities.unmatch', false));
        $this->get($this->reviewUrl($root))->assertInertia(fn (Assert $page) => $page->where('review.finalized', true)->where('review.approval_required', false));
        $this->assertSame($before, $this->finalDigest());
    }

    public function test_http_submit_approve_and_reopen_use_native_permissions(): void
    {
        [$root] = $this->matchedRoot();
        $this->actingAs($this->actor)->post($this->finalUrl($root).'/submit', $this->finalInput($root))->assertRedirect();
        $this->actingAs($this->reviewer)->post($this->finalUrl($root).'/approve', $this->finalInput($root))->assertRedirect();
        $this->actingAs($this->actor)->post($this->finalUrl($root).'/reopen', $this->finalInput($root))->assertRedirect();
        $this->assertSame('reopened', $this->finals()->review($this->actor, (int) $root->getKey())['state']);
    }

    public function test_reason_and_request_key_are_mandatory(): void
    {
        [$root] = $this->matchedRoot();
        $this->finalRejected(fn () => $this->finals()->execute($this->actor, (int) $root->getKey(), 'submit', $this->finalInput($root, ['reason' => ''])));
        $this->finalRejected(fn () => $this->finals()->execute($this->actor, (int) $root->getKey(), 'submit', $this->finalInput($root, ['key' => 'short'])));
    }

    public function test_inactive_company_prevents_new_finalization_changes(): void
    {
        [$root] = $this->matchedRoot();
        $this->company->update(['status' => 'inactive']);
        $this->finalRejected(fn () => $this->submitFinal($root));
    }

    public function test_seeder_is_idempotent_and_does_not_grant_native_matching_or_manual_journal_permissions(): void
    {
        $this->seed(AccountingBankFinalizationPermissionSeeder::class);
        $this->seed(AccountingBankFinalizationPermissionSeeder::class);
        foreach (AccountingBankFinalizationWorkflow::PERMISSIONS as $permission) {
            $this->assertSame(1, Permission::query()->where('name', $permission)->where('guard_name', 'web')->count());
        } $user = $this->staff($this->finalPermissions('submit'));
        $this->assertFalse($user->can('erp.bank-reconciliations.match'));
        $this->assertFalse($user->can('erp.journal-entries.approve'));
    }

    public function test_migration_collision_stops_before_ddl(): void
    {
        $files = glob(database_path('migrations/*create_bank_reconciliation_finalization.php'));
        $this->assertIsArray($files);
        $this->assertCount(1, $files);
        $migration = require $files[0];
        $before = $this->finalDigest();
        try {
            $migration->up();
            $this->fail('Expected preflight collision');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('No DDL was attempted', $error->getMessage());
        } $this->assertSame($before, $this->finalDigest());
    }

    public function test_approval_retry_after_reopen_and_undo_never_refinalizes(): void
    {
        [$root, $match] = $this->matchedRoot();
        $this->submitFinal($root);
        $input = $this->finalInput($root);
        $approval = $this->finals()->execute($this->reviewer, (int) $root->getKey(), 'approve', $input);
        $this->finals()->execute($this->actor, (int) $root->getKey(), 'reopen', $this->finalInput($root));
        $this->undo($root, $match);
        $before = $this->finalDigest();
        $retry = $this->finals()->execute($this->reviewer, (int) $root->getKey(), 'approve', $input);
        $this->assertSame($approval->getKey(), $retry->getKey());
        $this->assertSame($before, $this->finalDigest());
        $this->assertSame('reopened', $this->finals()->review($this->actor, (int) $root->getKey())['state']);
    }

    public function test_prepared_native_payment_cannot_post_into_a_newly_finalized_cutoff(): void
    {
        [$root] = $this->matchedRoot();
        $workflow = app(AccountingCashTransferWorkflow::class);
        $row = $workflow->save($this->actor, ['company_id' => (int) $this->company->getKey(), 'document_date' => '2026-10-16', 'source_cash_account_id' => (int) $this->cash->getKey(), 'destination_cash_account_id' => (int) $this->bank->getKey(), 'amount' => '25.00', 'purpose' => 'Prepared before bank finalization', 'notes' => null, 'key' => (string) Str::uuid()]);
        $row = $workflow->submit($this->actor, (int) $row->getKey(), 1);
        $row = $workflow->approve($this->reviewer, (int) $row->getKey(), 2);
        $this->finalize($root);
        $this->finalRejected(fn () => app(AccountingCashTransferPosting::class)->post($this->actor, (int) $row->getKey(), 3, (string) Str::uuid()));
        $this->assertSame('approved', $row->fresh()->getAttribute('status'));
    }

    public function test_finalization_reads_do_not_expose_snapshot_or_approval_hashes(): void
    {
        [$root] = $this->matchedRoot();
        $this->finalize($root);
        $view = $this->finals()->review($this->actor, (int) $root->getKey());
        $this->assertArrayNotHasKey('snapshot', $view);
        $this->assertArrayNotHasKey('evidence_hash', $view);
        foreach ($view['history'] as $event) {
            $this->assertArrayNotHasKey('payload', $event);
            $this->assertArrayNotHasKey('payload_hash', $event);
        }
    }

    public function test_later_statement_can_clear_an_earlier_approved_outstanding_book_item(): void
    {
        [$root] = $this->matchedRoot();
        $outstanding = $this->original();
        $this->finalize($root);
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $statement = $this->statement([$this->statementRow(['date' => '2026-10-17'])], ['starts_on' => '2026-10-17', 'ends_on' => '2026-10-17', 'opening_balance' => '100.00', 'closing_balance' => '200.00']);
        $next = $this->start($statement);
        $sl = (int) AccountingBankStatementLine::query()->where('statement_id', $statement->getKey())->value('id');
        $bl = (int) JournalEntryLine::query()->where('cash_account_id', $this->bank->getKey())->where('source_line_type', 'cash_transfer')->where('source_line_id', $outstanding->getKey())->value('id');
        $this->matcher()->match($this->actor, (int) $next->getKey(), $this->group($next, $sl, $bl));
        $this->assertSame('finalized', $this->finals()->review($this->actor, (int) $root->getKey())['state']);
        $this->assertSame('100.00', $this->readiness($root)['review']['bridge']['outstanding_book_net']);
        $this->finalize($next);
        $this->assertSame('finalized', $this->finals()->review($this->actor, (int) $next->getKey())['state']);
    }

    public function test_existing_draft_bank_journal_cannot_post_after_cutoff_finalization(): void
    {
        [$root] = $this->matchedRoot();
        $journal = DB::transaction(function (): JournalEntry {
            $journal = JournalEntry::query()->create(['company_id' => $this->company->getKey(), 'accounting_period_id' => DB::table('accounting_periods')->where('company_id', $this->company->getKey())->value('id'), 'mapping_version_id' => DB::table('accounting_mapping_versions')->where('company_id', $this->company->getKey())->value('id'), 'number' => 'PREPARED-FINAL-'.Str::uuid(), 'entry_kind' => 'automatic', 'status' => 'draft', 'accounting_date' => '2026-10-16', 'document_date' => '2026-10-16', 'currency_code' => 'BDT', 'description' => 'Prepared before finalization', 'created_by' => $this->actor->getKey(), 'updated_by' => $this->actor->getKey(), 'revision' => 1, 'contract_version' => '1.0.0']);
            JournalEntryLine::query()->create(['company_id' => $this->company->getKey(), 'journal_entry_id' => $journal->getKey(), 'line_number' => 1, 'account_id' => $this->bank->getAttribute('account_id'), 'cash_account_id' => $this->bank->getKey(), 'debit' => '25.00', 'credit' => '0.00']);
            JournalEntryLine::query()->create(['company_id' => $this->company->getKey(), 'journal_entry_id' => $journal->getKey(), 'line_number' => 2, 'account_id' => $this->cash->getAttribute('account_id'), 'cash_account_id' => $this->cash->getKey(), 'debit' => '0.00', 'credit' => '25.00']);

            return $journal;
        });
        $this->finalize($root);
        $this->finalRejected(fn () => DB::transaction(fn () => $journal->forceFill(['status' => 'posted', 'posted_by' => $this->actor->getKey(), 'posted_at' => now('UTC')])->save()));
        $this->assertSame('draft', $journal->fresh()->getAttribute('status'));
    }
}

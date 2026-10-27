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
use App\Services\ERP\Accounting\Reconciliation\AccountingBankFinalizationReadiness;
use App\Services\ERP\Accounting\Reconciliation\AccountingBankFinalizationWorkflow;
use App\Services\ERP\Accounting\Reconciliation\AccountingBankReconciliationExportService;
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
use Database\Seeders\AccountingBankReconciliationExportPermissionSeeder;
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

final class AccountingBankReconciliationExportTest extends TestCase
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
        $this->seed(AccountingBankReconciliationExportPermissionSeeder::class);
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

    private function exports(): AccountingBankReconciliationExportService
    {
        return app(AccountingBankReconciliationExportService::class);
    }

    private function exportUrl(AccountingBankReconciliation $root, string $format = 'csv', string $basis = 'current'): string
    {
        return '/erp/accounting/bank-reconciliations/'.$root->getKey().'/'.$format.'?basis='.$basis;
    }

    public function test_exports_require_authentication(): void
    {
        [$root] = $this->matchedRoot();
        $this->get($this->exportUrl($root))->assertRedirect('/login');
        $this->get($this->exportUrl($root, 'print'))->assertRedirect('/login');
    }

    public function test_dedicated_export_and_each_source_view_permission_are_required(): void
    {
        [$root] = $this->matchedRoot();
        $permissions = $this->finalPermissions('export');
        foreach ($permissions as $missing) {
            $user = $this->staff(array_values(array_diff($permissions, [$missing])));
            $this->actingAs($user)->get($this->exportUrl($root))->assertForbidden();
            $this->get($this->exportUrl($root, 'print'))->assertForbidden();
        }
    }

    public function test_export_does_not_require_finalize_or_manual_journal_authority(): void
    {
        [$root] = $this->matchedRoot();
        $user = $this->staff($this->finalPermissions('export'));
        $report = $this->exports()->report($user, (int) $root->getKey());
        $this->assertFalse($report['finalized']);
        $this->assertFalse($user->can('erp.bank-reconciliations.approve'));
        $this->assertFalse($user->can('erp.journal-entries.view'));
        $this->assertNull($report['report']['book_rows'][0]['journal_url']);
    }

    public function test_foreign_native_id_and_company_mismatch_are_rejected(): void
    {
        [$root] = $this->matchedRoot();
        $other = $this->newCompany('EXPORT-FOREIGN');
        $user = $this->staff($this->finalPermissions('export'));
        Employee::query()->where('user_id', $user->getKey())->update(['company_id' => $other->getKey()]);
        $this->actingAs($user)->get($this->exportUrl($root))->assertNotFound();
        $this->actingAs($this->actor)->get($this->exportUrl($root).'&company_id='.$other->getKey())->assertForbidden();
    }

    public function test_working_export_never_claims_independent_finalization(): void
    {
        [$root] = $this->matchedRoot();
        $data = $this->exports()->report($this->actor, (int) $root->getKey());
        $this->assertFalse($data['finalized']);
        $this->assertStringContainsString('WORKING / NOT FINALIZED', $data['status_label']);
        $this->assertNull($data['approval']);
        $this->assertTrue($data['technical_ready']);
    }

    public function test_zero_difference_with_unmatched_rows_exports_as_provisional(): void
    {
        [$root] = $this->fixture();
        $data = $this->exports()->report($this->actor, (int) $root->getKey());
        $this->assertSame('0.00', $data['report']['bridge']['statement_minus_book']);
        $this->assertFalse($data['technical_ready']);
        $this->assertFalse($data['finalized']);
        $this->assertNotEmpty($data['exceptions']);
    }

    public function test_approved_export_requires_an_actual_recorded_approval(): void
    {
        [$root] = $this->matchedRoot();
        $before = $this->finalDigest();
        $this->actingAs($this->actor)->get($this->exportUrl($root, 'csv', 'approved'))->assertSessionHasErrors('export');
        $this->assertSame($before, $this->finalDigest());
    }

    public function test_active_finalization_exports_verified_approved_prefix(): void
    {
        [$root] = $this->matchedRoot();
        $this->finalize($root);
        $data = $this->exports()->report($this->actor, (int) $root->getKey(), 'approved');
        $this->assertTrue($data['finalized']);
        $this->assertSame('FINALIZED — approved cutoff snapshot', $data['status_label']);
        $this->assertSame(2, $data['matching_revision']);
        $this->assertCount(1, $data['report']['history']);
        $this->assertArrayNotHasKey('currently_available', $data['report']['book_rows'][0]);
        $this->assertArrayNotHasKey('allocated_to_later_statements', $data['report']['book_rows'][0]);
    }

    public function test_reopened_current_and_approved_exports_have_distinct_figures_and_labels(): void
    {
        [$root] = $this->matchedRoot();
        $this->finalize($root);
        $this->finals()->execute($this->actor, (int) $root->getKey(), 'reopen', $this->finalInput($root));
        $this->original();
        $current = $this->exports()->report($this->actor, (int) $root->getKey());
        $approved = $this->exports()->report($this->actor, (int) $root->getKey(), 'approved');
        $this->assertFalse($current['finalized']);
        $this->assertSame('200.00', $current['report']['bridge']['book_closing']);
        $this->assertSame('100.00', $approved['report']['bridge']['book_closing']);
        $this->assertCount(2, $current['report']['book_rows']);
        $this->assertCount(1, $approved['report']['book_rows']);
        $this->assertStringContainsString('HISTORICAL APPROVED SNAPSHOT', $approved['status_label']);
        $this->assertSame('reopened', $approved['current_state']);
        $this->assertCount(1, $approved['later_events']);
    }

    public function test_historical_approved_match_stays_active_after_current_undo(): void
    {
        [$root, $match] = $this->matchedRoot();
        $this->finalize($root);
        $this->finals()->execute($this->actor, (int) $root->getKey(), 'reopen', $this->finalInput($root));
        $this->undo($root, $match);
        $before = $this->finalDigest();
        $current = $this->exports()->report($this->actor, (int) $root->getKey());
        $approved = $this->exports()->report($this->actor, (int) $root->getKey(), 'approved');
        $this->assertSame('100.00', $current['report']['statement_rows'][0]['remaining']);
        $this->assertSame('0.00', $approved['report']['statement_rows'][0]['remaining']);
        $this->assertTrue($approved['report']['history'][0]['active']);
        $this->assertCount(1, $approved['report']['history']);
        $this->assertSame($before, $this->finalDigest());
    }

    public function test_later_statement_clearing_does_not_rewrite_approved_outstanding_amount(): void
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
        $approved = $this->exports()->report($this->actor, (int) $root->getKey(), 'approved');
        $current = $this->exports()->report($this->actor, (int) $root->getKey());
        $this->assertSame('100.00', $approved['report']['bridge']['outstanding_book_net']);
        $this->assertArrayNotHasKey('currently_available', $approved['report']['book_rows'][1]);
        $this->assertSame('0.00', $current['report']['book_rows'][1]['currently_available']);
    }

    public function test_latest_approval_is_selected_after_a_new_review_cycle(): void
    {
        [$root] = $this->matchedRoot();
        $first = $this->finalize($root);
        $this->finals()->execute($this->actor, (int) $root->getKey(), 'reopen', $this->finalInput($root));
        $second = $this->finalize($root);
        $data = $this->exports()->report($this->actor, (int) $root->getKey(), 'approved');
        $this->assertNotSame($first->getKey(), $data['approval']['id']);
        $this->assertSame((int) $second->getKey(), $data['approval']['id']);
        $this->assertCount(5, $data['timeline']);
    }

    public function test_csv_and_print_are_private_and_read_only(): void
    {
        [$root] = $this->matchedRoot();
        $this->finalize($root);
        $before = $this->finalDigest();
        $this->actingAs($this->actor);
        $csv = $this->get($this->exportUrl($root, 'csv', 'approved'))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $csv->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment', $csv->headers->get('Content-Disposition'));
        $this->assertStringContainsString('FINALIZED', $csv->streamedContent());
        $print = $this->get($this->exportUrl($root, 'print', 'approved'))->assertOk()->assertViewIs('erp.accounting.bank-reconciliation-print');
        $this->assertStringContainsString("default-src 'none'", $print->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', $print->headers->get('Cache-Control'));
        $this->assertSame($before, $this->finalDigest());
    }

    public function test_full_export_includes_rows_beyond_the_screen_page(): void
    {
        $transfer = $this->original(['amount' => '60.00']);
        $rows = [];
        for ($i = 0; $i < 60; $i++) {
            $rows[] = $this->statementRow(['money_in' => '1.00', 'reference' => 'Export row '.($i + 1)]);
        }
        $statement = $this->statement($rows, ['closing_balance' => '60.00']);
        $root = $this->start($statement);
        $before = $this->finalDigest();
        $data = $this->exports()->report($this->actor, (int) $root->getKey());
        $this->assertCount(60, $data['report']['statement_rows']);
        $this->assertSame('60.00', $data['report']['bridge']['statement_closing']);
        $csv = $this->actingAs($this->actor)->get($this->exportUrl($root))->assertOk()->streamedContent();
        $this->assertStringContainsString('Export row 60', $csv);
        $print = $this->get($this->exportUrl($root, 'print'))->assertOk();
        $print->assertSeeText('Export row 60');
        $this->assertSame($before, $this->finalDigest());
        $allocations = [];
        foreach (AccountingBankStatementLine::query()->where('statement_id', $statement->getKey())->orderBy('id')->pluck('id')->all() as $lineId) {
            $allocations[] = ['id' => (int) $lineId, 'amount' => '1.00'];
        }
        $bookId = (int) JournalEntryLine::query()->where('cash_account_id', $this->bank->getKey())->where('source_line_type', 'cash_transfer')->where('source_line_id', $transfer->getKey())->value('id');
        $this->matcher()->match($this->actor, (int) $root->getKey(), ['revision' => 1, 'key' => (string) Str::uuid(), 'reason' => 'Match full statement', 'statement_allocations' => $allocations, 'book_allocations' => [['id' => $bookId, 'amount' => '60.00']]]);
        $this->finalize($root);
        $approvedBefore = $this->finalDigest();
        $approved = $this->exports()->report($this->actor, (int) $root->getKey(), 'approved');
        $this->assertCount(60, $approved['report']['statement_rows']);
        $this->assertSame('0.00', $approved['report']['bridge']['statement_minus_book']);
        $this->assertStringContainsString('Export row 60', $this->get($this->exportUrl($root, 'csv', 'approved'))->assertOk()->streamedContent());
        $this->assertSame($approvedBefore, $this->finalDigest());
    }

    public function test_print_escapes_bank_text_and_csv_neutralizes_formula_text(): void
    {
        $this->original();
        $statement = $this->statement([$this->statementRow(['reference' => '=SUM(1,2)', 'description' => '<script>alert(1)</script>'])]);
        $root = $this->start($statement);
        $this->actingAs($this->actor);
        $csv = $this->get($this->exportUrl($root))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=SUM(1,2)", $csv);
        $html = $this->get($this->exportUrl($root, 'print'))->assertOk()->getContent();
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_pagination_filters_and_client_approval_ids_are_rejected(): void
    {
        [$root] = $this->matchedRoot();
        $this->actingAs($this->actor);
        foreach (['page=2', 'per_page=1', 'approval_id=1', 'snapshot_hash=forged', 'direction=in'] as $query) {
            $this->get($this->exportUrl($root).'&'.$query)->assertSessionHasErrors('export');
        } $this->get($this->exportUrl($root).'&basis=unknown')->assertSessionHasErrors('basis');
    }

    public function test_corrupt_finalization_head_blocks_before_csv_headers(): void
    {
        [$root] = $this->matchedRoot();
        $this->finalize($root);
        DB::table('accounting_bank_reconciliations')->where('id', $root->getKey())->update(['finalization_hash' => str_repeat('0', 64)]);
        $before = $this->finalDigest();
        $response = $this->actingAs($this->actor)->get($this->exportUrl($root, 'csv', 'approved'))->assertSessionHasErrors('export');
        $response->assertHeaderMissing('Content-Disposition');
        $this->assertSame($before, $this->finalDigest());
    }

    public function test_missing_statement_proof_blocks_both_export_bases_without_repair(): void
    {
        [$root] = $this->matchedRoot();
        $this->finalize($root);
        DB::table('accounting_bank_statement_evidence')->delete();
        $before = $this->finalDigest();
        $this->actingAs($this->actor);
        foreach (['current', 'approved'] as $basis) {
            $this->get($this->exportUrl($root, 'csv', $basis))->assertSessionHasErrors('export');
            $this->get($this->exportUrl($root, 'print', $basis))->assertSessionHasErrors('export');
        } $this->assertSame($before, $this->finalDigest());
    }

    public function test_damaged_native_matching_history_is_not_repaired_by_download(): void
    {
        [$root] = $this->matchedRoot();
        $this->finalize($root);
        DB::table('accounting_bank_reconciliation_legs')->delete();
        $before = $this->finalDigest();
        $this->actingAs($this->actor)->get($this->exportUrl($root, 'csv', 'approved'))->assertSessionHasErrors('export');
        $this->assertSame($before, $this->finalDigest());
    }

    public function test_approved_source_and_journal_links_require_separate_view_authority(): void
    {
        [$root] = $this->matchedRoot();
        $this->finalize($root);
        $user = $this->staff($this->finalPermissions('export'));
        $data = $this->exports()->report($user, (int) $root->getKey(), 'approved');
        $this->assertNull($data['report']['book_rows'][0]['source_url']);
        $this->assertNull($data['report']['book_rows'][0]['journal_url']);
        $user->givePermissionTo(['erp.cash-transfers.view', 'erp.journal-entries.view']);
        $data = $this->exports()->report($user, (int) $root->getKey(), 'approved');
        $this->assertNotNull($data['report']['book_rows'][0]['source_url']);
        $this->assertNotNull($data['report']['book_rows'][0]['journal_url']);
    }

    public function test_read_only_native_viewer_gets_no_export_links(): void
    {
        [$root] = $this->matchedRoot();
        $user = $this->staff($this->finalPermissions());
        $this->assertSame([], $this->exports()->links($user, (int) $root->getKey(), true));
        $this->actingAs($user)->get($this->finalUrl($root))->assertInertia(fn (Assert $page) => $page->has('exportLinks', 0));
    }

    public function test_export_links_are_available_on_workspace_readiness_and_finalization_pages(): void
    {
        [$root] = $this->matchedRoot();
        $this->finalize($root);
        $this->actingAs($this->actor);
        foreach (['/erp/accounting/bank-reconciliations/'.$root->getKey(), $this->reviewUrl($root), $this->finalUrl($root)] as $url) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->has('exportLinks', 4));
        }
    }

    public function test_no_approved_link_is_exposed_without_an_approval(): void
    {
        [$root] = $this->matchedRoot();
        $links = $this->exports()->links($this->actor, (int) $root->getKey(), true);
        $this->assertCount(2, $links);
        $this->assertArrayNotHasKey('approved_csv', $links);
    }

    public function test_closed_period_disabled_settings_and_inactive_bank_do_not_rewrite_approved_export(): void
    {
        [$root] = $this->matchedRoot();
        $this->finalize($root);
        $before = $this->exports()->report($this->actor, (int) $root->getKey(), 'approved');
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->update(['state' => 'hard_closed']);
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['state' => 'disabled']);
        $this->bank->update(['status' => 'inactive']);
        $digest = $this->finalDigest();
        $after = $this->exports()->report($this->actor, (int) $root->getKey(), 'approved');
        $this->assertSame($before['report']['bridge'], $after['report']['bridge']);
        $this->assertTrue($after['finalized']);
        $this->assertSame($digest, $this->finalDigest());
    }

    public function test_native_bank_charge_posts_matches_finalizes_and_exports_without_second_money(): void
    {
        [$adjustment, $statement, $sl] = $this->adjustmentDraft();
        $adjustment = $this->postAdjustment($this->approvedAdjustment($adjustment));
        $root = $this->start($statement);
        $bl = (int) JournalEntryLine::query()->where('cash_account_id', $this->bank->getKey())->where('source_line_type', 'bank_adjustment')->where('source_line_id', $adjustment->getKey())->value('id');
        $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl, ['statement_allocations' => [['id' => $sl, 'amount' => '25.25']], 'book_allocations' => [['id' => $bl, 'amount' => '25.25']]]));
        $this->finalize($root);
        $before = $this->finalDigest();
        $data = $this->exports()->report($this->actor, (int) $root->getKey(), 'approved');
        $this->assertTrue($data['finalized']);
        $this->assertSame('-25.25', $data['report']['bridge']['book_closing']);
        $this->assertSame('/erp/accounting/bank-adjustments/'.$adjustment->getKey(), $data['report']['book_rows'][0]['source_url']);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertSame($before, $this->finalDigest());
    }

    public function test_safe_export_dto_contains_no_private_proof_commitments(): void
    {
        [$root] = $this->matchedRoot();
        $this->finalize($root);
        $data = $this->exports()->report($this->actor, (int) $root->getKey(), 'approved');
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        foreach (['proof_hash', 'snapshot_hash', 'source_hash', 'key_hash', 'request_hash', 'payload_hash'] as $field) {
            $this->assertStringNotContainsString(chr(34).$field.chr(34), $json);
        } $this->assertArrayNotHasKey('snapshot', $data);
    }

    public function test_export_permission_seeder_is_idempotent_and_does_not_grant_write_actions(): void
    {
        $this->seed(AccountingBankReconciliationExportPermissionSeeder::class);
        $this->seed(AccountingBankReconciliationExportPermissionSeeder::class);
        $this->assertSame(1, Permission::query()->where('name', 'erp.bank-reconciliations.export')->where('guard_name', 'web')->count());
        $user = $this->staff($this->finalPermissions('export'));
        $this->assertFalse($user->can('erp.bank-reconciliations.approve'));
        $this->assertFalse($user->can('erp.bank-reconciliations.match'));
    }
}

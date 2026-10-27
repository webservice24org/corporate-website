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
use App\Services\ERP\Accounting\Reconciliation\AccountingBankFinalizationReadiness;
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

final class AccountingBankFinalizationReadinessTest extends TestCase
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

    public function test_route_requires_authentication(): void
    {
        [$root] = $this->matchedRoot();
        $this->get($this->reviewUrl($root))->assertRedirect('/login');
    }

    public function test_all_three_view_dependencies_are_required(): void
    {
        [$root] = $this->matchedRoot();
        $permissions = ['erp.bank-reconciliations.view', 'erp.bank-statements.view', 'erp.cash-books.view'];
        foreach ($permissions as $missing) {
            $user = $this->staff(array_values(array_diff($permissions, [$missing])));
            $this->actingAs($user)->get($this->reviewUrl($root))->assertForbidden();
        }
    }

    public function test_native_reader_needs_no_adjustment_or_manual_journal_permission(): void
    {
        [$root] = $this->matchedRoot();
        $user = $this->staff(['erp.bank-reconciliations.view', 'erp.bank-statements.view', 'erp.cash-books.view']);
        $this->actingAs($user)->get($this->reviewUrl($root))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/BankReconciliations/Readiness')->where('review.technical_ready', true));
        $this->assertFalse($user->can('erp.journal-entries.view'));
        $this->assertFalse($user->can('erp.bank-adjustments.view'));
    }

    public function test_fully_matched_zero_opening_is_technically_ready_but_never_finalized(): void
    {
        [$root] = $this->matchedRoot();
        $before = $this->digest();
        $review = $this->readiness($root)['review'];
        $this->assertTrue($review['technical_ready']);
        $this->assertFalse($review['finalized']);
        $this->assertFalse($review['can_finalize']);
        $this->assertTrue($review['approval_required']);
        $this->assertSame('0.00', $review['bridge']['statement_minus_book']);
        $this->assertSame($before, $this->digest());
    }

    public function test_zero_difference_cannot_clear_unmatched_bank_movements(): void
    {
        [$root] = $this->fixture();
        $review = $this->readiness($root)['review'];
        $this->assertSame('0.00', $review['bridge']['statement_minus_book']);
        $this->assertFalse($review['technical_ready']);
        $this->assertSame('blocked', $this->checkState($review, 'statement_matches'));
        $this->assertSame(1, $review['counts']['statement_rows_unmatched']);
    }

    public function test_verified_outstanding_book_item_explains_nonzero_difference(): void
    {
        [$root] = $this->matchedRoot();
        $this->original();
        $review = $this->readiness($root)['review'];
        $this->assertTrue($review['technical_ready']);
        $this->assertSame('-100.00', $review['bridge']['statement_minus_book']);
        $this->assertSame('100.00', $review['bridge']['outstanding_book_net']);
        $this->assertSame(1, $review['counts']['outstanding_book_rows']);
        $this->assertFalse($review['finalized']);
    }

    public function test_undo_changes_readiness_without_creating_a_journal(): void
    {
        [$root, $op] = $this->matchedRoot();
        $this->assertTrue($this->readiness($root)['review']['technical_ready']);
        $before = $this->financialDigest();
        $this->undo($root, $op);
        $this->assertFalse($this->readiness($root)['review']['technical_ready']);
        $this->assertSame($before, $this->financialDigest());
    }

    public function test_partial_match_does_not_pass_statement_coverage(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $input = $this->group($root, $sl, $bl, ['statement_allocations' => [['id' => $sl, 'amount' => '25.00']], 'book_allocations' => [['id' => $bl, 'amount' => '25.00']]]);
        $this->matcher()->match($this->actor, (int) $root->getKey(), $input);
        $review = $this->readiness($root)['review'];
        $this->assertFalse($review['technical_ready']);
        $this->assertSame(1, $review['counts']['statement_rows_unmatched']);
    }

    public function test_unknown_evidence_is_not_reported_as_zero_bridge(): void
    {
        [$root] = $this->matchedRoot();
        DB::table('accounting_bank_reconciliation_legs')->delete();
        $before = $this->digest();
        $review = $this->readiness($root)['review'];
        $this->assertSame('review', $review['evidence_state']);
        $this->assertNull($review['bridge']);
        $this->assertFalse($review['technical_ready']);
        $this->assertSame($before, $this->digest());
    }

    public function test_missing_statement_evidence_blocks_without_repair(): void
    {
        [$root] = $this->matchedRoot();
        DB::table('accounting_bank_statement_evidence')->delete();
        $before = $this->digest();
        $review = $this->readiness($root)['review'];
        $this->assertSame('blocked', $this->checkState($review, 'evidence'));
        $this->assertNull($review['bridge']);
        $this->assertSame($before, $this->digest());
    }

    public function test_changed_matching_head_blocks_without_repair(): void
    {
        [$root] = $this->matchedRoot();
        DB::table('accounting_bank_reconciliations')->where('id', $root->getKey())->update(['revision' => 99]);
        $before = $this->digest();
        $review = $this->readiness($root)['review'];
        $this->assertFalse($review['technical_ready']);
        $this->assertSame('review', $review['evidence_state']);
        $this->assertSame($before, $this->digest());
    }

    public function test_foreign_native_id_is_not_found_for_staff(): void
    {
        [$root] = $this->matchedRoot();
        $other = $this->newCompany('REVIEW-FOREIGN');
        $user = $this->staff($this->views());
        Employee::query()->where('user_id', $user->getKey())->update(['company_id' => $other->getKey()]);
        $this->actingAs($user)->get($this->reviewUrl($root))->assertNotFound();
    }

    public function test_explicit_company_mismatch_is_forbidden(): void
    {
        [$root] = $this->matchedRoot();
        $other = $this->newCompany('REVIEW-MISMATCH');
        $this->actingAs($this->actor)->get($this->reviewUrl($root).'?company_id='.$other->getKey())->assertForbidden();
    }

    public function test_http_props_exclude_internal_snapshot_and_private_proofs(): void
    {
        [$root] = $this->matchedRoot();
        $this->actingAs($this->actor)->get($this->reviewUrl($root))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/BankReconciliations/Readiness')->missing('snapshot')->missing('snapshot_hash')->missing('review.heads')->missing('review.source_snapshot')->missing('review.creation_key_hash')->missing('review.reason')->where('review.finalized', false)->where('review.can_finalize', false));
    }

    public function test_repeated_review_is_read_only_and_internal_snapshot_is_stable(): void
    {
        [$root] = $this->matchedRoot();
        $before = $this->digest();
        $service = app(AccountingBankFinalizationReadiness::class);
        $first = $service->capture($this->actor, (int) $root->getKey());
        $second = $service->capture($this->actor, (int) $root->getKey());
        $this->assertSame($first['snapshot_hash'], $second['snapshot_hash']);
        $this->assertNotNull($first['snapshot']);
        $this->assertSame($before, $this->digest());
    }

    public function test_new_matching_revision_changes_internal_snapshot(): void
    {
        [$root, , , $sl, $bl] = $this->fixture();
        $service = app(AccountingBankFinalizationReadiness::class);
        $first = $service->capture($this->actor, (int) $root->getKey());
        $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl));
        $second = $service->capture($this->actor, (int) $root->getKey());
        $this->assertNotSame($first['snapshot_hash'], $second['snapshot_hash']);
    }

    public function test_pending_bank_adjustment_is_a_separate_gate(): void
    {
        [$adjustment, $statement] = $this->adjustmentDraft();
        $root = $this->start($statement);
        $review = $this->readiness($root)['review'];
        $this->assertFalse($review['technical_ready']);
        $this->assertSame('blocked', $this->checkState($review, 'adjustments'));
        $this->assertSame(1, $review['counts']['pending_adjustments']);
    }

    public function test_imported_statement_without_workspace_is_not_ignored(): void
    {
        [$root] = $this->matchedRoot();
        $this->statement([$this->statementRow(['date' => '2026-09-29'])], ['starts_on' => '2026-09-29', 'ends_on' => '2026-09-29']);
        $review = $this->readiness($root)['review'];
        $this->assertFalse($review['technical_ready']);
        $this->assertSame('blocked', $this->checkState($review, 'workspaces'));
    }

    public function test_overlapping_import_is_rejected_without_changing_readiness(): void
    {
        [$root] = $this->matchedRoot();
        $before = $this->digest();
        $this->rejected(fn () => $this->statement());
        $this->assertSame($before, $this->digest());
        $review = $this->readiness($root)['review'];
        $this->assertTrue($review['technical_ready']);
        $this->assertSame('pass', $this->checkState($review, 'statement_coverage'));
    }

    public function test_nonzero_opening_cannot_hide_behind_zero_final_difference(): void
    {
        $transfer = $this->original(['amount' => '150.00']);
        $statement = $this->statement(changes: ['opening_balance' => '50.00', 'closing_balance' => '150.00']);
        $root = $this->start($statement);
        $sl = (int) AccountingBankStatementLine::query()->where('statement_id', $statement->getKey())->value('id');
        $bl = (int) JournalEntryLine::query()->where('cash_account_id', $this->bank->getKey())->value('id');
        $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl));
        $review = $this->readiness($root)['review'];
        $this->assertSame('0.00', $review['bridge']['statement_minus_book']);
        $this->assertFalse($review['technical_ready']);
        $this->assertSame('blocked', $this->checkState($review, 'opening'));
        $this->assertSame('blocked', $this->checkState($review, 'statement_coverage'));
    }

    public function test_contiguous_statements_with_carried_opening_verify_as_a_chain(): void
    {
        [$previous] = $this->matchedRoot();
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $transfer = $this->original(['document_date' => '2026-10-17']);
        $statement = $this->statement([$this->statementRow(['date' => '2026-10-17'])], ['starts_on' => '2026-10-17', 'ends_on' => '2026-10-17', 'opening_balance' => '100.00', 'closing_balance' => '200.00']);
        $root = $this->start($statement);
        $sl = (int) AccountingBankStatementLine::query()->where('statement_id', $statement->getKey())->value('id');
        $bl = (int) JournalEntryLine::query()->where('cash_account_id', $this->bank->getKey())->where('source_line_type', 'cash_transfer')->where('source_line_id', $transfer->getKey())->value('id');
        $this->matcher()->match($this->actor, (int) $root->getKey(), $this->group($root, $sl, $bl));
        $review = $this->readiness($root)['review'];
        $this->assertTrue($review['technical_ready']);
        $this->assertSame(2, $review['counts']['statements']);
        $this->assertSame('0.00', $review['bridge']['unverified_opening_prior_statement_residual']);
    }

    public function test_closed_period_and_disabled_settings_do_not_rewrite_readiness(): void
    {
        [$root] = $this->matchedRoot();
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->update(['state' => 'hard_closed']);
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['state' => 'disabled']);
        $before = $this->digest();
        $review = $this->readiness($root)['review'];
        $this->assertTrue($review['technical_ready']);
        $this->assertFalse($review['can_finalize']);
        $this->assertSame($before, $this->digest());
    }

    public function test_inactive_bank_history_is_readable(): void
    {
        [$root] = $this->matchedRoot();
        $this->bank->update(['status' => 'inactive']);
        $before = $this->digest();
        $review = $this->readiness($root)['review'];
        $this->assertSame('verified', $review['evidence_state']);
        $this->assertFalse($review['can_finalize']);
        $this->assertSame($before, $this->digest());
    }

    public function test_invalid_or_unknown_query_filters_are_rejected(): void
    {
        [$root] = $this->matchedRoot();
        $this->actingAs($this->actor)->get($this->reviewUrl($root).'?page=0')->assertSessionHasErrors('page');
        $this->get($this->reviewUrl($root).'?key=private')->assertSessionHasErrors('readiness');
    }

    public function test_no_post_finalization_route_is_exposed(): void
    {
        [$root] = $this->matchedRoot();
        $before = $this->digest();
        $this->actingAs($this->actor)->post($this->reviewUrl($root), [])->assertStatus(405);
        $this->assertSame($before, $this->digest());
    }
}

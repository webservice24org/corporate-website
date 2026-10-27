<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingBankStatement;
use App\Models\Accounting\AccountingBankStatementEvidence;
use App\Models\Accounting\AccountingBankStatementLine;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingMappingVersion;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingAccountService;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingCashAccountService;
use App\Services\ERP\Accounting\AccountingMappingCatalog;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\Statements\AccountingBankStatementContract;
use App\Services\ERP\Accounting\Statements\AccountingBankStatementCsv;
use App\Services\ERP\Accounting\Statements\AccountingBankStatementService;
use Carbon\CarbonImmutable;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AccountingBankStatementManagementTest extends TestCase
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

    private const BASE = '/erp/accounting/bank-statements';

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function metadata(array $overrides = []): array
    {
        return ['company_id' => (int) $this->company->getKey(), 'cash_account_id' => (int) $this->bank->getKey(), 'statement_reference' => 'BANK-OCT-001', 'currency_code' => 'BDT', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-16', 'opening_balance' => '100.00', 'closing_balance' => '125.25', ...$overrides];
    }

    /** @return list<array<string, mixed>> */
    private function lines(): array
    {
        return [
            ['date' => '2026-10-02', 'value_date' => '', 'transaction_id' => 'BANK-T1', 'reference' => 'PRIVATE-REFERENCE', 'description' => 'PRIVATE-BANK-DESCRIPTION', 'money_in' => '50.00', 'money_out' => '', 'balance' => '150.00'],
            ['date' => '2026-10-03', 'value_date' => '2026-10-04', 'transaction_id' => 'BANK-T2', 'reference' => '', 'description' => 'Payment', 'money_in' => '', 'money_out' => '24.75', 'balance' => '125.25'],
        ];
    }

    /** @param list<array<string, mixed>>|null $lines */
    private function source(?array $lines = null): string
    {
        $stream = fopen('php://temp', 'w+');
        $this->assertIsResource($stream);
        fputcsv($stream, AccountingBankStatementContract::COLUMNS, ',', '"', '');
        foreach ($lines ?? $this->lines() as $line) {
            $cells = [];
            foreach (AccountingBankStatementContract::COLUMNS as $column) {
                $cells[] = $line[$column] ?? '';
            } fputcsv($stream, $cells, ',', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        $this->assertIsString($csv);

        return $csv;
    }

    private function service(): AccountingBankStatementService
    {
        return app(AccountingBankStatementService::class);
    }

    /** @param array<string, mixed>|null $metadata @param list<array<string, mixed>>|null $lines */
    private function hash(?array $metadata = null, ?array $lines = null): string
    {
        return app(AccountingCanonicalPayload::class)->hash(app(AccountingBankStatementContract::class)->normalize($metadata ?? $this->metadata(), $lines ?? $this->lines()));
    }

    /** @param array<string, mixed>|null $metadata @param list<array<string, mixed>>|null $lines */
    private function record(?array $metadata = null, ?array $lines = null, string $key = 'statement-test-key-0001', string $mode = 'manual', ?User $actor = null): AccountingBankStatement
    {
        $m = $metadata ?? $this->metadata();
        $rows = $lines ?? $this->lines();

        return $this->service()->record($actor ?? $this->actor, $mode, $m, $mode === 'csv' ? [] : $rows, $mode === 'csv' ? $this->source($rows) : null, $key, $this->hash($m, $rows));
    }

    private function financeDigest(): string
    {
        $data = [];
        foreach (['journal_entries', 'journal_entry_lines', 'accounting_source_effects', 'accounting_cash_accounts', 'accounting_company_settings', 'accounting_mapping_versions', 'accounting_cash_transfer_operations', 'accounting_cash_transfer_reversal_operations'] as $table) {
            $data[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function digest(): string
    {
        return hash('sha256', json_encode([$this->financeDigest(), DB::table('accounting_bank_statements')->orderBy('id')->get(), DB::table('accounting_bank_statement_lines')->orderBy('id')->get(), DB::table('accounting_bank_statement_evidence')->orderBy('id')->get()], JSON_THROW_ON_ERROR));
    }

    private function rejected(callable $action): void
    {
        $before = $this->digest();
        try {
            $action();
            $this->fail('Expected rejected statement operation.');
        } catch (ValidationException|AuthorizationException|\Illuminate\Database\Eloquent\ModelNotFoundException|\LogicException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, $this->digest());
    }

    /** @return array<string, mixed> */
    private function verify(AccountingBankStatement $row): array
    {
        return DB::transaction(function () use ($row): array {
            Company::query()->whereKey($this->company->getKey())->lockForUpdate()->firstOrFail();

            return $this->service()->verifyOwned((int) $this->company->getKey(), (int) $row->getKey());
        });
    }

    /** @return array<string, mixed> */
    private function command(string $mode = 'manual'): array
    {
        $data = ['mode' => $mode, 'metadata' => $this->metadata(), 'lines' => $mode === 'csv' ? [] : $this->lines(), 'key' => 'statement-http-key-0001', 'preview_hash' => $this->hash()];
        if ($mode === 'csv') {
            $data['file'] = UploadedFile::fake()->createWithContent('PRIVATE-FILENAME.csv', $this->source());
        }

        return $data;
    }

    public function test_manual_evidence_is_complete_and_creates_no_financial_writes(): void
    {
        $before = $this->financeDigest();
        $row = $this->record();
        $payload = $this->verify($row);
        $this->assertSame('125.25', $row->getAttribute('closing_balance'));
        $this->assertSame(2, $row->getAttribute('row_count'));
        $this->assertSame('50.00', $payload['totals']['money_in']);
        $this->assertDatabaseCount('accounting_bank_statement_lines', 2);
        $this->assertDatabaseCount('accounting_bank_statement_evidence', 1);
        $this->assertSame($before, $this->financeDigest());
    }

    public function test_csv_source_bytes_are_retained_and_reparsed(): void
    {
        $raw = $this->source();
        $row = $this->record(mode: 'csv');
        $this->verify($row);
        $evidence = AccountingBankStatementEvidence::query()->firstOrFail();
        $this->assertSame($raw, $evidence->getAttribute('source_text'));
        $this->assertSame(hash('sha256', $raw), $evidence->getAttribute('source_hash'));
        $this->assertSame('csv', $row->getAttribute('source_mode'));
    }

    public function test_preview_is_read_only(): void
    {
        $before = $this->digest();
        $data = $this->service()->preview($this->actor, 'manual', $this->metadata(), $this->lines());
        $this->assertSame($this->hash(), $data['preview_hash']);
        $this->assertCount(2, $data['lines']);
        $this->assertArrayNotHasKey('fingerprint', $data['lines'][0]);
        $this->assertSame($before, $this->digest());
    }

    public function test_identical_retry_replays_without_writes(): void
    {
        $row = $this->record();
        $before = $this->digest();
        $again = $this->record();
        $this->assertSame($row->getKey(), $again->getKey());
        $this->assertSame($before, $this->digest());
    }

    public function test_preview_retry_after_reload_returns_existing_statement(): void
    {
        $row = $this->record();
        $before = $this->digest();
        $preview = $this->service()->preview($this->actor, 'manual', $this->metadata(), $this->lines(), null, 'statement-test-key-0001');
        $this->assertSame(self::BASE.'/'.$row->getKey(), $preview['existing_url']);
        $this->assertSame($before, $this->digest());
    }

    public function test_same_key_with_different_reference_is_rejected(): void
    {
        $this->record();
        $this->rejected(fn () => $this->record($this->metadata(['statement_reference' => 'OTHER'])));
    }

    public function test_same_key_from_another_actor_is_rejected(): void
    {
        $this->record();
        $this->rejected(fn () => $this->record(actor: $this->reviewer));
    }

    public function test_duplicate_source_with_a_new_key_and_reference_is_rejected(): void
    {
        $this->record(mode: 'csv');
        $this->rejected(fn () => $this->record($this->metadata(['statement_reference' => 'OTHER']), key: 'statement-other-key-0002', mode: 'csv'));
    }

    public function test_overlapping_period_is_rejected_across_csv_and_manual(): void
    {
        $this->record();
        $this->rejected(fn () => $this->record($this->metadata(['statement_reference' => 'SECOND']), key: 'statement-other-key-0002', mode: 'csv'));
    }

    public function test_reused_transaction_id_in_later_period_is_rejected(): void
    {
        $this->record();
        $m = $this->metadata(['statement_reference' => 'SECOND', 'starts_on' => '2026-10-17', 'ends_on' => '2026-10-17', 'opening_balance' => '125.25', 'closing_balance' => '126.25']);
        $rows = [$this->lines()[0]];
        $rows[0] = [...$rows[0], 'date' => '2026-10-17', 'money_in' => '1.00', 'balance' => '126.25'];
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $this->rejected(fn () => $this->record($m, $rows, 'statement-other-key-0002'));
    }

    public function test_adjacent_statement_opening_must_match_previous_closing(): void
    {
        $this->record();
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $m = $this->metadata(['statement_reference' => 'SECOND', 'starts_on' => '2026-10-17', 'ends_on' => '2026-10-17', 'opening_balance' => '0.00', 'closing_balance' => '0.00']);
        $this->rejected(fn () => $this->record($m, [], 'statement-other-key-0002'));
    }

    public function test_adjacent_no_movement_statement_can_be_recorded(): void
    {
        $this->record();
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $m = $this->metadata(['statement_reference' => 'SECOND', 'starts_on' => '2026-10-17', 'ends_on' => '2026-10-17', 'opening_balance' => '125.25', 'closing_balance' => '125.25']);
        $row = $this->record($m, [], 'statement-other-key-0002');
        $this->assertSame(0, $row->getAttribute('row_count'));
        $this->verify($row);
    }

    public function test_out_of_order_import_must_match_following_adjacent_opening(): void
    {
        $this->record();
        $m = $this->metadata(['statement_reference' => 'SEP', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'opening_balance' => '99.00', 'closing_balance' => '99.00']);
        $this->rejected(fn () => $this->record($m, [], 'statement-other-key-0002'));
    }

    public function test_date_gaps_are_explicit_preview_warnings(): void
    {
        $this->record($this->metadata(['ends_on' => '2026-10-03']));
        $m = $this->metadata(['statement_reference' => 'GAP', 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-16', 'opening_balance' => '1.00', 'closing_balance' => '1.00']);
        $p = $this->service()->preview($this->actor, 'manual', $m, []);
        $this->assertStringContainsString('date gap', implode(' ', $p['warnings']));
    }

    public function test_negative_opening_and_closing_balances_are_exact(): void
    {
        $rows = [$this->lines()[0]];
        $rows[0] = [...$rows[0], 'money_in' => '0.01', 'balance' => '-25.24'];
        $row = $this->record($this->metadata(['opening_balance' => '-25.25', 'closing_balance' => '-25.24']), $rows);
        $this->assertSame('-25.24', $row->getAttribute('closing_balance'));
        $this->verify($row);
    }

    public function test_decimal_cents_survive_sqlite_reload_without_accepting_floats(): void
    {
        $rows = [$this->lines()[0]];
        $rows[0] = [...$rows[0], 'money_in' => '0.01', 'balance' => '0.01'];
        $row = $this->record($this->metadata(['opening_balance' => '0.00', 'closing_balance' => '0.01']), $rows);
        $this->assertSame('0.01', $row->fresh()->getAttribute('closing_balance'));
        $this->verify($row->fresh());
    }

    public function test_float_and_excess_precision_amounts_are_rejected(): void
    {
        foreach ([1.25, '1.001', '1e2', '1,000.00', '-1.00'] as $bad) {
            $rows = $this->lines();
            $rows[0]['money_in'] = $bad;
            $this->rejected(fn () => $this->record(lines: $rows));
        }
    }

    public function test_signed_balances_reject_floats_exponents_and_overflow(): void
    {
        foreach ([0.01, '1e2', '+1.00', '1000000000000000000.00', '-1.001'] as $bad) {
            $this->rejected(fn () => $this->record($this->metadata(['opening_balance' => $bad])));
        }
    }

    public function test_closing_mismatch_and_reported_running_balance_mismatch_are_rejected(): void
    {
        $this->rejected(fn () => $this->record($this->metadata(['closing_balance' => '0.00'])));
        $rows = $this->lines();
        $rows[0]['balance'] = '999.00';
        $this->rejected(fn () => $this->record(lines: $rows));
    }

    public function test_each_row_requires_exactly_one_positive_side(): void
    {
        foreach ([['money_in' => '0', 'money_out' => '0'], ['money_in' => '1', 'money_out' => '1']] as $bad) {
            $rows = $this->lines();
            $rows[0] = [...$rows[0], ...$bad];
            $this->rejected(fn () => $this->record(lines: $rows));
        }
    }

    public function test_duplicate_indistinguishable_rows_need_distinct_transaction_ids(): void
    {
        $rows = $this->lines();
        $rows[0]['transaction_id'] = '';
        $rows[0]['balance'] = '';
        $rows[1] = $rows[0];
        $this->rejected(fn () => $this->record($this->metadata(['closing_balance' => '200.00']), $rows));
    }

    public function test_legitimate_same_amount_movements_with_distinct_bank_ids_are_retained(): void
    {
        $rows = [$this->lines()[0], $this->lines()[0]];
        $rows[0]['balance'] = '150.00';
        $rows[1]['transaction_id'] = 'DISTINCT-BANK-ID';
        $rows[1]['balance'] = '200.00';
        $row = $this->record($this->metadata(['closing_balance' => '200.00']), $rows);
        $this->assertSame(2, $row->getAttribute('row_count'));
        $this->verify($row);
    }

    public function test_dates_outside_period_unsorted_and_future_period_are_rejected(): void
    {
        $rows = array_reverse($this->lines());
        $this->rejected(fn () => $this->record(lines: $rows));
        $this->rejected(fn () => $this->record($this->metadata(['starts_on' => '2026-10-03'])));
        $this->rejected(fn () => $this->record($this->metadata(['ends_on' => '2026-10-17'])));
    }

    public function test_cash_binding_foreign_binding_and_currency_injection_are_rejected(): void
    {
        $this->rejected(fn () => $this->record($this->metadata(['cash_account_id' => $this->cash->getKey()])));
        $other = $this->newCompany('STATEMENT-FOREIGN');
        $this->rejected(fn () => $this->record($this->metadata(['company_id' => $other->getKey()])));
        $this->rejected(fn () => $this->record($this->metadata(['currency_code' => 'USD'])));
    }

    public function test_historical_inactive_binding_is_allowed_without_financial_setup_changes(): void
    {
        $this->bank->update(['status' => 'inactive']);
        $before = $this->financeDigest();
        $row = $this->record();
        $this->verify($row);
        $this->assertSame($before, $this->financeDigest());
    }

    public function test_replay_and_detail_survive_inactive_company_and_bank(): void
    {
        $row = $this->record();
        $this->company->update(['status' => 'inactive']);
        $this->bank->update(['status' => 'inactive']);
        $before = $this->digest();
        $this->assertSame($row->getKey(), $this->record()->getKey());
        $this->actingAs($this->actor)->get(self::BASE.'/'.$row->getKey())->assertOk()->assertInertia(fn (Assert $p) => $p->where('evidenceState', 'verified'));
        $this->assertSame($before, $this->digest());
    }

    public function test_new_evidence_is_rejected_for_inactive_company(): void
    {
        $this->company->update(['status' => 'inactive']);
        $this->rejected(fn () => $this->record());
    }

    public function test_changed_payload_after_preview_is_rejected(): void
    {
        $this->rejected(fn () => $this->service()->record($this->actor, 'manual', $this->metadata(['statement_reference' => 'CHANGED']), $this->lines(), null, 'statement-test-key-0001', $this->hash()));
    }

    public function test_headers_lines_and_source_are_append_only(): void
    {
        $row = $this->record();
        $this->rejected(fn () => $row->update(['statement_reference' => 'CHANGED']));
        $this->rejected(fn () => $row->delete());
        $line = AccountingBankStatementLine::query()->firstOrFail();
        $this->rejected(fn () => $line->update(['money_in' => '1.00']));
        $this->rejected(fn () => $line->delete());
        $e = AccountingBankStatementEvidence::query()->firstOrFail();
        $this->rejected(fn () => $e->update(['source_text' => 'Changed']));
        $this->rejected(fn () => $e->delete());
    }

    public function test_missing_evidence_is_not_repaired_by_retry_preview_or_detail(): void
    {
        $row = $this->record();
        DB::table('accounting_bank_statement_evidence')->where('statement_id', $row->getKey())->delete();
        $before = $this->digest();
        $this->rejected(fn () => $this->record());
        $this->rejected(fn () => $this->service()->preview($this->actor, 'manual', $this->metadata(), $this->lines(), null, 'statement-test-key-0001'));
        $this->actingAs($this->actor)->get(self::BASE.'/'.$row->getKey())->assertOk()->assertInertia(fn (Assert $p) => $p->where('evidenceState', 'review')->where('rows', [])->where('exportUrl', null));
        $this->assertSame($before, $this->digest());
    }

    public function test_deleted_or_changed_line_is_reviewed_and_cannot_be_exported(): void
    {
        $row = $this->record();
        DB::table('accounting_bank_statement_lines')->where('statement_id', $row->getKey())->where('line_number', 2)->delete();
        $this->rejected(fn () => $this->verify($row));
        $this->actingAs($this->actor)->get(self::BASE.'/'.$row->getKey().'/csv')->assertSessionHasErrors();
    }

    public function test_raw_source_tampering_is_detected(): void
    {
        $row = $this->record(mode: 'csv');
        DB::table('accounting_bank_statement_evidence')->where('statement_id', $row->getKey())->update(['source_text' => $this->source().'
        ']);
        $this->rejected(fn () => $this->verify($row));
    }

    public function test_reordered_json_object_keys_keep_verification_stable(): void
    {
        $row = $this->record();
        $e = AccountingBankStatementEvidence::query()->firstOrFail();
        DB::table('accounting_bank_statement_evidence')->where('id', $e->getKey())->update(['payload' => json_encode(array_reverse($e->getAttribute('payload'), true), JSON_THROW_ON_ERROR)]);
        $this->verify($row);
    }

    public function test_failure_on_second_line_rolls_back_whole_import(): void
    {
        $before = $this->digest();
        $count = 0;
        AccountingBankStatementLine::creating(function () use (&$count): void {
            if (++$count === 2) {
                throw new \RuntimeException('Synthetic statement line failure');
            }
        });
        try {
            $this->record();
            $this->fail('Expected failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic statement line failure', $e->getMessage());
        } finally {
            AccountingBankStatementLine::flushEventListeners();
        }
        $this->assertSame($before, $this->digest());
    }

    public function test_failure_on_evidence_rolls_back_header_and_all_lines(): void
    {
        $before = $this->digest();
        AccountingBankStatementEvidence::creating(static function (): void {
            throw new \RuntimeException('Synthetic statement evidence failure');
        });
        try {
            $this->record();
            $this->fail('Expected failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic statement evidence failure', $e->getMessage());
        } finally {
            AccountingBankStatementEvidence::flushEventListeners();
        }
        $this->assertSame($before, $this->digest());
    }

    public function test_authentication_is_required_for_every_route(): void
    {
        foreach ([self::BASE, self::BASE.'/create', self::BASE.'/template', self::BASE.'/1', self::BASE.'/1/csv'] as $url) {
            $this->get($url)->assertRedirect('/login');
        } foreach (['preview', 'manual', 'import'] as $action) {
            $this->post(self::BASE.'/'.$action, [])->assertRedirect('/login');
        }
    }

    public function test_view_only_actor_cannot_record_preview_download_template_or_export(): void
    {
        $row = $this->record();
        $user = $this->staff(['erp.bank-statements.view']);
        $before = $this->digest();
        $this->actingAs($user)->get(self::BASE)->assertOk();
        $this->get(self::BASE.'/create')->assertForbidden();
        $this->get(self::BASE.'/template')->assertForbidden();
        $this->get(self::BASE.'/'.$row->getKey().'/csv')->assertForbidden();
        $this->postJson(self::BASE.'/preview', array_diff_key($this->command(), ['preview_hash' => true]))->assertForbidden();
        $this->post(self::BASE.'/manual', $this->command())->assertForbidden();
        $this->post(self::BASE.'/import', $this->command('csv'))->assertForbidden();
        $this->assertSame($before, $this->digest());
    }

    public function test_command_permission_without_native_view_is_forbidden(): void
    {
        $user = $this->staff(['erp.bank-statements.create']);
        $this->actingAs($user)->post(self::BASE.'/manual', $this->command())->assertForbidden();
    }

    public function test_manual_and_import_permissions_are_separate_and_need_no_journal_permission(): void
    {
        $user = $this->staff(['erp.bank-statements.view', 'erp.bank-statements.create']);
        $this->actingAs($user)->post(self::BASE.'/import', $this->command('csv'))->assertForbidden();
        $this->post(self::BASE.'/manual', $this->command())->assertRedirect();
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_csv_import_http_uses_complete_native_operation(): void
    {
        $user = $this->staff(['erp.bank-statements.view', 'erp.bank-statements.import']);
        $this->actingAs($user)->post(self::BASE.'/import', $this->command('csv'))->assertRedirect();
        $row = AccountingBankStatement::query()->firstOrFail();
        $this->verify($row);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_json_preview_and_reload_replay_preview_are_read_only(): void
    {
        $data = array_diff_key($this->command(), ['preview_hash' => true]);
        $before = $this->digest();
        $this->actingAs($this->actor)->postJson(self::BASE.'/preview', $data)->assertOk()->assertJsonPath('metadata.closing_balance', '125.25')->assertJsonCount(2, 'lines');
        $this->assertSame($before, $this->digest());
        $this->post(self::BASE.'/manual', $this->command())->assertRedirect();
        $row = AccountingBankStatement::query()->firstOrFail();
        $before = $this->digest();
        $this->postJson(self::BASE.'/preview', $data)->assertOk()->assertJsonPath('existing_url', self::BASE.'/'.$row->getKey());
        $this->assertSame($before, $this->digest());
    }

    public function test_all_inertia_components_exist_and_omit_raw_evidence(): void
    {
        $row = $this->record();
        $this->actingAs($this->actor)->get(self::BASE.'?company_id='.$this->company->getKey())->assertOk()->assertInertia(fn (Assert $p) => $p->component('ERP/Accounting/BankStatements/Index')->has('banks', 1)->has('statements.data', 1)->missing('statements.data.0.payload_hash')->missing('statements.data.0.source_text'));
        $this->get(self::BASE.'/create?company_id='.$this->company->getKey())->assertOk()->assertInertia(fn (Assert $p) => $p->component('ERP/Accounting/BankStatements/Form'));
        $this->get(self::BASE.'/'.$row->getKey())->assertOk()->assertInertia(fn (Assert $p) => $p->component('ERP/Accounting/BankStatements/Show')->where('evidenceState', 'verified')->has('rows', 2)->missing('rows.0.fingerprint')->missing('statement.creation_key_hash')->missing('source_text'));
    }

    public function test_company_queries_foreign_document_and_foreign_export_are_blocked(): void
    {
        $row = $this->record();
        $other = $this->newCompany('STMT-OWNERSHIP');
        $user = $this->staff(['erp.bank-statements.view', 'erp.bank-statements.export']);
        $this->actingAs($user)->get(self::BASE.'?company_id='.$other->getKey())->assertForbidden();
        $this->get(self::BASE.'/'.$row->getKey().'?company_id='.$other->getKey())->assertForbidden();
        DB::table('employees')->where('user_id', $user->getKey())->update(['company_id' => $other->getKey()]);
        $this->get(self::BASE.'/'.$row->getKey())->assertNotFound();
        $this->get(self::BASE.'/'.$row->getKey().'/csv')->assertNotFound();
    }

    public function test_foreign_company_and_bank_cannot_be_injected_into_preview_or_record(): void
    {
        $other = $this->newCompany('STMT-INPUT');
        $user = $this->staff(['erp.bank-statements.view', 'erp.bank-statements.create']);
        $data = $this->command();
        $data['metadata']['company_id'] = $other->getKey();
        $before = $this->digest();
        $this->actingAs($user)->postJson(self::BASE.'/preview', array_diff_key($data, ['preview_hash' => true]))->assertForbidden();
        $data['preview_hash'] = $this->hash($data['metadata']);
        $this->post(self::BASE.'/manual', $data)->assertForbidden();
        $this->assertSame($before, $this->digest());
    }

    public function test_unexpected_input_cannot_create_journals_or_override_actors(): void
    {
        $before = $this->digest();
        $this->actingAs($this->actor)->post(self::BASE.'/manual', [...$this->command(), 'created_by' => $this->reviewer->getKey()])->assertSessionHasErrors();
        $data = $this->command();
        $data['metadata']['journal_entry_id'] = 1;
        $this->post(self::BASE.'/manual', $data)->assertSessionHasErrors();
        $this->assertSame($before, $this->digest());
    }

    public function test_template_download_is_private_and_has_documented_columns(): void
    {
        $response = $this->actingAs($this->actor)->get(self::BASE.'/template')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString(implode(',', AccountingBankStatementContract::COLUMNS), $response->streamedContent());
    }

    public function test_csv_export_neutralizes_formulas_and_preserves_negative_numeric_balances(): void
    {
        $rows = [$this->lines()[0]];
        $rows[0] = [...$rows[0], 'description' => '=HYPERLINK("https://example.invalid")', 'reference' => '  @SUM(1)', 'money_in' => '0.01', 'balance' => '-0.99'];
        $row = $this->record($this->metadata(['opening_balance' => '-1.00', 'closing_balance' => '-0.99']), $rows);
        $response = $this->actingAs($this->actor)->get(self::BASE.'/'.$row->getKey().'/csv')->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'@SUM", str_replace(' ', '', $csv));
        $this->assertStringContainsString('-0.99', $csv);
        $this->assertStringNotContainsString('source_hash', $csv);
    }

    public function test_csv_quotes_bom_multiline_and_crlf_are_supported(): void
    {
        $rows = $this->lines();
        $rows[0]['description'] = "Quoted, \"bank\"\nsecond line";
        $raw = "\xEF\xBB\xBF".str_replace("\n", "\r\n", $this->source($rows));
        $parsed = app(AccountingBankStatementCsv::class)->read($raw);
        $p = app(AccountingBankStatementContract::class)->normalize($this->metadata(), $parsed);
        $this->assertStringContainsString('second line', $p['lines'][0]['description']);
    }

    public function test_malformed_csv_headers_quotes_delimiters_and_encodings_are_rejected(): void
    {
        foreach (["date,amount\n", "date;value_date;amount\n", $this->source().'"unclosed', str_replace('PRIVATE-REFERENCE', 'bad"quote', $this->source()), "\xFFbad", $this->source()."\0"] as $raw) {
            $this->rejected(fn () => $this->service()->preview($this->actor, 'csv', $this->metadata(), [], $raw));
        }
    }

    public function test_file_byte_and_row_limits_are_enforced(): void
    {
        $this->rejected(fn () => $this->service()->preview($this->actor, 'csv', $this->metadata(), [], str_repeat('x', 524289)));
        $rows = array_fill(0, 1001, $this->lines()[0]);
        $this->rejected(fn () => $this->record(lines: $rows));
    }

    public function test_manual_text_controls_and_unknown_row_fields_are_rejected(): void
    {
        $rows = $this->lines();
        $rows[0]['description'] = "bad\x01text";
        $this->rejected(fn () => $this->record(lines: $rows));
        $rows = $this->lines();
        $rows[0]['matched_journal_id'] = 1;
        $this->rejected(fn () => $this->record(lines: $rows));
    }

    public function test_seeder_is_idempotent_and_permissions_are_exact(): void
    {
        $this->seed(AccountingBankStatementPermissionSeeder::class);
        $this->seed(AccountingBankStatementPermissionSeeder::class);
        $this->assertSame(4, Permission::query()->whereIn('name', AccountingBankStatementContract::PERMISSIONS)->count());
    }

    public function test_migration_collision_is_reported_without_altering_existing_tables(): void
    {
        $files = glob(database_path('migrations/*create_bank_statement_import_foundation.php'));
        $this->assertIsArray($files);
        $this->assertCount(1, $files);
        $before = $this->digest();
        $migration = require $files[0];
        try {
            $migration->up();
            $this->fail('Expected migration collision.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No DDL was attempted', $e->getMessage());
        } $this->assertSame($before, $this->digest());
    }

    public function test_detail_pagination_keeps_full_statement_totals_and_export_rows(): void
    {
        $rows = [];
        for ($i = 1; $i <= 51; $i++) {
            $rows[] = [...$this->lines()[0], 'transaction_id' => 'PAGE-'.$i, 'money_in' => '0.01', 'balance' => ''];
        }
        $row = $this->record($this->metadata(['opening_balance' => '0.00', 'closing_balance' => '0.51']), $rows);
        $this->actingAs($this->actor)->get(self::BASE.'/'.$row->getKey().'?page=2')->assertOk()->assertInertia(fn (Assert $p) => $p->has('rows', 1)->where('rows.0.line_number', 51)->where('statement.money_in', '0.51')->where('page.total', 51));
        $csv = $this->get(self::BASE.'/'.$row->getKey().'/csv')->assertOk()->streamedContent();
        $this->assertSame(52, count(explode("\n", trim($csv))));
    }

    public function test_negative_zero_is_normalized_and_empty_statements_are_evidence(): void
    {
        $row = $this->record($this->metadata(['opening_balance' => '-0.00', 'closing_balance' => '0']), []);
        $this->assertSame('0.00', $row->getAttribute('opening_balance'));
        $this->assertSame(0, $row->getAttribute('row_count'));
        $this->verify($row);
    }

    public function test_aggregate_totals_can_exceed_single_balance_precision_without_floats(): void
    {
        $maximum = '999999999999999999.99';
        $rows = [];
        for ($i = 1; $i <= 4; $i++) {
            $rows[] = [...$this->lines()[0], 'transaction_id' => 'LARGE-'.$i, 'money_in' => $i % 2 === 1 ? $maximum : '0.00', 'money_out' => $i % 2 === 0 ? $maximum : '0.00', 'balance' => ''];
        }
        $data = app(AccountingBankStatementContract::class)->normalize($this->metadata(['opening_balance' => '0.00', 'closing_balance' => '0.00']), $rows);
        $this->assertSame('1999999999999999999.98', $data['totals']['money_in']);
        $this->assertSame($data['totals']['money_in'], $data['totals']['money_out']);
    }

    public function test_zero_movement_csv_can_repeat_header_bytes_in_nonoverlapping_periods(): void
    {
        $this->record($this->metadata(['statement_reference' => 'SEP-ZERO', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'opening_balance' => '0.00', 'closing_balance' => '0.00']), [], 'statement-sep-key-0001', 'csv');
        $row = $this->record($this->metadata(['statement_reference' => 'OCT-ZERO', 'opening_balance' => '0.00', 'closing_balance' => '0.00']), [], 'statement-oct-key-0002', 'csv');
        $this->verify($row);
        $this->assertDatabaseCount('accounting_bank_statements', 2);
    }

    public function test_new_import_cannot_hide_damaged_existing_bank_evidence(): void
    {
        $row = $this->record();
        DB::table('accounting_bank_statement_evidence')->where('statement_id', $row->getKey())->delete();
        $m = $this->metadata(['statement_reference' => 'OLDER', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'opening_balance' => '100.00', 'closing_balance' => '100.00']);
        $this->rejected(fn () => $this->record($m, [], 'statement-older-key-0002'));
    }

    public function test_statement_line_cannot_be_attached_to_another_bank_in_the_same_company(): void
    {
        $row = $this->record();
        $attrs = AccountingBankStatementLine::query()->firstOrFail()->getAttributes();
        unset($attrs['id']);
        $attrs['line_number'] = 3;
        $attrs['cash_account_id'] = $this->cash->getKey();
        $attrs['fingerprint'] = str_repeat('f', 64);
        $attrs['transaction_hash'] = null;
        $before = $this->digest();
        try {
            DB::transaction(fn () => DB::table('accounting_bank_statement_lines')->insert($attrs));
            $this->fail('Expected owned bank FK rejection.');
        } catch (QueryException) {
            $this->assertSame($before, $this->digest());
        }
    }

    public function test_http_multipart_preview_accepts_omitted_empty_rows_and_checks_the_file(): void
    {
        $data = $this->command('csv');
        unset($data['preview_hash'], $data['lines']);
        $before = $this->digest();
        $this->actingAs($this->actor)->post(self::BASE.'/preview', $data, ['Accept' => 'application/json'])->assertOk()->assertJsonCount(2, 'lines');
        $this->assertSame($before, $this->digest());
    }
}

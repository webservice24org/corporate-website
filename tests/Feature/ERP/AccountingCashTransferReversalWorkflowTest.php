<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingCashTransfer;
use App\Models\Accounting\AccountingCashTransferReversalAuditEvent;
use App\Models\Accounting\AccountingCashTransferReversalRequest;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingMappingVersion;
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
use App\Services\ERP\Accounting\Source\AccountingSourceEventRegistry;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferPosting;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferReversalContract;
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

final class AccountingCashTransferReversalWorkflowTest extends TestCase
{
    use RefreshDatabase;

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

        return app(AccountingCashTransferPosting::class)->post($this->actor, (int) $row->getKey(), 3, (string) Str::uuid());
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

    public function test_create_submit_approve_and_cancel_never_change_money_or_original(): void
    {
        $original = $this->original();
        $before = $this->financialDigest();
        $row = $this->draft($original);
        $row = $this->workflow()->submit($this->actor, (int) $row->getKey(), 1);
        $row = $this->workflow()->approve($this->reviewer, (int) $row->getKey(), 2);
        $this->assertSame('approved', $row->getAttribute('status'));
        $this->assertSame('100.00', $row->getAttribute('amount'));
        $row = $this->workflow()->cancel($this->actor, (int) $row->getKey(), 3, 'No longer required');
        $this->assertSame('cancelled', $row->getAttribute('status'));
        $this->assertNull($row->getAttribute('active_transfer_id'));
        $this->assertSame($before, $this->financialDigest());
        $this->assertDatabaseCount('accounting_cash_transfer_reversal_audit_events', 4);
    }

    public function test_preview_swaps_exact_original_legs_without_new_journal(): void
    {
        $original = $this->original(['amount' => '25.25']);
        $row = $this->draft($original);
        $before = $this->digest();
        $plan = $this->workflow()->preview($this->actor, (int) $row->getKey());
        $this->assertTrue($plan['preview_only']);
        $this->assertSame('25.25', $plan['amount']);
        $this->assertSame((int) $this->cash->getKey(), $plan['lines'][0]['cash_account_id']);
        $this->assertSame('25.25', $plan['lines'][0]['debit']);
        $this->assertSame((int) $this->bank->getKey(), $plan['lines'][1]['cash_account_id']);
        $this->assertSame('25.25', $plan['lines'][1]['credit']);
        $this->assertSame($before, $this->digest());
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_cents_survive_reloaded_reversal_review(): void
    {
        $original = $this->original(['amount' => '0.01']);
        $row = $this->draft($original)->fresh();
        $row = $this->workflow()->submit($this->actor, (int) $row->getKey(), 1)->fresh();
        $row = $this->workflow()->approve($this->reviewer, (int) $row->getKey(), 2)->fresh();
        $this->assertSame('0.01', $row->getAttribute('amount'));
        $this->assertSame('approved', $row->getAttribute('status'));
    }

    public function test_identical_creation_request_replays_without_new_rows(): void
    {
        $original = $this->original();
        $input = $this->requestData();
        $row = $this->workflow()->prepare($this->actor, (int) $original->getKey(), $input);
        $before = $this->digest();
        $again = $this->workflow()->prepare($this->actor, (int) $original->getKey(), $input);
        $this->assertSame($row->getKey(), $again->getKey());
        $this->assertSame($before, $this->digest());
    }

    public function test_changed_body_with_same_key_is_rejected(): void
    {
        $original = $this->original();
        $input = $this->requestData();
        $this->workflow()->prepare($this->actor, (int) $original->getKey(), $input);
        $this->rejected(fn () => $this->workflow()->prepare($this->actor, (int) $original->getKey(), [...$input, 'reason' => 'Different reason']));
    }

    public function test_same_key_cannot_be_claimed_by_another_maker(): void
    {
        $original = $this->original();
        $input = $this->requestData();
        $this->workflow()->prepare($this->actor, (int) $original->getKey(), $input);
        $this->rejected(fn () => $this->workflow()->prepare($this->reviewer, (int) $original->getKey(), $input));
    }

    public function test_company_creation_key_cannot_be_reused_for_another_original(): void
    {
        $a = $this->original();
        $b = $this->original();
        $input = $this->requestData();
        $this->workflow()->prepare($this->actor, (int) $a->getKey(), $input);
        $this->rejected(fn () => $this->workflow()->prepare($this->actor, (int) $b->getKey(), $input));
    }

    public function test_second_active_request_is_rejected_even_with_new_key(): void
    {
        $original = $this->original();
        $this->draft($original);
        $this->rejected(fn () => $this->draft($original));
    }

    public function test_cancel_releases_slot_for_a_new_request_without_reclaiming_old_replay(): void
    {
        $original = $this->original();
        $input = $this->requestData();
        $row = $this->workflow()->prepare($this->actor, (int) $original->getKey(), $input);
        $this->workflow()->cancel($this->actor, (int) $row->getKey(), 1, 'Use another review request');
        $new = $this->draft($original);
        $before = $this->digest();
        $again = $this->workflow()->prepare($this->actor, (int) $original->getKey(), $input);
        $this->assertSame('cancelled', $again->getAttribute('status'));
        $this->assertNotSame($again->getKey(), $new->getKey());
        $this->assertSame($before, $this->digest());
    }

    public function test_creation_replay_survives_closed_period_and_cutover_lock(): void
    {
        $original = $this->original();
        $input = $this->requestData();
        $this->workflow()->prepare($this->actor, (int) $original->getKey(), $input);
        $this->settings(['state' => 'disabled', 'cutover_locked_at' => now()]);
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->update(['state' => 'closed']);
        $before = $this->digest();
        $again = $this->workflow()->prepare($this->actor, (int) $original->getKey(), $input);
        $this->assertSame('draft', $again->getAttribute('status'));
        $this->assertSame($before, $this->digest());
    }

    public function test_draft_edit_changes_reason_date_and_editor_but_not_original_proof(): void
    {
        $original = $this->original();
        $row = $this->draft($original);
        $snapshot = $row->getAttribute('original_snapshot_hash');
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $row = $this->workflow()->prepare($this->reviewer, (int) $original->getKey(), $this->editData($row, ['document_date' => '2026-10-17', 'reason' => 'Revised reason']), (int) $row->getKey());
        $this->assertSame(2, $row->getAttribute('revision'));
        $this->assertSame($snapshot, $row->getAttribute('original_snapshot_hash'));
        $this->assertSame((int) $this->reviewer->getKey(), (int) $row->getAttribute('last_edited_by'));
        $this->assertSame('Revised reason', $row->getAttribute('reason'));
    }

    public function test_submitted_and_approved_requests_cannot_be_edited(): void
    {
        $original = $this->original();
        $row = $this->submitted($original);
        $this->rejected(fn () => $this->workflow()->prepare($this->actor, (int) $original->getKey(), $this->editData($row), (int) $row->getKey()));
        $row = $this->workflow()->approve($this->reviewer, (int) $row->getKey(), 2);
        $this->rejected(fn () => $this->workflow()->prepare($this->actor, (int) $original->getKey(), $this->editData($row), (int) $row->getKey()));
    }

    public function test_maker_cannot_approve_own_request(): void
    {
        $row = $this->submitted($this->original());
        $this->rejected(fn () => $this->workflow()->approve($this->actor, (int) $row->getKey(), 2));
    }

    public function test_last_editor_cannot_approve(): void
    {
        $original = $this->original();
        $row = $this->draft($original);
        $row = $this->workflow()->prepare($this->reviewer, (int) $original->getKey(), $this->editData($row), (int) $row->getKey());
        $row = $this->workflow()->submit($this->actor, (int) $row->getKey(), 2);
        $this->rejected(fn () => $this->workflow()->approve($this->reviewer, (int) $row->getKey(), 3));
    }

    public function test_distinct_submitter_cannot_approve(): void
    {
        $row = $this->draft($this->original());
        $submitter = $this->staff(['erp.cash-transfer-reversals.submit', 'erp.cash-transfer-reversals.approve']);
        $row = $this->workflow()->submit($submitter, (int) $row->getKey(), 1);
        $this->rejected(fn () => $this->workflow()->approve($submitter, (int) $row->getKey(), 2));
    }

    public function test_reasoned_rejection_clears_stamps_and_retains_active_slot(): void
    {
        $original = $this->original();
        $row = $this->submitted($original);
        $row = $this->workflow()->reject($this->reviewer, (int) $row->getKey(), 2, 'Review date');
        $this->assertSame('draft', $row->getAttribute('status'));
        $this->assertNull($row->getAttribute('submitted_at'));
        $this->assertNull($row->getAttribute('submitted_payload_hash'));
        $this->assertSame((int) $original->getKey(), (int) $row->getAttribute('active_transfer_id'));
        $row = $this->workflow()->submit($this->actor, (int) $row->getKey(), 3);
        $row = $this->workflow()->approve($this->reviewer, (int) $row->getKey(), 4);
        $this->assertSame('approved', $row->getAttribute('status'));
    }

    public function test_stale_revision_is_rejected_for_edit_and_submit(): void
    {
        $original = $this->original();
        $row = $this->draft($original);
        $this->rejected(fn () => $this->workflow()->prepare($this->actor, (int) $original->getKey(), $this->editData($row, ['revision' => 99]), (int) $row->getKey()));
        $this->rejected(fn () => $this->workflow()->submit($this->actor, (int) $row->getKey(), 99));
    }

    public function test_cancelled_request_is_immutable(): void
    {
        $row = $this->draft($this->original());
        $row = $this->workflow()->cancel($this->actor, (int) $row->getKey(), 1, 'Not required');
        $this->rejected(fn () => $this->workflow()->submit($this->actor, (int) $row->getKey(), 2));
        $this->rejected(fn () => DB::transaction(fn () => $row->forceFill(['reason' => 'Changed', 'revision' => 3])->save()));
    }

    public function test_new_requests_require_reversal_create_permission(): void
    {
        $original = $this->original();
        $viewer = $this->staff(['erp.cash-transfer-reversals.view']);
        $this->rejected(fn () => $this->draft($original, actor: $viewer));
    }

    public function test_native_transfer_permission_does_not_grant_reversal_authority(): void
    {
        $original = $this->original();
        $user = $this->staff(['erp.cash-transfers.view', 'erp.cash-transfers.post']);
        $this->rejected(fn () => $this->draft($original, actor: $user));
    }

    public function test_reversal_create_permission_does_not_require_manual_journal_authority(): void
    {
        $original = $this->original();
        $user = $this->staff(['erp.cash-transfer-reversals.create']);
        $this->assertFalse($user->can('erp.journal-entries.create'));
        $this->assertFalse($user->can('erp.cash-transfers.view'));
        $row = $this->draft($original, actor: $user);
        $this->assertSame('draft', $row->getAttribute('status'));
    }

    public function test_foreign_company_actor_cannot_prepare_or_view(): void
    {
        $original = $this->original();
        $row = $this->draft($original);
        $user = $this->staff(['erp.cash-transfer-reversals.create', 'erp.cash-transfer-reversals.view']);
        $foreign = $this->newCompany('REVERSAL-FOREIGN');
        DB::table('employees')->where('user_id', $user->getKey())->update(['company_id' => $foreign->getKey()]);
        $this->rejected(fn () => $this->draft($original, actor: $user));
        $this->rejected(fn () => $this->workflow()->find($user, (int) $row->getKey()));
    }

    public function test_view_only_actor_cannot_submit_approve_or_cancel(): void
    {
        $row = $this->draft($this->original());
        $viewer = $this->staff(['erp.cash-transfer-reversals.view']);
        $this->rejected(fn () => $this->workflow()->submit($viewer, (int) $row->getKey(), 1));
        $this->rejected(fn () => $this->workflow()->approve($viewer, (int) $row->getKey(), 1));
        $this->rejected(fn () => $this->workflow()->cancel($viewer, (int) $row->getKey(), 1, 'Not allowed'));
    }

    public function test_unposted_original_is_rejected(): void
    {
        $service = app(AccountingCashTransferWorkflow::class);
        $row = $service->save($this->actor, ['company_id' => (int) $this->company->getKey(), 'document_date' => '2026-10-16', 'source_cash_account_id' => (int) $this->cash->getKey(), 'destination_cash_account_id' => (int) $this->bank->getKey(), 'amount' => '100.00', 'purpose' => 'Draft original', 'key' => (string) Str::uuid()]);
        $this->rejected(fn () => $this->draft($row));
    }

    public function test_original_amount_currency_and_endpoints_cannot_be_supplied_as_overrides(): void
    {
        $original = $this->original();
        foreach ([['amount' => '1.00'], ['currency_code' => 'USD'], ['source_cash_account_id' => (int) $this->bank->getKey()], ['original_snapshot' => []], ['status' => 'approved']] as $override) {
            $this->rejected(fn () => $this->draft($original, $override));
        }
    }

    public function test_blank_reason_and_invalid_creation_key_are_rejected(): void
    {
        $original = $this->original();
        $this->rejected(fn () => $this->draft($original, ['reason' => '   ']));
        $this->rejected(fn () => $this->draft($original, ['key' => 'short']));
    }

    public function test_future_date_and_pre_original_date_are_rejected(): void
    {
        $original = $this->original();
        $this->rejected(fn () => $this->draft($original, ['document_date' => '2026-10-17']));
        $this->rejected(fn () => $this->draft($original, ['document_date' => '2026-10-15']));
    }

    public function test_closed_reversal_period_blocks_new_preparation(): void
    {
        $original = $this->original();
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->update(['state' => 'closed']);
        $this->rejected(fn () => $this->draft($original));
    }

    public function test_period_closure_after_draft_blocks_submission(): void
    {
        $row = $this->draft($this->original());
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->update(['state' => 'closed']);
        $this->rejected(fn () => $this->workflow()->submit($this->actor, (int) $row->getKey(), 1));
    }

    public function test_period_closure_after_submission_blocks_approval(): void
    {
        $row = $this->submitted($this->original());
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->update(['state' => 'closed']);
        $this->rejected(fn () => $this->workflow()->approve($this->reviewer, (int) $row->getKey(), 2));
    }

    public function test_closed_original_period_can_use_a_later_open_reversal_period(): void
    {
        $original = $this->original();
        $year = (int) DB::table('fiscal_years')->where('company_id', $this->company->getKey())->value('id');
        app(AccountingCalendarService::class)->savePeriod($this->actor, $year, ['company_id' => (int) $this->company->getKey(), 'code' => 'NOV', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'reason' => 'Reversal correction period']);
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->where('code', 'OCT')->update(['state' => 'closed']);
        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00', 'Asia/Dhaka'));
        $before = $this->financialDigest();
        $row = $this->draft($original);
        $row = $this->workflow()->submit($this->actor, (int) $row->getKey(), 1);
        $row = $this->workflow()->approve($this->reviewer, (int) $row->getKey(), 2);
        $this->assertSame('2026-11-01', $row->getAttribute('document_date')->format('Y-m-d'));
        $this->assertSame($before, $this->financialDigest());
    }

    public function test_backdated_review_requires_period_adjust_authority(): void
    {
        $original = $this->original();
        $this->travelTo(CarbonImmutable::parse('2026-10-18 12:00:00', 'Asia/Dhaka'));
        $user = $this->staff(['erp.cash-transfer-reversals.create']);
        $this->rejected(fn () => $this->draft($original, ['document_date' => '2026-10-17'], $user));
        $user->givePermissionTo('erp.accounting-periods.adjust');
        $row = $this->draft($original, ['document_date' => '2026-10-17'], $user);
        $this->assertSame('draft', $row->getAttribute('status'));
    }

    public function test_inactive_bindings_remain_fixed_original_review_endpoints(): void
    {
        $original = $this->original();
        $this->cash->update(['status' => 'inactive']);
        $this->bank->update(['status' => 'inactive']);
        $before = $this->financialDigest();
        $row = $this->submitted($original);
        $row = $this->workflow()->approve($this->reviewer, (int) $row->getKey(), 2);
        $plan = $this->workflow()->preview($this->actor, (int) $row->getKey());
        $this->assertSame((int) $this->cash->getAttribute('account_id'), $plan['lines'][0]['account_id']);
        $this->assertSame($before, $this->financialDigest());
    }

    public function test_later_mapping_remap_does_not_retarget_original_reversal(): void
    {
        $original = $this->original();
        $row = $this->draft($original);
        $previous = AccountingMappingVersion::query()->where('company_id', $this->company->getKey())->firstOrFail();
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $map = $this->mapping;
        [$map['cash:MAIN'], $map['bank:CURRENT']] = [$map['bank:CURRENT'], $map['cash:MAIN']];
        $this->publish(['base_version_id' => (int) $previous->getKey(), 'effective_from' => '2026-10-17', 'mappings' => $map]);
        $row = $this->workflow()->prepare($this->actor, (int) $original->getKey(), $this->editData($row, ['document_date' => '2026-10-17']), (int) $row->getKey());
        $row = $this->workflow()->submit($this->actor, (int) $row->getKey(), 2);
        $row = $this->workflow()->approve($this->reviewer, (int) $row->getKey(), 3);
        $plan = $this->workflow()->preview($this->actor, (int) $row->getKey());
        $this->assertSame((int) $this->cash->getAttribute('account_id'), $plan['lines'][0]['account_id']);
    }

    public function test_cutover_lock_and_inactive_company_block_new_changes(): void
    {
        $original = $this->original();
        $this->settings(['cutover_locked_at' => now()]);
        $this->rejected(fn () => $this->draft($original));
        $this->settings(['cutover_locked_at' => null]);
        $this->company->update(['status' => 'inactive']);
        $this->rejected(fn () => $this->draft($original));
    }

    public function test_disabled_accounting_can_review_existing_posted_transfer_without_money(): void
    {
        $original = $this->original();
        $this->settings(['state' => 'disabled']);
        $before = $this->financialDigest();
        $row = $this->submitted($original);
        $row = $this->workflow()->approve($this->reviewer, (int) $row->getKey(), 2);
        $this->assertSame('approved', $row->getAttribute('status'));
        $this->assertSame($before, $this->financialDigest());
    }

    public function test_missing_original_operation_blocks_preparation_without_repair(): void
    {
        $original = $this->original();
        DB::table('accounting_cash_transfer_operations')->delete();
        $this->rejected(fn () => $this->draft($original));
    }

    public function test_original_evidence_damage_after_draft_blocks_submit_and_creation_replay(): void
    {
        $original = $this->original();
        $input = $this->requestData();
        $row = $this->workflow()->prepare($this->actor, (int) $original->getKey(), $input);
        DB::table('accounting_cash_transfer_operations')->update(['payload_hash' => str_repeat('0', 64)]);
        $this->rejected(fn () => $this->workflow()->submit($this->actor, (int) $row->getKey(), 1));
        $this->rejected(fn () => $this->workflow()->prepare($this->actor, (int) $original->getKey(), $input));
    }

    public function test_original_evidence_damage_after_submit_blocks_approval(): void
    {
        $row = $this->submitted($this->original());
        DB::table('journal_entry_lines')->where('cash_account_id', $this->bank->getKey())->delete();
        $this->rejected(fn () => $this->workflow()->approve($this->reviewer, (int) $row->getKey(), 2));
    }

    public function test_cancellation_can_release_a_request_when_original_financial_evidence_is_damaged(): void
    {
        $original = $this->original();
        $row = $this->draft($original);
        DB::table('accounting_cash_transfer_operations')->delete();
        $before = $this->financialDigest();
        $row = $this->workflow()->cancel($this->actor, (int) $row->getKey(), 1, 'Escalate original evidence issue');
        $this->assertSame('cancelled', $row->getAttribute('status'));
        $this->assertSame($before, $this->financialDigest());
        $this->rejected(fn () => $this->draft($original));
    }

    public function test_tampered_reason_after_submission_is_not_approved(): void
    {
        $row = $this->submitted($this->original());
        DB::table('accounting_cash_transfer_reversal_requests')->where('id', $row->getKey())->update(['reason' => 'Changed outside review']);
        $this->rejected(fn () => $this->workflow()->approve($this->reviewer, (int) $row->getKey(), 2));
    }

    public function test_rehashed_original_snapshot_cannot_replace_actual_verified_source(): void
    {
        $row = $this->draft($this->original());
        $snapshot = $row->getAttribute('original_snapshot');
        $snapshot['operation_hash'] = str_repeat('f', 64);
        DB::table('accounting_cash_transfer_reversal_requests')->where('id', $row->getKey())->update(['original_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'original_snapshot_hash' => app(AccountingCanonicalPayload::class)->hash($snapshot)]);
        $this->rejected(fn () => $this->workflow()->submit($this->actor, (int) $row->getKey(), 1));
    }

    public function test_approved_intent_is_immutable_and_posted_state_requires_complete_metadata(): void
    {
        $row = $this->submitted($this->original());
        $row = $this->workflow()->approve($this->reviewer, (int) $row->getKey(), 2);
        $this->rejected(fn () => DB::transaction(fn () => $row->forceFill(['reason' => 'Changed', 'revision' => 4])->save()));
        $this->rejected(fn () => DB::transaction(fn () => $row->forceFill(['status' => 'posted', 'revision' => 4])->save()));
        $this->assertFalse(method_exists($this->workflow(), 'post'));
    }

    public function test_header_original_amount_and_links_cannot_be_changed_directly(): void
    {
        $row = $this->draft($this->original());
        $this->rejected(fn () => DB::transaction(fn () => $row->forceFill(['amount' => '1.00', 'revision' => 2])->save()));
        $row = $row->fresh();
        $this->rejected(fn () => DB::transaction(fn () => $row->forceFill(['source_cash_account_id' => (int) $this->bank->getKey(), 'revision' => 2])->save()));
    }

    public function test_headers_and_audits_cannot_be_deleted_or_rewritten(): void
    {
        $row = $this->draft($this->original());
        $this->rejected(fn () => DB::transaction(fn () => $row->delete()));
        $event = AccountingCashTransferReversalAuditEvent::query()->firstOrFail();
        $this->rejected(fn () => $event->update(['reason' => 'Changed']));
        $this->rejected(fn () => $event->delete());
    }

    public function test_blank_reject_and_cancel_reasons_are_rejected(): void
    {
        $row = $this->submitted($this->original());
        $this->rejected(fn () => $this->workflow()->reject($this->reviewer, (int) $row->getKey(), 2, '  '));
        $this->rejected(fn () => $this->workflow()->cancel($this->actor, (int) $row->getKey(), 2, '  '));
    }

    public function test_financial_preview_and_original_capture_do_not_copy_private_text(): void
    {
        $row = $this->draft($this->original());
        $plan = $this->workflow()->preview($this->actor, (int) $row->getKey());
        $json = json_encode([$row->getAttribute('original_snapshot'), $plan, AccountingCashTransferReversalAuditEvent::query()->firstOrFail()->getAttribute('after_payload')], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PRIVATE-', $json);
        $this->assertStringNotContainsString('creation_key_hash', $json);
    }

    public function test_audit_failure_rolls_back_new_request_and_active_slot(): void
    {
        $original = $this->original();
        $input = $this->requestData();
        $before = $this->digest();
        $breakAudit = true;
        Event::listen('eloquent.creating: '.AccountingCashTransferReversalAuditEvent::class, function () use (&$breakAudit): void {
            if ($breakAudit) {
                throw new \RuntimeException('Synthetic reversal audit failure');
            }
        });
        try {
            $this->workflow()->prepare($this->actor, (int) $original->getKey(), $input);
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic reversal audit failure', $exception->getMessage());
        } finally {
            $breakAudit = false;
        }
        $this->assertSame($before, $this->digest());
        $this->assertSame('draft', $this->workflow()->prepare($this->actor, (int) $original->getKey(), $input)->getAttribute('status'));
    }

    public function test_database_enforces_single_active_slot_without_disabling_foreign_keys(): void
    {
        $row = $this->draft($this->original());
        $attributes = $row->getAttributes();
        unset($attributes['id']);
        $attributes['creation_key_hash'] = hash('sha256', 'another-native-creation-key');
        $before = $this->digest();
        try {
            DB::transaction(fn () => DB::table('accounting_cash_transfer_reversal_requests')->insert($attributes));
            $this->fail('Expected unique active-slot constraint.');
        } catch (QueryException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertSame($before, $this->digest());
    }

    public function test_database_rejects_cross_company_original_links(): void
    {
        $row = $this->draft($this->original());
        $other = $this->newCompany('REVERSAL-FK');
        $attributes = $row->getAttributes();
        unset($attributes['id']);
        $attributes['company_id'] = (int) $other->getKey();
        $attributes['creation_key_hash'] = hash('sha256', 'foreign-native-creation-key');
        $before = $this->digest();
        try {
            DB::transaction(fn () => DB::table('accounting_cash_transfer_reversal_requests')->insert($attributes));
            $this->fail('Expected owned original foreign key.');
        } catch (QueryException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertSame($before, $this->digest());
    }

    public function test_permission_seed_is_idempotent_and_includes_separate_posting_permission(): void
    {
        $this->seed(AccountingCashTransferReversalPermissionSeeder::class);
        $this->seed(AccountingCashTransferReversalPermissionSeeder::class);
        foreach (AccountingCashTransferReversalContract::PERMISSIONS as $name) {
            $this->assertSame(1, Permission::query()->where('name', $name)->where('guard_name', 'web')->count());
        }
        $this->assertContains('erp.cash-transfer-reversals.post', AccountingCashTransferReversalContract::PERMISSIONS);
    }

    public function test_reversal_source_adapter_registers_exactly_one_cash_effect(): void
    {
        $this->assertSame(['cash' => []], app(AccountingSourceEventRegistry::class)->definition('cash_transfer_reversal', 'posted'));
    }

    public function test_migration_collision_is_reported_before_any_ddl(): void
    {
        $files = glob(database_path('migrations/*create_cash_transfer_reversal_foundation.php'));
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
}

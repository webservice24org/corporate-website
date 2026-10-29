<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingCashTransfer;
use App\Models\Accounting\AccountingCashTransferReversalRequest;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingMappingVersion;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingAccountService;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\AccountingCashAccountService;
use App\Services\ERP\Accounting\AccountingMappingCatalog;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\Books\AccountingCashBookReader;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferPosting;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferReversalContract;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AccountingCashTransferReversalScreenTest extends TestCase
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

    private const BASE = '/erp/accounting/cash-transfer-reversals';

    private function url(AccountingCashTransferReversalRequest $row, string $action = ''): string
    {
        return self::BASE.'/'.$row->getKey().($action === '' ? '' : '/'.$action);
    }

    private function screen(AccountingCashTransferReversalRequest $row, ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->actor)->get($this->url($row));
    }

    public function test_all_routes_require_authentication(): void
    {
        $row = $this->draft($this->original());
        $this->get(self::BASE)->assertRedirect('/login');
        $this->get(self::BASE.'/create?transfer_id='.$row->getAttribute('original_transfer_id'))->assertRedirect('/login');
        $this->get($this->url($row))->assertRedirect('/login');
        $this->get($this->url($row, 'edit'))->assertRedirect('/login');
        $this->post(self::BASE, [])->assertRedirect('/login');
        $this->put($this->url($row), [])->assertRedirect('/login');
        foreach (['submit', 'approve', 'reject', 'cancel', 'post'] as $action) {
            $this->post($this->url($row, $action), [])->assertRedirect('/login');
        }
    }

    public function test_native_view_is_required_even_with_create_permission(): void
    {
        $original = $this->original();
        $viewer = $this->staff(['erp.cash-transfer-reversals.create']);
        $this->actingAs($viewer)->get(self::BASE)->assertForbidden();
        $this->post(self::BASE, ['transfer_id' => $original->getKey(), ...$this->requestData()])->assertForbidden();
    }

    public function test_view_only_actor_cannot_execute_any_command(): void
    {
        $row = $this->draft($this->original());
        $before = $this->digest();
        $viewer = $this->staff(['erp.cash-transfer-reversals.view']);
        $this->screen($row, $viewer)->assertOk()->assertInertia(fn (Assert $page) => $page->where('capabilities.edit', false)->where('capabilities.submit', false)->where('capabilities.cancel', false));
        $this->get($this->url($row, 'edit'))->assertForbidden();
        $this->post(self::BASE, [])->assertForbidden();
        $this->put($this->url($row), [])->assertForbidden();
        foreach (['submit', 'approve', 'reject', 'cancel', 'post'] as $action) {
            $this->post($this->url($row, $action), [])->assertForbidden();
        }
        $this->assertSame($before, $this->digest());
    }

    public function test_register_component_is_scoped_and_omits_private_evidence(): void
    {
        $row = $this->draft($this->original());
        $viewer = $this->staff(['erp.cash-transfer-reversals.view']);
        $this->actingAs($viewer)->get(self::BASE)->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/CashTransferReversals/Index')->has('companies', 1)->has('reversals.data', 1)->where('reversals.data.0.id', (int) $row->getKey())->where('reversals.data.0.amount', '100.00')->missing('reversals.data.0.reason')->missing('reversals.data.0.original_snapshot')->missing('reversals.data.0.creation_key_hash'));
    }

    public function test_foreign_company_query_is_forbidden(): void
    {
        $other = $this->newCompany('REV-SCOPE');
        $viewer = $this->staff(['erp.cash-transfer-reversals.view']);
        $this->actingAs($viewer)->get(self::BASE.'?company_id='.$other->getKey())->assertForbidden();
    }

    public function test_foreign_request_is_not_found_for_staff(): void
    {
        $row = $this->draft($this->original());
        $other = $this->newCompany('REV-FOREIGN');
        $viewer = $this->staff(['erp.cash-transfer-reversals.view']);
        DB::table('employees')->where('user_id', $viewer->getKey())->update(['company_id' => $other->getKey()]);
        $this->screen($row, $viewer)->assertNotFound();
    }

    public function test_foreign_original_cannot_be_prepared(): void
    {
        $original = $this->original();
        $other = $this->newCompany('REV-CREATE');
        $viewer = $this->staff(['erp.cash-transfer-reversals.view', 'erp.cash-transfer-reversals.create']);
        DB::table('employees')->where('user_id', $viewer->getKey())->update(['company_id' => $other->getKey()]);
        $this->actingAs($viewer)->get(self::BASE.'/create?transfer_id='.$original->getKey())->assertNotFound();
        $this->post(self::BASE, ['transfer_id' => $original->getKey(), ...$this->requestData()])->assertNotFound();
    }

    public function test_detail_and_create_reject_conflicting_company_query(): void
    {
        $row = $this->draft($this->original());
        $other = $this->newCompany('REV-QUERY');
        $this->actingAs($this->actor)->get($this->url($row).'?company_id='.$other->getKey())->assertForbidden();
        $this->get(self::BASE.'/create?company_id='.$other->getKey().'&transfer_id='.$row->getAttribute('original_transfer_id'))->assertForbidden();
    }

    public function test_create_component_resolves_without_original_transfer_permission(): void
    {
        $original = $this->original();
        $viewer = $this->staff(['erp.cash-transfer-reversals.view', 'erp.cash-transfer-reversals.create']);
        $this->actingAs($viewer)->get(self::BASE.'/create?transfer_id='.$original->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/CashTransferReversals/Form')->where('reversal', null)->where('original.id', (int) $original->getKey())->where('original.amount', '100.00')->where('original.url', null)->where('original.journal_url', null)->missing('original.operation_hash')->missing('original.purpose')->missing('original.notes'));
    }

    public function test_draft_detail_and_edit_components_resolve_with_safe_preview(): void
    {
        $row = $this->draft($this->original());
        $this->screen($row)->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/CashTransferReversals/Show')->where('reversal.reason', 'PRIVATE-REVERSAL-REASON')->where('reversal.amount', '100.00')->missing('reversal.original_snapshot')->missing('reversal.creation_request_hash')->missing('reversal.submitted_payload_hash')->has('preview', 2)->where('preview.0.debit', '100.00')->where('preview.1.credit', '100.00')->where('finance.state', 'unposted')->where('capabilities.submit', true));
        $this->get($this->url($row, 'edit'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/CashTransferReversals/Form')->where('reversal.id', (int) $row->getKey()));
    }

    public function test_store_replays_same_creation_key_without_financial_writes(): void
    {
        $original = $this->original();
        $data = ['transfer_id' => $original->getKey(), ...$this->requestData()];
        $before = $this->financialDigest();
        $this->actingAs($this->actor)->post(self::BASE, $data)->assertRedirect();
        $row = AccountingCashTransferReversalRequest::query()->firstOrFail();
        $digest = $this->digest();
        $this->post(self::BASE, $data)->assertRedirect($this->url($row));
        $this->assertSame($digest, $this->digest());
        $this->assertSame($before, $this->financialDigest());
    }

    public function test_conflicting_creation_key_does_not_duplicate_request(): void
    {
        $original = $this->original();
        $data = ['transfer_id' => $original->getKey(), ...$this->requestData()];
        $this->actingAs($this->actor)->post(self::BASE, $data)->assertRedirect();
        $before = $this->digest();
        $this->post(self::BASE, [...$data, 'reason' => 'Changed retry'])->assertSessionHasErrors();
        $this->assertSame($before, $this->digest());
    }

    public function test_edit_changes_only_date_reason_and_revision(): void
    {
        $row = $this->draft($this->original());
        $before = $this->financialDigest();
        $this->actingAs($this->actor)->put($this->url($row), ['revision' => 1, 'document_date' => '2026-10-16', 'reason' => 'Updated reason'])->assertRedirect($this->url($row));
        $this->assertSame('Updated reason', $row->fresh()->getAttribute('reason'));
        $this->assertSame(2, $row->fresh()->getAttribute('revision'));
        $this->assertSame('100.00', $row->fresh()->getAttribute('amount'));
        $this->assertSame($before, $this->financialDigest());
    }

    public function test_amount_and_endpoint_overrides_are_rejected(): void
    {
        $row = $this->draft($this->original());
        $before = $this->digest();
        foreach (['amount' => '0.01', 'source_cash_account_id' => $this->bank->getKey(), 'destination_cash_account_id' => $this->cash->getKey(), 'original_transfer_id' => 99] as $field => $value) {
            $this->actingAs($this->actor)->put($this->url($row), [...$this->editData($row), $field => $value])->assertSessionHasErrors();
        }
        $this->assertSame($before, $this->digest());
    }

    public function test_stale_edit_revision_is_rejected(): void
    {
        $row = $this->draft($this->original());
        $before = $this->digest();
        $this->actingAs($this->actor)->put($this->url($row), $this->editData($row, ['revision' => 2]))->assertSessionHasErrors();
        $this->assertSame($before, $this->digest());
    }

    public function test_submit_and_independent_approval_use_native_services(): void
    {
        $row = $this->draft($this->original());
        $before = $this->financialDigest();
        $this->actingAs($this->actor)->post($this->url($row, 'submit'), ['revision' => 1])->assertRedirect($this->url($row));
        $this->screen($row)->assertOk()->assertInertia(fn (Assert $page) => $page->where('capabilities.approve', false)->where('capabilities.reject', true));
        $this->actingAs($this->reviewer)->post($this->url($row, 'approve'), ['revision' => 2])->assertRedirect($this->url($row));
        $this->assertSame('approved', $row->fresh()->getAttribute('status'));
        $this->assertSame($before, $this->financialDigest());
    }

    public function test_maker_cannot_approve_own_request(): void
    {
        $row = $this->submitted($this->original());
        $before = $this->digest();
        $this->actingAs($this->actor)->post($this->url($row, 'approve'), ['revision' => 2])->assertForbidden();
        $this->assertSame($before, $this->digest());
    }

    public function test_submitter_cannot_approve_even_when_not_maker_or_editor(): void
    {
        $row = $this->draft($this->original());
        $row = $this->workflow()->submit($this->reviewer, (int) $row->getKey(), 1);
        $this->screen($row, $this->reviewer)->assertOk()->assertInertia(fn (Assert $page) => $page->where('capabilities.approve', false));
        $this->post($this->url($row, 'approve'), ['revision' => 2])->assertForbidden();
    }

    public function test_reject_returns_to_draft_and_cancel_releases_slot(): void
    {
        $row = $this->submitted($this->original());
        $this->actingAs($this->reviewer)->post($this->url($row, 'reject'), ['revision' => 2, 'reason' => 'Correct the reason'])->assertRedirect();
        $this->assertSame('draft', $row->fresh()->getAttribute('status'));
        $this->post($this->url($row, 'cancel'), ['revision' => 3, 'reason' => 'Request withdrawn'])->assertRedirect();
        $this->assertSame('cancelled', $row->fresh()->getAttribute('status'));
        $this->assertNull($row->fresh()->getAttribute('active_transfer_id'));
    }

    public function test_blank_rejection_reason_is_rejected(): void
    {
        $row = $this->submitted($this->original());
        $before = $this->digest();
        $this->actingAs($this->reviewer)->post($this->url($row, 'reject'), ['revision' => 2, 'reason' => '   '])->assertSessionHasErrors();
        $this->assertSame($before, $this->digest());
    }

    public function test_post_requires_separate_native_permission(): void
    {
        $row = $this->ready($this->original());
        $viewer = $this->staff(['erp.cash-transfer-reversals.view', 'erp.cash-transfer-reversals.approve']);
        $before = $this->digest();
        $this->screen($row, $viewer)->assertOk()->assertInertia(fn (Assert $page) => $page->where('capabilities.post', false));
        $this->post($this->url($row, 'post'), ['revision' => 3, 'key' => 'screen-post-key-0001'])->assertForbidden();
        $this->assertSame($before, $this->digest());
    }

    public function test_post_works_without_manual_journal_or_transfer_authority_and_replays(): void
    {
        $row = $this->ready($this->original());
        $poster = $this->staff(['erp.cash-transfer-reversals.view', 'erp.cash-transfer-reversals.post']);
        $this->screen($row, $poster)->assertOk()->assertInertia(fn (Assert $page) => $page->where('capabilities.post', true));
        $data = ['revision' => 3, 'key' => 'screen-post-key-0001'];
        $this->post($this->url($row, 'post'), $data)->assertRedirect($this->url($row));
        $before = $this->digest();
        $this->post($this->url($row, 'post'), $data)->assertRedirect($this->url($row));
        $this->assertSame($before, $this->digest());
        $this->screen($row, $poster)->assertOk()->assertInertia(fn (Assert $page) => $page->where('finance.state', 'verified')->where('finance.journal_url', null)->where('capabilities.post', false));
    }

    public function test_conflicting_post_key_and_stale_revision_do_not_write(): void
    {
        $row = $this->ready($this->original());
        $before = $this->digest();
        $this->actingAs($this->actor)->post($this->url($row, 'post'), ['revision' => 2, 'key' => 'screen-post-key-0001'])->assertSessionHasErrors();
        $this->assertSame($before, $this->digest());
        $this->reverse($row);
        $before = $this->digest();
        $this->post($this->url($row, 'post'), ['revision' => 3, 'key' => 'different-post-key-0002'])->assertSessionHasErrors();
        $this->assertSame($before, $this->digest());
    }

    public function test_unexpected_post_payload_is_rejected(): void
    {
        $row = $this->ready($this->original());
        $before = $this->digest();
        $this->actingAs($this->actor)->post($this->url($row, 'post'), ['revision' => 3, 'key' => 'screen-post-key-0001', 'amount' => '0.01'])->assertSessionHasErrors();
        $this->assertSame($before, $this->digest());
    }

    public function test_posted_and_cancelled_requests_have_no_write_controls(): void
    {
        $original = $this->original();
        $cancelled = $this->draft($original);
        $cancelled = $this->workflow()->cancel($this->actor, (int) $cancelled->getKey(), 1, 'Withdraw');
        $posted = $this->reverse($this->ready($original));
        foreach ([$cancelled, $posted] as $row) {
            $this->screen($row)->assertOk()->assertInertia(function (Assert $page): void {
                foreach (['edit', 'submit', 'approve', 'reject', 'cancel', 'post'] as $action) {
                    $page->where('capabilities.'.$action, false);
                }
            });
            $this->get($this->url($row, 'edit'))->assertForbidden();
        }
    }

    public function test_disabled_accounting_permits_draft_review_but_not_post(): void
    {
        $row = $this->ready($this->original());
        $this->settings(['state' => 'disabled']);
        $this->screen($row)->assertOk()->assertInertia(fn (Assert $page) => $page->where('postingEnabled', false)->where('capabilities.post', false)->where('capabilities.cancel', true));
        $before = $this->digest();
        $this->post($this->url($row, 'post'), ['revision' => 3, 'key' => 'screen-post-key-0001'])->assertSessionHasErrors();
        $this->assertSame($before, $this->digest());
    }

    public function test_closed_period_disables_post_but_allows_cancellation(): void
    {
        $row = $this->ready($this->original());
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->update(['state' => 'hard_closed']);
        $this->screen($row)->assertOk()->assertInertia(fn (Assert $page) => $page->where('capabilities.post', false)->where('capabilities.cancel', true));
        $this->post($this->url($row, 'cancel'), ['revision' => 3, 'reason' => 'Period closed'])->assertRedirect();
    }

    public function test_inactive_finance_binding_still_uses_original_accounts(): void
    {
        $row = $this->ready($this->original());
        $this->cash->update(['status' => 'inactive']);
        $this->bank->update(['status' => 'inactive']);
        $this->screen($row)->assertOk()->assertInertia(fn (Assert $page) => $page->where('capabilities.post', true)->where('reversal.source.status', 'inactive'));
        $this->post($this->url($row, 'post'), ['revision' => 3, 'key' => 'screen-post-key-0001'])->assertRedirect();
        $this->proof($row->fresh());
    }

    public function test_inactive_original_gl_disables_posting(): void
    {
        $row = $this->ready($this->original());
        DB::table('accounts')->where('id', $this->cash->getAttribute('account_id'))->update(['status' => 'inactive']);
        $this->screen($row)->assertOk()->assertInertia(fn (Assert $page) => $page->where('capabilities.post', false));
    }

    public function test_posted_history_verifies_when_current_setup_is_disabled_and_period_closed(): void
    {
        $row = $this->reverse($this->ready($this->original()));
        $this->settings(['state' => 'disabled']);
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->update(['state' => 'hard_closed']);
        $before = $this->digest();
        $this->screen($row)->assertOk()->assertInertia(fn (Assert $page) => $page->where('finance.state', 'verified')->where('capabilities.post', false));
        $this->assertSame($before, $this->digest());
    }

    public function test_missing_operation_is_reviewed_and_never_repaired_by_page_or_retry(): void
    {
        $row = $this->reverse($this->ready($this->original()));
        DB::table('accounting_cash_transfer_reversal_operations')->where('reversal_request_id', $row->getKey())->delete();
        $before = $this->digest();
        $this->screen($row)->assertOk()->assertInertia(fn (Assert $page) => $page->where('finance.state', 'review')->where('preview', null)->where('capabilities.post', false));
        $this->post($this->url($row, 'post'), ['revision' => 3, 'key' => 'reversal-post-key-0001'])->assertSessionHasErrors();
        $this->assertSame($before, $this->digest());
    }

    public function test_missing_journal_leg_is_reviewed(): void
    {
        $row = $this->reverse($this->ready($this->original()));
        $proof = $this->proof($row);
        DB::table('journal_entry_lines')->where('journal_entry_id', $proof['journal']->getKey())->orderBy('id')->limit(1)->delete();
        $before = $this->digest();
        $this->screen($row)->assertOk()->assertInertia(fn (Assert $page) => $page->where('finance.state', 'review')->where('capabilities.post', false));
        $this->assertSame($before, $this->digest());
    }

    public function test_get_pages_are_read_only(): void
    {
        $original = $this->original();
        $before = $this->digest();
        $this->actingAs($this->actor)->get(self::BASE)->assertOk();
        $this->get(self::BASE.'/create?transfer_id='.$original->getKey())->assertOk();
        $this->assertSame($before, $this->digest());
        $row = $this->draft($original);
        $before = $this->digest();
        $this->screen($row)->assertOk();
        $this->get($this->url($row, 'edit'))->assertOk();
        $this->assertSame($before, $this->digest());
    }

    public function test_book_and_journal_links_have_independent_permissions(): void
    {
        $row = $this->reverse($this->ready($this->original()));
        $viewer = $this->staff(['erp.cash-transfer-reversals.view']);
        $this->screen($row, $viewer)->assertOk()->assertInertia(fn (Assert $page) => $page->where('bookLinks', [])->where('finance.journal_url', null)->where('original.url', null)->where('original.journal_url', null));
        $this->screen($row)->assertOk()->assertInertia(fn (Assert $page) => $page->has('bookLinks', 2)->where('finance.journal_url', fn ($url) => is_string($url) && str_starts_with($url, '/erp/accounting/journals/')));
    }

    public function test_book_source_link_requires_reversal_view_permission(): void
    {
        $row = $this->reverse($this->ready($this->original()));
        $viewer = $this->staff(['erp.cash-books.view']);
        $book = $this->book($this->cash, [], $viewer);
        $this->assertNull($book['rows'][1]['source_url']);
        $viewer->givePermissionTo('erp.cash-transfer-reversals.view');
        $book = $this->book($this->cash, [], $viewer);
        $this->assertSame($this->url($row), $book['rows'][1]['source_url']);
    }

    public function test_original_detail_shows_pending_then_posted_reversal_without_rewriting_original(): void
    {
        $original = $this->original();
        $url = '/erp/accounting/cash-transfers/'.$original->getKey();
        $this->actingAs($this->actor)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('reversalLinks.create_url', fn ($url) => is_string($url) && str_contains($url, '/create?')));
        $row = $this->ready($original);
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('reversalLinks.create_url', null)->has('reversalLinks.requests', 1)->where('reversalLinks.requests.0.status', 'approved'));
        $this->reverse($row);
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('transfer.status', 'posted')->where('finance.state', 'verified')->where('reversalLinks.requests.0.status', 'posted')->where('reversalLinks.create_url', null));
    }

    public function test_original_detail_withholds_links_without_reversal_view(): void
    {
        $original = $this->original();
        $this->draft($original);
        $viewer = $this->staff(['erp.cash-transfers.view']);
        $this->actingAs($viewer)->get('/erp/accounting/cash-transfers/'.$original->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->where('reversalLinks.create_url', null)->where('reversalLinks.register_url', null)->where('reversalLinks.requests', []));
    }

    public function test_original_detail_flags_broken_reversal_family(): void
    {
        $original = $this->original();
        $row = $this->reverse($this->ready($original));
        DB::table('accounting_cash_transfer_reversal_operations')->where('reversal_request_id', $row->getKey())->delete();
        $before = $this->digest();
        $this->actingAs($this->actor)->get('/erp/accounting/cash-transfers/'.$original->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->where('finance.state', 'review')->where('reversalLinks.create_url', null));
        $this->assertSame($before, $this->digest());
    }

    public function test_active_request_blocks_second_create_page_and_post(): void
    {
        $original = $this->original();
        $this->draft($original);
        $before = $this->digest();
        $this->actingAs($this->actor)->get(self::BASE.'/create?transfer_id='.$original->getKey())->assertSessionHasErrors();
        $this->post(self::BASE, ['transfer_id' => $original->getKey(), ...$this->requestData()])->assertSessionHasErrors();
        $this->assertSame($before, $this->digest());
    }

    public function test_cancelled_request_allows_a_fresh_create_page(): void
    {
        $original = $this->original();
        $row = $this->draft($original);
        $this->workflow()->cancel($this->actor, (int) $row->getKey(), 1, 'Withdraw');
        $this->actingAs($this->actor)->get(self::BASE.'/create?transfer_id='.$original->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->where('reversal', null));
    }

    public function test_register_filters_preserve_exact_amount_and_date(): void
    {
        $original = $this->original(['amount' => '0.01']);
        $row = $this->draft($original);
        $this->actingAs($this->actor)->get(self::BASE.'?company_id='.$this->company->getKey().'&transfer_id='.$original->getKey().'&status=draft&from=2026-10-16&to=2026-10-16')->assertOk()->assertInertia(fn (Assert $page) => $page->has('reversals.data', 1)->where('reversals.data.0.amount', '0.01'));
        $this->get(self::BASE.'?company_id='.$this->company->getKey().'&status=posted')->assertOk()->assertInertia(fn (Assert $page) => $page->has('reversals.data', 0));
    }

    public function test_invalid_filters_and_missing_original_are_validation_errors(): void
    {
        $this->actingAs($this->actor)->get(self::BASE.'?status=unsupported&from=2026-10-17&to=2026-10-16')->assertSessionHasErrors();
        $this->get(self::BASE.'/create')->assertSessionHasErrors('transfer_id');
    }

    public function test_audit_projection_is_bounded_and_does_not_serialize_payloads(): void
    {
        $row = $this->draft($this->original());
        $this->screen($row)->assertOk()->assertInertia(fn (Assert $page) => $page->has('history', 1)->missing('history.0.before_payload')->missing('history.0.after_payload')->where('historyTruncated', false)->where('history.0.action', 'created'));
    }

    public function test_foreign_request_cannot_be_edited_or_transitioned(): void
    {
        $row = $this->ready($this->original());
        $other = $this->newCompany('REV-COMMAND-SCOPE');
        $viewer = $this->staff(AccountingCashTransferReversalContract::PERMISSIONS);
        DB::table('employees')->where('user_id', $viewer->getKey())->update(['company_id' => $other->getKey()]);
        $before = $this->digest();
        $this->actingAs($viewer)->put($this->url($row), $this->editData($row))->assertNotFound();
        foreach (['submit', 'approve', 'reject', 'cancel', 'post'] as $action) {
            $data = ['revision' => 3];
            if (in_array($action, ['reject', 'cancel'], true)) {
                $data['reason'] = 'Foreign command';
            }
            if ($action === 'post') {
                $data['key'] = 'foreign-post-key-0001';
            }
            $this->post($this->url($row, $action), $data)->assertNotFound();
        }
        $this->assertSame($before, $this->digest());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingCashTransfer;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingMappingVersion;
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
use App\Services\ERP\Accounting\Source\AccountingSourceReconciliation;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AccountingCashTransferScreenTest extends TestCase
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
        app(AccountingCashAccountService::class)->initialize($this->actor, (int) $this->company->getKey(), 'Cash transfer screen fixture');
        $this->cash = AccountingCashAccount::query()->where('company_id', $this->company->getKey())->where('role', 'cash:MAIN')->firstOrFail();
        $this->bank = AccountingCashAccount::query()->where('company_id', $this->company->getKey())->where('role', 'bank:CURRENT')->firstOrFail();
        $calendar = app(AccountingCalendarService::class);
        $year = $calendar->saveYear($this->actor, ['company_id' => $this->company->getKey(), 'code' => 'FY26', 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'reason' => 'Cash transfer screen fixture']);
        $calendar->savePeriod($this->actor, (int) $year->getKey(), ['company_id' => $this->company->getKey(), 'code' => 'OCT', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'reason' => 'Cash transfer screen fixture']);
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

    private const BASE = '/erp/accounting/cash-transfers';

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
    private function draftTransfer(array $changes = []): AccountingCashTransfer
    {
        return $this->workflow()->save($this->actor, $this->transferInput($changes));
    }

    private function submitted(): AccountingCashTransfer
    {
        $row = $this->draftTransfer();

        return $this->workflow()->submit($this->actor, (int) $row->getKey(), 1);
    }

    private function approved(): AccountingCashTransfer
    {
        $row = $this->submitted();

        return $this->workflow()->approve($this->reviewer, (int) $row->getKey(), 2);
    }

    private function posted(): AccountingCashTransfer
    {
        $row = $this->approved();

        return app(AccountingCashTransferPosting::class)->post($this->actor, (int) $row->getKey(), 3, 'screen-transfer-post-key');
    }

    private function url(AccountingCashTransfer $row, string $action = ''): string
    {
        return self::BASE.'/'.$row->getKey().($action === '' ? '' : '/'.$action);
    }

    private function digest(): string
    {
        $parts = [];
        foreach (['accounting_cash_transfers', 'accounting_cash_transfer_audit_events', 'accounting_cash_transfer_sequences', 'accounting_cash_transfer_operations',
            'accounting_source_effects', 'journal_entries', 'journal_entry_lines', 'accounting_document_sequences', 'accounting_audit_events',
            'accounting_cash_accounts', 'accounting_company_settings', 'accounting_mapping_versions', 'accounting_account_mappings'] as $table) {
            $parts[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $changes */
    private function settings(array $changes): void
    {
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update($changes);
    }

    private function foreignBinding(): AccountingCashAccount
    {
        $other = $this->newCompany('TRANSFER-FOREIGN');
        app(AccountingSetupService::class)->initialize($this->actor, (int) $other->getKey());
        $mapping = $this->chart($other);

        return AccountingCashAccount::query()->create(['company_id' => $other->getKey(), 'role' => 'cash:MAIN', 'kind' => 'cash', 'name' => 'Foreign account', 'currency_code' => 'BDT', 'account_id' => $mapping['cash:MAIN'], 'status' => 'active', 'revision' => 1]);
    }

    public function test_register_requires_authentication(): void
    {
        $this->get(self::BASE)->assertRedirect('/login');
    }

    public function test_all_transfer_mutations_require_authentication(): void
    {
        $row = $this->draftTransfer();
        $this->post(self::BASE, $this->transferInput())->assertRedirect('/login');
        $this->put($this->url($row), [])->assertRedirect('/login');
        foreach (['submit', 'approve', 'reject', 'cancel', 'post'] as $action) {
            $this->post($this->url($row, $action), [])->assertRedirect('/login');
        }
    }

    public function test_register_requires_native_view_permission(): void
    {
        $this->actingAs($this->staff([]))->get(self::BASE)->assertForbidden();
    }

    public function test_view_is_required_even_when_actor_has_a_command_permission(): void
    {
        $user = $this->staff(['erp.cash-transfers.create']);
        $this->actingAs($user)->post(self::BASE, $this->transferInput())->assertForbidden();
    }

    public function test_register_and_create_components_exist_with_owned_choices(): void
    {
        $viewer = $this->staff(['erp.cash-transfers.view', 'erp.cash-transfers.create']);
        $row = $this->draftTransfer();
        $this->actingAs($viewer)->get(self::BASE)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('ERP/Accounting/CashTransfers/Index')->has('companies', 1)->where('company.id', (int) $this->company->getKey())
            ->has('transfers.data', 1)->where('transfers.data.0.id', (int) $row->getKey())->missing('transfers.data.0.notes')->missing('transfers.data.0.purpose')->missing('transfers.data.0.creation_key_hash'));
        $this->get(self::BASE.'/create')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('ERP/Accounting/CashTransfers/Form')->where('transfer', null)->has('bindings', 2)->where('canCreate', true));
    }

    public function test_own_staff_cannot_request_foreign_company_register(): void
    {
        $other = $this->newCompany('TRANSFER-SCOPE');
        $viewer = $this->staff(['erp.cash-transfers.view']);
        $this->actingAs($viewer)->get(self::BASE.'?company_id='.$other->getKey())->assertForbidden();
    }

    public function test_foreign_document_id_is_not_found_for_staff(): void
    {
        $row = $this->draftTransfer();
        $other = $this->newCompany('TRANSFER-STAFF');
        $viewer = $this->staff(['erp.cash-transfers.view']);
        DB::table('employees')->where('user_id', $viewer->getKey())->update(['company_id' => $other->getKey()]);
        $this->actingAs($viewer)->get($this->url($row))->assertNotFound();
    }

    public function test_detail_rejects_conflicting_company_query(): void
    {
        $row = $this->draftTransfer();
        $other = $this->newCompany('TRANSFER-QUERY');
        $this->actingAs($this->actor)->get($this->url($row).'?company_id='.$other->getKey())->assertForbidden();
    }

    public function test_draft_detail_and_edit_components_exist_without_internal_evidence(): void
    {
        $row = $this->draftTransfer();
        $this->actingAs($this->actor)->get($this->url($row))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('ERP/Accounting/CashTransfers/Show')->where('transfer.purpose', 'PRIVATE-TRANSFER-PURPOSE')->where('transfer.amount', '100.00')
            ->missing('transfer.source_snapshot')->missing('transfer.destination_snapshot')->missing('transfer.creation_request_hash')->missing('transfer.submitted_payload_hash')->missing('transfer.mapping_hash')
            ->where('finance.state', 'unposted')->where('capabilities.edit', true)->where('capabilities.submit', true)->where('capabilities.post', false));
        $this->get($this->url($row, 'edit'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/CashTransfers/Form')->where('transfer.id', (int) $row->getKey()));
    }

    public function test_view_only_actor_has_no_write_controls_and_cannot_edit_page(): void
    {
        $row = $this->draftTransfer();
        $viewer = $this->staff(['erp.cash-transfers.view']);
        $this->actingAs($viewer)->get($this->url($row))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canCreate', false)->where('capabilities.edit', false)->where('capabilities.submit', false)->where('capabilities.cancel', false));
        $this->get($this->url($row, 'edit'))->assertForbidden();
    }

    public function test_store_uses_native_service_and_replays_same_creation_request(): void
    {
        $data = $this->transferInput();
        $this->actingAs($this->actor)->post(self::BASE, $data)->assertRedirect();
        $row = AccountingCashTransfer::query()->firstOrFail();
        $before = $this->digest();
        $this->post(self::BASE, $data)->assertRedirect($this->url($row));
        $this->assertSame($before, $this->digest());
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_edit_updates_only_a_draft_at_current_revision(): void
    {
        $row = $this->draftTransfer();
        $data = $this->transferInput(['amount' => '75.25']);
        unset($data['key']);
        $this->actingAs($this->actor)->put($this->url($row), [...$data, 'revision' => 1])->assertRedirect($this->url($row));
        $this->assertSame('75.25', $row->fresh()->getAttribute('amount'));
        $this->assertSame(2, $row->fresh()->getAttribute('revision'));
    }

    public function test_unknown_native_fields_are_rejected_without_writes(): void
    {
        $before = $this->digest();
        $this->actingAs($this->actor)->post(self::BASE, $this->transferInput(['status' => 'posted']))->assertSessionHasErrors();
        $this->assertSame($before, $this->digest());
    }

    public function test_decimal_float_is_rejected_without_creating_a_draft(): void
    {
        $before = $this->digest();
        $this->actingAs($this->actor)->post(self::BASE, $this->transferInput(['amount' => 10.25]))->assertSessionHasErrors();
        $this->assertSame($before, $this->digest());
    }

    public function test_foreign_binding_filter_is_not_found(): void
    {
        $foreign = $this->foreignBinding();
        $viewer = $this->staff(['erp.cash-transfers.view']);
        $this->actingAs($viewer)->get(self::BASE.'?binding_id='.$foreign->getKey())->assertNotFound();
    }

    public function test_foreign_binding_cannot_be_injected_in_create(): void
    {
        $foreign = $this->foreignBinding();
        $before = $this->digest();
        $this->actingAs($this->actor)->post(self::BASE, $this->transferInput(['destination_cash_account_id' => (int) $foreign->getKey()]))->assertNotFound();
        $this->assertSame($before, $this->digest());
    }

    public function test_foreign_bindings_are_not_in_staff_form_choices(): void
    {
        $this->foreignBinding();
        $user = $this->staff(['erp.cash-transfers.view', 'erp.cash-transfers.create']);
        $this->actingAs($user)->get(self::BASE.'/create')->assertOk()->assertInertia(fn (Assert $page) => $page->has('companies', 1)->has('bindings', 2));
    }

    public function test_inactive_owned_account_is_visible_but_unselectable(): void
    {
        $this->bank->update(['status' => 'inactive']);
        $this->actingAs($this->actor)->get(self::BASE.'/create')->assertOk()->assertInertia(function (Assert $page): void {
            $page->component('ERP/Accounting/CashTransfers/Form')->where('bindings', fn ($rows): bool => collect($rows)->contains(fn ($row): bool => (int) $row['id'] === (int) $this->bank->getKey() && $row['selectable'] === false));
        });
    }

    public function test_disabled_accounting_allows_preparation_but_hides_post_control(): void
    {
        $this->settings(['state' => 'disabled']);
        $row = $this->approved();
        $this->actingAs($this->actor)->get($this->url($row))->assertOk()->assertInertia(fn (Assert $page) => $page->where('postingEnabled', false)->where('capabilities.post', false));
        $this->get(self::BASE.'/create')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canCreate', true));
    }

    public function test_cutover_lock_keeps_detail_readable_and_disables_commands(): void
    {
        $row = $this->draftTransfer();
        $this->settings(['cutover_locked_at' => now()]);
        $this->actingAs($this->actor)->get($this->url($row))->assertOk()->assertInertia(fn (Assert $page) => $page->where('writable', false)->where('capabilities.edit', false)->where('capabilities.submit', false)->where('capabilities.cancel', false));
        $this->get($this->url($row, 'edit'))->assertForbidden();
    }

    public function test_inactive_company_register_is_read_only(): void
    {
        $this->company->update(['status' => 'inactive']);
        $this->actingAs($this->actor)->get(self::BASE)->assertOk()->assertInertia(fn (Assert $page) => $page->where('writable', false)->where('canCreate', false));
    }

    public function test_submit_and_independent_approval_routes_preserve_native_workflow(): void
    {
        $row = $this->draftTransfer();
        $this->actingAs($this->actor)->post($this->url($row, 'submit'), ['revision' => 1])->assertRedirect($this->url($row));
        $this->actingAs($this->reviewer)->get($this->url($row))->assertOk()->assertInertia(fn (Assert $page) => $page->where('capabilities.approve', true));
        $this->post($this->url($row, 'approve'), ['revision' => 2])->assertRedirect($this->url($row));
        $this->assertSame('approved', $row->fresh()->getAttribute('status'));
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_maker_cannot_approve_and_does_not_see_approve_control(): void
    {
        $row = $this->submitted();
        $before = $this->digest();
        $this->actingAs($this->actor)->get($this->url($row))->assertOk()->assertInertia(fn (Assert $page) => $page->where('capabilities.approve', false));
        $this->post($this->url($row, 'approve'), ['revision' => 2])->assertSessionHasErrors();
        $this->assertSame($before, $this->digest());
    }

    public function test_submitter_distinct_from_maker_cannot_approve(): void
    {
        $row = $this->draftTransfer();
        $user = $this->staff(['erp.cash-transfers.view', 'erp.cash-transfers.submit', 'erp.cash-transfers.approve']);
        $this->workflow()->submit($user, (int) $row->getKey(), 1);
        $this->actingAs($user)->get($this->url($row))->assertOk()->assertInertia(fn (Assert $page) => $page->where('capabilities.approve', false));
        $this->post($this->url($row, 'approve'), ['revision' => 2])->assertSessionHasErrors();
    }

    public function test_return_for_revision_requires_reason_and_clears_review(): void
    {
        $row = $this->submitted();
        $this->actingAs($this->reviewer)->post($this->url($row, 'reject'), ['revision' => 2, 'reason' => ''])->assertSessionHasErrors('reason');
        $this->post($this->url($row, 'reject'), ['revision' => 2, 'reason' => 'Review the destination'])->assertRedirect($this->url($row));
        $this->assertSame('draft', $row->fresh()->getAttribute('status'));
        $this->assertNull($row->fresh()->getAttribute('submitted_payload_hash'));
    }

    public function test_cancel_requires_reason_and_makes_transfer_read_only(): void
    {
        $row = $this->draftTransfer();
        $this->actingAs($this->actor)->post($this->url($row, 'cancel'), ['revision' => 1, 'reason' => 'Not required'])->assertRedirect($this->url($row));
        $this->get($this->url($row))->assertOk()->assertInertia(fn (Assert $page) => $page->where('transfer.status', 'cancelled')->where('capabilities.edit', false)->where('capabilities.post', false));
    }

    public function test_every_command_checks_its_own_permission(): void
    {
        $row = $this->draftTransfer();
        $viewer = $this->staff(['erp.cash-transfers.view']);
        $before = $this->digest();
        $this->actingAs($viewer);
        foreach (['submit', 'approve', 'reject', 'cancel', 'post'] as $action) {
            $this->post($this->url($row, $action), ['revision' => 1, 'reason' => 'Reason', 'key' => 'screen-transfer-key-001'])->assertForbidden();
        }
        $this->assertSame($before, $this->digest());
    }

    public function test_stale_revision_and_unexpected_action_fields_do_not_write(): void
    {
        $row = $this->draftTransfer();
        $before = $this->digest();
        $this->actingAs($this->actor)->post($this->url($row, 'submit'), ['revision' => 99])->assertSessionHasErrors();
        $this->post($this->url($row, 'submit'), ['revision' => 1, 'amount' => '999.00'])->assertSessionHasErrors('transfer');
        $this->assertSame($before, $this->digest());
    }

    public function test_post_route_records_once_and_identical_request_replays(): void
    {
        $row = $this->approved();
        $data = ['revision' => 3, 'key' => 'screen-transfer-post-key'];
        $this->actingAs($this->actor)->post($this->url($row, 'post'), $data)->assertRedirect($this->url($row));
        $before = $this->digest();
        $this->post($this->url($row, 'post'), $data)->assertRedirect($this->url($row));
        $this->assertSame($before, $this->digest());
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('journal_entry_lines', 2);
        $this->get($this->url($row))->assertOk()->assertInertia(fn (Assert $page) => $page->where('finance.state', 'verified')->where('capabilities.post', false)->where('capabilities.cancel', false));
    }

    public function test_post_request_conflict_cannot_create_second_transfer_journal(): void
    {
        $row = $this->posted();
        $before = $this->digest();
        $this->actingAs($this->actor)->post($this->url($row, 'post'), ['revision' => 3, 'key' => 'different-screen-post-key'])->assertSessionHasErrors();
        $this->assertSame($before, $this->digest());
    }

    public function test_post_replay_works_after_setup_is_disabled_and_period_closed(): void
    {
        $row = $this->posted();
        $this->settings(['state' => 'disabled']);
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->update(['state' => 'closed']);
        $before = $this->digest();
        $this->actingAs($this->actor)->post($this->url($row, 'post'), ['revision' => 3, 'key' => 'screen-transfer-post-key'])->assertRedirect($this->url($row));
        $this->assertSame($before, $this->digest());
    }

    public function test_missing_operation_is_reviewed_and_http_retry_cannot_repair_it(): void
    {
        $row = $this->posted();
        DB::table('accounting_cash_transfer_operations')->delete();
        $before = $this->digest();
        $this->actingAs($this->actor)->get($this->url($row))->assertOk()->assertInertia(fn (Assert $page) => $page->where('finance.state', 'review')->where('capabilities.post', false)->where('finance.journal_url', null));
        $this->post($this->url($row, 'post'), ['revision' => 3, 'key' => 'screen-transfer-post-key'])->assertSessionHasErrors();
        $this->assertSame($before, $this->digest());
    }

    public function test_closed_period_hides_post_control_and_rejects_direct_request(): void
    {
        $row = $this->approved();
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->update(['state' => 'closed']);
        $before = $this->digest();
        $this->actingAs($this->actor)->get($this->url($row))->assertOk()->assertInertia(fn (Assert $page) => $page->where('capabilities.post', false));
        $this->post($this->url($row, 'post'), ['revision' => 3, 'key' => 'screen-transfer-post-key'])->assertSessionHasErrors();
        $this->assertSame($before, $this->digest());
    }

    public function test_post_only_staff_needs_no_manual_journal_authority(): void
    {
        $row = $this->approved();
        $user = $this->staff(['erp.cash-transfers.view', 'erp.cash-transfers.post']);
        $this->actingAs($user)->get($this->url($row))->assertOk()->assertInertia(fn (Assert $page) => $page->where('capabilities.post', true));
        $this->post($this->url($row, 'post'), ['revision' => 3, 'key' => 'screen-staff-transfer-key'])->assertRedirect($this->url($row));
        $this->get($this->url($row))->assertOk()->assertInertia(fn (Assert $page) => $page->where('finance.state', 'verified')->where('finance.journal_url', null)->has('bookLinks', 0));
    }

    public function test_register_filters_status_date_and_either_endpoint(): void
    {
        $a = $this->draftTransfer();
        $b = $this->submitted();
        $this->actingAs($this->actor)->get(self::BASE.'?status=submitted&binding_id='.$this->bank->getKey().'&from=2026-10-16&to=2026-10-16')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('transfers.data', 1)->where('transfers.data.0.id', (int) $b->getKey())->where('transfers.total', 1));
        $this->get(self::BASE.'?q='.rawurlencode((string) $a->getAttribute('number')))->assertOk()->assertInertia(fn (Assert $page) => $page->has('transfers.data', 1)->where('transfers.data.0.id', (int) $a->getKey()));
    }

    public function test_invalid_date_range_is_rejected(): void
    {
        $this->actingAs($this->actor)->get(self::BASE.'?from=2026-10-17&to=2026-10-16')->assertSessionHasErrors('to');
    }

    public function test_index_detail_and_edit_reads_do_not_write_or_post(): void
    {
        $row = $this->draftTransfer();
        $before = $this->digest();
        $this->actingAs($this->actor)->get(self::BASE)->assertOk();
        $this->get($this->url($row))->assertOk();
        $this->get($this->url($row, 'edit'))->assertOk();
        $this->assertSame($before, $this->digest());
    }

    public function test_posted_detail_read_preserves_complete_financial_batch(): void
    {
        $row = $this->posted();
        $before = $this->digest();
        $this->actingAs($this->actor)->get($this->url($row))->assertOk()->assertInertia(fn (Assert $page) => $page->where('finance.state', 'verified')->has('bookLinks', 2));
        $this->assertSame($before, $this->digest());
    }

    public function test_history_is_whitelisted_without_internal_snapshots(): void
    {
        $row = $this->approved();
        $this->actingAs($this->actor)->get($this->url($row))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('history', 3)->where('history.0.action', 'approved')->missing('history.0.after_payload')->missing('history.0.before_payload')->missing('transfer.posting_key_hash')->missing('transfer.approved_payload_hash'));
    }

    public function test_book_source_links_require_transfer_view_permission(): void
    {
        $row = $this->posted();
        $reader = app(AccountingCashBookReader::class);
        $viewer = $this->staff(['erp.cash-books.view']);
        $hidden = $reader->book($viewer, (int) $this->company->getKey(), (int) $this->bank->getKey());
        $this->assertNull($hidden['rows'][0]['source_url']);
        $viewer->givePermissionTo('erp.cash-transfers.view');
        $visible = $reader->book($viewer, (int) $this->company->getKey(), (int) $this->bank->getKey());
        $this->assertSame($this->url($row), $visible['rows'][0]['source_url']);
    }

    public function test_reconciliation_links_use_the_new_transfer_route_when_authorized(): void
    {
        $row = $this->posted();
        $page = app(AccountingSourceReconciliation::class)->page($this->actor, (int) $this->company->getKey(), ['kind' => 'cash_transfer']);
        $this->assertSame($this->url($row).'?company_id='.$this->company->getKey(), $page['rows'][0]['source_url']);
        $this->actingAs($this->actor)->get($page['rows'][0]['source_url'])->assertOk();
    }

    public function test_new_routes_are_registered_once_with_native_permissions(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'erp.accounting.cash-transfers.'));
        $this->assertCount(11, $routes);
        foreach ($routes as $route) {
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('can:erp.cash-transfers.view', $route->gatherMiddleware());
        }
        $this->assertContains('can:erp.cash-transfers.post', Route::getRoutes()->getByName('erp.accounting.cash-transfers.post')->gatherMiddleware());
    }

    public function test_last_editor_does_not_see_or_use_approval_control(): void
    {
        $row = $this->draftTransfer();
        $data = $this->transferInput();
        unset($data['key']);
        $row = $this->workflow()->save($this->reviewer, [...$data, 'revision' => 1], (int) $row->getKey());
        $row = $this->workflow()->submit($this->actor, (int) $row->getKey(), 2);
        $this->actingAs($this->reviewer)->get($this->url($row))->assertOk()->assertInertia(fn (Assert $page) => $page->where('capabilities.approve', false));
        $this->post($this->url($row, 'approve'), ['revision' => 3])->assertSessionHasErrors();
    }

    public function test_company_without_accounting_setup_can_be_viewed_but_not_created_in(): void
    {
        $other = $this->newCompany('TRANSFER-NO-SETUP');
        $this->actingAs($this->actor)->get(self::BASE.'?company_id='.$other->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->where('writable', false)->where('canCreate', false));
        $this->get(self::BASE.'/create?company_id='.$other->getKey())->assertForbidden();
    }

    public function test_register_pagination_has_stable_order_and_preserves_full_count(): void
    {
        for ($i = 0; $i < 26; $i++) {
            $this->draftTransfer();
        }
        $this->actingAs($this->actor)->get(self::BASE.'?page=1')->assertOk()->assertInertia(fn (Assert $page) => $page->has('transfers.data', 25)->where('transfers.total', 26)->where('transfers.current_page', 1)->where('transfers.data.0.number', 'CT-2026-000026'));
        $this->get(self::BASE.'?page=2')->assertOk()->assertInertia(fn (Assert $page) => $page->has('transfers.data', 1)->where('transfers.total', 26)->where('transfers.data.0.number', 'CT-2026-000001'));
    }
}

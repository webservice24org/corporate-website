<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingSupplierPaymentOperation;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\SupplierPaymentAuditLog;
use App\Models\SupplierPaymentTarget;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingAccountService;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\Source\AccountingSourceEventRegistry;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentContract;
use App\Services\ERP\Accounting\Source\Purchase\SupplierPaymentNumberService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\AccountingSupplierPaymentPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AccountingSupplierPaymentFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Supplier $supplier;

    private AccountingCashAccount $cash;

    private SupplierBill $bill;

    private int $payable;

    private User $maker;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingPermissionSeeder::class);
        $this->seed(AccountingSupplierPaymentPermissionSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00:00', 'Asia/Dhaka'));
        $this->maker = User::factory()->create();
        $this->maker->assignRole('super-admin');
        $this->reviewer = User::factory()->create();
        $this->company = $this->company('SP-A');
        $this->supplier = $this->supplier($this->company);
        $this->cash = $this->cash($this->company);
        $pair = $this->bill($this->company, $this->supplier);
        $this->bill = $pair[0];
        $this->payable = $pair[1];
    }

    private function company(string $code): Company
    {
        return Company::query()->create(['name' => $code, 'code' => $code, 'status' => 'active', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka']);
    }

    private function supplier(Company $company): Supplier
    {
        return Supplier::query()->create(['company_id' => $company->getKey(), 'code' => 'SP-SUP-'.(Supplier::query()->count() + 1), 'name' => 'Payment fixture supplier', 'currency_code' => 'BDT', 'status' => 'active']);
    }

    private function cash(Company $company): AccountingCashAccount
    {
        app(AccountingSetupService::class)->initialize($this->maker, (int) $company->getKey());
        app(AccountingAccountService::class)->initializeGroups($this->maker, (int) $company->getKey());
        $group = AccountGroup::query()->where('company_id', $company->getKey())->where('category', 'asset')->firstOrFail();
        $account = Account::query()->create(['company_id' => $company->getKey(), 'account_group_id' => $group->getKey(), 'code' => 'SP-CASH', 'name' => 'Fixture cash', 'normal_side' => 'debit', 'is_postable' => true, 'statement_role' => 'current_asset', 'cash_flow_role' => 'cash', 'status' => 'active', 'revision' => 1]);

        return AccountingCashAccount::query()->create(['company_id' => $company->getKey(), 'account_id' => $account->getKey(), 'role' => 'cash:MAIN', 'name' => 'Fixture cash', 'kind' => 'cash', 'currency_code' => 'BDT', 'status' => 'active', 'revision' => 1]);
    }

    /** @return array{0: SupplierBill, 1: int} */
    private function bill(Company $company, Supplier $supplier): array
    {
        $n = SupplierBill::query()->count() + 1;
        // Storage-only fixture, not recognized AP or an application cutover procedure.
        $bill = SupplierBill::query()->create(['company_id' => $company->getKey(), 'supplier_id' => $supplier->getKey(), 'number' => 'SP-BILL-'.$n, 'supplier_invoice_number' => 'SP-VENDOR-'.$n, 'status' => 'approved', 'invoice_date' => '2026-10-25', 'due_date' => '2026-10-31', 'subtotal' => '100.00', 'grand_total' => '100.00', 'currency_code' => 'BDT', 'revision' => 1, 'created_by' => $this->maker->getKey(), 'approved_by' => $this->reviewer->getKey(), 'approved_at' => now()]);
        $payable = DB::table('supplier_payables')->insertGetId(['company_id' => $company->getKey(), 'supplier_id' => $supplier->getKey(), 'supplier_bill_id' => $bill->getKey(), 'currency_code' => 'BDT', 'amount' => '100.00', 'paid_amount' => '0.00', 'credited_amount' => '0.00', 'due_date' => '2026-10-31', 'created_by' => $this->maker->getKey(), 'created_at' => now()]);

        return [$bill, (int) $payable];
    }

    /** @param array<string, mixed> $overrides */
    private function payment(array $overrides = []): SupplierPayment
    {
        return DB::transaction(function () use ($overrides): SupplierPayment {
            $company = (int) ($overrides['company_id'] ?? $this->company->getKey());
            $date = (string) ($overrides['document_date'] ?? '2026-10-25');

            return SupplierPayment::query()->create(['company_id' => $company, 'supplier_id' => $this->supplier->getKey(), 'cash_account_id' => $this->cash->getKey(), 'number' => app(SupplierPaymentNumberService::class)->next($company, $date), 'document_date' => $date, 'currency_code' => 'BDT', 'payment_method' => 'cash', 'amount' => '100.00', 'advance_amount' => '0.00', 'created_by' => $this->maker->getKey(), 'updated_by' => $this->maker->getKey(), 'last_edited_by' => $this->maker->getKey(), ...$overrides]);
        });
    }

    /** Native storage fixture only. It creates no recognized financial effect.
     * @param  array<string, mixed>  $overrides
     */
    private function posted(array $overrides = []): SupplierPayment
    {
        return $this->payment(['status' => 'posted', 'submitted_by' => $this->maker->getKey(), 'submitted_at' => now(), 'submitted_payload_hash' => str_repeat('a', 64), 'approved_by' => $this->reviewer->getKey(), 'approved_at' => now(), 'approved_payload_hash' => str_repeat('a', 64), 'posted_by' => $this->maker->getKey(), 'posted_at' => now(), 'payload_hash' => str_repeat('b', 64), 'idempotency_key' => (string) Str::uuid(), ...$overrides]);
    }

    /** @param array<string, mixed> $overrides */
    private function target(SupplierPayment $payment, array $overrides = []): SupplierPaymentTarget
    {
        return SupplierPaymentTarget::query()->create(['company_id' => $payment->getAttribute('company_id'), 'supplier_payment_id' => $payment->getKey(), 'supplier_bill_id' => $this->bill->getKey(), 'supplier_payable_id' => $this->payable, 'amount' => '20.00', ...$overrides]);
    }

    /** @param array<string, mixed> $overrides */
    private function allocation(SupplierPayment $payment, array $overrides = []): SupplierPaymentAllocation
    {
        return SupplierPaymentAllocation::query()->create(['company_id' => $payment->getAttribute('company_id'), 'supplier_payment_id' => $payment->getKey(), 'supplier_bill_id' => $this->bill->getKey(), 'supplier_payable_id' => $this->payable, 'amount' => '20.00', 'posted_by' => $this->maker->getKey(), 'posted_at' => now(), 'payload_hash' => str_repeat('b', 64), 'idempotency_key' => (string) Str::uuid(), ...$overrides]);
    }

    /** @param array<string, mixed> $overrides */
    private function operation(SupplierPayment $payment, array $overrides = []): AccountingSupplierPaymentOperation
    {
        $body = ['company_id' => (int) $payment->getAttribute('company_id'), 'supplier_payment_id' => (int) $payment->getKey(), 'kind' => 'post', 'contract_version' => AccountingSupplierPaymentContract::VERSION, 'key_hash' => hash('sha256', (string) $payment->getAttribute('idempotency_key')), 'request_hash' => (string) $payment->getAttribute('payload_hash'), 'members' => [['source_kind' => 'supplier_payment', 'source_id' => (int) $payment->getKey(), 'event_kind' => 'posted']]];
        foreach (['company_id', 'supplier_payment_id', 'kind', 'contract_version', 'key_hash', 'request_hash', 'members'] as $key) {
            if (array_key_exists($key, $overrides)) {
                $body[$key] = $overrides[$key];
            }
        }

        return AccountingSupplierPaymentOperation::query()->create([...$body, 'payload_hash' => app(AccountingCanonicalPayload::class)->hash($body), 'created_by' => $this->maker->getKey(), ...$overrides]);
    }

    private function invalid(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected rejected storage input.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    private function immutable(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected immutable storage rejection.');
        } catch (\LogicException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    private function constraint(callable $action, bool $unique = false): void
    {
        try {
            DB::transaction($action);
            $this->fail('Expected database ownership/uniqueness rejection.');
        } catch (QueryException $e) {
            $this->assertMatchesRegularExpression($unique ? '/unique|duplicate/i' : '/foreign key/i', $e->getMessage());
        }
    }

    public function test_all_five_tables_and_workflow_columns_exist(): void
    {
        foreach (app(AccountingSupplierPaymentContract::class)->tables() as $table) {
            $this->assertTrue(Schema::hasTable($table));
        } foreach (['last_edited_by', 'submitted_payload_hash', 'approved_payload_hash', 'payload_hash', 'reverses_payment_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('supplier_payments', $column));
        }
    }

    public function test_draft_header_and_target_round_trip_exact_decimals(): void
    {
        $p = $this->payment(['amount' => '20.1']);
        $t = $this->target($p, ['amount' => '0.01']);
        $this->assertSame('20.10', $p->refresh()->getAttribute('amount'));
        $this->assertSame('0.01', $t->refresh()->getAttribute('amount'));
        $this->assertSame((int) $p->getKey(), (int) $t->payment()->firstOrFail()->getKey());
    }

    public function test_zero_negative_and_float_payments_are_rejected(): void
    {
        foreach (['0.00', '-1.00', 1.5, '1.001'] as $amount) {
            $this->invalid(fn () => $this->payment(['amount' => $amount]));
        }
    }

    public function test_advance_cannot_exceed_payment(): void
    {
        $this->invalid(fn () => $this->payment(['advance_amount' => '100.01']));
    }

    public function test_foreign_company_supplier_is_rejected_by_model(): void
    {
        $other = $this->company('SP-B');
        $supplier = $this->supplier($other);
        $this->invalid(fn () => $this->payment(['supplier_id' => $supplier->getKey()]));
    }

    public function test_foreign_cash_binding_is_rejected_by_model(): void
    {
        $other = $this->company('SP-B');
        $cash = $this->cash($other);
        $this->invalid(fn () => $this->payment(['cash_account_id' => $cash->getKey()]));
    }

    public function test_cash_method_must_match_finance_binding_kind(): void
    {
        $this->invalid(fn () => $this->payment(['payment_method' => 'bank_transfer']));
    }

    public function test_foreign_currency_payment_is_rejected(): void
    {
        $this->invalid(fn () => $this->payment(['currency_code' => 'USD']));
    }

    public function test_numbered_identity_cannot_be_reassigned(): void
    {
        $p = $this->payment();
        $this->immutable(fn () => $p->update(['document_date' => '2026-10-26']));
        $this->assertSame('2026-10-25', $p->fresh()->getAttribute('document_date')->format('Y-m-d'));
    }

    public function test_submitted_intent_is_frozen(): void
    {
        $p = $this->payment(['status' => 'submitted', 'submitted_by' => $this->maker->getKey(), 'submitted_at' => now(), 'submitted_payload_hash' => str_repeat('a', 64)]);
        $this->immutable(fn () => $p->update(['amount' => '99.00']));
    }

    public function test_submitted_review_hash_cannot_be_rewritten(): void
    {
        $p = $this->payment(['status' => 'submitted', 'submitted_by' => $this->maker->getKey(), 'submitted_at' => now(), 'submitted_payload_hash' => str_repeat('a', 64)]);
        $this->immutable(fn () => $p->update(['submitted_payload_hash' => str_repeat('c', 64)]));
    }

    public function test_approved_review_actor_cannot_be_rewritten(): void
    {
        $p = $this->payment(['status' => 'approved', 'submitted_by' => $this->maker->getKey(), 'submitted_at' => now(), 'submitted_payload_hash' => str_repeat('a', 64), 'approved_by' => $this->reviewer->getKey(), 'approved_at' => now(), 'approved_payload_hash' => str_repeat('a', 64)]);
        $this->immutable(fn () => $p->update(['approved_by' => $this->maker->getKey()]));
    }

    public function test_stale_draft_cannot_overwrite_submitted_header(): void
    {
        $p = $this->payment();
        $stale = $p->fresh();
        $p->update(['status' => 'submitted', 'revision' => 2, 'submitted_by' => $this->maker->getKey(), 'submitted_at' => now(), 'submitted_payload_hash' => str_repeat('a', 64)]);
        $this->immutable(fn () => $stale->update(['notes' => 'Stale draft attempt']));
    }

    public function test_approval_must_be_independent_of_maker_and_last_editor(): void
    {
        $this->invalid(fn () => $this->posted(['approved_by' => $this->maker->getKey()]));
        $this->invalid(fn () => $this->posted(['last_edited_by' => $this->reviewer->getKey()]));
    }

    public function test_approval_hash_must_match_submitted_intent(): void
    {
        $this->invalid(fn () => $this->posted(['approved_payload_hash' => str_repeat('c', 64)]));
    }

    public function test_posted_header_requires_actor_time_and_key(): void
    {
        $this->invalid(fn () => $this->posted(['posted_at' => null]));
        $this->invalid(fn () => $this->posted(['idempotency_key' => null]));
    }

    public function test_posted_header_immutability_precedes_decimal_validation(): void
    {
        $p = $this->posted();
        $this->immutable(fn () => $p->update(['amount' => 1.5]));
        $this->assertSame('100.00', $p->fresh()->getAttribute('amount'));
    }

    public function test_posted_and_draft_headers_cannot_be_deleted(): void
    {
        $draft = $this->payment();
        $posted = $this->posted();
        $this->immutable(fn () => $draft->delete());
        $this->immutable(fn () => $posted->delete());
    }

    public function test_cancelled_header_is_immutable(): void
    {
        $p = $this->payment(['status' => 'cancelled', 'cancelled_by' => $this->maker->getKey(), 'cancelled_at' => now(), 'reason' => 'Unposted fixture cancellation']);
        $this->immutable(fn () => $p->update(['status' => 'draft']));
    }

    public function test_draft_target_can_be_edited_and_removed(): void
    {
        $p = $this->payment();
        $t = $this->target($p);
        $t->update(['amount' => '21.00']);
        $this->assertSame('21.00', $t->refresh()->getAttribute('amount'));
        $t->delete();
        $this->assertDatabaseCount('supplier_payment_targets', 0);
    }

    public function test_target_changes_are_blocked_after_submission(): void
    {
        $p = $this->payment();
        $t = $this->target($p);
        $p->update(['status' => 'submitted', 'revision' => 2, 'submitted_by' => $this->maker->getKey(), 'submitted_at' => now(), 'submitted_payload_hash' => str_repeat('a', 64)]);
        $this->immutable(fn () => $t->update(['amount' => '21.00']));
        $this->immutable(fn () => $t->delete());
    }

    public function test_target_bill_and_payable_must_share_supplier(): void
    {
        $other = $this->supplier($this->company);
        $pair = $this->bill($this->company, $other);
        $p = $this->payment();
        $this->invalid(fn () => $this->target($p, ['supplier_bill_id' => $pair[0]->getKey(), 'supplier_payable_id' => $pair[1]]));
    }

    public function test_target_amount_must_be_positive_exact_money(): void
    {
        $p = $this->payment();
        foreach (['0.00', '-1.00', 1.5] as $amount) {
            $this->invalid(fn () => $this->target($p, ['amount' => $amount]));
        }
    }

    public function test_database_rejects_duplicate_target_bill(): void
    {
        $p = $this->payment();
        $t = $this->target($p);
        $data = $t->getAttributes();
        unset($data['id']);
        $this->constraint(fn () => DB::table('supplier_payment_targets')->insert($data), true);
    }

    public function test_database_rejects_foreign_cash_even_without_model_hooks(): void
    {
        $p = $this->payment();
        $other = $this->company('SP-B');
        $cash = $this->cash($other);
        $data = $p->getAttributes();
        unset($data['id']);
        $data['number'] = 'SP-FOREIGN-CASH';
        $data['cash_account_id'] = $cash->getKey();
        $this->constraint(fn () => DB::table('supplier_payments')->insert($data));
    }

    public function test_database_requires_payable_to_belong_to_exact_bill(): void
    {
        $p = $this->payment();
        $pair = $this->bill($this->company, $this->supplier);
        $this->constraint(fn () => DB::table('supplier_payment_targets')->insert(['company_id' => $this->company->getKey(), 'supplier_payment_id' => $p->getKey(), 'supplier_bill_id' => $this->bill->getKey(), 'supplier_payable_id' => $pair[1], 'amount' => '20.00']));
    }

    public function test_database_rejects_target_parent_from_another_company(): void
    {
        $other = $this->company('SP-B');
        $supplier = $this->supplier($other);
        $cash = $this->cash($other);
        $p = $this->payment(['company_id' => $other->getKey(), 'supplier_id' => $supplier->getKey(), 'cash_account_id' => $cash->getKey()]);
        $this->constraint(fn () => DB::table('supplier_payment_targets')->insert(['company_id' => $this->company->getKey(), 'supplier_payment_id' => $p->getKey(), 'supplier_bill_id' => $this->bill->getKey(), 'supplier_payable_id' => $this->payable, 'amount' => '20.00']));
    }

    public function test_allocation_requires_posted_original_payment(): void
    {
        $this->invalid(fn () => $this->allocation($this->payment()));
    }

    public function test_allocation_and_compensation_are_append_only(): void
    {
        $p = $this->posted();
        $a = $this->allocation($p);
        $undo = $this->allocation($p, ['reverses_allocation_id' => $a->getKey(), 'reason' => 'Exact storage compensation']);
        $this->immutable(fn () => $a->update(['amount' => '19.00']));
        $this->immutable(fn () => $undo->delete());
    }

    public function test_compensation_must_match_original_amount(): void
    {
        $p = $this->posted();
        $a = $this->allocation($p);
        $this->invalid(fn () => $this->allocation($p, ['reverses_allocation_id' => $a->getKey(), 'amount' => '19.00', 'reason' => 'Wrong amount']));
    }

    public function test_compensation_cannot_reverse_compensation(): void
    {
        $p = $this->posted();
        $a = $this->allocation($p);
        $undo = $this->allocation($p, ['reverses_allocation_id' => $a->getKey(), 'reason' => 'Exact compensation']);
        $this->invalid(fn () => $this->allocation($p, ['reverses_allocation_id' => $undo->getKey(), 'reason' => 'Compensation of compensation']));
    }

    public function test_database_enforces_exact_compensation_amount_without_hooks(): void
    {
        $p = $this->posted();
        $a = $this->allocation($p);
        $data = $a->getAttributes();
        unset($data['id']);
        $data['idempotency_key'] = (string) Str::uuid();
        $data['reverses_allocation_id'] = $a->getKey();
        $data['amount'] = '21.00';
        $this->constraint(fn () => DB::table('supplier_payment_allocations')->insert($data));
    }

    public function test_database_allows_only_one_compensation(): void
    {
        $p = $this->posted();
        $a = $this->allocation($p);
        $undo = $this->allocation($p, ['reverses_allocation_id' => $a->getKey(), 'reason' => 'Exact compensation']);
        $data = $undo->getAttributes();
        unset($data['id']);
        $data['idempotency_key'] = (string) Str::uuid();
        $this->constraint(fn () => DB::table('supplier_payment_allocations')->insert($data), true);
    }

    public function test_allocation_cannot_predate_native_cash_posting(): void
    {
        $p = $this->posted();
        $this->invalid(fn () => $this->allocation($p, ['posted_at' => now()->subSecond()]));
    }

    public function test_reversal_must_keep_original_cash_identity(): void
    {
        $p = $this->posted();
        $this->invalid(fn () => $this->posted(['reverses_payment_id' => $p->getKey(), 'amount' => '99.00', 'reason' => 'Wrong original amount']));
    }

    public function test_database_allows_only_one_cash_reversal(): void
    {
        $p = $this->posted();
        $r = $this->posted(['reverses_payment_id' => $p->getKey(), 'reason' => 'Storage reversal fixture']);
        $data = $r->getAttributes();
        unset($data['id']);
        $data['number'] = 'SP-DUPLICATE-REVERSAL';
        $data['idempotency_key'] = (string) Str::uuid();
        $this->constraint(fn () => DB::table('supplier_payments')->insert($data), true);
    }

    public function test_audit_log_is_append_only_and_has_no_updated_at_column(): void
    {
        $p = $this->payment();
        $log = SupplierPaymentAuditLog::query()->create(['company_id' => $this->company->getKey(), 'supplier_payment_id' => $p->getKey(), 'actor_id' => $this->maker->getKey(), 'action' => 'created', 'new_values' => ['revision' => 1], 'created_at' => now()]);
        $this->immutable(fn () => $log->delete());
        $this->immutable(fn () => $log->update(['reason' => 'Changed history']));
        $this->assertFalse(Schema::hasColumn('supplier_payment_audit_logs', 'updated_at'));
    }

    public function test_operation_envelope_round_trips_and_is_append_only(): void
    {
        $op = $this->operation($this->posted(['advance_amount' => '100.00']));
        $this->assertCount(1, $op->refresh()->getAttribute('members'));
        $this->immutable(fn () => $op->update(['kind' => 'change']));
        $this->immutable(fn () => $op->delete());
    }

    public function test_operation_rejects_corrupt_hash_and_wrong_original_key(): void
    {
        $p = $this->posted();
        $this->invalid(fn () => $this->operation($p, ['payload_hash' => str_repeat('0', 64)]));
        $this->invalid(fn () => $this->operation($p, ['key_hash' => str_repeat('1', 64)]));
    }

    public function test_operation_rejects_foreign_or_duplicate_native_member(): void
    {
        $p = $this->posted();
        $other = $this->posted();
        $this->invalid(fn () => $this->operation($p, ['members' => [['source_kind' => 'supplier_payment', 'source_id' => (int) $other->getKey(), 'event_kind' => 'posted']]]));
        $member = ['source_kind' => 'supplier_payment', 'source_id' => (int) $p->getKey(), 'event_kind' => 'posted'];
        $this->invalid(fn () => $this->operation($p, ['members' => [$member, $member]]));
    }

    public function test_numbering_uses_document_year_and_company_scope(): void
    {
        $other = $this->company('SP-B');
        $numbers = app(SupplierPaymentNumberService::class);
        $values = DB::transaction(fn () => [$numbers->next((int) $this->company->getKey(), '2025-12-31'), $numbers->next((int) $this->company->getKey(), '2026-10-25'), $numbers->next((int) $other->getKey(), '2026-10-25')]);
        $this->assertSame(['SP-2025-000001', 'SP-2026-000001', 'SP-2026-000001'], $values);
    }

    public function test_number_reservation_rolls_back_with_native_transaction(): void
    {
        $numbers = app(SupplierPaymentNumberService::class);
        $id = (int) $this->company->getKey();
        $this->assertSame('SP-2026-000001', DB::transaction(fn () => $numbers->next($id, '2026-10-25')));
        try {
            DB::transaction(function () use ($numbers, $id): void {
                $numbers->next($id, '2026-10-25');
                throw new \RuntimeException('rollback fixture');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('rollback fixture', $e->getMessage());
        } $this->assertSame('SP-2026-000002', DB::transaction(fn () => $numbers->next($id, '2026-10-25')));
    }

    public function test_numbering_rejects_invalid_date_and_exhaustion(): void
    {
        $numbers = app(SupplierPaymentNumberService::class);
        try {
            $numbers->next((int) $this->company->getKey(), '2026-02-30');
            $this->fail('Invalid date accepted.');
        } catch (\InvalidArgumentException $e) {
            $this->assertNotEmpty($e->getMessage());
        } DB::table('purchase_document_sequences')->insert(['company_id' => $this->company->getKey(), 'document_type' => 'supplier_payment', 'year' => 2026, 'last_number' => 999999, 'created_at' => now(), 'updated_at' => now()]);
        $this->immutable(fn () => DB::transaction(fn () => $numbers->next((int) $this->company->getKey(), '2026-10-25')));
    }

    public function test_permission_seeder_is_idempotent_and_does_not_grant_staff_posting(): void
    {
        $this->seed(AccountingSupplierPaymentPermissionSeeder::class);
        $staff = User::factory()->create();
        $staff->givePermissionTo('erp.supplier-payments.view');
        $permissions = app(AccountingSupplierPaymentContract::class)->permissions();
        $this->assertSame(count($permissions), Permission::query()->whereIn('name', $permissions)->where('guard_name', 'web')->count());
        $role = Role::findByName('super-admin', 'web');
        foreach ($permissions as $permission) {
            $this->assertTrue($role->hasPermissionTo($permission));
        } $this->assertFalse($staff->can('erp.supplier-payments.post'));
        $this->assertFalse($staff->can('erp.supplier-payments.approve'));
    }

    public function test_migration_collision_is_rejected_before_ddl(): void
    {
        $files = glob(database_path('migrations/*create_supplier_payment_foundation.php'));
        $this->assertIsArray($files);
        $this->assertCount(1, $files);
        $before = Schema::getTableListing();
        $migration = require $files[0];
        try {
            $migration->up();
            $this->fail('Expected migration collision.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No DDL was attempted', $e->getMessage());
        } $this->assertSame($before, Schema::getTableListing());
    }

    public function test_storage_alone_does_not_post_finance_or_update_payables(): void
    {
        $this->allocation($this->posted());
        $this->assertSame('0.00', bcadd((string) DB::table('supplier_payables')->where('id', $this->payable)->value('paid_amount'), '0', 2));
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('accounting_source_effects', 0);
        $this->assertSame(['cash' => []], app(AccountingSourceEventRegistry::class)->definition('supplier_payment', 'posted'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\ERP;

use App\Services\ERP\Accounting\Source\AccountingSourceEventRegistry;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentChangeContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AccountingSupplierPaymentChangeContractTest extends TestCase
{
    private AccountingSupplierPaymentChangeContract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contract = app(AccountingSupplierPaymentChangeContract::class);
    }

    /** @return array<string, mixed> */
    private function root(): array
    {
        return ['company_id' => 1, 'payment_id' => 10, 'supplier_id' => 20, 'cash_account_id' => 30, 'currency_code' => 'BDT', 'revision' => 4, 'amount' => '100.00'];
    }

    /** @return array<string, mixed> */
    private function row(int $id = 1, string $amount = '30.00', ?int $reverses = null, int $bill = 40): array
    {
        return ['id' => $id, 'company_id' => 1, 'supplier_payment_id' => 10, 'supplier_bill_id' => $bill, 'supplier_payable_id' => $bill + 10, 'amount' => $amount, 'reverses_allocation_id' => $reverses];
    }

    /** @param array<array-key, array<string, mixed>> $history
     * @param array<string, mixed> $overrides
     * @return array<string, mixed> */
    private function request(array $history = [], array $overrides = []): array
    {
        $position = $this->contract->position($this->root(), $history);

        return ['revision' => 4, 'history_hash' => $position['history_hash'], 'key' => 'payment_change_test_0001', 'reason' => 'Settle an additional bill', 'compensate_ids' => [], 'targets' => [['supplier_bill_id' => 40, 'amount' => '10.00']], ...$overrides];
    }

    private function invalid(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected rejected payment operation.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    public function test_empty_history_is_an_entirely_available_advance(): void
    {
        $p = $this->contract->position($this->root(), []);
        $this->assertSame('0.00', $p['allocated']);
        $this->assertSame('100.00', $p['available']);
        $this->assertSame([], $p['active']);
    }

    public function test_native_history_derives_available_funds(): void
    {
        $p = $this->contract->position($this->root(), [$this->row()]);
        $this->assertSame('30.00', $p['allocated']);
        $this->assertSame('70.00', $p['available']);
    }

    public function test_exact_compensation_restores_funds_once(): void
    {
        $p = $this->contract->position($this->root(), [$this->row(), $this->row(2, '30.00', 1)]);
        $this->assertSame('100.00', $p['available']);
        $this->assertSame([], $p['active']);
    }

    public function test_history_input_order_does_not_change_fingerprint(): void
    {
        $rows = [$this->row(), $this->row(2, '10.00', null, 41)];
        $this->assertSame($this->contract->position($this->root(), $rows), $this->contract->position(array_reverse($this->root(), true), array_reverse($rows)));
    }

    public function test_appended_history_changes_fingerprint_without_header_revision_change(): void
    {
        $before = $this->contract->position($this->root(), []);
        $after = $this->contract->position($this->root(), [$this->row()]);
        $this->assertNotSame($before['history_hash'], $after['history_hash']);
        $this->assertSame($before['root']['revision'], $after['root']['revision']);
    }

    public function test_foreign_history_is_rejected(): void
    {
        foreach (['company_id' => 2, 'supplier_payment_id' => 11] as $field => $value) {
            $this->invalid(fn () => $this->contract->position($this->root(), [[...$this->row(), $field => $value]]));
        }
    }

    public function test_duplicate_native_identity_is_rejected(): void
    {
        $this->invalid(fn () => $this->contract->position($this->root(), [$this->row(), $this->row()]));
    }

    public function test_missing_and_unknown_native_fields_are_rejected(): void
    {
        $row = $this->row();
        unset($row['amount']);
        $this->invalid(fn () => $this->contract->position($this->root(), [$row]));
        $this->invalid(fn () => $this->contract->position($this->root(), [[...$this->row(), 'notes' => 'not a history identity']]));
    }

    public function test_float_zero_negative_and_excess_decimal_history_is_rejected(): void
    {
        foreach ([1.0, '0.00', '-1.00', '1.001'] as $amount) {
            $this->invalid(fn () => $this->contract->position($this->root(), [[...$this->row(), 'amount' => $amount]]));
        }
    }

    public function test_dangling_or_later_original_compensation_is_rejected(): void
    {
        $this->invalid(fn () => $this->contract->position($this->root(), [$this->row(1, '30.00', 9)]));
        $this->invalid(fn () => $this->contract->position($this->root(), [$this->row(2), $this->row(1, '30.00', 2)]));
    }

    public function test_compensation_of_compensation_is_rejected(): void
    {
        $this->invalid(fn () => $this->contract->position($this->root(), [$this->row(), $this->row(2, '30.00', 1), $this->row(3, '30.00', 2)]));
    }

    public function test_duplicate_compensation_is_rejected(): void
    {
        $this->invalid(fn () => $this->contract->position($this->root(), [$this->row(), $this->row(2, '30.00', 1), $this->row(3, '30.00', 1)]));
    }

    public function test_compensation_must_match_original_amount_bill_and_payable(): void
    {
        foreach (['amount' => '20.00', 'supplier_bill_id' => 99, 'supplier_payable_id' => 99] as $field => $value) {
            $this->invalid(fn () => $this->contract->position($this->root(), [$this->row(), [...$this->row(2, '30.00', 1), $field => $value]]));
        }
    }

    public function test_one_bill_cannot_change_payable_identity(): void
    {
        $this->invalid(fn () => $this->contract->position($this->root(), [$this->row(), [...$this->row(2), 'supplier_payable_id' => 90]]));
    }

    public function test_two_bills_cannot_share_payable_identity(): void
    {
        $this->invalid(fn () => $this->contract->position($this->root(), [$this->row(), [...$this->row(2, '10.00', null, 41), 'supplier_payable_id' => 50]]));
    }

    public function test_later_compensation_cannot_conceal_historical_overspend(): void
    {
        $this->invalid(fn () => $this->contract->position($this->root(), [$this->row(1, '80.00'), $this->row(2, '30.00'), $this->row(3, '80.00', 1)]));
    }

    public function test_later_allocation_has_no_cash_effect(): void
    {
        $plan = $this->contract->change($this->root(), [], $this->request());
        $this->assertSame('90.00', $plan['available_after']);
        $this->assertSame(0, $plan['cash_effects']);
        $this->assertSame(['erp.supplier-payments.allocate'], $plan['required_permissions']);
    }

    public function test_reallocation_uses_restored_funds_atomically(): void
    {
        $history = [$this->row(1, '100.00')];
        $plan = $this->contract->change($this->root(), $history, $this->request($history, ['compensate_ids' => [1], 'targets' => [['supplier_bill_id' => 41, 'amount' => '100.00']]]));
        $this->assertSame('100.00', $plan['restored']);
        $this->assertSame('0.00', $plan['available_after']);
        $this->assertSame(0, $plan['cash_effects']);
    }

    public function test_compensation_only_request_is_valid(): void
    {
        $history = [$this->row()];
        $plan = $this->contract->change($this->root(), $history, $this->request($history, ['compensate_ids' => [1], 'targets' => []]));
        $this->assertSame('100.00', $plan['available_after']);
        $this->assertSame('0.00', $plan['new_allocated']);
    }

    public function test_stale_header_or_history_is_rejected_for_new_operation(): void
    {
        $history = [$this->row()];
        $this->invalid(fn () => $this->contract->change($this->root(), $history, $this->request()));
        $this->invalid(fn () => $this->contract->change($this->root(), [], $this->request([], ['revision' => 3])));
    }

    public function test_bad_key_and_blank_or_overlong_reason_are_rejected(): void
    {
        foreach ([['key' => 'short'], ['key' => str_repeat('x', 65)], ['key' => 'bad key with spaces'], ['reason' => ' '], ['reason' => str_repeat('x', 2001)]] as $input) {
            $this->invalid(fn () => $this->contract->change($this->root(), [], $this->request([], $input)));
        }
    }

    public function test_unknown_missing_and_noop_request_fields_are_rejected(): void
    {
        $request = $this->request();
        unset($request['reason']);
        $this->invalid(fn () => $this->contract->change($this->root(), [], $request));
        $this->invalid(fn () => $this->contract->change($this->root(), [], $this->request([], ['cash_account_id' => 99])));
        $this->invalid(fn () => $this->contract->change($this->root(), [], $this->request([], ['targets' => []])));
    }

    public function test_duplicate_or_spent_compensation_targets_are_rejected(): void
    {
        $history = [$this->row()];
        $this->invalid(fn () => $this->contract->change($this->root(), $history, $this->request($history, ['compensate_ids' => [1, 1]])));
        $history[] = $this->row(2, '30.00', 1);
        $this->invalid(fn () => $this->contract->change($this->root(), $history, $this->request($history, ['compensate_ids' => [1]])));
    }

    public function test_duplicate_foreign_shaped_and_nonpositive_bill_targets_are_rejected(): void
    {
        foreach ([[['supplier_bill_id' => 40, 'amount' => '10.00'], ['supplier_bill_id' => 40, 'amount' => '1.00']], [['supplier_bill_id' => '40', 'amount' => '1.00']], [['supplier_bill_id' => 40, 'amount' => '0.00']], [['supplier_bill_id' => 40, 'amount' => '1.00', 'company_id' => 2]], ['not an object']] as $targets) {
            $this->invalid(fn () => $this->contract->change($this->root(), [], $this->request([], ['targets' => $targets])));
        }
    }

    public function test_more_than_available_funds_is_rejected(): void
    {
        $history = [$this->row()];
        $this->invalid(fn () => $this->contract->change($this->root(), $history, $this->request($history, ['targets' => [['supplier_bill_id' => 41, 'amount' => '70.01']]])));
    }

    public function test_list_and_one_hundred_member_limits_are_enforced(): void
    {
        $this->invalid(fn () => $this->contract->change($this->root(), [], $this->request([], ['targets' => [9 => ['supplier_bill_id' => 40, 'amount' => '1.00']]])));
        $targets = [];
        for ($i = 1; $i <= 101; $i++) {
            $targets[] = ['supplier_bill_id' => $i, 'amount' => '0.01'];
        } $this->invalid(fn () => $this->contract->change($this->root(), [], $this->request([], ['targets' => $targets])));
    }

    public function test_normalized_request_hash_is_order_independent_and_private(): void
    {
        $history = [$this->row(), $this->row(2, '20.00', null, 41)];
        $request = $this->request($history, ['compensate_ids' => [2, 1], 'targets' => [['supplier_bill_id' => 42, 'amount' => '20'], ['supplier_bill_id' => 43, 'amount' => '10.0']]]);
        $a = $this->contract->change($this->root(), $history, $request);
        $request['compensate_ids'] = [1, 2];
        $request['targets'] = array_reverse($request['targets']);
        $b = $this->contract->change($this->root(), array_reverse($history), array_reverse($request, true));
        $this->assertSame($a, $b);
        $this->assertArrayNotHasKey('key', $a);
        $this->assertArrayNotHasKey('reason', $a);
    }

    public function test_changed_reason_or_key_changes_request_hash(): void
    {
        $a = $this->contract->change($this->root(), [], $this->request());
        foreach ([['reason' => 'A different operation reason'], ['key' => 'payment_change_test_0002']] as $input) {
            $this->assertNotSame($a['request_hash'], $this->contract->change($this->root(), [], $this->request([], $input))['request_hash']);
        }
    }

    public function test_full_reversal_restores_every_allocation_and_original_cash_identity(): void
    {
        $history = [$this->row(), $this->row(2, '20.00', null, 41)];
        $request = $this->request($history);
        unset($request['compensate_ids'], $request['targets']);
        $plan = $this->contract->reversal($this->root(), $history, $request);
        $this->assertSame([1, 2], $plan['compensate_ids']);
        $this->assertSame('50.00', $plan['restored']);
        $this->assertSame('100.00', $plan['cash_amount']);
        $this->assertSame(30, $plan['cash_account_id']);
        $this->assertSame(1, $plan['cash_effects']);
        $this->assertSame(['erp.supplier-payments.reverse'], $plan['required_permissions']);
    }

    public function test_cash_only_reversal_still_reverses_full_original_cash(): void
    {
        $request = $this->request();
        unset($request['compensate_ids'], $request['targets']);
        $plan = $this->contract->reversal($this->root(), [], $request);
        $this->assertSame([], $plan['compensations']);
        $this->assertSame('100.00', $plan['cash_amount']);
    }

    public function test_full_reversal_does_not_truncate_more_than_one_hundred_active_rows(): void
    {
        $history = [];
        for ($i = 1; $i <= 150; $i++) {
            $history[] = $this->row($i, '0.01', null, 40 + $i);
        } $request = $this->request($history);
        unset($request['compensate_ids'], $request['targets']);
        $plan = $this->contract->reversal($this->root(), $history, $request);
        $this->assertCount(150, $plan['compensations']);
        $this->assertSame('1.50', $plan['restored']);
    }

    public function test_reversal_cannot_accept_partial_amount_or_replacement_cash_binding(): void
    {
        $request = $this->request();
        unset($request['compensate_ids'], $request['targets']);
        foreach (['amount' => '10.00', 'cash_account_id' => 99, 'compensate_ids' => []] as $field => $value) {
            $this->invalid(fn () => $this->contract->reversal($this->root(), [], [...$request, $field => $value]));
        }
    }

    public function test_root_currency_ids_and_positive_amount_are_validated(): void
    {
        foreach (['currency_code' => 'bdt', 'revision' => '4', 'payment_id' => 0, 'amount' => '0.00'] as $field => $value) {
            $this->invalid(fn () => $this->contract->position([...$this->root(), $field => $value], []));
        }
    }

    public function test_full_cash_reversal_and_allocation_compensation_are_installed(): void
    {
        $contracts = app(AccountingSourceEventRegistry::class)->contracts();
        $this->assertArrayHasKey('supplier_payment.posted', $contracts);
        $this->assertArrayHasKey('supplier_payment_allocation.created', $contracts);
        $this->assertArrayHasKey('supplier_payment.reversed', $contracts);
        $this->assertArrayHasKey('supplier_payment_allocation.reversed', $contracts);
    }

    public function test_full_reversal_rejects_more_than_two_hundred_active_members_before_planning_cash(): void
    {
        $history = [];
        for ($i = 1; $i <= 201; $i++) {
            $history[] = $this->row($i, '0.01', null, 40 + $i);
        } $request = $this->request($history);
        unset($request['compensate_ids'], $request['targets']);
        $this->invalid(fn () => $this->contract->reversal($this->root(), $history, $request));
    }

    public function test_long_history_hash_does_not_exceed_canonical_array_width(): void
    {
        $history = [];
        for ($i = 0; $i < 600; $i++) {
            $id = $i * 2 + 1;
            $history[] = $this->row($id, '0.01');
            $history[] = $this->row($id + 1, '0.01', $id);
        } $p = $this->contract->position($this->root(), $history);
        $this->assertSame(1200, $p['history_count']);
        $this->assertSame('100.00', $p['available']);
        $this->assertSame([], $p['active']);
    }
}

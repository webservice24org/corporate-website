<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Services\ERP\Accounting\AccountingMappingCatalog;
use App\Services\ERP\Accounting\Source\AccountingSourceEventRegistry;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Pure contract tests: no synthetic activation or payment data is inserted. */
final class AccountingSupplierPaymentContractTest extends TestCase
{
    private function contract(): AccountingSupplierPaymentContract
    {
        return app(AccountingSupplierPaymentContract::class);
    }

    private function invalid(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected invalid supplier payment intent.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    /** @param list<array{role: string, debit: string, credit: string}> $lines @return array<string, string> */
    private function net(array $lines): array
    {
        $net = [];
        foreach ($lines as $line) {
            $net[$line['role']] = bcadd($net[$line['role']] ?? '0.00', bcsub($line['debit'], $line['credit'], 2), 2);
        } ksort($net);

        return $net;
    }

    public function test_cash_payment_recognizes_supplier_advance_and_cash_once(): void
    {
        $p = $this->contract()->plan('cash_paid', '95.00', 'cash:MAIN');
        $this->assertSame(['cash:MAIN' => '-95.00', 'supplier_advances' => '95.00'], $this->net($p['lines']));
    }

    public function test_bank_payment_uses_a_concrete_bank_role(): void
    {
        $this->assertSame(['bank:CURRENT' => '-25.00', 'supplier_advances' => '25.00'], $this->net($this->contract()->plan('cash_paid', '25', 'bank:CURRENT')['lines']));
    }

    public function test_ap_allocation_reclassifies_advances_without_another_cash_event(): void
    {
        $this->assertSame(['supplier_advances' => '-20.00', 'trade_ap' => '20.00'], $this->net($this->contract()->plan('allocated', '20')['lines']));
    }

    public function test_compensation_restores_advance_and_ap(): void
    {
        $this->assertSame(['supplier_advances' => '20.00', 'trade_ap' => '-20.00'], $this->net($this->contract()->plan('allocation_compensated', '20')['lines']));
    }

    public function test_cash_reversal_reverses_original_cash_and_advances(): void
    {
        $this->assertSame(['cash:MAIN' => '95.00', 'supplier_advances' => '-95.00'], $this->net($this->contract()->plan('cash_reversed', '95', 'cash:MAIN')['lines']));
    }

    public function test_partly_allocated_payment_preserves_explicit_supplier_advance(): void
    {
        $s = $this->contract()->initialSplit('95', [['supplier_bill_id' => 1, 'amount' => '20']], '75');
        $lines = [...$this->contract()->plan('cash_paid', $s['amount'], 'bank:CURRENT')['lines'], ...$this->contract()->plan('allocated', $s['allocated'])['lines']];
        $this->assertSame(['bank:CURRENT' => '-95.00', 'supplier_advances' => '75.00', 'trade_ap' => '20.00'], $this->net($lines));
    }

    public function test_fully_allocated_payment_has_no_residual_advance(): void
    {
        $s = $this->contract()->initialSplit('95', [['supplier_bill_id' => 1, 'amount' => '95']], '0');
        $this->assertSame('0.00', $s['advance']);
        $this->assertSame(['cash:MAIN' => '-95.00', 'supplier_advances' => '0.00', 'trade_ap' => '95.00'], $this->net([...$this->contract()->plan('cash_paid', '95', 'cash:MAIN')['lines'], ...$this->contract()->plan('allocated', '95')['lines']]));
    }

    public function test_advance_only_payment_needs_explicit_full_advance(): void
    {
        $s = $this->contract()->initialSplit('100', [], '100');
        $this->assertSame('0.00', $s['allocated']);
        $this->assertSame('100.00', $s['advance']);
        $this->assertSame([], $s['targets']);
    }

    public function test_later_allocation_does_not_change_original_cash_amount(): void
    {
        $lines = [...$this->contract()->plan('cash_paid', '100', 'cash:MAIN')['lines'], ...$this->contract()->plan('allocated', '20')['lines'], ...$this->contract()->plan('allocated', '30')['lines']];
        $this->assertSame(['cash:MAIN' => '-100.00', 'supplier_advances' => '50.00', 'trade_ap' => '50.00'], $this->net($lines));
    }

    public function test_complete_allocation_compensation_and_cash_reversal_net_to_zero(): void
    {
        $lines = [...$this->contract()->plan('cash_paid', '100', 'cash:MAIN')['lines'], ...$this->contract()->plan('allocated', '70')['lines'], ...$this->contract()->plan('allocation_compensated', '70')['lines'], ...$this->contract()->plan('cash_reversed', '100', 'cash:MAIN')['lines']];
        $this->assertSame(['cash:MAIN' => '0.00', 'supplier_advances' => '0.00', 'trade_ap' => '0.00'], $this->net($lines));
    }

    public function test_payment_plans_do_not_recognize_expense_tax_or_inventory_again(): void
    {
        foreach (['cash_paid', 'allocated', 'allocation_compensated', 'cash_reversed'] as $event) {
            $p = $this->contract()->plan($event, '10', str_starts_with($event, 'cash_') ? 'bank:CURRENT' : null);
            foreach ($p['lines'] as $line) {
                $this->assertContains($line['role'], ['bank:CURRENT', 'trade_ap', 'supplier_advances']);
            }
        }
    }

    public function test_every_event_balances_at_exact_cent_precision(): void
    {
        foreach (['0.01', '10.10', '999999999999999999.99'] as $amount) {
            foreach (['cash_paid', 'allocated', 'allocation_compensated', 'cash_reversed'] as $event) {
                $p = $this->contract()->plan($event, $amount, str_starts_with($event, 'cash_') ? 'cash:MAIN' : null);
                $debit = '0.00';
                $credit = '0.00';
                foreach ($p['lines'] as $line) {
                    $debit = bcadd($debit, $line['debit'], 2);
                    $credit = bcadd($credit, $line['credit'], 2);
                } $this->assertSame($amount, $debit);
                $this->assertSame($debit, $credit);
            }
        }
    }

    public function test_cash_role_is_required_for_cash_events(): void
    {
        foreach (['cash_paid', 'cash_reversed'] as $event) {
            $this->invalid(fn () => $this->contract()->plan($event, '10'));
        }
    }

    public function test_non_cash_and_malformed_roles_are_rejected(): void
    {
        foreach (['trade_ap', 'bank:', 'cash:main', 'cash:MAIN OR 1=1', 'bank:'.str_repeat('X', 31)] as $role) {
            $this->invalid(fn () => $this->contract()->plan('cash_paid', '10', $role));
        }
    }

    public function test_allocation_events_cannot_smuggle_a_cash_role(): void
    {
        foreach (['allocated', 'allocation_compensated'] as $event) {
            $this->invalid(fn () => $this->contract()->plan($event, '10', 'cash:MAIN'));
        }
    }

    public function test_unknown_event_has_no_fallback_financial_recipe(): void
    {
        $this->invalid(fn () => $this->contract()->plan('refund_supplier_credit', '10'));
    }

    public function test_zero_negative_float_and_excess_precision_effects_are_rejected(): void
    {
        foreach (['0', '-1', 1.5, '1.001', '1e2', true] as $amount) {
            $this->invalid(fn () => $this->contract()->plan('allocated', $amount));
        }
    }

    public function test_target_order_and_object_key_order_normalize_stably(): void
    {
        $a = [['supplier_bill_id' => 2, 'amount' => '20'], ['amount' => '10.00', 'supplier_bill_id' => 1]];
        $b = array_reverse($a);
        $this->assertSame($this->contract()->initialSplit('35', $a, '5'), $this->contract()->initialSplit('35.00', $b, '5.00'));
    }

    public function test_duplicate_bill_target_is_rejected(): void
    {
        $this->invalid(fn () => $this->contract()->initialSplit('20', [['supplier_bill_id' => 1, 'amount' => '10'], ['supplier_bill_id' => 1, 'amount' => '10']], '0'));
    }

    public function test_target_bill_identity_must_be_a_positive_integer(): void
    {
        foreach ([0, -1, '1', true, 1.0] as $id) {
            $this->invalid(fn () => $this->contract()->initialSplit('10', [['supplier_bill_id' => $id, 'amount' => '10']], '0'));
        }
    }

    public function test_unapproved_residual_is_not_automatically_converted_to_advance(): void
    {
        $this->invalid(fn () => $this->contract()->initialSplit('100', [['supplier_bill_id' => 1, 'amount' => '20']], '0'));
    }

    public function test_targets_plus_advance_cannot_exceed_payment(): void
    {
        $this->invalid(fn () => $this->contract()->initialSplit('100', [['supplier_bill_id' => 1, 'amount' => '90']], '20'));
    }

    public function test_zero_payment_and_zero_target_are_rejected(): void
    {
        $this->invalid(fn () => $this->contract()->initialSplit('0', [], '0'));
        $this->invalid(fn () => $this->contract()->initialSplit('10', [['supplier_bill_id' => 1, 'amount' => '0']], '10'));
    }

    public function test_allocation_target_cannot_override_company_or_financial_evidence(): void
    {
        foreach (['company_id' => 2, 'supplier_id' => 3, 'journal_entry_id' => 4, 'approved' => true] as $key => $value) {
            $this->invalid(fn () => $this->contract()->initialSplit('10', [['supplier_bill_id' => 1, 'amount' => '10', $key => $value]], '0'));
        }
    }

    public function test_initial_target_list_is_bounded_and_not_silently_truncated(): void
    {
        $targets = [];
        for ($i = 1; $i <= 100; $i++) {
            $targets[] = ['supplier_bill_id' => $i, 'amount' => '1'];
        } $this->assertCount(100, $this->contract()->initialSplit('100', $targets, '0')['targets']);
        $targets[] = ['supplier_bill_id' => 101, 'amount' => '1'];
        $this->invalid(fn () => $this->contract()->initialSplit('101', $targets, '0'));
    }

    public function test_non_list_targets_are_rejected_at_runtime(): void
    {
        $this->invalid(fn () => $this->contract()->initialSplit('10', [2 => ['supplier_bill_id' => 1, 'amount' => '10']], '0'));
    }

    public function test_existing_mapping_roles_already_classify_supplier_advances_as_an_asset(): void
    {
        $catalog = app(AccountingMappingCatalog::class);
        $this->assertSame('asset', $catalog->category('supplier_advances'));
        $this->assertSame('supplier_advances', $catalog->control('supplier_advances'));
        $this->assertSame(['current_asset'], $catalog->statements('supplier_advances'));
    }

    public function test_live_registry_installs_payment_and_full_reversal_events(): void
    {
        $registry = app(AccountingSourceEventRegistry::class);
        $this->assertSame(['cash' => []], $registry->definition('supplier_payment', 'posted'));
        $this->assertSame(['allocation' => []], $registry->definition('supplier_payment_allocation', 'created'));
        $this->assertSame(['cash' => []], $registry->definition('supplier_payment', 'reversed'));
    }

    public function test_candidate_tables_and_permissions_are_distinct_and_bounded_identifiers(): void
    {
        $tables = $this->contract()->tables();
        $this->assertCount(count(array_unique($tables)), $tables);
        foreach ($tables as $table) {
            $this->assertMatchesRegularExpression('/\A[a-z_]{1,64}\z/', $table);
        } $permissions = $this->contract()->permissions();
        $this->assertCount(count(array_unique($permissions)), $permissions);
        $this->assertContains('erp.supplier-payments.approve', $permissions);
        $this->assertContains('erp.supplier-statements.view', $permissions);
    }

    public function test_preflight_requires_credit_aware_payables_and_concrete_cash_bindings(): void
    {
        $schema = $this->contract()->requiredSchema();
        $this->assertContains('credited_amount', $schema['supplier_payables']);
        $this->assertContains('account_id', $schema['accounting_cash_accounts']);
        $this->assertContains('currency_code', $schema['accounting_cash_accounts']);
    }
}

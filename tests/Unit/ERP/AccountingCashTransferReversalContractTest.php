<?php

declare(strict_types=1);

namespace Tests\Unit\ERP;

use App\Services\ERP\Accounting\Transfers\AccountingCashTransferReversalContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AccountingCashTransferReversalContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private function original(string $sourceKind = 'cash', string $destinationKind = 'bank'): array
    {
        return ['version' => AccountingCashTransferReversalContract::ORIGINAL_VERSION, 'company_id' => 1, 'transfer_id' => 3, 'transfer_revision' => 4,
            'number' => 'CT-2026-000001', 'document_date' => '2026-10-16', 'currency_code' => 'BDT', 'amount' => '25.25',
            'source' => ['id' => 1, 'company_id' => 1, 'account_id' => 101, 'role' => $sourceKind.':MAIN', 'kind' => $sourceKind, 'currency_code' => 'BDT'],
            'destination' => ['id' => 2, 'company_id' => 1, 'account_id' => 102, 'role' => $destinationKind.':SECOND', 'kind' => $destinationKind, 'currency_code' => 'BDT'],
            'operation_id' => 4, 'operation_hash' => str_repeat('a', 64), 'effect_id' => 5, 'source_hash' => str_repeat('b', 64),
            'journal_id' => 6, 'journal_hash' => str_repeat('c', 64), 'mapping_version_id' => 7, 'mapping_hash' => str_repeat('d', 64)];
    }

    private function contract(): AccountingCashTransferReversalContract
    {
        return app(AccountingCashTransferReversalContract::class);
    }

    private function invalid(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected invalid reversal contract.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cash_transfer_reversal', $exception->errors());
        }
    }

    public function test_all_cash_and_bank_combinations_swap_the_original_legs(): void
    {
        foreach ([['cash', 'cash'], ['cash', 'bank'], ['bank', 'cash'], ['bank', 'bank']] as [$source, $destination]) {
            $plan = $this->contract()->plan($this->original($source, $destination), '2026-10-17');
            $this->assertTrue($plan['preview_only']);
            $this->assertSame(101, $plan['lines'][0]['account_id']);
            $this->assertSame(1, $plan['lines'][0]['cash_account_id']);
            $this->assertSame('25.25', $plan['lines'][0]['debit']);
            $this->assertSame('0.00', $plan['lines'][0]['credit']);
            $this->assertSame(102, $plan['lines'][1]['account_id']);
            $this->assertSame('25.25', $plan['lines'][1]['credit']);
            $this->assertSame('0.00', bcsub($plan['lines'][0]['debit'], $plan['lines'][1]['credit'], 2));
        }
    }

    public function test_cents_and_maximum_supported_money_are_exact(): void
    {
        foreach (['0.01', '999999999999999999.99'] as $amount) {
            $plan = $this->contract()->plan([...$this->original(), 'amount' => $amount], '2026-10-16');
            $this->assertSame($amount, $plan['lines'][0]['debit']);
            $this->assertSame($amount, $plan['lines'][1]['credit']);
        }
    }

    public function test_float_and_noncanonical_amount_are_rejected(): void
    {
        $this->invalid(fn () => $this->contract()->plan([...$this->original(), 'amount' => 25.25], '2026-10-16'));
        $this->invalid(fn () => $this->contract()->plan([...$this->original(), 'amount' => '25.2'], '2026-10-16'));
    }

    public function test_excess_decimals_zero_and_negative_amounts_are_rejected(): void
    {
        foreach (['1.001', '0.00', '-1.00'] as $amount) {
            // The existing strict transfer/decimal contract supplies the corresponding amount error.
            try {
                $this->contract()->plan([...$this->original(), 'amount' => $amount], '2026-10-16');
                $this->fail('Expected invalid amount.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_reversal_date_cannot_predate_original(): void
    {
        $this->invalid(fn () => $this->contract()->plan($this->original(), '2026-10-15'));
    }

    public function test_invalid_calendar_date_is_rejected(): void
    {
        foreach (['2026-02-30', '1899-12-31', '2026-13-01'] as $date) {
            $this->invalid(fn () => $this->contract()->plan($this->original(), $date));
        }
    }

    public function test_original_number_and_date_must_agree(): void
    {
        $this->invalid(fn () => $this->contract()->plan([...$this->original(), 'number' => 'CT-2025-000001'], '2026-10-16'));
    }

    public function test_missing_or_private_original_fields_are_rejected(): void
    {
        $original = $this->original();
        unset($original['operation_hash']);
        $this->invalid(fn () => $this->contract()->plan($original, '2026-10-16'));
        $this->invalid(fn () => $this->contract()->plan([...$this->original(), 'notes' => 'Private context'], '2026-10-16'));
    }

    public function test_original_fingerprints_and_revision_are_validated(): void
    {
        $this->invalid(fn () => $this->contract()->plan([...$this->original(), 'operation_hash' => 'not-a-hash'], '2026-10-16'));
        $this->invalid(fn () => $this->contract()->plan([...$this->original(), 'transfer_revision' => 3], '2026-10-16'));
    }

    public function test_foreign_endpoint_is_rejected(): void
    {
        $original = $this->original();
        $original['source']['company_id'] = 2;
        $this->expectException(ValidationException::class);
        $this->contract()->plan($original, '2026-10-16');
    }

    public function test_same_binding_and_currency_drift_are_rejected(): void
    {
        foreach (['id' => 1, 'currency_code' => 'USD'] as $field => $value) {
            $original = $this->original();
            $original['destination'][$field] = $value;
            try {
                $this->contract()->plan($original, '2026-10-16');
                $this->fail('Expected invalid endpoint.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_approval_is_independent_of_all_three_review_actors(): void
    {
        $this->assertTrue($this->contract()->independent(4, 1, 2, 3));
        foreach ([1, 2, 3, 0] as $approver) {
            $this->assertFalse($this->contract()->independent($approver, 1, 2, 3));
        }
    }

    public function test_only_approved_reversals_can_transition_to_posted(): void
    {
        foreach (['draft' => ['draft', 'submitted', 'cancelled'], 'submitted' => ['draft', 'approved', 'cancelled'], 'approved' => ['cancelled', 'posted']] as $from => $targets) {
            foreach ($targets as $to) {
                $this->contract()->transition($from, $to);
                $this->assertTrue(true);
            }
        }
        $this->invalid(fn () => $this->contract()->transition('draft', 'posted'));
        $this->invalid(fn () => $this->contract()->transition('cancelled', 'draft'));
    }

    public function test_foundation_permissions_are_separate_from_original_posting(): void
    {
        $this->assertCount(7, AccountingCashTransferReversalContract::PERMISSIONS);
        $this->assertNotContains('erp.cash-transfers.post', AccountingCashTransferReversalContract::PERMISSIONS);
        $this->assertContains('erp.cash-transfer-reversals.post', AccountingCashTransferReversalContract::PERMISSIONS);
    }
}

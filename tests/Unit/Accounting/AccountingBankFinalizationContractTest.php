<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting;

use App\Services\ERP\Accounting\Reconciliation\AccountingBankFinalizationContract;
use PHPUnit\Framework\TestCase;

final class AccountingBankFinalizationContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private function facts(): array
    {
        return ['evidence_verified' => true, 'date_valid' => true, 'currency_agrees' => true, 'statement_coverage_complete' => true, 'workspaces_complete' => true,
            'unmatched_statement_rows' => 0, 'prior_unmatched_statement_rows' => 0, 'pending_adjustments' => 0, 'book_complete' => true, 'unsupported_cash_lines' => 0,
            'bridge' => ['unverified_opening_prior_statement_residual' => '0.00', 'statement_minus_book' => '0.00', 'explained_difference' => '0.00']];
    }

    public function test_all_gates_are_required_and_unknown_is_not_zero(): void
    {
        $contract = new AccountingBankFinalizationContract;
        $facts = $this->facts();
        $this->assertTrue($contract->ready($contract->checks($facts)));
        foreach (['evidence_verified', 'date_valid', 'currency_agrees', 'statement_coverage_complete', 'workspaces_complete', 'book_complete'] as $field) {
            $this->assertFalse($contract->ready($contract->checks([...$facts, $field => false])), $field);
        }
        foreach (['unmatched_statement_rows', 'prior_unmatched_statement_rows', 'pending_adjustments', 'unsupported_cash_lines'] as $field) {
            $this->assertFalse($contract->ready($contract->checks([...$facts, $field => 1])), $field);
            $this->assertFalse($contract->ready($contract->checks([...$facts, $field => null])), $field.' unknown');
        }
    }

    public function test_equal_nonzero_bridge_does_not_require_a_fake_zero_difference(): void
    {
        $contract = new AccountingBankFinalizationContract;
        $facts = $this->facts();
        $facts['bridge']['statement_minus_book'] = '-9007199254740993.27';
        $facts['bridge']['explained_difference'] = '-9007199254740993.27';
        $this->assertTrue($contract->ready($contract->checks($facts)));
        $facts['bridge']['explained_difference'] = '-9007199254740993.26';
        $this->assertFalse($contract->ready($contract->checks($facts)));
    }

    public function test_zero_difference_cannot_hide_opening_residual(): void
    {
        $contract = new AccountingBankFinalizationContract;
        $facts = $this->facts();
        $facts['bridge']['unverified_opening_prior_statement_residual'] = '0.01';
        $this->assertFalse($contract->ready($contract->checks($facts)));
    }

    public function test_missing_float_exponent_or_excess_precision_bridge_never_passes(): void
    {
        $contract = new AccountingBankFinalizationContract;
        foreach ([null, 0, 0.0, '0e0', '0.000', 'NaN', '', ' 0.00', '1000000000000000000.00'] as $invalid) {
            $facts = $this->facts();
            $facts['bridge']['statement_minus_book'] = $invalid;
            $this->assertFalse($contract->ready($contract->checks($facts)));
        }
        $facts = $this->facts();
        $facts['bridge'] = [];
        $this->assertFalse($contract->ready($contract->checks($facts)));
    }

    public function test_empty_checklist_cannot_be_ready(): void
    {
        $this->assertFalse((new AccountingBankFinalizationContract)->ready([]));
    }
}

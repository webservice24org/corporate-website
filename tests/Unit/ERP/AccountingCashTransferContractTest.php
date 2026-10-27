<?php

declare(strict_types=1);

namespace Tests\Unit\ERP;

use App\Services\ERP\Accounting\Transfers\AccountingCashTransferContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AccountingCashTransferContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private function binding(int $id, string $kind = 'cash'): array
    {
        return ['id' => $id, 'company_id' => 1, 'account_id' => $id + 100, 'role' => $kind.':A'.$id, 'kind' => $kind, 'currency_code' => 'BDT'];
    }

    public function test_all_cash_and_bank_direction_combinations_are_balanced(): void
    {
        foreach ([['cash', 'cash'], ['cash', 'bank'], ['bank', 'cash'], ['bank', 'bank']] as [$a, $b]) {
            $plan = app(AccountingCashTransferContract::class)->plan(1, 'BDT', '123.45', $this->binding(1, $a), $this->binding(2, $b));
            $this->assertSame('123.45', $plan['lines'][0]['debit']);
            $this->assertSame('0.00', $plan['lines'][0]['credit']);
            $this->assertSame(2, $plan['lines'][0]['cash_account_id']);
            $this->assertSame('123.45', $plan['lines'][1]['credit']);
            $this->assertSame(1, $plan['lines'][1]['cash_account_id']);
        }
    }

    public function test_same_binding_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(AccountingCashTransferContract::class)->plan(1, 'BDT', '1.00', $this->binding(1), $this->binding(1));
    }

    public function test_same_gl_account_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(AccountingCashTransferContract::class)->plan(1, 'BDT', '1.00', $this->binding(1), [...$this->binding(2), 'account_id' => 101]);
    }

    public function test_foreign_company_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(AccountingCashTransferContract::class)->plan(1, 'BDT', '1.00', $this->binding(1), [...$this->binding(2), 'company_id' => 2]);
    }

    public function test_currency_mismatch_is_rejected_without_fx(): void
    {
        $this->expectException(ValidationException::class);
        app(AccountingCashTransferContract::class)->plan(1, 'BDT', '1.00', $this->binding(1), [...$this->binding(2), 'currency_code' => 'USD']);
    }

    public function test_kind_must_match_role(): void
    {
        $this->expectException(ValidationException::class);
        app(AccountingCashTransferContract::class)->plan(1, 'BDT', '1.00', $this->binding(1), [...$this->binding(2), 'kind' => 'bank']);
    }

    public function test_private_or_unknown_snapshot_fields_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(AccountingCashTransferContract::class)->plan(1, 'BDT', '1.00', [...$this->binding(1), 'bank_account_number' => 'PRIVATE'], $this->binding(2));
    }

    public function test_float_amount_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(AccountingCashTransferContract::class)->amount(0.1);
    }

    public function test_zero_amount_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(AccountingCashTransferContract::class)->amount('0.00');
    }

    public function test_excess_decimals_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(AccountingCashTransferContract::class)->amount('1.001');
    }

    public function test_maximum_supported_amount_stays_exact(): void
    {
        $this->assertSame('999999999999999999.99', app(AccountingCashTransferContract::class)->amount('999999999999999999.99'));
    }

    public function test_posting_permission_is_installed_and_reversal_remains_separate(): void
    {
        $this->assertCount(7, AccountingCashTransferContract::PERMISSIONS);
        $this->assertContains('erp.cash-transfers.post', AccountingCashTransferContract::PERMISSIONS);
        $this->assertNotContains('erp.cash-transfers.reverse', AccountingCashTransferContract::PERMISSIONS);
    }
}

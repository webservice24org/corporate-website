<?php

declare(strict_types=1);

namespace Tests\Unit\ERP;

use App\Services\ERP\Accounting\Expenses\AccountingExpenseContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Uses Laravel's validator facade for expected validation exceptions, without database fixtures. */
final class AccountingExpenseContractTest extends TestCase
{
    public function test_exact_totals_and_canonical_zero(): void
    {
        $this->assertSame(['net_amount' => '123456789012345678.27', 'tax_amount' => '0.03', 'total_amount' => '123456789012345678.30'], app(AccountingExpenseContract::class)->amounts('123456789012345678.27', '0.03', 'recoverable'));
    }

    public function test_float_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        app(AccountingExpenseContract::class)->amounts(0.1, '0', 'none');
    }

    public function test_excess_decimals_are_rejected(): void
    {
        $this->expectException(ValidationException::class);

        app(AccountingExpenseContract::class)->amounts('1.001', '0', 'none');
    }

    public function test_overflow_total_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        app(AccountingExpenseContract::class)->amounts('999999999999999999.99', '0.01', 'included_in_cost');
    }

    public function test_zero_expense_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        app(AccountingExpenseContract::class)->amounts('0', '0', 'none');
    }

    public function test_tax_with_none_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        app(AccountingExpenseContract::class)->amounts('1', '1', 'none');
    }

    public function test_other_payee_label_can_use_full_160_characters_and_normalized_claim(): void
    {
        $contract = app(AccountingExpenseContract::class);

        $a = ['company_id' => 1, 'payee_type' => 'other', 'payee_label' => str_repeat('a', 160), 'document_reference' => 'REF'];

        $b = [...$a, 'payee_label' => str_repeat('A', 160)];

        $this->assertSame($contract->claim($a), $contract->claim($b));
    }

    public function test_supplier_and_employee_identifiers_never_alias(): void
    {
        $contract = app(AccountingExpenseContract::class);

        $a = ['company_id' => 1, 'payee_type' => 'supplier', 'supplier_id' => 7, 'employee_id' => 7, 'payee_label' => '', 'document_reference' => 'REF'];

        $this->assertNotSame($contract->claim($a), $contract->claim([...$a, 'payee_type' => 'employee']));
    }
}

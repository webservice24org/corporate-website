<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Services\ERP\Accounting\Payroll\AccountingPayrollReadiness;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AccountingPayrollReadinessTest extends TestCase
{
    public function test_readiness_explicitly_reports_blocked_live_posting(): void
    {
        $report = app(AccountingPayrollReadiness::class)->report();
        $this->assertFalse($report['live_posting_enabled']);
        $this->assertSame('blocked_pending_native_payroll_contract', $report['status']);
        $this->assertContains('exact_decimal_native_calculation_and_rounding', $report['blockers']);
        $this->assertContains('native_salary_payment_and_allocation_ledger', $report['blockers']);
    }

    public function test_a_configuration_flag_cannot_enable_the_unimplemented_adapter(): void
    {
        config(['accounting.payroll.enabled' => true]);
        $this->expectException(ValidationException::class);
        app(AccountingPayrollReadiness::class)->assertLivePostingAvailable();
    }
}

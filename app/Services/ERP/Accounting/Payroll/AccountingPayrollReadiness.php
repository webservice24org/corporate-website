<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Payroll;

use Illuminate\Validation\ValidationException;

/** No flag, period status or salary structure can activate a financial write. */
final class AccountingPayrollReadiness
{
    /** @return array{milestone: string, live_posting_enabled: false, status: string, blockers: list<string>} */
    public function report(): array
    {
        return [
            'milestone' => '6.10',
            'live_posting_enabled' => false,
            'status' => 'blocked_pending_native_payroll_contract',
            'blockers' => [
                'immutable_approved_payroll_run_and_lines',
                'exact_decimal_native_calculation_and_rounding',
                'independent_native_approval_and_revision_proof',
                'typed_component_mapping_and_balanced_aggregate',
                'native_salary_payment_and_allocation_ledger',
                'reversal_dependencies_and_legacy_coverage',
                'source_transaction_admission_and_complete_replay_evidence',
                'restricted_payroll_detail_and_installed_schema_verification',
            ],
        ];
    }

    public function assertLivePostingAvailable(): never
    {
        throw ValidationException::withMessages([
            'payroll_accounting' => 'Live payroll accounting is disabled until the native payroll source, exact-decimal calculation, payment and reversal contracts are implemented and verified.',
        ]);
    }
}

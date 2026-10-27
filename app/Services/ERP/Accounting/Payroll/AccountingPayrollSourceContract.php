<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Payroll;

/** Future native payroll boundary. Deliberately unbound and unregistered. */
interface AccountingPayrollSourceContract
{
    /**
     * Return immutable approved aggregate source evidence, never recalculate current salary structures.
     * See docs/Phase6_10_Payroll_Accounting_Contract.md for the required payload and invariants.
     *
     * @return array<string, mixed>
     */
    public function approvedRun(int $companyId, int $runId): array;

    /** @return array<string, mixed> Exact native payment event, separate from salary recognition. */
    public function approvedSettlement(int $companyId, int $paymentId): array;

    /** @return array<string, mixed> Original operation dependency and full compensation evidence. */
    public function approvedReversal(int $companyId, int $reversalId): array;
}

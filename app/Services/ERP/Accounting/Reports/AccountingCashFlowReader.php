<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Models\User;
use Illuminate\Support\Facades\Validator;

/** Backend readiness/read model. No source capture, mapping writes, repair or financial mutation. */
final class AccountingCashFlowReader
{
    public const PERMISSION = 'erp.cash-flow.view';

    public function __construct(private readonly AccountingLedgerReader $ledger, private readonly AccountingCashFlowContract $contract) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function report(User $actor, int $companyId, array $filters): array
    {
        Validator::make($filters, ['account_id' => ['prohibited'], 'branch_id' => ['prohibited'], 'department_id' => ['prohibited']])->validate();
        $source = $this->ledger->cashFlowSource($actor, $companyId, $filters);

        return [...$this->contract->project($source['accounts'], $source['cash_flow_lines'], $source['cash_bindings'], $source['period_opening_movements'], $source['currency']), 'company' => $source['company'], 'currency' => $source['currency'], 'filters' => $source['filters'], 'journal_evidence_checked' => $source['journal_evidence_checked'], 'native_source_coverage_certified' => false, 'coverage_note' => $source['coverage_note']];
    }
}

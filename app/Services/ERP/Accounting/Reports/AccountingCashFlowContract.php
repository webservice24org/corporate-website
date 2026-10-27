<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Services\ERP\Accounting\AccountingDecimal;
use Illuminate\Validation\ValidationException;

/** Indirect reconciliation under declared roles. Ambiguous multi-activity cash journals never certify flows. */
final class AccountingCashFlowContract
{
    public function __construct(private readonly AccountingDecimal $decimal, private readonly AccountingProfitLossContract $profit) {}

    /** Verified snapshot input only; the reader supplies canonical dates, owned accounts and complete journals.
     * @param  list<array<string, mixed>>  $accounts
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $bindings
     * @param  array<int, array{debit: string, credit: string}>  $openingMovements
     * @return array<string, mixed>
     */
    public function project(array $accounts, array $lines, array $bindings, array $openingMovements, string $currency): array
    {
        $pnl = $this->profit->project($accounts, $openingMovements);
        $issues = $pnl['issues'];
        $warnings = [];
        $index = [];
        $cash = [];
        $bindingCounts = [];
        foreach ($bindings as $binding) {
            $account = (int) $binding['account_id'];
            $bindingCounts[$account] = ($bindingCounts[$account] ?? 0) + 1;
            $kind = (string) $binding['kind'];
            $role = (string) $binding['role'];
            if ($binding['currency'] !== $currency || ! in_array($binding['status'], ['active', 'inactive'], true) || ! in_array($kind, ['cash', 'bank'], true) || ! str_starts_with($role, $kind.':') || preg_match('/\A(?:cash|bank):[A-Z0-9][A-Z0-9_-]{0,29}\z/D', $role) !== 1) {
                $issues[] = ['issue' => 'cash_binding_identity_review', 'account_id' => $account, 'binding_id' => (int) $binding['id']];
            }
        }
        $beginning = '0.00';
        $closing = '0.00';
        $cashAccounts = [];
        foreach ($accounts as $account) {
            $id = (int) $account['id'];
            if (isset($index[$id])) {
                $this->fail('Duplicate accounts in a cash-flow snapshot are not valid.');
            }
            $index[$id] = $account;
            if (($account['cash_flow_role'] ?? null) !== 'cash') {
                continue;
            }
            $base = ['account_id' => $id, 'code' => (string) $account['code'], 'name' => (string) $account['name']];
            if ($account['category'] !== 'asset' || ! (bool) $account['is_postable'] || ($account['control_role'] ?? null) !== null || ($account['normal_side'] ?? null) !== 'debit') {
                $issues[] = [...$base, 'issue' => 'invalid_cash_classification'];

                continue;
            }
            $cash[$id] = true;
            $old = bcsub((string) $account['opening_debit'], (string) $account['opening_credit'], 2);
            $end = bcsub((string) $account['closing_debit'], (string) $account['closing_credit'], 2);
            $beginning = bcadd($beginning, $old, 2);
            $closing = bcadd($closing, $end, 2);
            if (bccomp($old, '0', 2) < 0 || bccomp($end, '0', 2) < 0) {
                $warnings[] = [...$base, 'issue' => 'cash_credit_balance_review'];
            }
            $cashAccounts[] = [...$base, 'status' => (string) $account['status'], 'beginning' => $old, 'closing' => $end];
            if (($bindingCounts[$id] ?? 0) !== 1) {
                $issues[] = [...$base, 'issue' => 'cash_binding_missing_or_ambiguous'];
            }
        }
        foreach ($bindings as $binding) {
            if (! isset($cash[(int) $binding['account_id']])) {
                $issues[] = ['issue' => 'binding_gl_not_in_declared_cash_scope', 'account_id' => (int) $binding['account_id'], 'binding_id' => (int) $binding['id']];
            }
        }
        if ($cash === []) {
            $issues[] = ['issue' => 'cash_scope_undefined'];
        }
        $journals = [];
        foreach ($lines as $line) {
            $account = (int) $line['account_id'];
            if (! isset($index[$account])) {
                $this->fail('A cash-flow line references an account outside the verified snapshot.');
            }
            $journal = (int) $line['journal_id'];
            $journals[$journal][] = $line;
        }
        $working = '0.00';
        $noncashAdjustment = '0.00';
        $reclassification = '0.00';
        $directOperating = '0.00';
        $investing = '0.00';
        $financing = '0.00';
        $economicCash = '0.00';
        $openingCash = '0.00';
        $periodCash = '0.00';
        $unallocated = '0.00';
        $noncashJournals = [];
        $cashJournals = [];
        $transferCount = 0;
        $seenMissing = [];
        foreach ($journals as $journalId => $journalLines) {
            $cashNet = '0.00';
            $cashGross = '0.00';
            $journalProfit = '0.00';
            $profitOperating = '0.00';
            $noncashLineCount = 0;
            $operatingBalance = '0.00';
            $noncashNet = '0.00';
            $unmappedNet = '0.00';
            $journalDebit = '0.00';
            $journalCredit = '0.00';
            $activity = ['operating' => '0.00', 'investing' => '0.00', 'financing' => '0.00'];
            $first = $journalLines[0];
            $isOpening = $first['entry_kind'] === 'opening';
            foreach ($journalLines as $line) {
                if ($line['date'] !== $first['date'] || $line['entry_kind'] !== $first['entry_kind']) {
                    $this->fail('A journal snapshot has inconsistent date or kind.');
                }
                $debit = $this->decimal->money($line['debit']);
                $credit = $this->decimal->money($line['credit']);
                if ((bccomp($debit, '0', 2) > 0) === (bccomp($credit, '0', 2) > 0)) {
                    $this->fail('Cash-flow lines require exactly one positive side.');
                }
                $journalDebit = bcadd($journalDebit, $debit, 2);
                $journalCredit = bcadd($journalCredit, $credit, 2);
                $id = (int) $line['account_id'];
                $account = $index[$id];
                $net = bcsub($credit, $debit, 2);
                if (isset($cash[$id])) {
                    $cashNet = bcsub($cashNet, $net, 2);
                    $cashGross = bcadd($cashGross, bcadd($debit, $credit, 2), 2);

                    continue;
                }
                $noncashLineCount++;
                if ($isOpening) {
                    continue;
                }
                $nominal = in_array($account['category'], ['revenue', 'expense'], true);
                if ($nominal) {
                    $journalProfit = bcadd($journalProfit, $net, 2);
                }
                $role = (string) ($account['cash_flow_role'] ?? '');
                if (! in_array($role, ['operating', 'investing', 'financing', 'noncash'], true)) {
                    if (! isset($seenMissing[$id])) {
                        $issues[] = ['issue' => 'active_account_cash_flow_role_missing_or_invalid', 'account_id' => $id, 'code' => (string) $account['code'], 'name' => (string) $account['name']];
                        $seenMissing[$id] = true;
                    }
                    $unmappedNet = bcadd($unmappedNet, $net, 2);

                    continue;
                }
                if (! (bool) $account['is_postable']) {
                    $issues[] = ['issue' => 'nonpostable_account_has_activity', 'account_id' => $id, 'journal_id' => $journalId];
                }
                if ($role === 'noncash') {
                    $noncashNet = bcadd($noncashNet, $net, 2);

                    continue;
                }
                $activity[$role] = bcadd($activity[$role], $net, 2);
                if ($role === 'operating') {
                    if ($nominal) {
                        $profitOperating = bcadd($profitOperating, $net, 2);
                    } else {
                        $operatingBalance = bcadd($operatingBalance, $net, 2);
                    }
                }
            }
            if (bccomp($journalDebit, $journalCredit, 2) !== 0) {
                $this->fail('A cash-flow journal is incomplete or unbalanced.');
            }
            $periodCash = bcadd($periodCash, $cashNet, 2);
            if ($isOpening) {
                $openingCash = bcadd($openingCash, $cashNet, 2);
                if (bccomp($cashGross, '0', 2) !== 0) {
                    $issues[] = ['issue' => 'in_period_cash_opening_requires_cutover_review', 'journal_id' => $journalId, 'amount' => $cashNet];
                }

                continue;
            }
            $economicCash = bcadd($economicCash, $cashNet, 2);
            $working = bcadd($working, $operatingBalance, 2);
            if (bccomp($cashGross, '0', 2) === 0) {
                $noncashAdjustment = bcsub($noncashAdjustment, bcadd($journalProfit, $operatingBalance, 2), 2);
                $noncashJournals[] = ['journal_id' => $journalId, 'date' => $first['date'], 'number' => $first['number'], 'profit_movement' => $journalProfit, 'operating_balance_adjustment' => $operatingBalance, 'investing_balance_change' => $activity['investing'], 'financing_balance_change' => $activity['financing']];

                continue;
            }
            $reclassification = bcadd($reclassification, bcsub($profitOperating, $journalProfit, 2), 2);
            $directOperating = bcadd($directOperating, $activity['operating'], 2);
            $investing = bcadd($investing, $activity['investing'], 2);
            $financing = bcadd($financing, $activity['financing'], 2);
            $unallocated = bcadd($unallocated, bcadd($noncashNet, $unmappedNet, 2), 2);
            $active = array_filter($activity, static fn (string $amount): bool => bccomp($amount, '0', 2) !== 0);
            if (count($active) > 1) {
                $issues[] = ['issue' => 'multi_activity_cash_journal_requires_native_split', 'journal_id' => $journalId, 'amount' => $cashNet];
            }
            if (bccomp($noncashNet, '0', 2) !== 0) {
                $issues[] = ['issue' => 'mixed_cash_noncash_net_requires_native_split', 'journal_id' => $journalId, 'amount' => $noncashNet];
            }
            if ($noncashLineCount === 0) {
                $transferCount++;
            }
            $cashJournals[] = ['journal_id' => $journalId, 'date' => $first['date'], 'number' => $first['number'], 'cash_movement' => $cashNet, 'operating' => $activity['operating'], 'investing' => $activity['investing'], 'financing' => $activity['financing']];
        }
        $profit = (string) $pnl['provisional_result'];
        $operating = bcadd(bcadd(bcadd($profit, $working, 2), $noncashAdjustment, 2), $reclassification, 2);
        $net = bcadd(bcadd($operating, $investing, 2), $financing, 2);
        $snapshotDifference = bcsub(bcsub($closing, $beginning, 2), $periodCash, 2);
        $indirectDifference = bcsub($operating, $directOperating, 2);
        $difference = bcsub(bcsub(bcsub($closing, $beginning, 2), $openingCash, 2), $net, 2);
        if (bccomp($snapshotDifference, '0', 2) !== 0) {
            $issues[] = ['issue' => 'cash_snapshot_movement_mismatch', 'amount' => $snapshotDifference];
        }
        if (bccomp($indirectDifference, '0', 2) !== 0) {
            $issues[] = ['issue' => 'indirect_operating_bridge_mismatch', 'amount' => $indirectDifference];
        }
        if (bccomp($difference, '0', 2) !== 0) {
            $issues[] = ['issue' => 'cash_reconciliation_difference', 'amount' => $difference];
        }
        $flows = ['operating' => $operating, 'investing' => $investing, 'financing' => $financing, 'net_change' => $net];
        $complete = $issues === [];

        return ['method' => 'indirect', 'classification_basis' => 'current_account_roles_with_complete_journal_review', 'complete' => $complete, 'confirmed_flows' => $complete ? $flows : null, 'provisional_flows' => $flows, 'issues' => $issues, 'warnings' => $warnings, 'cash_accounts' => $cashAccounts, 'beginning_cash' => $beginning, 'closing_cash' => $closing, 'opening_cash_movements' => $openingCash, 'economic_cash_movement' => $economicCash, 'unallocated_cash_counterparts' => $unallocated, 'reconciliation_difference' => $difference, 'snapshot_difference' => $snapshotDifference, 'indirect_operating_difference' => $indirectDifference, 'indirect_bridge' => ['period_result' => $profit, 'operating_balance_adjustments' => $working, 'other_noncash_adjustments' => $noncashAdjustment, 'earnings_reclassification' => $reclassification, 'operating_cash' => $operating], 'noncash_journals' => $noncashJournals, 'cash_journals' => $cashJournals, 'cash_transfer_count' => $transferCount];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['cash_flow' => $message]);
    }
}

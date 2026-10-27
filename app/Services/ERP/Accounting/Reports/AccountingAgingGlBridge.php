<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

/** Source recipes to verified posted GL controls, including retired mappings and unassigned lines. */
final class AccountingAgingGlBridge
{
    /** @param list<array<string, mixed>> $expected @param list<array<string, mixed>> $actual
     * @param  array<int, array<string, bool>>  $roleAccounts  @return array<string, mixed>
     */
    public function reconcile(string $kind, array $expected, array $actual, array $roleAccounts, string $asOf, string $documentTotal): array
    {
        $main = $kind === 'ar' ? 'trade_ar' : 'trade_ap';
        $fund = $kind === 'ar' ? 'unapplied_customer_funds' : 'supplier_advances';
        $party = $kind === 'ar' ? 'customer' : 'supplier';
        $want = [];
        $have = [];
        $issues = [];
        $source = [$main => '0.00', $fund => '0.00'];
        $gl = $source;
        $present = [];
        foreach ($roleAccounts as $set) {
            foreach (array_keys($set) as $role) {
                $present[$role] = true;
            }
        }
        foreach ([$main, $fund] as $role) {
            if (! isset($present[$role])) {
                $issues[] = ['issue' => 'control_mapping_missing', 'role' => $role];
            }
        }
        foreach ($expected as $line) {
            if ($line['date'] > $asOf) {
                continue;
            } $role = $line['role'];
            if (! array_key_exists($role, $source) || ! isset($roleAccounts[$line['account_id']][$role])) {
                $issues[] = ['issue' => 'expected_control_mapping_mismatch', 'account_id' => $line['account_id']];

                continue;
            }
            $key = $this->key($line);
            $previous = $want[$key] ?? ['debit' => '0.00', 'credit' => '0.00'];
            $want[$key] = ['debit' => bcadd($previous['debit'], $line['debit'], 2), 'credit' => bcadd($previous['credit'], $line['credit'], 2)];
            $source[$role] = bcadd($source[$role], $this->delta($role, $line['debit'], $line['credit']), 2);
        }
        foreach ($actual as $line) {
            if ($line['date'] > $asOf || ! isset($roleAccounts[$line['account_id']])) {
                continue;
            }
            $set = $roleAccounts[$line['account_id']];
            if (count($set) !== 1) {
                $issues[] = ['issue' => 'control_role_ambiguous', 'account_id' => $line['account_id']];

                continue;
            }
            $role = array_key_first($set);
            if (! array_key_exists($role, $gl)) {
                $issues[] = ['issue' => 'unsupported_control_role'];

                continue;
            }
            $gl[$role] = bcadd($gl[$role], $this->delta($role, $line['debit'], $line['credit']), 2);
            if (($line['party_type'] ?? null) !== $party || ! is_int($line['party_id'] ?? null) || $line['party_id'] < 1) {
                $issues[] = ['issue' => 'unassigned_control_line', 'journal_id' => $line['journal_id']];
            }
            $key = $this->key($line);
            $previous = $have[$key] ?? ['debit' => '0.00', 'credit' => '0.00'];
            $have[$key] = ['debit' => bcadd($previous['debit'], $line['debit'], 2), 'credit' => bcadd($previous['credit'], $line['credit'], 2)];
            if ($line['entry_kind'] !== 'automatic' || ! isset($want[$key])) {
                $issues[] = ['issue' => $line['entry_kind'] === 'opening' ? 'opening_control_coverage_not_integrated' : 'unsupported_control_membership', 'journal_id' => $line['journal_id']];
            }
        }
        foreach (array_unique([...array_keys($want), ...array_keys($have)]) as $key) {
            if (($want[$key] ?? ['debit' => '0.00', 'credit' => '0.00']) !== ($have[$key] ?? ['debit' => '0.00', 'credit' => '0.00'])) {
                $issues[] = ['issue' => 'source_gl_membership_mismatch'];
            }
        }
        $difference = [$main => bcsub($gl[$main], $source[$main], 2), $fund => bcsub($gl[$fund], $source[$fund], 2)];
        $documentDifference = bcsub($source[$main], $documentTotal, 2);
        if (bccomp($documentDifference, '0', 2) !== 0) {
            $issues[] = ['issue' => 'document_position_control_mismatch', 'amount' => $documentDifference];
        }
        if (bccomp($source[$fund], '0', 2) < 0) {
            $issues[] = ['issue' => 'negative_unapplied_funds_or_advances'];
        }

        return ['source' => $source, 'gl' => $gl, 'difference' => $difference, 'document_difference' => $documentDifference, 'separate_unapplied_or_advance_balance' => $source[$fund], 'reconciled' => $issues === [] && $source === $gl, 'issues' => $issues];
    }

    private function delta(string $role, string $debit, string $credit): string
    {
        return in_array($role, ['trade_ar', 'supplier_advances'], true) ? bcsub($debit, $credit, 2) : bcsub($credit, $debit, 2);
    }

    /** @param array<string, mixed> $line */
    private function key(array $line): string
    {
        return $line['journal_id'].':'.$line['account_id'].':'.($line['party_id'] ?? 0).':'.($line['source_kind'] ?? '').':'.($line['source_id'] ?? 0);
    }
}

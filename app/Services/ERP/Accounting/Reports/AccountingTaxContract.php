<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Services\ERP\Accounting\AccountingDecimal;
use Illuminate\Validation\ValidationException;

/** Configured tax GL amounts and native line membership. No rate calculation, return netting or filing authority. */
final class AccountingTaxContract
{
    public function __construct(private readonly AccountingDecimal $decimal) {}

    /** @param list<array<string, mixed>> $accounts @param list<array<string, mixed>> $actual
     * @param  list<array<string, mixed>>  $expected  @param array<int, array<string, bool>> $roles
     * @return array<string, mixed>
     */
    public function project(array $accounts, array $actual, array $expected, array $roles, string $from, string $to): array
    {
        $index = [];
        foreach ($accounts as $a) {
            $index[(int) $a['id']] = $a;
        }
        $issues = [];
        $present = [];
        $accountRows = [];
        $gl = [];
        $source = [];
        foreach (['input_tax', 'output_tax'] as $role) {
            $gl[$role] = $this->empty();
            $source[$role] = $this->empty();
        }
        foreach ($roles as $id => $set) {
            foreach (array_keys($set) as $role) {
                $present[$role] = true;
            }
            if (! isset($index[$id]) || count($set) !== 1) {
                $issues[] = ['issue' => 'tax_account_missing_or_role_ambiguous', 'account_id' => $id];

                continue;
            }
            $role = array_key_first($set);
            $a = $index[$id];
            if (! isset($gl[$role])) {
                $issues[] = ['issue' => 'unsupported_tax_role', 'account_id' => $id];

                continue;
            }
            $input = $role === 'input_tax';
            if ($a['category'] !== ($input ? 'asset' : 'liability') || $a['statement_role'] !== ($input ? 'current_asset' : 'current_liability') || $a['normal_side'] !== ($input ? 'debit' : 'credit') || ! (bool) $a['is_postable'] || ($a['cash_flow_role'] ?? null) === 'cash' || ($a['control_role'] ?? null) !== null) {
                $issues[] = ['issue' => 'tax_account_classification_review', 'account_id' => $id];
            }
            $accountRows[$id] = ['account_id' => $id, 'code' => $a['code'], 'name' => $a['name'], 'status' => $a['status'], 'role' => $role, ...$this->empty()];
        }
        foreach (['input_tax', 'output_tax'] as $role) {
            if (! isset($present[$role])) {
                $issues[] = ['issue' => 'tax_mapping_missing', 'role' => $role];
            }
        }
        $want = [];
        $have = [];
        $rows = [];
        $rowKeys = [];
        $unclassified = ['count' => 0, 'debit' => '0.00', 'credit' => '0.00'];
        foreach ($expected as $line) {
            if ($line['date'] > $to) {
                continue;
            } $role = $line['role'];
            if (! isset($source[$role]) || ! isset($roles[$line['account_id']][$role])) {
                $issues[] = ['issue' => 'source_tax_mapping_mismatch', 'account_id' => $line['account_id']];

                continue;
            }
            $line = $this->amounts($line);
            $source[$role] = $this->add($source[$role], $line, $from, $role);
            $key = $this->key($line);
            $prev = $want[$key] ?? ['debit' => '0.00', 'credit' => '0.00'];
            $want[$key] = ['debit' => bcadd($prev['debit'], $line['debit'], 2), 'credit' => bcadd($prev['credit'], $line['credit'], 2)];
        }
        foreach ($actual as $line) {
            $id = (int) $line['account_id'];
            if ($line['date'] > $to || ! isset($roles[$id])) {
                continue;
            }
            $line = $this->amounts($line);
            if (! isset($accountRows[$id])) {
                $unclassified['count']++;
                $unclassified['debit'] = bcadd($unclassified['debit'], $line['debit'], 2);
                $unclassified['credit'] = bcadd($unclassified['credit'], $line['credit'], 2);
                if ($line['date'] >= $from) {
                    $rows[] = ['date' => $line['date'], 'journal_id' => $line['journal_id'], 'number' => $line['number'], 'account_id' => $id, 'account_code' => $index[$id]['code'] ?? '#'.$id, 'role' => 'unclassified', 'debit' => $line['debit'], 'credit' => $line['credit'], 'native_recipe_matched' => false];
                }

                continue;
            }
            $role = $accountRows[$id]['role'];
            $gl[$role] = $this->add($gl[$role], $line, $from, $role);
            $updated = $this->add($accountRows[$id], $line, $from, $role);
            $accountRows[$id] = [...$accountRows[$id], ...$updated];
            $key = $this->key($line);
            $prev = $have[$key] ?? ['debit' => '0.00', 'credit' => '0.00'];
            $have[$key] = ['debit' => bcadd($prev['debit'], $line['debit'], 2), 'credit' => bcadd($prev['credit'], $line['credit'], 2)];
            $supported = $line['entry_kind'] === 'automatic' && isset($want[$key]);
            if (! $supported) {
                $issues[] = ['issue' => $line['entry_kind'] === 'opening' ? 'opening_tax_coverage_not_integrated' : 'tax_adjustment_or_source_unverified', 'journal_id' => $line['journal_id'], 'account_id' => $id];
            }
            if ($line['date'] >= $from) {
                $rows[] = ['date' => $line['date'], 'journal_id' => $line['journal_id'], 'number' => $line['number'], 'account_id' => $id, 'account_code' => $accountRows[$id]['code'], 'role' => $role, 'debit' => $line['debit'], 'credit' => $line['credit'], 'native_recipe_matched' => $supported];
                $rowKeys[array_key_last($rows)] = $key;
            }
        }
        foreach (array_unique([...array_keys($want), ...array_keys($have)]) as $key) {
            if (($want[$key] ?? ['debit' => '0.00', 'credit' => '0.00']) !== ($have[$key] ?? ['debit' => '0.00', 'credit' => '0.00'])) {
                $issues[] = ['issue' => 'tax_source_gl_membership_mismatch'];
            }
        }
        foreach ($rowKeys as $offset => $key) {
            $rows[$offset]['native_recipe_matched'] = $rows[$offset]['native_recipe_matched'] && ($want[$key] ?? null) === ($have[$key] ?? null);
        }
        $difference = [];
        foreach (['input_tax', 'output_tax'] as $role) {
            $difference[$role] = [];
            foreach (['opening', 'period_debit', 'period_credit', 'closing'] as $field) {
                $difference[$role][$field] = bcsub($gl[$role][$field], $source[$role][$field], 2);
            }
        }
        $complete = $issues === [] && $gl === $source;

        return ['gl' => $gl, 'source' => $source, 'difference' => $difference, 'unclassified_lines' => $unclassified, 'accounts' => array_values($accountRows), 'rows' => $rows, 'issues' => $issues, 'complete' => $complete, 'confirmed_balances' => $complete ? $gl : null, 'arithmetic_output_less_input' => bcsub($gl['output_tax']['closing'], $gl['input_tax']['closing'], 2)];
    }

    /** @return array{opening: string, period_debit: string, period_credit: string, closing: string} */
    private function empty(): array
    {
        return ['opening' => '0.00', 'period_debit' => '0.00', 'period_credit' => '0.00', 'closing' => '0.00'];
    }

    /** @param array<string, mixed> $total @param array<string, mixed> $line @return array<string, string> */
    private function add(array $total, array $line, string $from, string $role): array
    {
        $delta = $role === 'input_tax' ? bcsub($line['debit'], $line['credit'], 2) : bcsub($line['credit'], $line['debit'], 2);
        $result = ['opening' => $total['opening'], 'period_debit' => $total['period_debit'], 'period_credit' => $total['period_credit'], 'closing' => bcadd($total['closing'], $delta, 2)];
        if ($line['date'] < $from) {
            $result['opening'] = bcadd($result['opening'], $delta, 2);
        } else {
            $result['period_debit'] = bcadd($result['period_debit'], $line['debit'], 2);
            $result['period_credit'] = bcadd($result['period_credit'], $line['credit'], 2);
        }

        return $result;
    }

    /** @param array<string, mixed> $line @return array<string, mixed> */
    private function amounts(array $line): array
    {
        $d = $this->decimal->money($line['debit']);
        $c = $this->decimal->money($line['credit']);
        if ((bccomp($d, '0', 2) > 0) === (bccomp($c, '0', 2) > 0)) {
            throw ValidationException::withMessages(['tax_report' => 'A tax line needs exactly one positive side.']);
        }

        return [...$line, 'debit' => $d, 'credit' => $c];
    }

    /** @param array<string, mixed> $line */
    private function key(array $line): string
    {
        return $line['journal_id'].':'.$line['account_id'].':'.($line['source_kind'] ?? '').':'.($line['source_id'] ?? 0);
    }
}

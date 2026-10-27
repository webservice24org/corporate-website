<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\YearEnd;

use App\Services\ERP\Accounting\AccountingDecimal;
use App\Services\ERP\Accounting\AccountingJournalAmounts;
use Illuminate\Validation\ValidationException;

/** Clears nominal balances only; never creates an opening, cash, tax or control-account entry. */
final class AccountingYearEndContract
{
    public function __construct(private readonly AccountingDecimal $decimal, private readonly AccountingJournalAmounts $amounts) {}

    /** @param list<array<string, mixed>> $accounts @param array<int, array{debit: string, credit: string}> $openings @return array<string, mixed> */
    public function plan(array $accounts, array $openings, int $retained): array
    {
        $lines = [];
        $result = '0.00';
        $found = false;
        $seen = [];
        foreach ($accounts as $account) {
            $id = (int) $account['id'];
            if (isset($seen[$id])) {
                $this->fail('Duplicate account in year-end snapshot.');
            } $seen[$id] = true;
            if ($id === $retained) {
                $found = $account['category'] === 'equity' && $account['statement_role'] === 'retained_earnings' && $account['control_role'] === 'retained_earnings' && $account['normal_side'] === 'credit' && $account['status'] === 'active' && $account['is_postable'] && $account['cash_flow_role'] !== 'cash';
            }
            if (! in_array($account['category'], ['revenue', 'expense'], true)) {
                continue;
            }
            $opening = $openings[$id] ?? ['debit' => '0.00', 'credit' => '0.00'];
            foreach ([$account['opening_debit'], $account['opening_credit'], $opening['debit'], $opening['credit']] as $value) {
                if (bccomp($this->decimal->money($value), '0', 2) !== 0) {
                    $this->fail('Prior nominal balances and nominal opening journals require a separate cutover/prior-year review.');
                }
            }
            $debit = $this->decimal->money($account['closing_debit']);
            $credit = $this->decimal->money($account['closing_credit']);
            $net = bcsub($credit, $debit, 2);
            if ($net === '0.00') {
                continue;
            }
            if (! $account['is_postable'] || $account['cash_flow_role'] === 'cash' || $id === $retained || ! in_array($account['status'], ['active', 'inactive'], true)) {
                $this->fail('A nominal balance has an invalid posting classification.');
            }
            $result = bcadd($result, $net, 2);
            $lines[] = $this->line($id, bccomp($net, '0', 2) > 0 ? $net : '0.00', bccomp($net, '0', 2) < 0 ? bcsub('0', $net, 2) : '0.00');
        }
        if (! $found) {
            $this->fail('Use the active, company-owned retained-earnings equity account from the recorded posting profile.');
        }
        if (count($lines) > 199) {
            $this->fail('Year-end requires a reviewed batch writer for more than 199 nominal accounts.');
        }
        if ($result !== '0.00') {
            $lines[] = $this->line($retained, bccomp($result, '0', 2) < 0 ? bcsub('0', $result, 2) : '0.00', bccomp($result, '0', 2) > 0 ? $result : '0.00');
        }
        foreach ($lines as $i => &$line) {
            $line['line_number'] = $i + 1;
        } unset($line);
        if ($lines !== []) {
            $this->amounts->balanced($lines);
        }

        return ['lines' => $lines, 'result' => $result, 'retained_account_id' => $retained, 'no_journal_reason' => $lines === [] ? 'zero_nominal_balances' : null];
    }

    /** @param array<string, mixed> $original @return array<string, mixed> */
    public function reverse(array $original): array
    {
        $lines = [];
        foreach ($original['lines'] as $line) {
            $lines[] = [...$line, 'debit' => $line['credit'], 'credit' => $line['debit']];
        }
        if ($lines !== []) {
            $this->amounts->balanced($lines);
        }

        return [...$original, 'lines' => $lines, 'result' => bcsub('0', $original['result'], 2)];
    }

    /** @return array<string, mixed> */
    private function line(int $account, string $debit, string $credit): array
    {
        return ['line_number' => 0, 'account_id' => $account, 'debit' => $debit, 'credit' => $credit, 'party_type' => null, 'party_id' => null, 'source_line_type' => null, 'source_line_id' => null, 'cash_account_id' => null, 'branch_id' => null, 'department_id' => null, 'description' => null];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['year_end' => $message]);
    }
}

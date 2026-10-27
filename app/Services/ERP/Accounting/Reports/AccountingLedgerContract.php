<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Services\ERP\Accounting\AccountingDecimal;
use Illuminate\Validation\ValidationException;

/** Debit-positive signed balances; aggregate arithmetic is not limited to one journal's precision. */
final class AccountingLedgerContract
{
    public function __construct(private readonly AccountingDecimal $decimal) {}

    /** @return array{debit: string, credit: string} */
    public function sides(string $net): array
    {
        return bccomp($net, '0', 2) < 0
            ? ['debit' => '0.00', 'credit' => bcsub('0', $net, 2)]
            : ['debit' => bcadd($net, '0', 2), 'credit' => '0.00'];
    }

    /** Input ordered by accounting date, journal ID, line number, line ID.
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    public function ledger(array $lines, string $from, string $to, int $page = 1, int $perPage = 25, bool $allRows = false): array
    {
        if ($page < 1 || $perPage < 1 || $perPage > 100) {
            throw ValidationException::withMessages(['page' => 'Use a positive page and 1–100 rows per page.']);
        }
        $opening = '0.00';
        $debit = '0.00';
        $credit = '0.00';
        $running = '0.00';
        $rows = [];
        foreach ($lines as $line) {
            $date = (string) $line['date'];
            $d = $this->decimal->money($line['debit']);
            $c = $this->decimal->money($line['credit']);
            if ((bccomp($d, '0', 2) > 0) === (bccomp($c, '0', 2) > 0)) {
                throw ValidationException::withMessages(['ledger' => 'A posted line requires exactly one positive side.']);
            }
            if ($date > $to) {
                continue;
            }
            $running = bcadd($running, bcsub($d, $c, 2), 2);
            if ($date < $from) {
                $opening = $running;

                continue;
            }
            $debit = bcadd($debit, $d, 2);
            $credit = bcadd($credit, $c, 2);
            $rows[] = [...$line, 'debit' => $d, 'credit' => $c, 'balance' => $running, 'balance_sides' => $this->sides($running)];
        }
        $total = count($rows);
        $last = max(1, (int) ceil($total / $perPage));

        return [
            'opening' => $opening, 'opening_sides' => $this->sides($opening),
            'period_debit' => $debit, 'period_credit' => $credit,
            'closing' => $running, 'closing_sides' => $this->sides($running),
            'data' => $allRows ? $rows : array_slice($rows, ($page - 1) * $perPage, $perPage),
            'page' => ['current_page' => $page, 'last_page' => $last, 'per_page' => $perPage, 'total' => $total],
        ];
    }

    /** @param list<array<string, mixed>> $accounts
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    public function trial(array $accounts, array $lines, string $from, string $to): array
    {
        $groups = [];
        foreach ($accounts as $account) {
            $groups[(int) $account['id']] = [];
        }
        foreach ($lines as $line) {
            $id = (int) $line['account_id'];
            if (! array_key_exists($id, $groups)) {
                throw ValidationException::withMessages(['trial_balance' => 'A posted line references an account outside the company scope.']);
            }
            $groups[$id][] = $line;
        }
        $totals = array_fill_keys(['opening_debit', 'opening_credit', 'period_debit', 'period_credit', 'closing_debit', 'closing_credit'], '0.00');
        $rows = [];
        foreach ($accounts as $account) {
            $book = $this->ledger($groups[(int) $account['id']], $from, $to);
            $values = ['opening_debit' => $book['opening_sides']['debit'], 'opening_credit' => $book['opening_sides']['credit'], 'period_debit' => $book['period_debit'], 'period_credit' => $book['period_credit'], 'closing_debit' => $book['closing_sides']['debit'], 'closing_credit' => $book['closing_sides']['credit']];
            foreach ($values as $key => $value) {
                $totals[$key] = bcadd($totals[$key], $value, 2);
            }
            $rows[] = [...$account, ...$values];
        }
        $differences = ['opening' => bcsub($totals['opening_debit'], $totals['opening_credit'], 2), 'period' => bcsub($totals['period_debit'], $totals['period_credit'], 2), 'closing' => bcsub($totals['closing_debit'], $totals['closing_credit'], 2)];

        return ['accounts' => $rows, 'totals' => $totals, 'differences' => $differences, 'balanced' => count(array_filter($differences, static fn (string $v): bool => bccomp($v, '0', 2) !== 0)) === 0];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Books;

use App\Services\ERP\Accounting\AccountingDecimal;
use Illuminate\Validation\ValidationException;

/** One owned finance binding/currency; signed balances, exact gross movements and stable dated ordering. */
final class AccountingCashBookContract
{
    public const VERSION = 'cash-book.v1';

    public function __construct(private readonly AccountingDecimal $decimal) {}

    /** @param array<array-key, array<string, mixed>> $lines @return array<string, mixed> */
    public function project(array $lines, string $from, string $to, int $page = 1, int $perPage = 25, bool $allRows = false): array
    {
        $this->date($from);
        $this->date($to);
        if ($from > $to || $page < 1 || $perPage < 1 || $perPage > 100 || ! array_is_list($lines)) {
            $this->fail('Use an inclusive date range, positive page and page size from one to one hundred.');
        }
        $seen = [];
        $scope = null;
        foreach ($lines as &$line) {
            foreach (['company_id', 'binding_id', 'journal_id', 'journal_line_id'] as $field) {
                if (! is_int($line[$field] ?? null) || $line[$field] < 1) {
                    $this->fail('Book identities must be positive normalized integers.');
                }
            }
            if (! is_string($line['date'] ?? null) || ! is_string($line['currency'] ?? null) || ! preg_match('/\A[A-Z]{3}\z/', $line['currency']) || ! is_bool($line['verified'] ?? null)) {
                $this->fail('A book line requires a real date, currency and verification state.');
            }
            $this->date($line['date']);
            $line['debit'] = $this->decimal->money($line['debit'] ?? null);
            $line['credit'] = $this->decimal->money($line['credit'] ?? null);
            if ((bccomp($line['debit'], '0', 2) > 0) === (bccomp($line['credit'], '0', 2) > 0)) {
                $this->fail('Each book line requires exactly one positive side.');
            }
            foreach (['number', 'source_kind', 'event', 'source_url', 'journal_url'] as $field) {
                if (! array_key_exists($field, $line) || ($line[$field] !== null && ! is_string($line[$field]))) {
                    $this->fail('Unsafe book display projection.');
                }
            }
            foreach (['source_id', 'recorded_binding_id'] as $field) {
                if (! array_key_exists($field, $line) || ($line[$field] !== null && (! is_int($line[$field]) || $line[$field] < 1))) {
                    $this->fail('Invalid source/binding projection.');
                }
            }
            $identity = $line['company_id'].':'.$line['binding_id'].':'.$line['currency'];
            if (($scope !== null && $scope !== $identity) || isset($seen[$line['journal_line_id']])) {
                $this->fail('A book cannot mix bindings, companies, currencies or duplicate journal lines.');
            }
            $scope = $identity;
            $seen[$line['journal_line_id']] = true;
        }
        unset($line);
        usort($lines, static fn (array $a, array $b): int => [$a['date'], $a['journal_id'], $a['journal_line_id']] <=> [$b['date'], $b['journal_id'], $b['journal_line_id']]);
        $posted = $this->zero();
        $verified = $this->zero();
        $rows = [];
        foreach ($lines as $line) {
            if ($line['date'] > $to) {
                continue;
            }
            $delta = bcsub($line['debit'], $line['credit'], 2);
            $posted = $this->add($posted, $line, $delta, $line['date'] < $from);
            if ($line['verified']) {
                $verified = $this->add($verified, $line, $delta, $line['date'] < $from);
            }
            if ($line['date'] < $from) {
                continue;
            }
            $row = [];
            foreach (['journal_id', 'journal_line_id', 'recorded_binding_id', 'date', 'number', 'source_kind', 'source_id', 'event', 'source_url', 'journal_url', 'debit', 'credit', 'verified'] as $field) {
                $row[$field] = $line[$field];
            }
            $rows[] = [...$row, 'movement' => $delta, 'posted_balance' => $posted['closing'], 'verified_balance' => $verified['closing']];
        }

        return [
            'contract_version' => self::VERSION, 'from' => $from, 'to' => $to,
            'posted_gl' => $posted, 'source_verified' => $verified,
            'difference' => ['opening' => bcsub($posted['opening'], $verified['opening'], 2), 'debits' => bcsub($posted['debits'], $verified['debits'], 2), 'credits' => bcsub($posted['credits'], $verified['credits'], 2), 'closing' => bcsub($posted['closing'], $verified['closing'], 2)],
            'rows' => $allRows ? $rows : array_slice($rows, ($page - 1) * $perPage, $perPage),
            'pagination' => ['current_page' => $page, 'per_page' => $perPage, 'total' => count($rows), 'last_page' => max(1, (int) ceil(count($rows) / $perPage))],
        ];
    }

    /** @return array{opening: string, debits: string, credits: string, closing: string} */
    private function zero(): array
    {
        return ['opening' => '0.00', 'debits' => '0.00', 'credits' => '0.00', 'closing' => '0.00'];
    }

    /** @param array{opening: string, debits: string, credits: string, closing: string} $total
     * @param  array<string, mixed>  $line
     * @return array{opening: string, debits: string, credits: string, closing: string}
     */
    private function add(array $total, array $line, string $delta, bool $opening): array
    {
        $total['closing'] = bcadd($total['closing'], $delta, 2);
        if ($opening) {
            $total['opening'] = bcadd($total['opening'], $delta, 2);
        } else {
            $total['debits'] = bcadd($total['debits'], $line['debit'], 2);
            $total['credits'] = bcadd($total['credits'], $line['credit'], 2);
        }

        return $total;
    }

    private function date(string $value): void
    {
        if (! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value) || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) || $value < '1900-01-01') {
            $this->fail('Use a real YYYY-MM-DD date from 1900 onward.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['cash_book' => $message]);
    }
}

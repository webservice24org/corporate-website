<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use Illuminate\Validation\ValidationException;

/** Pure exact-string arithmetic, independent of database DECIMAL implementations. */
final class AccountingJournalAmounts
{
    public function __construct(private readonly AccountingDecimal $decimal) {}

    /** @param array<array-key, mixed> $lines
     * @return array{total_debit: string, total_credit: string, amounts: list<array{debit: string, credit: string}>}
     */
    public function balanced(array $lines): array
    {
        if (! array_is_list($lines) || count($lines) < 2 || count($lines) > 200) {
            throw ValidationException::withMessages(['lines' => 'Provide an ordered list of 2 to 200 journal lines.']);
        }
        $amounts = [];
        $debits = [];
        $credits = [];
        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                throw ValidationException::withMessages(['lines.'.$index => 'A journal line must contain a debit and credit.']);
            }
            $debit = $this->side($line['debit'] ?? null, 'lines.'.$index.'.debit');
            $credit = $this->side($line['credit'] ?? null, 'lines.'.$index.'.credit');
            if ((bccomp($debit, '0', 2) > 0) === (bccomp($credit, '0', 2) > 0)) {
                throw ValidationException::withMessages(['lines.'.$index => 'Each line must have exactly one positive debit or credit.']);
            }
            $amounts[] = ['debit' => $debit, 'credit' => $credit];
            $debits[] = $debit;
            $credits[] = $credit;
        }
        try {
            $debit = $this->decimal->total($debits);
            $credit = $this->decimal->total($credits);
        } catch (ValidationException) {
            throw ValidationException::withMessages(['lines' => 'Journal totals exceed the supported DECIMAL(20,2) range.']);
        }
        if (bccomp($debit, $credit, 2) !== 0) {
            throw ValidationException::withMessages(['lines' => 'Journal debit and credit totals must be exactly equal.']);
        }

        return ['total_debit' => $debit, 'total_credit' => $credit, 'amounts' => $amounts];
    }

    private function side(mixed $value, string $field): string
    {
        try {
            return $this->decimal->money($value);
        } catch (ValidationException) {
            throw ValidationException::withMessages([$field => 'Use an unsigned exact decimal with at most 18 integer digits and two decimals; floats, signs, exponents and excess precision are not accepted.']);
        }
    }
}

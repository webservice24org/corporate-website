<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Statements;

use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingDecimal;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Signed bank asset balances; money_in increases and money_out decreases the balance. */
final class AccountingBankStatementContract
{
    public const VERSION = 'bank-statement.v1';

    public const MAX_ROWS = 1000;

    public const MAX_BYTES = 524288;

    public const PERMISSIONS = ['erp.bank-statements.view', 'erp.bank-statements.import', 'erp.bank-statements.create', 'erp.bank-statements.export'];

    public const COLUMNS = ['date', 'value_date', 'transaction_id', 'reference', 'description', 'money_in', 'money_out', 'balance'];

    public function __construct(private readonly AccountingDecimal $decimal, private readonly AccountingCanonicalPayload $canonical) {}

    public function signed(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/\A-?[0-9]{1,18}(?:\.[0-9]{1,2})?\z/', $value)) {
            $this->fail('Use exact signed balance strings with at most 18 integer digits and two decimals.');
        }
        $negative = str_starts_with($value, '-');
        $absolute = $this->decimal->money($negative ? substr($value, 1) : $value);

        return $negative && bccomp($absolute, '0', 2) !== 0 ? '-'.$absolute : $absolute;
    }

    /** @param array<string, mixed> $metadata @param array<int|string, array<string, mixed>> $lines @return array<string, mixed> */
    public function normalize(array $metadata, array $lines): array
    {
        $this->keys($metadata, ['company_id', 'cash_account_id', 'statement_reference', 'currency_code', 'starts_on', 'ends_on', 'opening_balance', 'closing_balance']);
        $data = Validator::make($metadata, [
            'company_id' => ['required', 'integer', 'min:1'], 'cash_account_id' => ['required', 'integer', 'min:1'],
            'statement_reference' => ['required', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_.:\/-]{0,79}\z/'],
            'currency_code' => ['required', 'string', 'regex:/\A[A-Z]{3}\z/'],
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2999-12-31'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on', 'before_or_equal:2999-12-31'],
            'opening_balance' => ['required'], 'closing_balance' => ['required'],
        ])->validate();
        $data['company_id'] = (int) $data['company_id'];
        $data['cash_account_id'] = (int) $data['cash_account_id'];
        $data['statement_reference'] = strtoupper($data['statement_reference']);
        $data['opening_balance'] = $this->signed($data['opening_balance']);
        $data['closing_balance'] = $this->signed($data['closing_balance']);
        if (! array_is_list($lines) || count($lines) > self::MAX_ROWS) {
            $this->fail('Supply a list of at most 1,000 statement rows.');
        }
        $normalized = [];
        $seen = [];
        $transactionIds = [];
        $running = $data['opening_balance'];
        $incoming = '0.00';
        $outgoing = '0.00';
        $previous = $data['starts_on'];
        foreach ($lines as $index => $line) {
            $this->keys($line, self::COLUMNS);
            $number = $index + 1;
            $valid = Validator::make($line, [
                'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$data['starts_on'], 'before_or_equal:'.$data['ends_on']],
                'value_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2999-12-31'],
                'transaction_id' => ['nullable', 'string', 'max:80'], 'reference' => ['nullable', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:500'],
                'money_in' => ['nullable'], 'money_out' => ['nullable'], 'balance' => ['nullable'],
            ])->validate();
            if ($valid['date'] < $previous) {
                $this->fail('Statement dates must be in ascending order; row '.$number.' is out of order.');
            }
            $previous = $valid['date'];
            $in = $this->decimal->money(($valid['money_in'] ?? '') === '' ? '0' : $valid['money_in']);
            $out = $this->decimal->money(($valid['money_out'] ?? '') === '' ? '0' : $valid['money_out']);
            if ((bccomp($in, '0', 2) > 0) === (bccomp($out, '0', 2) > 0)) {
                $this->fail('Row '.$number.' requires exactly one positive money-in or money-out value.');
            }
            $running = $this->signed(bcsub(bcadd($running, $in, 2), $out, 2));
            $reported = $valid['balance'] ?? '';
            $balance = $reported === '' ? null : $this->signed($reported);
            if ($balance !== null && $balance !== $running) {
                $this->fail('Reported balance differs from the calculated balance on row '.$number.'.');
            }
            $row = ['date' => $valid['date'], 'value_date' => ($valid['value_date'] ?? '') === '' ? null : $valid['value_date'],
                'transaction_id' => $this->text($valid['transaction_id'] ?? '', 80), 'reference' => $this->text($valid['reference'] ?? '', 160), 'description' => $this->text($valid['description'] ?? '', 500),
                'money_in' => $in, 'money_out' => $out, 'balance' => $balance];
            // Exclude running balance: changing a displayed balance must not disguise a repeated transaction.
            $fingerprint = $this->canonical->hash(array_diff_key($row, ['balance' => true]));
            if (isset($seen[$fingerprint])) {
                $this->fail('Indistinguishable duplicate row '.$number.'. Supply distinct bank transaction IDs for legitimate identical movements.');
            }
            $seen[$fingerprint] = true;
            $transactionHash = $row['transaction_id'] === '' ? null : hash('sha256', $row['transaction_id']);
            if ($transactionHash !== null && isset($transactionIds[$transactionHash])) {
                $this->fail('Repeated bank transaction ID on row '.$number.'.');
            }
            if ($transactionHash !== null) {
                $transactionIds[$transactionHash] = true;
            }
            $normalized[] = [...$row, 'line_number' => $number, 'running_balance' => $running, 'fingerprint' => $fingerprint, 'transaction_hash' => $transactionHash];
            $incoming = bcadd($incoming, $in, 2);
            $outgoing = bcadd($outgoing, $out, 2);
        }
        if ($running !== $data['closing_balance']) {
            $this->fail('Opening balance plus money in minus money out must equal the stated closing balance.');
        }

        return ['version' => self::VERSION, 'metadata' => $data, 'lines' => $normalized, 'totals' => ['money_in' => $incoming, 'money_out' => $outgoing, 'row_count' => count($normalized)]];
    }

    /** @param array<string, mixed> $data @param list<string> $allowed */
    private function keys(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed) !== [] || array_diff($allowed, array_keys($data)) !== []) {
            $this->fail('Statement fields are missing or unsupported. Use the documented template.');
        }
    }

    private function text(mixed $value, int $limit): string
    {
        if ($value === null) {
            return '';
        }
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            $this->fail('Statement text must be valid UTF-8 without control characters.');
        }
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
        if (mb_strlen($value) > $limit) {
            $this->fail('Statement text exceeds its field limit.');
        }

        return $value;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['statement' => $message]);
    }
}

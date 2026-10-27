<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Statements;

use Illuminate\Validation\ValidationException;

/** Strict comma-separated UTF-8 CSV; optional BOM; RFC-style double-quote escaping. No spreadsheet execution. */
final class AccountingBankStatementCsv
{
    /** @return list<array<string, string>> */
    public function read(string $raw): array
    {
        if (strlen($raw) > AccountingBankStatementContract::MAX_BYTES || ! mb_check_encoding($raw, 'UTF-8') || str_contains($raw, "\0")) {
            $this->fail('CSV must be UTF-8 text, without NUL bytes, and at most 512 KiB.');
        }
        $raw = str_starts_with($raw, "\xEF\xBB\xBF") ? substr($raw, 3) : $raw;
        $records = [];
        $cells = [];
        $cell = '';
        $state = 'start';
        $pending = false;
        $length = strlen($raw);
        for ($i = 0; $i < $length; $i++) {
            $char = $raw[$i];
            if ($state === 'quoted') {
                if ($char === '"') {
                    if ($i + 1 < $length && $raw[$i + 1] === '"') {
                        $cell .= '"';
                        $i++;
                    } else {
                        $state = 'closed';
                    }
                } else {
                    $cell .= $char;
                }

                continue;
            }
            if ($char === ',' || $char === "\n" || $char === "\r") {
                $cells[] = $cell;
                $cell = '';
                $state = 'start';
                $pending = true;
                if (count($cells) > 8) {
                    $this->fail('CSV requires exactly eight columns.');
                }
                if ($char !== ',') {
                    if ($char === "\r") {
                        if ($i + 1 >= $length || $raw[++$i] !== "\n") {
                            $this->fail('Use LF or CRLF line endings.');
                        }
                    }
                    $records[] = $cells;
                    $cells = [];
                    $pending = false;
                    if (count($records) > AccountingBankStatementContract::MAX_ROWS + 1) {
                        $this->fail('CSV exceeds 1,000 data rows.');
                    }
                }

                continue;
            }
            if ($state === 'closed') {
                $this->fail('Only a comma or newline may follow a closing quote.');
            }
            if ($char === '"') {
                if ($state !== 'start') {
                    $this->fail('Quotes inside a CSV field must be escaped.');
                } $state = 'quoted';
                $pending = true;
            } else {
                $state = 'plain';
                $cell .= $char;
                $pending = true;
            }
        }
        if ($state === 'quoted') {
            $this->fail('CSV has an unclosed quoted field.');
        }
        if ($pending || $cells !== []) {
            $cells[] = $cell;
            $records[] = $cells;
        }
        if (count($records) > AccountingBankStatementContract::MAX_ROWS + 1) {
            $this->fail('CSV exceeds 1,000 data rows.');
        }
        $header = array_shift($records);
        if ($header !== AccountingBankStatementContract::COLUMNS) {
            $this->fail('CSV header must exactly match the downloadable template.');
        }
        $rows = [];
        foreach ($records as $record) {
            if (count($record) !== 8) {
                $this->fail('Every CSV row requires eight fields; blank lines are not allowed.');
            }
            $row = [];
            foreach (AccountingBankStatementContract::COLUMNS as $index => $name) {
                $row[$name] = $record[$index];
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}

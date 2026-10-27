<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Statements;

final class AccountingBankStatementDocument
{
    /** @param resource $stream @param array<string, mixed>|null $payload */
    public function csv($stream, ?array $payload = null): void
    {
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, AccountingBankStatementContract::COLUMNS, ',', '"', '');
        foreach ($payload['lines'] ?? [] as $line) {
            $cells = [];
            foreach (AccountingBankStatementContract::COLUMNS as $column) {
                $cells[] = $this->safe((string) ($line[$column] ?? ''));
            }
            fputcsv($stream, $cells, ',', '"', '');
        }
    }

    private function safe(string $value): string
    {
        $check = preg_replace('/\A[\s\x{FEFF}\x00-\x1F\x7F]+/u', '', $value) ?? $value;
        // Exact numeric literals are safe, including negative overdraft balances.
        if (preg_match('/\A-?[0-9]+(?:\.[0-9]{1,2})?\z/', $check)) {
            return $value;
        }

        return preg_match('/\A[=+\-@]/', $check) ? "'".$value : $value;
    }
}

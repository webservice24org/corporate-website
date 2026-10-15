<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

final class SalesReportCsv
{
    public function cell(mixed $value, string $kind = 'text'): string
    {
        $text = $value === null ? '' : (string) $value;
        if (in_array($kind, ['money', 'quantity'], true) && preg_match('/\A-?[0-9]+(?:\.[0-9]+)?\z/', $text) === 1) {
            return $text;
        }
        if (preg_match('/\A[\x00-\x20\x7F]|\A[\s\x{FEFF}]*[=+\-@]/u', $text) === 1) {
            return "'".$text;
        }

        return $text;
    }
}

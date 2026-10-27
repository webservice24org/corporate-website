<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Internal allocator. Caller authorizes the action and inserts the document in this transaction. */
final class AccountingDocumentNumberService
{
    public function next(int $companyId, int $fiscalYearId, string $kind, string $accountingDate): string
    {
        $prefix = match ($kind) {
            'journal' => 'JV', 'opening' => 'OB', 'reversal' => 'RV', 'closing' => 'CL', default => throw new \InvalidArgumentException('Unknown accounting document kind.')
        };
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $accountingDate);
        if ($date === false || $date->format('Y-m-d') !== $accountingDate || (int) $date->format('Y') < 1) {
            throw new \InvalidArgumentException('Use a valid YYYY-MM-DD accounting date.');
        }
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Accounting numbering requires the document transaction.');
        }
        Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
        $year = FiscalYear::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($fiscalYearId);
        $starts = $year->getAttribute('starts_on');
        $ends = $year->getAttribute('ends_on');
        if (! $starts instanceof CarbonInterface || ! $ends instanceof CarbonInterface || $year->getAttribute('state') !== 'open' || $accountingDate < $starts->format('Y-m-d') || $accountingDate > $ends->format('Y-m-d')) {
            throw ValidationException::withMessages(['accounting_date' => 'Numbering requires an open fiscal year containing this date.']);
        }
        $code = (string) $year->getAttribute('code');
        if (! preg_match('/\A[A-Za-z0-9_.-]{1,30}\z/', $code)) {
            throw new \InvalidArgumentException('Fiscal year code must be 1–30 ASCII letters, digits, dots, underscores or hyphens.');
        }
        $scope = ['company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId, 'document_kind' => $kind];
        $query = DB::table('accounting_document_sequences')->where($scope);
        $sequence = (clone $query)->lockForUpdate()->first();
        if ($sequence === null) {
            DB::table('accounting_document_sequences')->insert([...$scope, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $sequence = (clone $query)->lockForUpdate()->first();
        }
        if ($sequence === null || (int) $sequence->last_number >= 999999) {
            throw new \LogicException('Accounting sequence is unavailable or exhausted.');
        }
        $next = (int) $sequence->last_number + 1;
        $query->update(['last_number' => $next, 'updated_at' => now()]);

        return sprintf('%s-%s-%06d', $prefix, $code, $next);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class AccountingCalendarDates
{
    public function assertYearRange(string $start, string $end): void
    {
        if (CarbonImmutable::parse($end, 'UTC')->greaterThan(CarbonImmutable::parse($start, 'UTC')->addYears(2)->subDay())) {
            throw ValidationException::withMessages(['ends_on' => 'A fiscal year may span at most two calendar years.']);
        }
    }

    /** Caller validates real ISO dates and the year range before using this pure helper.
     * @return list<array{code: string, starts_on: string, ends_on: string}>
     */
    public function monthlyRanges(string $start, string $end): array
    {
        $this->assertYearRange($start, $end);
        $cursor = CarbonImmutable::parse($start, 'UTC');
        $last = CarbonImmutable::parse($end, 'UTC');
        $ranges = [];
        while ($cursor->lessThanOrEqualTo($last)) {
            $until = $cursor->endOfMonth()->startOfDay();
            if ($until->greaterThan($last)) {
                $until = $last;
            }
            $ranges[] = ['code' => 'P-'.$cursor->format('Ymd'), 'starts_on' => $cursor->toDateString(), 'ends_on' => $until->toDateString()];
            $cursor = $until->addDay();
        }

        return $ranges;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Close;

/** A dashboard page sample can never substitute for complete close verification. */
final class AccountingPeriodCloseScan
{
    /** @param callable(array<string, mixed>): array<string, mixed> $load */
    public function verified(callable $load): bool
    {
        $input = ['limit' => 50];
        $count = 0;
        $ready = true;
        $cursors = [];
        $total = null;
        do {
            $page = $load($input);
            if ($page['total_candidates'] > 1000 || ($total !== null && $total !== $page['total_candidates'])) {
                return false;
            }
            $total = $page['total_candidates'];
            foreach ($page['rows'] as $row) {
                $count++;
                if (! in_array($row['status'], ['verified_live', 'verified_opening_ar'], true)) {
                    $ready = false;
                }
            }
            if ($count > 1000 || $count > $total) {
                return false;
            }
            if ($page['has_more']) {
                if ($page['next'] === null || $page['rows'] === []) {
                    return false;
                }
                $cursor = json_encode($page['next'], JSON_THROW_ON_ERROR);
                if (isset($cursors[$cursor])) {
                    return false;
                } $cursors[$cursor] = true;
                $input = ['limit' => 50, ...$page['next']];
            }
        } while ($page['has_more']);

        return $ready && $count === $total;
    }
}

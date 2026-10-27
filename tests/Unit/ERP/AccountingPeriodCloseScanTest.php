<?php

declare(strict_types=1);

namespace Tests\Unit\ERP;

use App\Services\ERP\Accounting\Close\AccountingPeriodCloseScan;
use PHPUnit\Framework\TestCase;

final class AccountingPeriodCloseScanTest extends TestCase
{
    /** @return array<string, mixed> */
    private function page(int $total, int $rows, bool $more = false, ?array $next = null): array
    {
        return ['total_candidates' => $total, 'rows' => array_fill(0, $rows, ['status' => 'verified_live']), 'has_more' => $more, 'next' => $next];
    }

    public function test_all_pages_must_be_loaded(): void
    {
        $calls = 0;
        $ok = (new AccountingPeriodCloseScan)->verified(function (array $input) use (&$calls): array {
            $calls++;
            if ($calls === 1) {
                return $this->page(60, 50, true, ['after_kind' => 'x', 'after_id' => 50]);
            } $this->assertSame(50, $input['after_id']);

            return $this->page(60, 10);
        });
        $this->assertTrue($ok);
        $this->assertSame(2, $calls);
    }

    public function test_error_on_later_page_blocks_even_when_first_page_was_clean(): void
    {
        $calls = 0;
        $ok = (new AccountingPeriodCloseScan)->verified(function () use (&$calls): array {
            $calls++;
            if ($calls === 1) {
                return $this->page(51, 50, true, ['after_kind' => 'x', 'after_id' => 50]);
            } $p = $this->page(51, 1);
            $p['rows'][0]['status'] = 'missing_source';

            return $p;
        });
        $this->assertFalse($ok);
    }

    public function test_interactive_limit_does_not_certify_partial_scan(): void
    {
        $this->assertFalse((new AccountingPeriodCloseScan)->verified(fn (): array => $this->page(1001, 50, true)));
    }

    public function test_missing_cursor_blocks(): void
    {
        $this->assertFalse((new AccountingPeriodCloseScan)->verified(fn (): array => $this->page(60, 50, true)));
    }

    public function test_repeated_cursor_blocks(): void
    {
        $this->assertFalse((new AccountingPeriodCloseScan)->verified(fn (): array => $this->page(1000, 50, true, ['after_kind' => 'x', 'after_id' => 50])));
    }

    public function test_declared_total_must_match_inspected_count(): void
    {
        $this->assertFalse((new AccountingPeriodCloseScan)->verified(fn (): array => $this->page(60, 50)));
    }

    public function test_no_candidates_is_verified_without_fabricated_errors(): void
    {
        $this->assertTrue((new AccountingPeriodCloseScan)->verified(fn (): array => $this->page(0, 0)));
    }

    public function test_unknown_or_legacy_pending_status_blocks(): void
    {
        $p = $this->page(1, 1);
        $p['rows'][0]['status'] = 'legacy_pending';
        $this->assertFalse((new AccountingPeriodCloseScan)->verified(static fn (): array => $p));
    }
}

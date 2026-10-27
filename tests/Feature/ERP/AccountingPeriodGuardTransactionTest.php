<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\User;
use App\Services\ERP\Accounting\AccountingPeriodGuard;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AccountingPeriodGuardTransactionTest extends TestCase
{
    public function test_period_guard_refuses_to_release_locks_in_its_own_short_transaction(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->expectException(\LogicException::class);
        app(AccountingPeriodGuard::class)->lockForJournal(new User, 1, 1);
    }
}

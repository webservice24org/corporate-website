<?php

declare(strict_types=1);

namespace Tests\Unit\ERP;

use App\Services\ERP\Sales\SalesCompanyAccess;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class SalesWriteTransactionGuardTest extends TestCase
{
    public function test_sales_writes_require_a_transaction(): void
    {
        DB::shouldReceive('transactionLevel')->once()->andReturn(0);
        $this->expectException(LogicException::class);
        app(SalesCompanyAccess::class)->requireTransaction();
    }
}

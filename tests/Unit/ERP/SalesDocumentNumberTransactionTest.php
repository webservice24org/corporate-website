<?php

declare(strict_types=1);

namespace Tests\Unit\ERP;

use App\Services\ERP\Sales\SalesDocumentNumberService;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class SalesDocumentNumberTransactionTest extends TestCase
{
    public function test_allocator_requires_a_caller_owned_transaction(): void
    {
        DB::shouldReceive('transactionLevel')->once()->andReturn(0);
        $this->expectException(LogicException::class);
        app(SalesDocumentNumberService::class)->next(1, 'order', '2026-10-14');
    }
}

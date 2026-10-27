<?php

declare(strict_types=1);

namespace Tests\Unit\ERP;

use App\Services\ERP\Accounting\AccountingCompanyAccess;
use App\Services\ERP\Accounting\AccountingDocumentNumberService;
use Tests\TestCase;

final class AccountingTransactionGuardTest extends TestCase
{
    public function test_internal_write_guard_requires_caller_transaction(): void
    {
        $this->expectException(\LogicException::class);
        app(AccountingCompanyAccess::class)->requireTransaction();
    }

    public function test_internal_number_allocator_requires_document_transaction(): void
    {
        $this->expectException(\LogicException::class);
        app(AccountingDocumentNumberService::class)->next(1, 1, 'journal', '2026-10-15');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\ERP;

use App\Services\ERP\Accounting\AccountingDecimal;
use App\Services\ERP\Accounting\Reports\AccountingSupplierReportDocument;
use App\Services\ERP\Accounting\Reports\AccountingSupplierStatementContract;
use Tests\TestCase;

final class AccountingSupplierReportExportTest extends TestCase
{
    public function test_formula_prefixes_are_escaped_including_after_whitespace(): void
    {
        $d = new AccountingSupplierReportDocument;
        foreach (['=1+1', '+SUM(A1:A2)', '-1+2', '@SUM(A1)', "\t=HYPERLINK(\"https://example.invalid\")", " \r\n+1", "\u{FEFF}=1"] as $value) {
            $this->assertStringStartsWith("'", $d->cell($value));
        }
    }

    public function test_control_characters_cannot_hide_a_formula_prefix(): void
    {
        $d = new AccountingSupplierReportDocument;
        $this->assertSame("'=1+1", $d->cell("\x00\x01=1+1"));
    }

    public function test_plain_text_and_exact_unsigned_decimals_are_preserved(): void
    {
        $d = new AccountingSupplierReportDocument;
        foreach (['BDT', 'Supplier, "one"', '999999999999999999.99', '0.00'] as $value) {
            $this->assertSame($value, $d->cell($value));
        }
    }

    public function test_signed_decimals_are_exported_as_safe_exact_text(): void
    {
        $this->assertSame("'-5.00", (new AccountingSupplierReportDocument)->cell('-5.00'));
    }

    public function test_csv_quotes_delimiters_quotes_and_multiline_cells(): void
    {
        $d = new AccountingSupplierReportDocument;
        $stream = fopen('php://temp', 'w+b');
        $this->assertIsResource($stream);
        $document = ['title' => 'Test', 'meta' => [], 'headers' => ['Document', 'Amount'], 'rows' => [["Line, \"quoted\"\nsecond line", '95.00']], 'totals' => [], 'exceptions' => []];
        $d->writeCsv($stream, $document);
        rewind($stream);
        $raw = stream_get_contents($stream);
        fclose($stream);
        $this->assertIsString($raw);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $raw);
        $this->assertStringContainsString('"Line, ""quoted""', $raw);
        $this->assertStringContainsString('second line",95.00', $raw);
    }

    public function test_full_statement_projection_has_no_page_limit_or_truncation(): void
    {
        $contract = new AccountingSupplierStatementContract(new AccountingDecimal);
        $events = [];
        for ($id = 1; $id <= 127; $id++) {
            $events[] = ['company_id' => 1, 'supplier_id' => 1, 'currency' => 'BDT', 'source_kind' => 'supplier_bill', 'source_id' => $id, 'sequence' => $id, 'bill_id' => $id, 'payment_id' => null, 'event' => 'bill_approved', 'date' => '2026-10-16', 'number' => 'B-'.$id, 'amount' => '1.00', 'source_url' => null, 'journal_url' => null];
        }
        $page = $contract->project($events, '2026-10-16', '2026-10-16', 3, 10);
        $all = $contract->project($events, '2026-10-16', '2026-10-16', 3, 10, true);
        $this->assertCount(10, $page['rows']);
        $this->assertCount(127, $all['rows']);
        $this->assertSame($page['closing'], $all['closing']);
        $this->assertSame('127.00', $all['closing']['ap']);
    }

    public function test_document_refuses_unknown_report_kind(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AccountingSupplierReportDocument)->make('anything', []);
    }
}

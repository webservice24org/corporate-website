<?php

declare(strict_types=1);

namespace Tests\Unit\ERP;

use App\Services\ERP\Accounting\AccountingDecimal;
use App\Services\ERP\Accounting\Books\AccountingCashBookContract;
use App\Services\ERP\Accounting\Books\AccountingCashBookDocument;
use Tests\TestCase;

final class AccountingCashBookExportTest extends TestCase
{
    public function test_csv_formula_prefixes_are_protected_after_whitespace_and_bom(): void
    {
        $d = new AccountingCashBookDocument;
        foreach (['=1+1', '+SUM(A1:A2)', '-1+2', '@SUM(A1)', "\t=1", " \r\n+1", "\u{FEFF}=1"] as $value) {
            $this->assertStringStartsWith("'", $d->cell($value));
        }
    }

    public function test_hidden_controls_cannot_hide_formula_prefixes(): void
    {
        $this->assertSame("'=1+1", (new AccountingCashBookDocument)->cell("\x00\x01=1+1"));
    }

    public function test_plain_and_large_decimal_strings_remain_exact(): void
    {
        $d = new AccountingCashBookDocument;
        foreach (['BDT', '999999999999999999.99', '0.00', 'Cash, "Main"'] as $value) {
            $this->assertSame($value, $d->cell($value));
        }
    }

    public function test_negative_decimals_are_safe_exact_csv_text(): void
    {
        $this->assertSame("'-95.00", (new AccountingCashBookDocument)->cell('-95.00'));
    }

    public function test_full_projection_has_more_rows_than_the_page_limit(): void
    {
        $lines = [];
        for ($id = 1; $id <= 135; $id++) {
            $lines[] = ['company_id' => 1, 'binding_id' => 1, 'recorded_binding_id' => 1, 'journal_id' => $id, 'journal_line_id' => $id, 'date' => '2026-10-16', 'currency' => 'BDT', 'debit' => '1.00', 'credit' => '0.00', 'verified' => true, 'number' => 'R-'.$id, 'source_kind' => 'customer_receipt', 'source_id' => $id, 'event' => 'received', 'source_url' => null, 'journal_url' => null];
        }
        $contract = new AccountingCashBookContract(new AccountingDecimal);
        $page = $contract->project($lines, '2026-10-16', '2026-10-16', 3, 1);
        $all = $contract->project($lines, '2026-10-16', '2026-10-16', 3, 1, true);
        $this->assertCount(1, $page['rows']);
        $this->assertCount(135, $all['rows']);
        $this->assertSame($page['posted_gl'], $all['posted_gl']);
        $this->assertSame('135.00', $all['posted_gl']['closing']);
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        $zero = ['opening' => '0.00', 'debits' => '0.00', 'credits' => '0.00', 'closing' => '0.00'];

        return ['company' => ['code' => 'C', 'name' => 'Company'], 'binding' => ['id' => 1, 'kind' => 'cash', 'name' => 'Main', 'role' => 'cash:MAIN', 'status' => 'active', 'currency_code' => 'BDT', 'account_id' => 1], 'from' => '2026-10-16', 'to' => '2026-10-16', 'posted_gl' => $zero, 'source_verified' => $zero, 'difference' => $zero, 'complete' => false, 'posted_totals_complete' => false, 'excluded_posted_lines' => 1, 'rows' => [], 'exceptions' => [['code' => 'invalid_cash_amount', 'journal_id' => 1, 'journal_line_id' => 1]]];
    }

    public function test_document_carries_incomplete_values_and_coverage_instead_of_clean_totals_claim(): void
    {
        $doc = (new AccountingCashBookDocument)->make($this->report());
        $this->assertTrue($doc['review']);
        $this->assertContains(['Posted GL values', 'INCOMPLETE — relevant posted lines were excluded'], $doc['meta']);
        $this->assertContains(['Posted GL closing', '0.00'], $doc['totals']);
        $this->assertContains(['Source verified closing', '0.00'], $doc['totals']);
        $this->assertSame('invalid_cash_amount', $doc['exceptions'][0][0]);
    }

    public function test_document_does_not_serialize_extra_private_fields(): void
    {
        $r = [...$this->report(), 'bank_account_number' => 'PRIVATE', 'payload_hash' => 'SECRET'];
        $json = json_encode((new AccountingCashBookDocument)->make($r), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PRIVATE', $json);
        $this->assertStringNotContainsString('SECRET', $json);
    }

    public function test_csv_writer_quotes_embedded_delimiters_and_multiline_text(): void
    {
        $d = new AccountingCashBookDocument;
        $document = $d->make($this->report());
        $document['rows'] = [["Cash, \"Main\"\nsecond line"]];
        $stream = fopen('php://temp', 'w+b');
        $this->assertIsResource($stream);
        $d->writeCsv($stream, $document);
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        $this->assertIsString($csv);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('"Cash, ""Main""', $csv);
        $this->assertStringContainsString('second line"', $csv);
    }
}

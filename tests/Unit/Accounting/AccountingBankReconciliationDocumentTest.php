<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting;

use App\Services\ERP\Accounting\Reconciliation\AccountingBankReconciliationDocument;
use PHPUnit\Framework\TestCase;

final class AccountingBankReconciliationDocumentTest extends TestCase
{
    /** @return array<string, mixed> */
    private function data(): array
    {
        return ['id' => 1, 'company' => 'Test company', 'bank' => 'Bank', 'statement_reference' => 'Statement', 'currency' => 'BDT', 'cutoff' => '2026-10-16', 'basis' => 'current', 'status_label' => 'WORKING / NOT FINALIZED', 'current_state' => 'working', 'matching_revision' => 2, 'current_matching_revision' => 2, 'finalization_revision' => 0, 'technical_ready' => false, 'generated_at' => '2026-10-26T08:00:00+00:00', 'approval' => null,
            'report' => ['bridge' => ['book_closing' => '-9007199254740993.27'], 'statement_rows' => [['id' => 1, 'date' => '2026-10-16', 'reference' => 'Reference', 'description' => 'Description', 'direction' => 'out', 'amount' => '9007199254740993.27', 'matched' => '0.00', 'remaining' => '9007199254740993.27']], 'book_rows' => [['id' => 2, 'date' => '2026-10-16', 'direction' => 'out', 'amount' => '9007199254740993.27', 'matched_through_cutoff' => '0.00', 'outstanding_through_cutoff' => '9007199254740993.27', 'source' => 'cash transfer #1', 'source_url' => null, 'journal_url' => null, 'currently_available' => '9007199254740993.27', 'allocated_to_later_statements' => '0.00']], 'history' => []], 'checks' => [], 'exceptions' => [], 'timeline' => [], 'later_events' => []];
    }

    /** @param array<string, mixed> $data @return list<list<string|null>> */
    private function rows(array $data): array
    {
        $document = new AccountingBankReconciliationDocument;
        $made = $document->make($data);
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('Test stream unavailable');
        }
        try {
            $document->csv($stream, $made);
            rewind($stream);
            $this->assertSame("\xEF\xBB\xBF", fread($stream, 3));
            $rows = [];
            while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    public function test_large_signed_decimals_are_written_without_float_rounding(): void
    {
        $cells = array_merge(...$this->rows($this->data()));
        $this->assertContains('-9007199254740993.27', $cells);
        $this->assertContains('9007199254740993.27', $cells);
        $this->assertNotContains("'-9007199254740993.27", $cells);
    }

    public function test_formula_markers_after_controls_unicode_spacing_and_bom_are_text(): void
    {
        foreach (['=SUM(1,2)', '+SUM(A1)', '-2+3', '@SUM(A1)', "\t=HYPERLINK(\"x\")", "\u{FEFF} =1+1", "\u{200B}@SUM(A1)", "\r\n+1+1"] as $value) {
            $data = $this->data();
            $data['bank'] = $value;
            $data['report']['statement_rows'][0]['reference'] = $value;
            $this->assertContains("'".$value, array_merge(...$this->rows($data)));
        }
    }

    public function test_csv_quotes_commas_double_quotes_and_multiline_text_correctly(): void
    {
        $data = $this->data();
        $text = "Bank, \"quoted\"\nsecond line";
        $data['report']['statement_rows'][0]['description'] = $text;
        $this->assertContains($text, array_merge(...$this->rows($data)));
    }

    public function test_approved_document_never_invents_current_availability(): void
    {
        $data = $this->data();
        $data['basis'] = 'approved';
        $data['status_label'] = 'HISTORICAL APPROVED SNAPSHOT — currently reopened';
        $data['current_state'] = 'reopened';
        unset($data['report']['book_rows'][0]['currently_available'], $data['report']['book_rows'][0]['allocated_to_later_statements']);
        $made = (new AccountingBankReconciliationDocument)->make($data);
        foreach ($made['tables'] as $table) {
            if ($table['title'] === 'Book movements — all rows') {
                $this->assertNotContains('Currently available', $table['headers']);
                $this->assertNotContains('Allocated to later statements', $table['headers']);
            }
        }
        $this->assertStringContainsString('HISTORICAL', $made['status']);
    }

    public function test_all_movement_and_history_rows_are_retained(): void
    {
        $data = $this->data();
        $row = $data['report']['statement_rows'][0];
        $data['report']['statement_rows'] = [];
        for ($i = 1; $i <= 60; $i++) {
            $data['report']['statement_rows'][] = [...$row, 'id' => $i, 'reference' => 'Row '.$i];
        }
        for ($i = 1; $i <= 105; $i++) {
            $data['timeline'][] = ['id' => $i, 'kind' => 'submit', 'actor_id' => 1, 'occurred_at' => '2026-10-26T08:00:00+00:00', 'reason' => 'History '.$i];
        }
        $cells = array_merge(...$this->rows($data));
        $this->assertContains('Row 60', $cells);
        $this->assertContains('History 105', $cells);
    }

    public function test_untrusted_drilldown_urls_are_rejected_before_streaming(): void
    {
        $data = $this->data();
        $data['report']['book_rows'][0]['source_url'] = 'javascript:alert(1)';
        $this->expectException(\InvalidArgumentException::class);
        (new AccountingBankReconciliationDocument)->make($data);
    }

    public function test_invalid_utf8_is_rejected_before_streaming(): void
    {
        $data = $this->data();
        $data['bank'] = "\xC3\x28";
        $this->expectException(\InvalidArgumentException::class);
        (new AccountingBankReconciliationDocument)->make($data);
    }
}

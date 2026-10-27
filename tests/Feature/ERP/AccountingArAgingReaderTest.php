<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Services\ERP\Accounting\Reports\AccountingAgingReader;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingAgingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\ERP\AccountingArAgingTestCase;

final class AccountingArAgingReaderTest extends AccountingArAgingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingAgingPermissionSeeder::class);
    }

    /** @return array<string, mixed> */
    private function report(string $asOf): array
    {
        return app(AccountingAgingReader::class)->report($this->creator, (int) $this->company->getKey(), 'ar', ['as_of' => $asOf]);
    }

    public function test_live_invoice_uses_admitted_due_terms_and_matches_trade_ar(): void
    {
        $invoice = $this->invoice();
        $r = $this->report('2026-10-16');
        $this->assertTrue($r['complete']);
        $this->assertSame('60.00', $r['totals']['open_documents']);
        $this->assertSame('admitted', $r['rows'][0]['due_basis']);
        $this->assertSame((int) $invoice->getKey(), $r['rows'][0]['document_id']);
    }

    public function test_later_allocation_does_not_rewrite_yesterdays_ar_or_unapplied_funds(): void
    {
        $invoice = $this->invoice();
        $receipt = $this->postReceipt($this->receipt());
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $this->allocate($receipt, $invoice, '40.00');
        $old = $this->report('2026-10-16');
        $now = $this->report('2026-10-17');
        $this->assertSame('60.00', $old['totals']['signed_documents']);
        $this->assertSame('60.00', $old['reconciliation']['separate_unapplied_or_advance_balance']);
        $this->assertSame('20.00', $now['totals']['signed_documents']);
        $this->assertSame('20.00', $now['reconciliation']['separate_unapplied_or_advance_balance']);
    }

    public function test_later_compensation_reopens_only_on_its_effective_date(): void
    {
        $invoice = $this->invoice();
        $receipt = $this->postReceipt($this->receipt(), [['sales_invoice_id' => (int) $invoice->getKey(), 'amount' => '40.00']]);
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'Asia/Dhaka'));
        $this->undo($receipt, $this->originalAllocation($receipt));
        $this->assertSame('20.00', $this->report('2026-10-16')['totals']['signed_documents']);
        $this->assertSame('60.00', $this->report('2026-10-17')['totals']['signed_documents']);
    }

    public function test_legacy_unrecognized_invoice_stays_unknown(): void
    {
        $this->settings(['state' => 'disabled']);
        $this->invoice();
        $r = $this->report('2026-10-16');
        $this->assertNull($r['confirmed_totals']);
        $this->assertNull($r['rows'][0]['outstanding']);
    }

    public function test_expense_or_sales_permissions_do_not_imply_aging_permission(): void
    {
        $staff = $this->staff([]);
        $this->expectException(AuthorizationException::class);
        app(AccountingAgingReader::class)->report($staff, (int) $this->company->getKey(), 'ar', ['as_of' => '2026-10-16']);
    }

    public function test_damaged_journal_is_not_repaired_or_returned_as_partial_aging(): void
    {
        $this->invoice();
        DB::table('journal_entry_lines')->where('account_id', $this->mapping['trade_ar'])->update(['debit' => '60.01']);
        $this->expectException(ValidationException::class);
        $this->report('2026-10-16');
    }
}

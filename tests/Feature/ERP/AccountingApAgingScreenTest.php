<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use Carbon\CarbonImmutable;
use Database\Seeders\AccountingAgingWorkspacePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ERP\AccountingApAgingTestCase;

final class AccountingApAgingScreenTest extends AccountingApAgingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingAgingWorkspacePermissionSeeder::class);
    }

    private function url(?string $format = null, ?string $day = null): string
    {
        return '/erp/accounting/ap-aging'.($format === null ? '' : '/export').'?company_id='.$this->company->getKey().'&as_of='.($day ?? now('Asia/Dhaka')->toDateString()).($format === null ? '' : '&format='.$format);
    }

    public function test_bill_screen_resolves_with_explicit_native_terms_basis(): void
    {
        $bill = $this->liveBill();
        $this->actingAs($this->actor)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Aging/Index')->where('kind', 'ap')->where('report.rows.0.document_id', (int) $bill->getKey())->where('report.rows.0.due_basis', 'current_native_terms'));
    }

    public function test_historical_terms_keep_screen_and_export_provisional(): void
    {
        $this->liveBill();
        $day = now('Asia/Dhaka')->toDateString();
        $this->travelTo(CarbonImmutable::now('Asia/Dhaka')->addDay());
        $this->actingAs($this->actor)->get($this->url(null, $day))->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.complete', false)->where('report.confirmed_totals', null));
        $csv = $this->get($this->url('csv', $day))->assertOk()->streamedContent();
        $this->assertStringContainsString('PROVISIONAL', $csv);
        $this->assertStringContainsString('historical_due_terms_unverified', $csv);
    }

    public function test_advance_does_not_reduce_positive_bill_aging(): void
    {
        $bill = $this->liveBill();
        $gross = bcadd((string) $bill->getAttribute('grand_total'), '0', 2);
        $this->postPayment($this->ready($bill, $this->paymentData($bill, '40.13', '0.00')));
        $this->actingAs($this->actor)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.totals.open_documents', $gross)->where('report.reconciliation.separate_unapplied_or_advance_balance', '40.13'));
    }

    public function test_ap_only_view_does_not_grant_ar_or_native_bill_links(): void
    {
        $this->liveBill();
        $staff = $this->staff(['erp.ap-aging.view']);
        $this->actingAs($staff)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->where('canAr', false)->where('canExport', false)->where('report.rows.0.native_url', null));
        $this->get('/erp/accounting/ar-aging')->assertForbidden();
    }

    public function test_view_only_cannot_download_ap_review_reports(): void
    {
        $this->actingAs($this->staff(['erp.ap-aging.view']))->get($this->url('csv'))->assertForbidden();
        $this->get($this->url('print'))->assertForbidden();
    }

    public function test_ap_print_is_private_and_includes_coverage_scope(): void
    {
        $this->actingAs($this->actor)->get($this->url('print'))->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertSee('Unknown is not zero')->assertSee('Current supplier terms');
    }

    public function test_damaged_evidence_blocks_screen_and_export_before_partial_output(): void
    {
        $this->liveBill();
        DB::table('journal_entry_lines')->where('account_id', $this->mapping['trade_ap'])->where('credit', '>', 0)->update(['credit' => '999.99']);
        $this->actingAs($this->actor)->getJson($this->url())->assertUnprocessable();
        $this->getJson($this->url('csv'))->assertUnprocessable();
    }

    public function test_ap_screen_and_export_do_not_append_financial_rows(): void
    {
        $this->liveBill();
        $before = [DB::table('journal_entries')->count(), DB::table('supplier_payment_allocations')->count()];
        $this->actingAs($this->actor)->get($this->url())->assertOk();
        $this->get($this->url('csv'))->assertOk()->streamedContent();
        $this->assertSame($before, [DB::table('journal_entries')->count(), DB::table('supplier_payment_allocations')->count()]);
    }
}

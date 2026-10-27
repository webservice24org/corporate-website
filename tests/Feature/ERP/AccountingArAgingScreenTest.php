<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use Database\Seeders\AccountingAgingWorkspacePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ERP\AccountingArAgingTestCase;

final class AccountingArAgingScreenTest extends AccountingArAgingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingAgingWorkspacePermissionSeeder::class);
    }

    private function url(?string $format = null, string $extra = ''): string
    {
        return '/erp/accounting/ar-aging'.($format === null ? '' : '/export').'?company_id='.$this->company->getKey().'&as_of=2026-10-16'.($format === null ? '' : '&format='.$format).$extra;
    }

    public function test_guests_redirect_from_both_screens_and_downloads(): void
    {
        foreach (['ar-aging', 'ap-aging'] as $kind) {
            $this->get('/erp/accounting/'.$kind)->assertRedirect('/login');
            $this->get('/erp/accounting/'.$kind.'/export?format=csv')->assertRedirect('/login');
        }
    }

    public function test_native_permissions_do_not_grant_aging(): void
    {
        $this->actingAs($this->staff([]))->get($this->url())->assertForbidden();
    }

    public function test_aging_only_staff_sees_safe_party_name_without_native_link(): void
    {
        $this->invoice();
        $this->actingAs($this->staff(['erp.ar-aging.view']))->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Aging/Index')->where('kind', 'ar')->where('canAp', false)->where('canExport', false)->where('report.rows.0.party_label', 'Sales customer')->where('report.rows.0.native_url', null)->where('report.rows.0.outstanding', '60.00'));
    }

    public function test_admin_without_company_receives_selector_only(): void
    {
        $this->actingAs($this->creator)->get('/erp/accounting/ar-aging')->assertOk()->assertInertia(fn (Assert $page) => $page->where('company', null)->where('report', null));
    }

    public function test_staff_defaults_to_own_company(): void
    {
        $this->actingAs($this->staff(['erp.ar-aging.view']))->get('/erp/accounting/ar-aging')->assertOk()->assertInertia(fn (Assert $page) => $page->where('company.id', (int) $this->company->getKey())->has('companies', 1));
    }

    public function test_view_only_cannot_export_csv_or_print(): void
    {
        $staff = $this->staff(['erp.ar-aging.view']);
        foreach (['csv', 'print'] as $format) {
            $this->actingAs($staff)->get($this->url($format))->assertForbidden();
        }
    }

    public function test_export_only_does_not_grant_view_or_download(): void
    {
        $staff = $this->staff(['erp.ar-aging.export']);
        $this->actingAs($staff)->get($this->url())->assertForbidden();
        $this->get($this->url('csv'))->assertForbidden();
    }

    public function test_foreign_company_screen_and_export_are_forbidden(): void
    {
        $other = Company::query()->create(['code' => 'AR-SCREEN-B', 'name' => 'Foreign', 'status' => 'active', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka']);
        $this->actingAs($this->staff(['erp.ar-aging.view', 'erp.ar-aging.export']))->get('/erp/accounting/ar-aging?company_id='.$other->getKey())->assertForbidden();
        $this->get('/erp/accounting/ar-aging/export?company_id='.$other->getKey().'&as_of=2026-10-16&format=csv')->assertForbidden();
    }

    public function test_csv_neutralizes_formula_name_and_excludes_private_receipt_text(): void
    {
        $this->invoice();
        $this->postReceipt($this->receipt());
        DB::table('companies')->where('id', $this->company->getKey())->update(['name' => '=1+1']);
        $csv = $this->actingAs($this->creator)->get($this->url('csv'))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString('60.00', $csv);
        $this->assertStringContainsString('unapplied_customer_funds', $csv);
        $this->assertStringNotContainsString('PRIVATE-BANK-REFERENCE', $csv);
        $this->assertStringNotContainsString('PRIVATE-RECEIPT-NOTES', $csv);
    }

    public function test_print_escapes_html_labels(): void
    {
        DB::table('companies')->where('id', $this->company->getKey())->update(['name' => '<script>alert(1)</script>']);
        $this->actingAs($this->creator)->get($this->url('print'))->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>', false)->assertSee('Unknown is not zero');
    }

    public function test_unknown_legacy_amount_is_null_on_screen_and_explicit_in_export(): void
    {
        $this->settings(['state' => 'disabled']);
        $this->invoice();
        $this->actingAs($this->creator)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.rows.0.outstanding', null)->where('report.complete', false));
        $csv = $this->get($this->url('csv'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Unknown', $csv);
        $this->assertStringContainsString('PROVISIONAL', $csv);
    }

    public function test_export_includes_all_documents_despite_page_controls(): void
    {
        $this->balance->forceFill(['quantity_on_hand' => '20.0000'])->save();
        $a = $this->invoice();
        $b = $this->invoice();
        $csv = $this->actingAs($this->creator)->get($this->url('csv', '&page=99&per_page=1'))->assertOk()->streamedContent();
        $this->assertStringContainsString((string) $a->getAttribute('number'), $csv);
        $this->assertStringContainsString((string) $b->getAttribute('number'), $csv);
    }

    public function test_future_dates_and_partial_scope_are_rejected(): void
    {
        $this->actingAs($this->creator)->getJson('/erp/accounting/ar-aging?company_id='.$this->company->getKey().'&as_of=2026-10-17')->assertUnprocessable();
        $this->getJson($this->url(null, '&party_id=1'))->assertUnprocessable();
        $this->getJson($this->url('csv', '&branch_id=1'))->assertUnprocessable();
    }

    public function test_screen_and_download_are_private_and_read_only(): void
    {
        $this->invoice();
        $before = DB::table('journal_entries')->count();
        foreach ([null, 'csv'] as $format) {
            $response = $this->actingAs($this->creator)->get($this->url($format))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            if ($format === 'csv') {
                $response->streamedContent();
            }
        }
        $this->assertSame($before, DB::table('journal_entries')->count());
    }
}

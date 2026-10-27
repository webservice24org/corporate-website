<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Services\ERP\Accounting\Reports\AccountingAgingReader;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingAgingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ERP\AccountingApAgingTestCase;

final class AccountingApAgingReaderTest extends AccountingApAgingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingAgingPermissionSeeder::class);
    }

    /** @return array<string, mixed> */
    private function report(string $asOf): array
    {
        return app(AccountingAgingReader::class)->report($this->actor, (int) $this->company->getKey(), 'ap', ['as_of' => $asOf]);
    }

    public function test_current_bill_position_matches_ap_without_netting_advances(): void
    {
        $bill = $this->liveBill();
        $r = $this->report(now('Asia/Dhaka')->toDateString());
        $this->assertSame(bcadd((string) $bill->getAttribute('grand_total'), '0', 2), $r['totals']['signed_documents']);
        $this->assertTrue($r['reconciliation']['reconciled']);
    }

    public function test_later_payment_does_not_reduce_prior_dated_bill_balance(): void
    {
        $bill = $this->liveBill();
        $day = now('Asia/Dhaka')->toDateString();
        $amount = bcadd((string) $bill->getAttribute('grand_total'), '0', 2);
        $this->travelTo(CarbonImmutable::now('Asia/Dhaka')->addDay());
        $this->postPayment($this->ready($bill, $this->paymentData($bill, '40.13', '40.13')));
        $r = $this->report($day);
        $this->assertSame($amount, $r['totals']['signed_documents']);
        $this->assertTrue($r['reconciliation']['reconciled']);
    }

    public function test_historical_bill_terms_are_explicitly_provisional(): void
    {
        $this->liveBill();
        $day = now('Asia/Dhaka')->toDateString();
        $this->travelTo(CarbonImmutable::now('Asia/Dhaka')->addDay());
        $r = $this->report($day);
        $this->assertNull($r['confirmed_totals']);
        $this->assertContains('historical_due_terms_unverified', array_column($r['issues'], 'issue'));
    }

    public function test_ap_aging_has_its_own_permission(): void
    {
        $this->expectException(AuthorizationException::class);
        app(AccountingAgingReader::class)->report($this->staff([]), (int) $this->company->getKey(), 'ap', ['as_of' => now('Asia/Dhaka')->toDateString()]);
    }

    public function test_read_appends_no_journals_or_payment_allocations(): void
    {
        $this->liveBill();
        $before = [DB::table('journal_entries')->count(), DB::table('supplier_payment_allocations')->count()];
        $this->report(now('Asia/Dhaka')->toDateString());
        $this->assertSame($before, [DB::table('journal_entries')->count(), DB::table('supplier_payment_allocations')->count()]);
    }
}

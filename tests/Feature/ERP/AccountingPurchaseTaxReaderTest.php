<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Services\ERP\Accounting\Reports\AccountingTaxReader;
use Database\Seeders\AccountingTaxPermissionSeeder;
use Tests\Support\ERP\AccountingApAgingTestCase;

final class AccountingPurchaseTaxReaderTest extends AccountingApAgingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingTaxPermissionSeeder::class);
    }

    /** @return array<string, mixed> */
    private function taxReport(): array
    {
        return app(AccountingTaxReader::class)->report($this->actor, (int) $this->company->getKey(), ['from' => '2026-10-01', 'to' => now('Asia/Dhaka')->toDateString()]);
    }

    public function test_supplier_bill_input_tax_matches_native_recipe(): void
    {
        $bill = $this->liveBill();
        $r = $this->taxReport();
        $this->assertTrue($r['complete']);
        $this->assertSame(bcadd((string) $bill->getAttribute('tax_total'), '0', 2), $r['gl']['input_tax']['closing']);
    }

    public function test_supplier_payment_does_not_recognize_input_tax_again(): void
    {
        $bill = $this->liveBill();
        $this->postPayment($this->ready($bill, $this->paymentData($bill, '40.13', '40.13')));
        $r = $this->taxReport();
        $this->assertTrue($r['complete']);
        $this->assertSame(bcadd((string) $bill->getAttribute('tax_total'), '0', 2), $r['source']['input_tax']['closing']);
    }

    public function test_supplier_credit_tax_uses_frozen_native_credit_recipe(): void
    {
        $receipt = $this->receive($this->draftReceipt($this->order()));
        $bill = $this->approveBill($this->draftBill($receipt, '10.0000'));
        $return = $this->postReturn($this->draftReturn($receipt, '1.0000'));
        $this->issueCredit($this->draftCredit($bill, $return));
        $r = $this->taxReport();
        $this->assertTrue($r['complete']);
        $this->assertSame('0.00', $r['difference']['input_tax']['closing']);
        $this->assertGreaterThan(0, bccomp($r['source']['input_tax']['period_credit'], '0', 2));
    }
}

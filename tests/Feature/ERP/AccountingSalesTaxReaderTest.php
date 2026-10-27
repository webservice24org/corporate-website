<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\SalesInvoice;
use App\Services\ERP\Accounting\Reports\AccountingTaxReader;
use Database\Seeders\AccountingTaxPermissionSeeder;
use Tests\Support\ERP\AccountingArAgingTestCase;

final class AccountingSalesTaxReaderTest extends AccountingArAgingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingTaxPermissionSeeder::class);
    }

    private function taxedInvoice(): SalesInvoice
    {
        $o = $this->order([['product_id' => $this->product->getKey(), 'quantity' => '6', 'unit_price' => '10', 'discount_percent' => '0', 'tax_percent' => '10']]);

        return $this->issueInvoice($this->bill($o, $this->fulfillment($o)));
    }

    /** @return array<string, mixed> */
    private function taxReport(): array
    {
        return app(AccountingTaxReader::class)->report($this->creator, (int) $this->company->getKey(), ['from' => '2026-10-01', 'to' => '2026-10-16']);
    }

    public function test_sales_output_tax_matches_admitted_invoice_lines(): void
    {
        $this->taxedInvoice();
        $r = $this->taxReport();
        $this->assertTrue($r['complete']);
        $this->assertSame('6.00', $r['gl']['output_tax']['closing']);
    }

    public function test_receipt_allocation_does_not_create_a_second_output_tax(): void
    {
        $i = $this->taxedInvoice();
        $this->postReceipt($this->receipt(), [['sales_invoice_id' => (int) $i->getKey(), 'amount' => '40.00']]);
        $this->assertSame('6.00', $this->taxReport()['source']['output_tax']['period_credit']);
    }

    public function test_unrecognized_native_invoice_does_not_certify_zero_tax(): void
    {
        $this->settings(['state' => 'disabled']);
        $this->taxedInvoice();
        $r = $this->taxReport();
        $this->assertNull($r['confirmed_balances']);
        $this->assertFalse($r['complete']);
    }
}

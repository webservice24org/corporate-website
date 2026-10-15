<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesQuotation;
use App\Models\SalesQuotationItem;
use App\Models\Unit;
use App\Services\ERP\Sales\SalesDocumentNumberService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

final class SalesDomainFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $code = 'SALES-A'): Company
    {
        return Company::query()->create(['name' => $code, 'code' => $code, 'currency_code' => 'BDT', 'status' => 'active']);
    }

    private function quotation(Company $company): SalesQuotation
    {
        $customer = Customer::query()->create(['company_id' => $company->getKey(), 'name' => 'Sales customer', 'phone' => '01700000000', 'customer_type' => 'individual']);

        return SalesQuotation::query()->create([
            'company_id' => $company->getKey(), 'customer_id' => $customer->getKey(),
            'number' => 'SQ-2026-000001', 'currency_code' => 'BDT', 'document_date' => '2026-10-14',
            'customer_snapshot' => ['name' => 'Sales customer'], 'grand_total' => '12.34',
        ]);
    }

    public function test_foundation_tables_exist_without_stock_effects(): void
    {
        foreach ([
            'sales_document_sequences', 'sales_document_events', 'sales_quotations', 'sales_quotation_items',
            'sales_orders', 'sales_order_items', 'sales_stock_reservations', 'sales_reservation_events',
            'sales_deliveries', 'sales_delivery_items', 'sales_invoices', 'sales_invoice_items',
            'customer_receipts', 'customer_receipt_allocations', 'sales_returns', 'sales_return_items',
            'sales_credit_notes', 'sales_credit_note_items',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_balances', 0);
    }

    public function test_numbers_are_separate_by_company_type_and_document_year(): void
    {
        $a = $this->company();
        $b = $this->company('SALES-B');
        $service = app(SalesDocumentNumberService::class);
        DB::transaction(function () use ($service, $a, $b): void {
            $this->assertSame('SO-2026-000001', $service->next((int) $a->getKey(), 'order', '2026-10-14'));
            $this->assertSame('SO-2026-000002', $service->next((int) $a->getKey(), 'order', '2026-10-15'));
            $this->assertSame('SO-2026-000001', $service->next((int) $b->getKey(), 'order', '2026-10-14'));
            $this->assertSame('SI-2026-000001', $service->next((int) $a->getKey(), 'invoice', '2026-10-14'));
            $this->assertSame('SO-2027-000001', $service->next((int) $a->getKey(), 'order', '2027-01-01'));
        });
    }

    public function test_rolled_back_allocation_does_not_consume_a_number(): void
    {
        $company = $this->company();
        $service = app(SalesDocumentNumberService::class);
        try {
            DB::transaction(function () use ($service, $company): void {
                $service->next((int) $company->getKey(), 'order', '2026-10-14');
                throw new RuntimeException('Rollback fixture');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Rollback fixture', $exception->getMessage());
        }
        $this->assertSame('SO-2026-000001', DB::transaction(fn (): string => $service->next((int) $company->getKey(), 'order', '2026-10-14')));
    }

    public function test_invalid_dates_and_types_do_not_create_sequences(): void
    {
        $company = $this->company();
        $service = app(SalesDocumentNumberService::class);
        foreach ([['order', '2026-02-30'], ['order', '2026-1-01'], ['unknown', '2026-10-14']] as [$type, $date]) {
            try {
                $service->next((int) $company->getKey(), $type, $date);
                $this->fail('Invalid allocator input was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('sales_document_sequences', 0);
            }
        }
    }

    public function test_sequence_exhaustion_is_rejected(): void
    {
        $company = $this->company();
        DB::table('sales_document_sequences')->insert(['company_id' => $company->getKey(), 'document_type' => 'order', 'year' => 2026, 'last_number' => 999999]);
        $this->expectException(LogicException::class);
        app(SalesDocumentNumberService::class)->next((int) $company->getKey(), 'order', '2026-10-14');
    }

    public function test_model_casts_and_customer_source_relation_resolve(): void
    {
        $quotation = $this->quotation($this->company())->fresh();
        $this->assertInstanceOf(SalesQuotation::class, $quotation);
        $this->assertSame('12.34', $quotation->grand_total);
        $this->assertSame(['name' => 'Sales customer'], $quotation->customer_snapshot);
        $this->assertSame('2026-10-14', $quotation->document_date->format('Y-m-d'));
        $this->assertInstanceOf(Customer::class, $quotation->customer);
        $this->assertSame(0, $quotation->items()->count());
    }

    public function test_referenced_customer_cannot_be_hard_deleted(): void
    {
        $quotation = $this->quotation($this->company());
        $this->expectException(QueryException::class);
        DB::table('customers')->where('id', $quotation->customer_id)->delete();
    }

    public function test_nonpositive_line_quantity_is_rejected_by_database(): void
    {
        $company = $this->company();
        $quotation = $this->quotation($company);
        $unit = Unit::query()->create(['company_id' => $company->getKey(), 'name' => 'Piece', 'short_name' => 'pc']);
        $product = Product::query()->create(['company_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'name' => 'Sales item']);
        $this->expectException(QueryException::class);
        SalesQuotationItem::query()->create(['sales_quotation_id' => $quotation->getKey(), 'line_number' => 1, 'product_id' => $product->getKey(), 'unit_id' => $unit->getKey(), 'quantity' => '0.0000']);
    }

    public function test_document_number_is_unique_within_company(): void
    {
        $quotation = $this->quotation($this->company());
        $this->expectException(QueryException::class);
        DB::table('sales_quotations')->insert(['company_id' => $quotation->company_id, 'customer_id' => $quotation->customer_id, 'number' => $quotation->number, 'currency_code' => 'BDT', 'document_date' => '2026-10-14']);
    }
}

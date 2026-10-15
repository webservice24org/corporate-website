<?php

namespace Tests\Feature\ERP;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalesSchemaAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_reports_metadata_without_writing_to_the_database(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $exitCode = Artisan::call('sales:schema-audit', ['--json' => true, '--allow-sales-tables' => true]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($report['ready_for_schema_design']);
        $this->assertSame('schema_metadata_only', $report['scope']);
        $this->assertArrayHasKey('inventory_balances', $report['tables']);
        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i', $sql);
        }
        $this->assertTrue(Schema::hasTable('sales_orders'));
    }

    public function test_existing_sales_table_blocks_the_next_schema_step(): void
    {
        $this->assertTrue(Schema::hasTable('sales_orders'));
        $this->assertSame(1, Artisan::call('sales:schema-audit', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($report['ready_for_schema_design']);
        $this->assertContains('Planned Sales table already exists; review before 5.1B: sales_orders', $report['errors']);
    }
}

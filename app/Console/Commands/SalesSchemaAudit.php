<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SalesSchemaAudit extends Command
{
    protected $signature = 'sales:schema-audit {--json : Output metadata as JSON} {--allow-sales-tables : Allow installed Sales tables during prerequisite checks}';

    protected $description = 'Read-only Sales prerequisite and table-name audit (no customer data)';

    /** @var array<string, list<string>> */
    private const REQUIRED = [
        'companies' => ['id', 'currency_code', 'status'],
        'users' => ['id'],
        'employees' => ['id', 'company_id', 'user_id'],
        'customers' => ['id', 'company_id', 'customer_code', 'credit_limit', 'payment_terms_days', 'is_active', 'deleted_at'],
        'customer_contacts' => ['id', 'customer_id', 'is_primary'],
        'customer_addresses' => ['id', 'customer_id', 'address_type', 'address', 'is_primary'],
        'leads' => ['id', 'company_id', 'converted_to_type', 'converted_to_id'],
        'opportunities' => ['id', 'company_id', 'party_type', 'party_id', 'currency'],
        'units' => ['id'],
        'products' => ['id', 'company_id', 'unit_id', 'product_type', 'selling_price', 'track_inventory', 'has_variants', 'is_active'],
        'product_variants' => ['id', 'product_id', 'is_active'],
        'warehouses' => ['id', 'company_id', 'is_active'],
        'inventory_balances' => ['id', 'company_id', 'warehouse_id', 'product_id', 'product_variant_id', 'variant_key', 'quantity_on_hand', 'quantity_reserved', 'average_cost'],
        'stock_movements' => ['id', 'company_id', 'warehouse_id', 'product_id', 'product_variant_id', 'movement_type', 'direction', 'quantity', 'unit_cost', 'reference_type', 'reference_id'],
    ];

    /** @var list<string> */
    private const PLANNED = [
        'sales_document_sequences', 'sales_document_events',
        'sales_quotations', 'sales_quotation_items',
        'sales_orders', 'sales_order_items',
        'sales_stock_reservations', 'sales_reservation_events',
        'sales_deliveries', 'sales_delivery_items',
        'sales_invoices', 'sales_invoice_items',
        'customer_receipts', 'customer_receipt_allocations',
        'sales_returns', 'sales_return_items',
        'sales_credit_notes', 'sales_credit_note_items',
    ];

    public function handle(): int
    {
        $tables = [];
        $errors = [];
        $warnings = [
            'Metadata audit only: row integrity, CHECK enforcement and application authorization are not verified.',
            'Review credit_limit=0 semantics before enabling credit sales; existing CRM source does not define them.',
            'Validate company currency codes before using them in Sales; existing columns allow up to 10 characters.',
        ];

        try {
            foreach (self::REQUIRED as $table => $required) {
                if (! Schema::hasTable($table)) {
                    $errors[] = "Missing prerequisite table: {$table}";

                    continue;
                }

                $columns = Schema::getColumnListing($table);
                $missing = array_values(array_diff($required, $columns));
                $indexes = Schema::getIndexes($table);
                $tables[$table] = [
                    'columns' => $columns,
                    'missing_columns' => $missing,
                    'column_metadata' => Schema::getColumns($table),
                    'indexes' => $indexes,
                    'foreign_keys' => Schema::getForeignKeys($table),
                ];

                if ($missing !== []) {
                    $errors[] = "Missing columns in {$table}: ".implode(', ', $missing);
                }

                if ($table === 'inventory_balances') {
                    $identityFound = false;
                    foreach ($indexes as $index) {
                        if ($index['unique'] && $index['columns'] === ['warehouse_id', 'product_id', 'variant_key']) {
                            $identityFound = true;
                        }
                    }

                    if (! $identityFound) {
                        $errors[] = 'Missing unique inventory stock identity (warehouse_id, product_id, variant_key).';
                    }
                }
            }

            foreach (self::PLANNED as $table) {
                if (Schema::hasTable($table) && ! $this->option('allow-sales-tables')) {
                    $errors[] = "Planned Sales table already exists; review before 5.1B: {$table}";
                }
            }
        } catch (Throwable) {
            // Do not expose connection credentials or exception SQL in the report.
            $errors[] = 'Metadata inspection failed. Check the configured connection and schema metadata permissions locally.';
        }

        $report = [
            'audit_version' => '5.1A',
            'scope' => 'schema_metadata_only',
            'ready_for_schema_design' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'tables' => $tables,
            'planned_table_names' => self::PLANNED,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->info('Sales 5.1A: read-only schema metadata audit');
            foreach ($errors as $error) {
                $this->error($error);
            }
            foreach ($warnings as $warning) {
                $this->warn($warning);
            }
            $this->line($errors === [] ? 'Prerequisite metadata passed. Complete policy and integrity review before 5.1B.' : 'Prerequisite metadata failed. Review the errors before 5.1B.');
            $this->line('Use --json to capture column, index and foreign-key metadata.');
        }

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }
}

<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;

// Optional development CLI probe. Metadata only; no routes or business writes.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
if (! is_file($projectRoot.'/vendor/autoload.php') || ! is_file($projectRoot.'/bootstrap/app.php')) {
    fwrite(STDERR, "Run this script inside the installed Laravel project.\n");
    exit(2);
}

require $projectRoot.'/vendor/autoload.php';

$required = [
    'companies' => ['id', 'currency_code', 'timezone', 'status'],
    'employees' => ['company_id', 'user_id'],
    'goods_receipts' => ['company_id', 'status', 'posted_at'],
    'goods_receipt_items' => ['stock_movement_id', 'unit_cost', 'is_stock_item'],
    'supplier_bills' => ['company_id', 'supplier_id', 'status', 'approved_at', 'grand_total'],
    'supplier_payables' => ['company_id', 'supplier_id', 'supplier_bill_id', 'amount', 'paid_amount'],
    'purchase_returns' => ['company_id', 'status', 'posted_at'],
    'stock_movements' => ['company_id', 'unit_cost', 'total_cost', 'reference_type', 'reference_id'],
    'inventory_balances' => ['company_id', 'quantity_on_hand', 'quantity_reserved', 'average_cost'],
    'sales_invoices' => ['company_id', 'customer_id', 'issued_at', 'idempotency_key', 'payload_hash'],
    'sales_invoice_items' => ['sales_delivery_item_id', 'credited_quantity', 'credited_amount'],
    'customer_receipts' => ['company_id', 'customer_id', 'posted_at', 'reverses_receipt_id'],
    'customer_receipt_allocations' => ['company_id', 'sales_invoice_id', 'reverses_allocation_id', 'posted_at'],
    'sales_return_items' => ['unbilled_quantity', 'credit_review_quantity', 'stock_movement_id'],
    'sales_credit_notes' => ['company_id', 'issued_at', 'issue_sequence', 'payload_hash'],
    'customer_credit_allocations' => ['company_id', 'source_sales_invoice_id', 'sales_invoice_id', 'reverses_allocation_id'],
    'sales_document_events' => ['company_id', 'document_type', 'document_id', 'action'],
    'payroll_periods' => ['company_id', 'start_date', 'end_date', 'status'],
];
$futureTables = [
    'fiscal_years', 'accounting_periods', 'fiscal_periods', 'account_groups', 'accounts',
    'accounting_accounts', 'journal_entries', 'journal_entry_lines', 'accounting_journals',
    'accounting_journal_lines', 'bank_accounts', 'bank_transactions', 'bank_reconciliations',
    'expense_categories', 'expenses', 'expense_attachments', 'supplier_payments',
    'supplier_payment_allocations', 'supplier_credit_notes', 'payrolls', 'payroll_items', 'payroll_runs',
];

try {
    $app = require $projectRoot.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $result = [
        'audit' => 'phase-6.1A-installed-schema',
        'generated_at_utc' => gmdate('c'),
        'scope' => 'Known table and column existence only; no business values or financial reconciliation',
        'operational' => [],
        'future_discovery' => [],
        'missing_operational' => [],
    ];
    foreach ($required as $table => $columns) {
        $present = Schema::hasTable($table);
        $result['operational'][$table] = ['present' => $present, 'columns' => []];
        if (! $present) {
            $result['missing_operational'][] = $table;

            continue;
        }
        foreach ($columns as $column) {
            $exists = Schema::hasColumn($table, $column);
            $result['operational'][$table]['columns'][$column] = $exists;
            if (! $exists) {
                $result['missing_operational'][] = $table.'.'.$column;
            }
        }
    }
    foreach ($futureTables as $table) {
        $result['future_discovery'][$table] = Schema::hasTable($table);
    }
    $result['operational_prerequisites_present'] = $result['missing_operational'] === [];
    $result['accounting_readiness'] = 'Not determined by this metadata probe';
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    exit($result['operational_prerequisites_present'] ? 0 : 1);
} catch (Throwable $exception) {
    // Do not print SQL, connection details, credentials or business values.
    fwrite(STDERR, 'Schema inspection failed ('.$exception::class."). Check Laravel configuration locally.\n");
    exit(2);
}

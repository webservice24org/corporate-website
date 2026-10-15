<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @return array<string, string> */
    private function checks(): array
    {
        return [
            'sales_document_sequences' => '(last_number >= 0) AND (last_number <= 999999) AND (year >= 1) AND (year <= 9999)',
            'sales_quotations' => '(subtotal >= 0) AND (discount_total >= 0) AND (tax_total >= 0) AND (grand_total >= 0)',
            'sales_quotation_items' => '(quantity > 0) AND (unit_price >= 0) AND (discount_percent >= 0) AND (tax_percent >= 0) AND (subtotal >= 0) AND (discount_amount >= 0) AND (tax_amount >= 0) AND (line_total >= 0) AND (discount_percent <= 100) AND (tax_percent <= 100)',
            'sales_orders' => '(subtotal >= 0) AND (discount_total >= 0) AND (tax_total >= 0) AND (grand_total >= 0)',
            'sales_order_items' => '(quantity > 0) AND (unit_price >= 0) AND (discount_percent >= 0) AND (tax_percent >= 0) AND (subtotal >= 0) AND (discount_amount >= 0) AND (tax_amount >= 0) AND (line_total >= 0) AND (cancelled_quantity >= 0) AND (delivered_quantity >= 0) AND (invoiced_quantity >= 0) AND (discount_percent <= 100) AND (tax_percent <= 100) AND (cancelled_quantity + delivered_quantity <= quantity) AND (invoiced_quantity <= delivered_quantity)',
            'sales_stock_reservations' => '(allocated_quantity >= 0) AND (released_quantity >= 0) AND (consumed_quantity >= 0) AND (released_quantity + consumed_quantity <= allocated_quantity) AND (variant_key = COALESCE(product_variant_id, 0))',
            'sales_reservation_events' => '(quantity > 0)',
            'sales_delivery_items' => '(quantity > 0) AND (unit_cost >= 0) AND (total_cost >= 0) AND (invoiced_quantity >= 0) AND (returned_quantity >= 0) AND (invoiced_quantity <= quantity) AND (returned_quantity <= quantity)',
            'sales_invoices' => '(subtotal >= 0) AND (discount_total >= 0) AND (tax_total >= 0) AND (grand_total >= 0)',
            'sales_invoice_items' => '(quantity > 0) AND (unit_price >= 0) AND (discount_percent >= 0) AND (tax_percent >= 0) AND (subtotal >= 0) AND (discount_amount >= 0) AND (tax_amount >= 0) AND (line_total >= 0) AND (credited_quantity >= 0) AND (credited_amount >= 0) AND (discount_percent <= 100) AND (tax_percent <= 100) AND (credited_quantity <= quantity) AND (credited_amount <= line_total)',
            'customer_receipts' => '(amount > 0)',
            'customer_receipt_allocations' => '(amount > 0)',
            'sales_return_items' => '(quantity > 0) AND (accepted_quantity >= 0) AND (restocked_quantity >= 0) AND (unit_cost >= 0) AND (accepted_quantity <= quantity) AND (restocked_quantity <= accepted_quantity)',
            'sales_credit_notes' => '(subtotal >= 0) AND (discount_total >= 0) AND (tax_total >= 0) AND (grand_total >= 0)',
            'sales_credit_note_items' => '(quantity >= 0) AND (unit_price >= 0) AND (discount_percent >= 0) AND (tax_percent >= 0) AND (subtotal >= 0) AND (discount_amount >= 0) AND (tax_amount >= 0) AND (line_total >= 0) AND (discount_percent <= 100) AND (tax_percent <= 100)',
        ];
    }

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new RuntimeException('Sales foundation supports MySQL, MariaDB and SQLite.');
        }

        foreach ($this->checks() as $table => $expression) {
            $name = $table.'_integrity_chk';
            if ($driver === 'sqlite') {
                $condition = preg_replace_callback(
                    '/\b[a-z_][a-z_0-9]*\b/i',
                    static function (array $match): string {
                        return in_array(strtoupper($match[0]), ['AND', 'COALESCE'], true)
                            ? $match[0]
                            : 'NEW."'.$match[0].'"';
                    },
                    $expression
                );
                foreach (['insert', 'update'] as $action) {
                    DB::unprepared('CREATE TRIGGER "'.$name.'_'.$action.'" BEFORE '.strtoupper($action)
                        .' ON "'.$table.'" FOR EACH ROW WHEN NOT ('.$condition.') BEGIN SELECT RAISE(ABORT, '
                        ."'{$name} violated'); END");
                }
            } else {
                DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
            }
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        foreach ($this->checks() as $table => $expression) {
            $name = $table.'_integrity_chk';
            if ($driver === 'sqlite') {
                foreach (['insert', 'update'] as $action) {
                    DB::statement('DROP TRIGGER IF EXISTS "'.$name.'_'.$action.'"');
                }
            } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
                $keyword = $driver === 'mariadb' ? 'CONSTRAINT' : 'CHECK';
                DB::statement("ALTER TABLE `{$table}` DROP {$keyword} `{$name}`");
            } else {
                throw new RuntimeException('Unsupported Sales foundation rollback driver.');
            }
        }
    }
};

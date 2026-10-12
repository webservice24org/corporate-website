<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Database-level inventory integrity rules.
     *
     * MySQL / MariaDB: CHECK constraints.
     * SQLite: INSERT and UPDATE validation triggers.
     *
     * Existing historical migrations remain unchanged.
     */
    private function constraints(): array
    {
        return [
            'inventory_balances' => [
                'inv_bal_reserved_nonnegative_chk' =>
                    'quantity_reserved >= 0',

                'inv_bal_cost_nonnegative_chk' =>
                    'average_cost >= 0',

                'inv_bal_variant_consistency_chk' =>
                    'variant_key = COALESCE(product_variant_id, 0)',
            ],

            'stock_transfer_items' => [
                'stock_transfer_item_qty_positive_chk' =>
                    'quantity > 0',
            ],

            'stock_transfers' => [
                'stock_transfer_warehouses_differ_chk' =>
                    'source_warehouse_id <> destination_warehouse_id',
            ],

            'stock_movements' => [
                'stock_movement_qty_positive_chk' =>
                    'quantity > 0',
            ],
        ];
    }

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->addSqlCheckConstraints();

            return;
        }

        if ($driver === 'sqlite') {
            $this->addSqliteValidationTriggers();

            return;
        }

        throw new RuntimeException(
            "Unsupported database driver for inventory constraints: {$driver}"
        );
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            foreach ($this->constraints() as $table => $checks) {
                foreach ($checks as $name => $expression) {
                    if ($driver === 'mariadb') {
                        DB::statement(
                            "ALTER TABLE `{$table}` DROP CONSTRAINT `{$name}`"
                        );
                    } else {
                        DB::statement(
                            "ALTER TABLE `{$table}` DROP CHECK `{$name}`"
                        );
                    }
                }
            }

            return;
        }

        if ($driver === 'sqlite') {
            foreach ($this->constraints() as $checks) {
                foreach ($checks as $name => $expression) {
                    DB::statement(
                        "DROP TRIGGER IF EXISTS \"{$name}_insert\""
                    );

                    DB::statement(
                        "DROP TRIGGER IF EXISTS \"{$name}_update\""
                    );
                }
            }

            return;
        }

        throw new RuntimeException(
            "Unsupported database driver for inventory rollback: {$driver}"
        );
    }

    private function addSqlCheckConstraints(): void
    {
        foreach ($this->constraints() as $table => $checks) {
            foreach ($checks as $name => $expression) {
                DB::statement(
                    "ALTER TABLE `{$table}` " .
                    "ADD CONSTRAINT `{$name}` " .
                    "CHECK ({$expression})"
                );
            }
        }
    }

    private function addSqliteValidationTriggers(): void
    {
        foreach ($this->constraints() as $table => $checks) {
            foreach ($checks as $name => $expression) {
                $condition = $this->sqliteExpression($expression);

                DB::unprepared(
                    "CREATE TRIGGER \"{$name}_insert\"
                     BEFORE INSERT ON \"{$table}\"
                     FOR EACH ROW
                     WHEN NOT ({$condition})
                     BEGIN
                         SELECT RAISE(
                             ABORT,
                             '{$name} violated'
                         );
                     END"
                );

                DB::unprepared(
                    "CREATE TRIGGER \"{$name}_update\"
                     BEFORE UPDATE ON \"{$table}\"
                     FOR EACH ROW
                     WHEN NOT ({$condition})
                     BEGIN
                         SELECT RAISE(
                             ABORT,
                             '{$name} violated'
                         );
                     END"
                );
            }
        }
    }

    /**
     * SQLite trigger conditions must reference NEW columns.
     */
    private function sqliteExpression(string $expression): string
    {
        $columns = [
            'quantity_reserved',
            'average_cost',
            'variant_key',
            'product_variant_id',
            'quantity',
            'source_warehouse_id',
            'destination_warehouse_id',
        ];

        return (string) preg_replace_callback(
            '/\b(' . implode('|', $columns) . ')\b/',
            static fn(array $match): string =>
                'NEW."' . $match[1] . '"',
            $expression
        );
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_quotations', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_id')->nullable()->change();
        });
        $this->restoreSqliteChecks();
    }

    public function down(): void
    {
        if (DB::table('sales_quotations')->whereNull('customer_id')->exists()) {
            throw new RuntimeException('Resolve lead-only quotations before rolling back the nullable customer reference.');
        }
        Schema::table('sales_quotations', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_id')->nullable(false)->change();
        });
        $this->restoreSqliteChecks();
    }

    private function restoreSqliteChecks(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }
        // SQLite column alteration may rebuild the table and remove its existing triggers.
        $condition = 'NEW."subtotal" >= 0 AND NEW."discount_total" >= 0 AND NEW."tax_total" >= 0 AND NEW."grand_total" >= 0';
        foreach (['insert', 'update'] as $action) {
            $name = 'sales_quotations_integrity_chk_'.$action;
            DB::unprepared('CREATE TRIGGER IF NOT EXISTS "'.$name.'" BEFORE '.strtoupper($action)
                .' ON "sales_quotations" FOR EACH ROW WHEN NOT ('.$condition.') BEGIN SELECT RAISE(ABORT, '
                ."'sales_quotations_integrity_chk violated'); END");
        }
    }
};

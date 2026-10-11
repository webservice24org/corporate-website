<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'inventory_balances',
            function (Blueprint $table) {
                $table
                    ->unsignedBigInteger(
                        'variant_key'
                    )
                    ->default(0)
                    ->after(
                        'product_variant_id'
                    );
            }
        );

        DB::table(
            'inventory_balances'
        )->whereNotNull(
            'product_variant_id'
        )->update([
            'variant_key' =>
                DB::raw(
                    'product_variant_id'
                ),
        ]);

        Schema::table(
            'inventory_balances',
            function (Blueprint $table) {
                $table->unique(
                    [
                        'warehouse_id',
                        'product_id',
                        'variant_key',
                    ],
                    'inventory_balances_stock_identity_unique'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'inventory_balances',
            function (Blueprint $table) {
                $table->dropUnique(
                    'inventory_balances_stock_identity_unique'
                );

                $table->dropColumn(
                    'variant_key'
                );
            }
        );
    }
};
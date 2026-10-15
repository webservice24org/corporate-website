<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_return_items', function (Blueprint $table): void {
            $table->decimal('unbilled_quantity', 18, 4)->default(0);
            $table->decimal('credit_review_quantity', 18, 4)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('sales_return_items', function (Blueprint $table): void {
            $table->dropColumn(['unbilled_quantity', 'credit_review_quantity']);
        });
    }
};

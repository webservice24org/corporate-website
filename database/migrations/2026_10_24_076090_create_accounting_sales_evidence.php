<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['accounting_sales_events', 'accounting_sales_cost_slices'] as $name) {
            if (Schema::hasTable($name)) {
                throw new RuntimeException($name.' already exists; reconcile the collision before migrating.');
            }
        }
        Schema::create('accounting_sales_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('source_kind', 40);
            $t->unsignedBigInteger('source_id');
            $t->json('payload');
            $t->char('payload_hash', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'source_kind', 'source_id'], 'ase_source_identity');
            $t->unique(['id', 'company_id'], 'ase_id_company');
        });
        Schema::create('accounting_sales_cost_slices', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('sales_event_id');
            $t->foreign(['sales_event_id', 'company_id'], 'ascs_event_company')->references(['id', 'company_id'])->on('accounting_sales_events')->restrictOnDelete()->restrictOnUpdate();
            $t->foreignId('sales_delivery_item_id')->constrained('sales_delivery_items')->restrictOnDelete();
            $t->unsignedBigInteger('source_line_id');
            $t->decimal('quantity', 18, 4);
            $t->decimal('quantity_before', 18, 4);
            $t->decimal('quantity_after', 18, 4);
            $t->decimal('amount', 20, 2);
            $t->timestamps();
            $t->unique(['sales_event_id', 'source_line_id'], 'ascs_event_line');
            $t->index(['company_id', 'sales_delivery_item_id'], 'ascs_delivery_item');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_sales_cost_slices');
        Schema::dropIfExists('accounting_sales_events');
    }
};

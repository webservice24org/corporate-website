<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['accounting_purchase_events', 'accounting_purchase_cost_slices'] as $name) {
            if (Schema::hasTable($name)) {
                throw new RuntimeException($name.' already exists; reconcile the collision before migrating.');
            }
        }
        Schema::create('accounting_purchase_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('source_kind', 40);
            $t->unsignedBigInteger('source_id');
            $t->json('payload');
            $t->char('payload_hash', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'source_kind', 'source_id'], 'ape_source_identity');
            $t->unique(['id', 'company_id'], 'ape_id_company');
        });
        Schema::create('accounting_purchase_cost_slices', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('purchase_event_id');
            $t->foreign(['purchase_event_id', 'company_id'], 'apcs_event_company')->references(['id', 'company_id'])->on('accounting_purchase_events')->restrictOnDelete()->restrictOnUpdate();
            $t->foreignId('goods_receipt_item_id')->constrained('goods_receipt_items')->restrictOnDelete();
            $t->unsignedBigInteger('source_line_id');
            $t->decimal('quantity', 18, 4);
            $t->decimal('grni_quantity', 18, 4);
            $t->decimal('billed_return_quantity', 18, 4);
            $t->decimal('grni_amount', 20, 2);
            $t->decimal('return_clearing_amount', 20, 2);
            $t->timestamps();
            $t->unique(['purchase_event_id', 'source_line_id'], 'apcs_event_line');
            $t->index(['company_id', 'goods_receipt_item_id'], 'apcs_receipt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_purchase_cost_slices');
        Schema::dropIfExists('accounting_purchase_events');
    }
};

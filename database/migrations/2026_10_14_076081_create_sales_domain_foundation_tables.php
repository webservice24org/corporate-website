<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('document_type', 30);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->unique(['company_id', 'document_type', 'year'], 'sales_sequence_scope_unique');
            $table->timestamps();
        });

        Schema::create('sales_document_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('document_type', 30);
            $table->unsignedBigInteger('document_id');
            $table->string('action', 40);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('actor_snapshot')->nullable();
            $table->json('before_payload')->nullable();
            $table->json('after_payload')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at');
            $table->index(['company_id', 'document_type', 'document_id'], 'sales_event_document_index');
            $table->timestamps();
        });

        Schema::create('sales_quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('number', 80);
            $table->unique(['company_id', 'number']);
            $table->string('currency_code', 3);
            $table->string('status', 30)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->date('document_date');
            $table->json('customer_snapshot')->nullable();
            $table->json('billing_address_snapshot')->nullable();
            $table->json('shipping_address_snapshot')->nullable();
            $table->json('terms_snapshot')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('grand_total', 18, 2)->default(0);
            $table->index(['company_id', 'customer_id', 'status']);
            $table->foreignId('opportunity_id')->nullable()->constrained('opportunities')->restrictOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->restrictOnDelete();
            $table->date('valid_until')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_quotation_id')->constrained('sales_quotations')->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->unique(['sales_quotation_id', 'line_number']);
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->json('product_snapshot')->nullable();
            $table->text('description')->nullable();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('discount_percent', 18, 4)->default(0);
            $table->decimal('tax_percent', 18, 4)->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('number', 80);
            $table->unique(['company_id', 'number']);
            $table->string('currency_code', 3);
            $table->string('status', 30)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->date('document_date');
            $table->json('customer_snapshot')->nullable();
            $table->json('billing_address_snapshot')->nullable();
            $table->json('shipping_address_snapshot')->nullable();
            $table->json('terms_snapshot')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('grand_total', 18, 2)->default(0);
            $table->index(['company_id', 'customer_id', 'status']);
            $table->foreignId('sales_quotation_id')->constrained('sales_quotations')->restrictOnDelete();
            $table->unique('sales_quotation_id');
            $table->date('expected_delivery_date')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->json('credit_check_snapshot')->nullable();
            $table->foreignId('credit_override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('credit_override_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->unique(['sales_order_id', 'line_number']);
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->json('product_snapshot')->nullable();
            $table->text('description')->nullable();
            $table->decimal('quantity', 18, 4);
            $table->foreignId('sales_quotation_item_id')->constrained('sales_quotation_items')->restrictOnDelete();
            $table->unique('sales_quotation_item_id');
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('discount_percent', 18, 4)->default(0);
            $table->decimal('tax_percent', 18, 4)->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->decimal('cancelled_quantity', 18, 4)->default(0);
            $table->decimal('delivered_quantity', 18, 4)->default(0);
            $table->decimal('invoiced_quantity', 18, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('sales_stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('sales_order_item_id')->constrained('sales_order_items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->unsignedBigInteger('variant_key')->default(0);
            $table->decimal('allocated_quantity', 18, 4)->default(0);
            $table->decimal('released_quantity', 18, 4)->default(0);
            $table->decimal('consumed_quantity', 18, 4)->default(0);
            $table->unique(['sales_order_item_id', 'warehouse_id', 'product_id', 'variant_key'], 'sales_reservation_identity_unique');
            $table->timestamps();
        });

        Schema::create('sales_reservation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('sales_stock_reservation_id')->constrained('sales_stock_reservations')->restrictOnDelete();
            $table->string('action', 30);
            $table->decimal('quantity', 18, 4);
            $table->string('idempotency_key', 100);
            $table->char('payload_hash', 64);
            $table->unique(['company_id', 'idempotency_key'], 'sales_reservation_event_key_unique');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('number', 80);
            $table->unique(['company_id', 'number']);
            $table->string('currency_code', 3);
            $table->string('status', 30)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->date('document_date');
            $table->json('customer_snapshot')->nullable();
            $table->json('billing_address_snapshot')->nullable();
            $table->json('shipping_address_snapshot')->nullable();
            $table->json('terms_snapshot')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->index(['company_id', 'customer_id', 'status']);
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->unique(['company_id', 'idempotency_key'], 'sales_delivery_key_unique');
            $table->timestamps();
        });

        Schema::create('sales_delivery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_delivery_id')->constrained('sales_deliveries')->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->unique(['sales_delivery_id', 'line_number']);
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->json('product_snapshot')->nullable();
            $table->text('description')->nullable();
            $table->decimal('quantity', 18, 4);
            $table->foreignId('sales_order_item_id')->constrained('sales_order_items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('sales_stock_reservation_id')->nullable()->constrained('sales_stock_reservations')->restrictOnDelete();
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->unique('stock_movement_id');
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('total_cost', 18, 4)->default(0);
            $table->decimal('invoiced_quantity', 18, 4)->default(0);
            $table->decimal('returned_quantity', 18, 4)->default(0);
            $table->string('fulfillment_type', 30)->default('physical');
            $table->timestamps();
        });

        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('number', 80);
            $table->unique(['company_id', 'number']);
            $table->string('currency_code', 3);
            $table->string('status', 30)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->date('document_date');
            $table->json('customer_snapshot')->nullable();
            $table->json('billing_address_snapshot')->nullable();
            $table->json('shipping_address_snapshot')->nullable();
            $table->json('terms_snapshot')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('grand_total', 18, 2)->default(0);
            $table->index(['company_id', 'customer_id', 'status']);
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->date('due_date');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->unique(['company_id', 'idempotency_key'], 'sales_invoice_key_unique');
            $table->timestamps();
        });

        Schema::create('sales_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->unique(['sales_invoice_id', 'line_number']);
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->json('product_snapshot')->nullable();
            $table->text('description')->nullable();
            $table->decimal('quantity', 18, 4);
            $table->foreignId('sales_delivery_item_id')->constrained('sales_delivery_items')->restrictOnDelete();
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('discount_percent', 18, 4)->default(0);
            $table->decimal('tax_percent', 18, 4)->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->decimal('credited_quantity', 18, 4)->default(0);
            $table->decimal('credited_amount', 18, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('customer_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('number', 80);
            $table->unique(['company_id', 'number']);
            $table->string('currency_code', 3);
            $table->string('status', 30)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->date('document_date');
            $table->json('customer_snapshot')->nullable();
            $table->json('billing_address_snapshot')->nullable();
            $table->json('shipping_address_snapshot')->nullable();
            $table->json('terms_snapshot')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->index(['company_id', 'customer_id', 'status']);
            $table->decimal('amount', 18, 2);
            $table->string('payment_method', 30);
            $table->string('payment_reference', 150)->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->unique(['company_id', 'idempotency_key'], 'customer_receipt_key_unique');
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reverses_receipt_id')->nullable()->constrained('customer_receipts')->restrictOnDelete();
            $table->unique('reverses_receipt_id');
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_receipt_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('customer_receipt_id')->constrained('customer_receipts')->restrictOnDelete();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->foreignId('reverses_allocation_id')->nullable()->constrained('customer_receipt_allocations')->restrictOnDelete();
            $table->unique('reverses_allocation_id');
            $table->string('idempotency_key', 100);
            $table->char('payload_hash', 64);
            $table->unique(['company_id', 'idempotency_key'], 'customer_allocation_key_unique');
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at');
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('number', 80);
            $table->unique(['company_id', 'number']);
            $table->string('currency_code', 3);
            $table->string('status', 30)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->date('document_date');
            $table->json('customer_snapshot')->nullable();
            $table->json('billing_address_snapshot')->nullable();
            $table->json('shipping_address_snapshot')->nullable();
            $table->json('terms_snapshot')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->index(['company_id', 'customer_id', 'status']);
            $table->foreignId('sales_delivery_id')->constrained('sales_deliveries')->restrictOnDelete();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('inspected_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->unique(['company_id', 'idempotency_key'], 'sales_return_key_unique');
            $table->timestamps();
        });

        Schema::create('sales_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_return_id')->constrained('sales_returns')->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->unique(['sales_return_id', 'line_number']);
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->json('product_snapshot')->nullable();
            $table->text('description')->nullable();
            $table->decimal('quantity', 18, 4);
            $table->foreignId('sales_delivery_item_id')->constrained('sales_delivery_items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->unique('stock_movement_id');
            $table->decimal('accepted_quantity', 18, 4)->default(0);
            $table->decimal('restocked_quantity', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->string('disposition', 30)->default('pending');
            $table->timestamps();
        });

        Schema::create('sales_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('number', 80);
            $table->unique(['company_id', 'number']);
            $table->string('currency_code', 3);
            $table->string('status', 30)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->date('document_date');
            $table->json('customer_snapshot')->nullable();
            $table->json('billing_address_snapshot')->nullable();
            $table->json('shipping_address_snapshot')->nullable();
            $table->json('terms_snapshot')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('grand_total', 18, 2)->default(0);
            $table->index(['company_id', 'customer_id', 'status']);
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $table->foreignId('sales_return_id')->nullable()->constrained('sales_returns')->restrictOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->text('reason');
            $table->string('idempotency_key', 100)->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->unique(['company_id', 'idempotency_key'], 'sales_credit_key_unique');
            $table->timestamps();
        });

        Schema::create('sales_credit_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_credit_note_id')->constrained('sales_credit_notes')->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->unique(['sales_credit_note_id', 'line_number']);
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->json('product_snapshot')->nullable();
            $table->text('description')->nullable();
            $table->decimal('quantity', 18, 4);
            $table->foreignId('sales_invoice_item_id')->constrained('sales_invoice_items')->restrictOnDelete();
            $table->foreignId('sales_return_item_id')->nullable()->constrained('sales_return_items')->restrictOnDelete();
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('discount_percent', 18, 4)->default(0);
            $table->decimal('tax_percent', 18, 4)->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->string('credit_type', 30)->default('quantity');
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('sales_credit_note_items');
        Schema::dropIfExists('sales_credit_notes');
        Schema::dropIfExists('sales_return_items');
        Schema::dropIfExists('sales_returns');
        Schema::dropIfExists('customer_receipt_allocations');
        Schema::dropIfExists('customer_receipts');
        Schema::dropIfExists('sales_invoice_items');
        Schema::dropIfExists('sales_invoices');
        Schema::dropIfExists('sales_delivery_items');
        Schema::dropIfExists('sales_deliveries');
        Schema::dropIfExists('sales_reservation_events');
        Schema::dropIfExists('sales_stock_reservations');
        Schema::dropIfExists('sales_order_items');
        Schema::dropIfExists('sales_orders');
        Schema::dropIfExists('sales_quotation_items');
        Schema::dropIfExists('sales_quotations');
        Schema::dropIfExists('sales_document_events');
        Schema::dropIfExists('sales_document_sequences');
    }
};

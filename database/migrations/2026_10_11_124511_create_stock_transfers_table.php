<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('document_number', 60);
            $table->date('transfer_date');
            $table->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('destination_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'document_number'], 'stock_transfers_company_document_unique');
            $table->index(['company_id', 'status', 'transfer_date'], 'stock_transfers_company_status_date_idx');
            $table->index(['source_warehouse_id', 'transfer_date'], 'stock_transfers_source_date_idx');
            $table->index(['destination_warehouse_id', 'transfer_date'], 'stock_transfers_destination_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_stocks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('warehouse_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('document_number', 50)
                ->unique();

            $table->date('document_date');

            $table->string('status', 20)
                ->default('draft');

            /*
             * Cached document totals.
             *
             * These are maintained by the backend from
             * opening_stock_items. React must never be
             * trusted to calculate/store these totals.
             */
            $table->unsignedInteger('total_items')
                ->default(0);

            $table->decimal(
                'total_quantity',
                18,
                4
            )->default(0);

            $table->decimal(
                'total_value',
                18,
                4
            )->default(0);

            $table->text('notes')
                ->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('posted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('posted_at')
                ->nullable();

            $table->timestamps();

            $table->index([
                'company_id',
                'warehouse_id',
            ]);

            $table->index([
                'status',
                'document_date',
            ]);

            $table->index([
                'warehouse_id',
                'document_date',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'opening_stocks'
        );
    }
};
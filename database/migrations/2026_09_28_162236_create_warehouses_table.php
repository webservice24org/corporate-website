<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();

            $table
                ->foreignId('company_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table
                ->foreignId('division_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table
                ->foreignId('district_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table
                ->foreignId('upazila_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('code', 50);
            $table->string('name', 150);

            $table
                ->string('type', 30)
                ->default('main');

            $table
                ->string('contact_person', 150)
                ->nullable();

            $table
                ->string('phone', 50)
                ->nullable();

            $table
                ->string('email')
                ->nullable();

            $table
                ->text('address')
                ->nullable();

            $table
                ->string('postal_code', 30)
                ->nullable();

            $table
                ->boolean('is_active')
                ->default(true);

            $table
                ->text('notes')
                ->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['company_id', 'code'],
                'warehouses_company_code_unique'
            );

            $table->index(
                ['company_id', 'is_active']
            );

            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};

<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('customer_code')->nullable()->unique();
            $table->enum('customer_type', ['individual','company'])->default('individual');
            $table->string('name');
            $table->string('business_name')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('phone',50);
            $table->string('alternate_phone',50)->nullable();
            $table->string('email')->nullable();
            $table->string('nid_number',100)->nullable();
            $table->string('tin_number',100)->nullable();
            $table->string('vat_number',100)->nullable();
            $table->decimal('credit_limit',18,2)->default(0);
            $table->unsignedInteger('payment_terms_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id','is_active']);
            $table->index(['customer_type','is_active']);
            $table->index('phone');
        });
    }
    public function down(): void { Schema::dropIfExists('customers'); }
};

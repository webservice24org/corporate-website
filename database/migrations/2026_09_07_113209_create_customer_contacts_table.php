<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('customer_contacts', function(Blueprint $table){
   $table->id();
   $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
   $table->string('name');
   $table->string('designation')->nullable();
   $table->string('phone',50)->nullable();
   $table->string('email')->nullable();
   $table->boolean('is_primary')->default(false);
   $table->text('notes')->nullable();
   $table->timestamps();
   $table->index(['customer_id','is_primary']);
  });
 }
 public function down(): void { Schema::dropIfExists('customer_contacts'); }
};

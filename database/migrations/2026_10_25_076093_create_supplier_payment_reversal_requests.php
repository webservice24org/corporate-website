<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_payment_reversal_requests')) {
            throw new RuntimeException('Supplier payment reversal requests already exist. No DDL was attempted.');
        }
        Schema::create('supplier_payment_reversal_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('supplier_payment_id');
            $t->unsignedBigInteger('reversal_payment_id')->nullable();
            $t->string('status', 20)->default('draft');
            $t->unsignedInteger('revision')->default(1);
            $t->date('document_date');
            $t->text('reason');
            $t->text('cancellation_reason')->nullable();
            $t->unsignedInteger('original_revision');
            $t->char('original_payload_hash', 64);
            $t->char('history_hash', 64);
            $t->char('submitted_payload_hash', 64)->nullable();
            $t->char('approved_payload_hash', 64)->nullable();
            $t->string('idempotency_key', 64)->nullable();
            foreach (['created_by', 'last_edited_by', 'updated_by'] as $field) {
                $t->foreignId($field)->constrained('users')->restrictOnDelete();
            }
            foreach (['submitted', 'approved', 'posted', 'cancelled'] as $action) {
                $t->foreignId($action.'_by')->nullable()->constrained('users')->restrictOnDelete();
                $t->dateTime($action.'_at')->nullable();
            }
            $t->timestamps();
            $t->unique(['company_id', 'idempotency_key'], 'sprr_company_key');
            $t->unique('reversal_payment_id', 'sprr_one_header');
            $t->index(['company_id', 'supplier_payment_id', 'status'], 'sprr_parent_status');
            $t->foreign(['supplier_payment_id', 'company_id'], 'sprr_original_owner')->references(['id', 'company_id'])->on('supplier_payments')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['reversal_payment_id', 'company_id'], 'sprr_reversal_owner')->references(['id', 'company_id'])->on('supplier_payments')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_reversal_requests');
    }
};

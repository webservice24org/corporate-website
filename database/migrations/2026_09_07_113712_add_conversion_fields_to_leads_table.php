<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('converted_to_type',100)->nullable();
            $table->unsignedBigInteger('converted_to_id')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('converted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('conversion_notes')->nullable();
            $table->index(['converted_to_type','converted_to_id'],'leads_converted_target_idx');
            $table->index(['converted_at','converted_by'],'leads_conversion_audit_idx');
        });
    }
    public function down(): void {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_converted_target_idx');
            $table->dropIndex('leads_conversion_audit_idx');
            $table->dropConstrainedForeignId('converted_by');
            $table->dropColumn(['converted_to_type','converted_to_id','converted_at','conversion_notes']);
        });
    }
};

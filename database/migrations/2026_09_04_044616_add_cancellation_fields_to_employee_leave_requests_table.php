<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'employee_leave_requests',
            function (Blueprint $table) {
                $table
                    ->foreignId('cancelled_by')
                    ->nullable()
                    ->after('cancelled_at')
                    ->constrained('users')
                    ->nullOnDelete();

                $table
                    ->text('cancellation_reason')
                    ->nullable()
                    ->after('cancelled_by');
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'employee_leave_requests',
            function (Blueprint $table) {
                $table->dropForeign([
                    'cancelled_by',
                ]);

                $table->dropColumn([
                    'cancelled_by',
                    'cancellation_reason',
                ]);
            }
        );
    }
};
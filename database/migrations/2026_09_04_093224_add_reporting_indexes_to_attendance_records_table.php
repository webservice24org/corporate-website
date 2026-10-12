<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'attendance_records',
            function (
                Blueprint $table
            ) {
                $table->index(
                    [
                        'company_id',
                        'attendance_date',
                    ],
                    'attendance_company_date_idx'
                );

                $table->index(
                    [
                        'employee_id',
                        'attendance_date',
                    ],
                    'attendance_employee_date_idx'
                );

                $table->index(
                    [
                        'company_id',
                        'attendance_status',
                        'attendance_date',
                    ],
                    'attendance_status_report_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'attendance_records',
            function (
                Blueprint $table
            ) {
                $table->dropIndex(
                    'attendance_company_date_idx'
                );

                $table->dropIndex(
                    'attendance_employee_date_idx'
                );

                $table->dropIndex(
                    'attendance_status_report_idx'
                );
            }
        );
    }
};

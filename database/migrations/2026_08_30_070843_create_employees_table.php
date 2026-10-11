<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'employees',
            function (Blueprint $table) {
                $table->id();

                /*
                 * Optional login account.
                 *
                 * Employee records and
                 * application users remain
                 * separate concepts.
                 */
                $table
                    ->foreignId('user_id')
                    ->nullable()
                    ->unique()
                    ->constrained()
                    ->nullOnDelete();

                $table
                    ->foreignId('company_id')
                    ->constrained()
                    ->restrictOnDelete();

                $table
                    ->foreignId('branch_id')
                    ->nullable()
                    ->constrained()
                    ->nullOnDelete();

                $table
                    ->foreignId('department_id')
                    ->nullable()
                    ->constrained()
                    ->nullOnDelete();

                $table
                    ->foreignId('designation_id')
                    ->nullable()
                    ->constrained()
                    ->nullOnDelete();

                /*
                 * Human-readable employee ID.
                 *
                 * We will later move automatic
                 * generation into the reusable
                 * document_sequences engine.
                 */
                $table
                    ->string(
                        'employee_number',
                        50
                    )
                    ->unique();

                $table->string(
                    'first_name'
                );

                $table
                    ->string('middle_name')
                    ->nullable();

                $table->string(
                    'last_name'
                );

                $table
                    ->string('preferred_name')
                    ->nullable();

                $table
                    ->string('work_email')
                    ->nullable();

                $table
                    ->string(
                        'work_phone',
                        50
                    )
                    ->nullable();

                $table
                    ->string('personal_email')
                    ->nullable();

                $table
                    ->string(
                        'personal_phone',
                        50
                    )
                    ->nullable();

                $table
                    ->date('date_of_birth')
                    ->nullable();

                $table
                    ->string(
                        'gender',
                        30
                    )
                    ->nullable();

                $table
                    ->string(
                        'marital_status',
                        30
                    )
                    ->nullable();

                $table
                    ->string(
                        'nationality',
                        100
                    )
                    ->nullable();

                $table
                    ->string(
                        'national_id',
                        100
                    )
                    ->nullable();

                $table
                    ->string(
                        'passport_number',
                        100
                    )
                    ->nullable();

                /*
                 * Employment information
                 */
                $table
                    ->date('joining_date')
                    ->nullable();

                $table
                    ->date('probation_end_date')
                    ->nullable();

                $table
                    ->date('confirmation_date')
                    ->nullable();

                $table
                    ->date('termination_date')
                    ->nullable();

                $table
                    ->string(
                        'employment_type',
                        30
                    )
                    ->default('full_time');

                $table
                    ->string(
                        'employment_status',
                        30
                    )
                    ->default('active');

                /*
                 * Address
                 */
                $table
                    ->text('present_address')
                    ->nullable();

                $table
                    ->text('permanent_address')
                    ->nullable();

                $table
                    ->string('city')
                    ->nullable();

                $table
                    ->string('state')
                    ->nullable();

                $table
                    ->string(
                        'postal_code',
                        30
                    )
                    ->nullable();

                $table
                    ->string('country')
                    ->nullable();

                /*
                 * Emergency contact
                 */
                $table
                    ->string(
                        'emergency_contact_name'
                    )
                    ->nullable();

                $table
                    ->string(
                        'emergency_contact_relationship',
                        100
                    )
                    ->nullable();

                $table
                    ->string(
                        'emergency_contact_phone',
                        50
                    )
                    ->nullable();

                /*
                 * Profile image.
                 *
                 * Store relative storage path,
                 * not a complete URL.
                 */
                $table
                    ->string('photo')
                    ->nullable();

                $table
                    ->text('notes')
                    ->nullable();

                $table->timestamps();

                $table->index([
                    'company_id',
                    'employment_status',
                ]);

                $table->index([
                    'branch_id',
                    'employment_status',
                ]);

                $table->index([
                    'department_id',
                    'employment_status',
                ]);

                $table->index([
                    'designation_id',
                    'employment_status',
                ]);

                $table->index(
                    'joining_date'
                );

                $table->index([
                    'last_name',
                    'first_name',
                ]);
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'employees'
        );
    }
};
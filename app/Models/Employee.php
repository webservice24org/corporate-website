<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',

        'company_id',
        'branch_id',
        'department_id',
        'designation_id',

        'employee_number',

        'first_name',
        'middle_name',
        'last_name',
        'preferred_name',

        'work_email',
        'work_phone',

        'personal_email',
        'personal_phone',

        'date_of_birth',
        'gender',
        'marital_status',
        'nationality',
        'national_id',
        'passport_number',

        'joining_date',
        'probation_end_date',
        'confirmation_date',
        'termination_date',

        'employment_type',
        'employment_status',

        'present_address',
        'permanent_address',

        'city',
        'state',
        'postal_code',
        'country',

        'emergency_contact_name',
        'emergency_contact_relationship',
        'emergency_contact_phone',

        'photo',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' =>
                'date:Y-m-d',

            'joining_date' =>
                'date:Y-m-d',

            'probation_end_date' =>
                'date:Y-m-d',

            'confirmation_date' =>
                'date:Y-m-d',

            'termination_date' =>
                'date:Y-m-d',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class
        );
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Company::class
        );
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(
            Branch::class
        );
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(
            Department::class
        );
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(
            Designation::class
        );
    }

    public function scopeActive(
        Builder $query
    ): Builder {
        return $query->where(
            'employment_status',
            'active'
        );
    }

    public function getFullNameAttribute(): string
    {
        return trim(
            implode(
                ' ',
                array_filter([
                    $this->first_name,
                    $this->middle_name,
                    $this->last_name,
                ])
            )
        );
    }

        public function documents(): HasMany
        {
            return $this->hasMany(
                EmployeeDocument::class
            );
        }

        public function employmentHistories(): HasMany
        {
            return $this->hasMany(
                EmployeeEmploymentHistory::class
            );
        }

        public function shiftAssignments(): HasMany
        {
            return $this->hasMany(
                EmployeeShiftAssignment::class
            );
        }

        public function attendanceDeviceUsers(): HasMany
        {
            return $this->hasMany(
                AttendanceDeviceUser::class
            );
        }

    public function attendanceRawPunches(): HasMany
    {
        return $this->hasMany(
            AttendanceRawPunch::class
        );
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(
            AttendanceRecord::class
        );
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(
            EmployeeLeaveRequest::class
        );
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(
            EmployeeLeaveBalance::class
        );
    }

    public function leaveBalanceAdjustments(): HasMany
    {
        return $this->hasMany(
            EmployeeLeaveBalanceAdjustment::class
        );
    }

    public function salaryStructures(): HasMany
    {
        return $this->hasMany(
            EmployeeSalaryStructure::class
        );
    }


}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


class Company extends Model
{
    protected $fillable = [
        'name',
        'legal_name',
        'code',
        'email',
        'phone',
        'website',
        'registration_number',
        'tax_number',
        'address',
        'city',
        'state',
        'postal_code',
        'country',
        'currency_code',
        'timezone',
        'status',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where(
            'status',
            'active'
        );
    }
    public function branches(): HasMany
    {
        return $this->hasMany(
            Branch::class
        );
    }

    public function departments(): HasMany
    {
        return $this->hasMany(
            Department::class
        );
    }

    public function designations(): HasMany
    {
        return $this->hasMany(
            Designation::class
        );
    }

    public function employees(): HasMany
    {
        return $this->hasMany(
            Employee::class
        );
    }

    public function attendanceSetting(): HasOne
    {
        return $this->hasOne(
            AttendanceSetting::class
        );
    }

    public function attendanceDevices(): HasMany
    {
        return $this->hasMany(
            AttendanceDevice::class
        );
    }

    public function attendanceHolidays(): HasMany
    {
        return $this->hasMany(
            AttendanceHoliday::class
        );
    }

    public function salaryComponents(): HasMany
    {
        return $this->hasMany(
            SalaryComponent::class
        );
    }
    public function employeeSalaryStructures(): HasMany
    {
        return $this->hasMany(
            EmployeeSalaryStructure::class
        );
    }

    public function payrollSetting(): HasOne
    {
        return $this->hasOne(
            PayrollSetting::class
        );
    }

    public function payrollPeriods(): HasMany
    {
        return $this->hasMany(
            PayrollPeriod::class
        );
    }

    public function leadSources(): HasMany
    {
        return $this->hasMany(
            LeadSource::class
        );
    }

    public function leadStatuses(): HasMany
    {
        return $this->hasMany(
            LeadStatus::class
        );
    }

    public function leads(): HasMany
    {
        return $this->hasMany(
            Lead::class
        );
    }

    public function crmActivities(): HasMany
    {
        return $this->hasMany(
            CrmActivity::class
        );
    }

    public function depots(): HasMany
    {
        return $this->hasMany(
            Depot::class
        );
    }

    public function depotApplications(): HasMany
    {
        return $this->hasMany(
            DepotApplication::class
        );
    }

    public function dealers()
    {
        return $this->hasMany(
            Dealer::class
        );
    }

    public function dealerApplications()
    {
        return $this->hasMany(
            DealerApplication::class
        );
    }

    public function sellers(): HasMany
    {
        return $this->hasMany(Seller::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(
            Warehouse::class
        );
    }

    public function inventoryBalances(): HasMany
    {
        return $this->hasMany(
            InventoryBalance::class
        );
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(
            StockMovement::class
        );
    }

    public function openingStocks(): HasMany
    {
        return $this->hasMany(
            OpeningStock::class
        );
    }


}
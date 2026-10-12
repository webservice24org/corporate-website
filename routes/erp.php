<?php

use App\Http\Controllers\ERP\CRM\CrmActivityController;
use App\Http\Controllers\ERP\CRM\CrmAnalyticsController;
use App\Http\Controllers\ERP\CRM\CustomerController;
use App\Http\Controllers\ERP\CRM\DealerApplicationController;
use App\Http\Controllers\ERP\CRM\DealerController;
use App\Http\Controllers\ERP\CRM\DealerDocumentController;
use App\Http\Controllers\ERP\CRM\DepotAdminEditController;
use App\Http\Controllers\ERP\CRM\DepotApplicationController;
use App\Http\Controllers\ERP\CRM\DepotController;
use App\Http\Controllers\ERP\CRM\DepotDocumentController;
use App\Http\Controllers\ERP\CRM\LeadController;
use App\Http\Controllers\ERP\CRM\LeadConversionController;
use App\Http\Controllers\ERP\CRM\LeadSourceController;
use App\Http\Controllers\ERP\CRM\LeadStatusController;
use App\Http\Controllers\ERP\CRM\MasterProfileActivityController;
use App\Http\Controllers\ERP\CRM\OpportunityController;
use App\Http\Controllers\ERP\CRM\OpportunityStageController;
use App\Http\Controllers\ERP\CRM\SellerController;
use App\Http\Controllers\ERP\Geography\DistrictController;
use App\Http\Controllers\ERP\Geography\DivisionController;
use App\Http\Controllers\ERP\Geography\UpazilaController;
use App\Http\Controllers\ERP\HRM\AttendanceDeviceController;
use App\Http\Controllers\ERP\HRM\AttendanceDeviceUserController;
use App\Http\Controllers\ERP\HRM\AttendanceFinalizationController;
use App\Http\Controllers\ERP\HRM\AttendanceHolidayController;
use App\Http\Controllers\ERP\HRM\AttendanceRawPunchController;
use App\Http\Controllers\ERP\HRM\AttendanceRecordController;
use App\Http\Controllers\ERP\HRM\AttendanceSettingController;
use App\Http\Controllers\ERP\HRM\AttendanceShiftController;
use App\Http\Controllers\ERP\HRM\EmployeeController;
use App\Http\Controllers\ERP\HRM\EmployeeDocumentController;
use App\Http\Controllers\ERP\HRM\EmployeeEmploymentHistoryController;
use App\Http\Controllers\ERP\HRM\EmployeeLeaveRequestController;
use App\Http\Controllers\ERP\HRM\EmployeeSalaryStructureController;
use App\Http\Controllers\ERP\HRM\EmployeeShiftAssignmentController;
use App\Http\Controllers\ERP\HRM\LeaveBalanceController;
use App\Http\Controllers\ERP\HRM\LeaveTypeController;
use App\Http\Controllers\ERP\HRM\PayrollPeriodController;
use App\Http\Controllers\ERP\HRM\PayrollSettingController;
use App\Http\Controllers\ERP\HRM\SalaryComponentController;
use App\Http\Controllers\ERP\Inventory\BrandController;
use App\Http\Controllers\ERP\Inventory\InventoryReorderSettingController;
use App\Http\Controllers\ERP\Inventory\InventoryStockController;
use App\Http\Controllers\ERP\Inventory\LowStockReportController;
use App\Http\Controllers\ERP\Inventory\OpeningStockController;
use App\Http\Controllers\ERP\Inventory\ProductCategoryController;
use App\Http\Controllers\ERP\Inventory\ProductController;
use App\Http\Controllers\ERP\Inventory\StockMovementController;
use App\Http\Controllers\ERP\Inventory\StockTransferController;
use App\Http\Controllers\ERP\Inventory\UnitController;
use App\Http\Controllers\ERP\Inventory\WarehouseController;
use App\Http\Controllers\ERP\NotificationController;
use App\Http\Controllers\ERP\Organization\BranchController;
use App\Http\Controllers\ERP\Organization\CompanyController;
use App\Http\Controllers\ERP\Organization\DepartmentController;
use App\Http\Controllers\ERP\Organization\DesignationController;
use App\Http\Controllers\ERP\Reports\AttendanceReportController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| ERP Routes
|--------------------------------------------------------------------------
*/

Route::prefix('erp/')->name('erp.')->middleware(['auth'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    Route::get('dashboard', function () {
        return Inertia::render('ERP/Dashboard/Index');
    })->middleware('can:erp.dashboard.view')->name('dashboard');

    /*
    |--------------------------------------------------------------------------
    | Organization - Companies
    |--------------------------------------------------------------------------
    */

    Route::get('companies', [CompanyController::class, 'index'])->middleware('can:erp.companies.view')->name('companies.index');
    Route::get('companies/create', [CompanyController::class, 'create'])->middleware('can:erp.companies.create')->name('companies.create');
    Route::post('companies', [CompanyController::class, 'store'])->middleware('can:erp.companies.create')->name('companies.store');
    Route::get('companies/{company}/edit', [CompanyController::class, 'edit'])->middleware('can:erp.companies.edit')->name('companies.edit');
    Route::put('companies/{company}', [CompanyController::class, 'update'])->middleware('can:erp.companies.edit')->name('companies.update');
    Route::delete('companies/{company}', [CompanyController::class, 'destroy'])->middleware('can:erp.companies.delete')->name('companies.destroy');

    /*
    |--------------------------------------------------------------------------
    | Organization - Branches
    |--------------------------------------------------------------------------
    */

    Route::get('branches', [BranchController::class, 'index'])->middleware('can:erp.branches.view')->name('branches.index');
    Route::get('branches/create', [BranchController::class, 'create'])->middleware('can:erp.branches.create')->name('branches.create');
    Route::post('branches', [BranchController::class, 'store'])->middleware('can:erp.branches.create')->name('branches.store');
    Route::get('branches/{branch}/edit', [BranchController::class, 'edit'])->middleware('can:erp.branches.edit')->name('branches.edit');
    Route::put('branches/{branch}', [BranchController::class, 'update'])->middleware('can:erp.branches.edit')->name('branches.update');
    Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->middleware('can:erp.branches.delete')->name('branches.destroy');

    /*
    |--------------------------------------------------------------------------
    | Departments
    |--------------------------------------------------------------------------
    */

    Route::get('departments', [DepartmentController::class, 'index'])->middleware('can:erp.departments.view')->name('departments.index');
    Route::get('departments/create', [DepartmentController::class, 'create'])->middleware('can:erp.departments.create')->name('departments.create');
    Route::post('departments', [DepartmentController::class, 'store'])->middleware('can:erp.departments.create')->name('departments.store');
    Route::get('departments/{department}/edit', [DepartmentController::class, 'edit'])->middleware('can:erp.departments.edit')->name('departments.edit');
    Route::put('departments/{department}', [DepartmentController::class, 'update'])->middleware('can:erp.departments.edit')->name('departments.update');
    Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])->middleware('can:erp.departments.delete')->name('departments.destroy');

    /*
    |--------------------------------------------------------------------------
    | Designations
    |--------------------------------------------------------------------------
    */

    Route::get('designations', [DesignationController::class, 'index'])->middleware('can:erp.designations.view')->name('designations.index');
    Route::get('designations/create', [DesignationController::class, 'create'])->middleware('can:erp.designations.create')->name('designations.create');
    Route::post('designations', [DesignationController::class, 'store'])->middleware('can:erp.designations.create')->name('designations.store');
    Route::get('designations/{designation}/edit', [DesignationController::class, 'edit'])->middleware('can:erp.designations.edit')->name('designations.edit');
    Route::put('designations/{designation}', [DesignationController::class, 'update'])->middleware('can:erp.designations.edit')->name('designations.update');
    Route::delete('designations/{designation}', [DesignationController::class, 'destroy'])->middleware('can:erp.designations.delete')->name('designations.destroy');

    /*
    |--------------------------------------------------------------------------
    | Employees
    |--------------------------------------------------------------------------
    */

    Route::get('employees', [EmployeeController::class, 'index'])->middleware('can:erp.employees.view')->name('employees.index');
    Route::get('employees/create', [EmployeeController::class, 'create'])->middleware('can:erp.employees.create')->name('employees.create');
    Route::post('employees', [EmployeeController::class, 'store'])->middleware('can:erp.employees.create')->name('employees.store');
    Route::get('employees/{employee}', [EmployeeController::class, 'show'])->middleware('can:erp.employees.view')->name('employees.show');
    Route::get('employees/{employee}/edit', [EmployeeController::class, 'edit'])->middleware('can:erp.employees.edit')->name('employees.edit');
    Route::post('employees/{employee}', [EmployeeController::class, 'update'])->middleware('can:erp.employees.edit')->name('employees.update');
    Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])->middleware('can:erp.employees.delete')->name('employees.destroy');

    /*
    |--------------------------------------------------------------------------
    | Employee Documents
    |--------------------------------------------------------------------------
    */

    Route::get('employees/{employee}/documents/create', [EmployeeDocumentController::class, 'create'])->middleware('can:erp.employee-documents.create')->name('employees.documents.create');
    Route::post('employees/{employee}/documents', [EmployeeDocumentController::class, 'store'])->middleware('can:erp.employee-documents.create')->name('employees.documents.store');
    Route::get('employees/{employee}/documents/{document}/edit', [EmployeeDocumentController::class, 'edit'])->middleware('can:erp.employee-documents.edit')->name('employees.documents.edit');
    Route::post('employees/{employee}/documents/{document}', [EmployeeDocumentController::class, 'update'])->middleware('can:erp.employee-documents.edit')->name('employees.documents.update');
    Route::delete('employees/{employee}/documents/{document}', [EmployeeDocumentController::class, 'destroy'])->middleware('can:erp.employee-documents.delete')->name('employees.documents.destroy');

    /*
    |--------------------------------------------------------------------------
    | Employment History
    |--------------------------------------------------------------------------
    */

    Route::get('employees/{employee}/employment-history/create', [EmployeeEmploymentHistoryController::class, 'create'])->middleware('can:erp.employee-history.create')->name('employees.employment-history.create');
    Route::post('employees/{employee}/employment-history', [EmployeeEmploymentHistoryController::class, 'store'])->middleware('can:erp.employee-history.create')->name('employees.employment-history.store');
    Route::get('employees/{employee}/employment-history/{history}/edit', [EmployeeEmploymentHistoryController::class, 'edit'])->middleware('can:erp.employee-history.edit')->name('employees.employment-history.edit');
    Route::put('employees/{employee}/employment-history/{history}', [EmployeeEmploymentHistoryController::class, 'update'])->middleware('can:erp.employee-history.edit')->name('employees.employment-history.update');
    Route::delete('employees/{employee}/employment-history/{history}', [EmployeeEmploymentHistoryController::class, 'destroy'])->middleware('can:erp.employee-history.delete')->name('employees.employment-history.destroy');
    /*
    |--------------------------------------------------------------------------
    | Attendance Settings
    |--------------------------------------------------------------------------
    */

    Route::get('attendance/settings', [AttendanceSettingController::class, 'index'])->middleware('can:erp.attendance-settings.view')->name('attendance.settings.index');
    Route::put('attendance/settings/{attendanceSetting}', [AttendanceSettingController::class, 'update'])->middleware('can:erp.attendance-settings.edit')->name('attendance.settings.update');

    /*
    |--------------------------------------------------------------------------
    | Attendance Shifts
    |--------------------------------------------------------------------------
    */

    Route::get('attendance/shifts', [AttendanceShiftController::class, 'index'])->middleware('can:erp.shifts.view')->name('shifts.index');
    Route::get('attendance/shifts/create', [AttendanceShiftController::class, 'create'])->middleware('can:erp.shifts.create')->name('shifts.create');
    Route::post('attendance/shifts', [AttendanceShiftController::class, 'store'])->middleware('can:erp.shifts.create')->name('shifts.store');
    Route::get('attendance/shifts/{shift}/edit', [AttendanceShiftController::class, 'edit'])->middleware('can:erp.shifts.edit')->name('shifts.edit');
    Route::put('attendance/shifts/{shift}', [AttendanceShiftController::class, 'update'])->middleware('can:erp.shifts.edit')->name('shifts.update');
    Route::delete('attendance/shifts/{shift}', [AttendanceShiftController::class, 'destroy'])->middleware('can:erp.shifts.delete')->name('shifts.destroy');

    /*
    |--------------------------------------------------------------------------
    | Employee Shift Assignments
    |--------------------------------------------------------------------------
    */

    Route::get('attendance/employee-shifts', [EmployeeShiftAssignmentController::class, 'index'])->middleware('can:erp.employee-shifts.view')->name('employee-shifts.index');
    Route::get('attendance/employee-shifts/create', [EmployeeShiftAssignmentController::class, 'create'])->middleware('can:erp.employee-shifts.create')->name('employee-shifts.create');
    Route::post('attendance/employee-shifts', [EmployeeShiftAssignmentController::class, 'store'])->middleware('can:erp.employee-shifts.create')->name('employee-shifts.store');
    Route::get('attendance/employee-shifts/{employeeShift}/edit', [EmployeeShiftAssignmentController::class, 'edit'])->middleware('can:erp.employee-shifts.edit')->name('employee-shifts.edit');
    Route::put('attendance/employee-shifts/{employeeShift}', [EmployeeShiftAssignmentController::class, 'update'])->middleware('can:erp.employee-shifts.edit')->name('employee-shifts.update');
    Route::delete('attendance/employee-shifts/{employeeShift}', [EmployeeShiftAssignmentController::class, 'destroy'])->middleware('can:erp.employee-shifts.delete')->name('employee-shifts.destroy');
    /*
    |--------------------------------------------------------------------------
    | Attendance Devices
    |--------------------------------------------------------------------------
    */

    Route::get('attendance/devices', [AttendanceDeviceController::class, 'index'])->middleware('can:erp.attendance-devices.view')->name('attendance-devices.index');
    Route::get('attendance/devices/create', [AttendanceDeviceController::class, 'create'])->middleware('can:erp.attendance-devices.create')->name('attendance-devices.create');
    Route::post('attendance/devices', [AttendanceDeviceController::class, 'store'])->middleware('can:erp.attendance-devices.create')->name('attendance-devices.store');
    Route::get('attendance/devices/{attendanceDevice}/edit', [AttendanceDeviceController::class, 'edit'])->middleware('can:erp.attendance-devices.edit')->name('attendance-devices.edit');
    Route::put('attendance/devices/{attendanceDevice}', [AttendanceDeviceController::class, 'update'])->middleware('can:erp.attendance-devices.edit')->name('attendance-devices.update');
    Route::delete('attendance/devices/{attendanceDevice}', [AttendanceDeviceController::class, 'destroy'])->middleware('can:erp.attendance-devices.delete')->name('attendance-devices.destroy');

    /*
    |--------------------------------------------------------------------------
    | Attendance Device Employee Mapping
    |--------------------------------------------------------------------------
    */

    Route::get('attendance/device-users', [AttendanceDeviceUserController::class, 'index'])->middleware('can:erp.attendance-device-users.view')->name('attendance-device-users.index');
    Route::get('attendance/device-users/create', [AttendanceDeviceUserController::class, 'create'])->middleware('can:erp.attendance-device-users.create')->name('attendance-device-users.create');
    Route::post('attendance/device-users', [AttendanceDeviceUserController::class, 'store'])->middleware('can:erp.attendance-device-users.create')->name('attendance-device-users.store');
    Route::get('attendance/device-users/{attendanceDeviceUser}/edit', [AttendanceDeviceUserController::class, 'edit'])->middleware('can:erp.attendance-device-users.edit')->name('attendance-device-users.edit');
    Route::put('attendance/device-users/{attendanceDeviceUser}', [AttendanceDeviceUserController::class, 'update'])->middleware('can:erp.attendance-device-users.edit')->name('attendance-device-users.update');
    Route::delete('attendance/device-users/{attendanceDeviceUser}', [AttendanceDeviceUserController::class, 'destroy'])->middleware('can:erp.attendance-device-users.delete')->name('attendance-device-users.destroy');
    Route::post('attendance/device-users/{attendanceDeviceUser}/resolve-punches', [AttendanceDeviceUserController::class, 'resolvePunches'])->middleware('can:erp.attendance-device-users.resolve')->name('attendance-device-users.resolve-punches');

    /*
    |--------------------------------------------------------------------------
    | Attendance Raw Punch Logs
    |--------------------------------------------------------------------------
    */

    Route::get('attendance/raw-punches', [AttendanceRawPunchController::class, 'index'])->middleware('can:erp.attendance-raw-punches.view')->name('attendance-raw-punches.index');
    Route::get('attendance/raw-punches/{attendanceRawPunch}', [AttendanceRawPunchController::class, 'show'])->middleware('can:erp.attendance-raw-punches.view')->name('attendance-raw-punches.show');

    /*
    |--------------------------------------------------------------------------
    | Attendance Record System
    |--------------------------------------------------------------------------
    */

    Route::get('attendance/records', [AttendanceRecordController::class, 'index'])->middleware('can:erp.attendance-records.view')->name('attendance-records.index');
    Route::get('attendance/records/{attendanceRecord}', [AttendanceRecordController::class, 'show'])->middleware('can:erp.attendance-records.view')->name('attendance-records.show');
    Route::post('attendance/records/{attendanceRecord}/reprocess', [AttendanceRecordController::class, 'reprocess'])->middleware('can:erp.attendance-records.process')->name('attendance-records.reprocess');
    Route::get('attendance/holidays', [AttendanceHolidayController::class, 'index'])->middleware('can:erp.attendance-holidays.view')->name('attendance-holidays.index');
    Route::get('attendance/holidays/create', [AttendanceHolidayController::class, 'create'])->middleware('can:erp.attendance-holidays.create')->name('attendance-holidays.create');
    Route::post('attendance/holidays', [AttendanceHolidayController::class, 'store'])->middleware('can:erp.attendance-holidays.create')->name('attendance-holidays.store');
    Route::get('attendance/holidays/{attendanceHoliday}/edit', [AttendanceHolidayController::class, 'edit'])->middleware('can:erp.attendance-holidays.edit')->name('attendance-holidays.edit');
    Route::put('attendance/holidays/{attendanceHoliday}', [AttendanceHolidayController::class, 'update'])->middleware('can:erp.attendance-holidays.edit')->name('attendance-holidays.update');
    Route::delete('attendance/holidays/{attendanceHoliday}', [AttendanceHolidayController::class, 'destroy'])->middleware('can:erp.attendance-holidays.delete')->name('attendance-holidays.destroy');
    Route::get('attendance/finalization', [AttendanceFinalizationController::class, 'index'])->middleware('can:erp.attendance-finalization.view')->name('attendance-finalization.index');
    Route::post('attendance/finalization', [AttendanceFinalizationController::class, 'run'])->middleware('can:erp.attendance-finalization.run')->name('attendance-finalization.run');

    /*
    |--------------------------------------------------------------------------
    | Leave Management System
    |--------------------------------------------------------------------------
    */

    Route::get('leave/types', [LeaveTypeController::class, 'index'])->middleware('can:erp.leave-types.view')->name('leave-types.index');
    Route::get('leave/types/create', [LeaveTypeController::class, 'create'])->middleware('can:erp.leave-types.create')->name('leave-types.create');
    Route::post('leave/types', [LeaveTypeController::class, 'store'])->middleware('can:erp.leave-types.create')->name('leave-types.store');
    Route::get('leave/types/{leaveType}/edit', [LeaveTypeController::class, 'edit'])->middleware('can:erp.leave-types.edit')->name('leave-types.edit');
    Route::put('leave/types/{leaveType}', [LeaveTypeController::class, 'update'])->middleware('can:erp.leave-types.edit')->name('leave-types.update');
    Route::delete('leave/types/{leaveType}', [LeaveTypeController::class, 'destroy'])->middleware('can:erp.leave-types.delete')->name('leave-types.destroy');

    Route::get('leave/requests', [EmployeeLeaveRequestController::class, 'index'])->middleware('can:erp.leave-requests.view')->name('leave-requests.index');
    Route::get('leave/requests/create', [EmployeeLeaveRequestController::class, 'create'])->middleware('can:erp.leave-requests.create')->name('leave-requests.create');
    Route::post('leave/requests', [EmployeeLeaveRequestController::class, 'store'])->middleware('can:erp.leave-requests.create')->name('leave-requests.store');
    Route::get('leave/requests/{leaveRequest}', [EmployeeLeaveRequestController::class, 'show'])->middleware('can:erp.leave-requests.view')->name('leave-requests.show');
    Route::post('leave/requests/{leaveRequest}/approve', [EmployeeLeaveRequestController::class, 'approve'])->middleware('can:erp.leave-requests.approve')->name('leave-requests.approve');
    Route::post('leave/requests/{leaveRequest}/reject', [EmployeeLeaveRequestController::class, 'reject'])->middleware('can:erp.leave-requests.reject')->name('leave-requests.reject');
    Route::post('leave/requests/{leaveRequest}/cancel', [EmployeeLeaveRequestController::class, 'cancel'])->middleware('can:erp.leave-requests.cancel')->name('leave-requests.cancel');

    Route::get('leave/balances', [LeaveBalanceController::class, 'index'])->middleware('can:erp.leave-balances.view')->name('leave-balances.index');
    Route::post('leave/balances/adjust', [LeaveBalanceController::class, 'adjust'])->middleware('can:erp.leave-balances.adjust')->name('leave-balances.adjust');
    Route::get('reports/attendance', [AttendanceReportController::class, 'index'])->middleware('can:erp.attendance-reports.view')->name('reports.attendance.index');
    Route::get('reports/attendance/export/csv', [AttendanceReportController::class, 'exportCsv'])->middleware('can:erp.attendance-reports.export')->name('reports.attendance.export.csv');

    /*
    |--------------------------------------------------------------------------
    | Salary Management System
    |--------------------------------------------------------------------------
    */
    Route::get('payroll/salary-components', [SalaryComponentController::class, 'index'])->middleware('can:erp.salary-components.view')->name('salary-components.index');
    Route::get('payroll/salary-components/create', [SalaryComponentController::class, 'create'])->middleware('can:erp.salary-components.create')->name('salary-components.create');
    Route::post('payroll/salary-components', [SalaryComponentController::class, 'store'])->middleware('can:erp.salary-components.create')->name('salary-components.store');
    Route::get('payroll/salary-components/{salaryComponent}/edit', [SalaryComponentController::class, 'edit'])->middleware('can:erp.salary-components.edit')->name('salary-components.edit');
    Route::put('payroll/salary-components/{salaryComponent}', [SalaryComponentController::class, 'update'])->middleware('can:erp.salary-components.edit')->name('salary-components.update');
    Route::delete('payroll/salary-components/{salaryComponent}', [SalaryComponentController::class, 'destroy'])->middleware('can:erp.salary-components.delete')->name('salary-components.destroy');
    Route::get('payroll/salary-structures', [EmployeeSalaryStructureController::class, 'index'])->middleware('can:erp.salary-structures.view')->name('salary-structures.index');
    Route::get('payroll/salary-structures/create', [EmployeeSalaryStructureController::class, 'create'])->middleware('can:erp.salary-structures.create')->name('salary-structures.create');
    Route::post('payroll/salary-structures', [EmployeeSalaryStructureController::class, 'store'])->middleware('can:erp.salary-structures.create')->name('salary-structures.store');
    Route::get('payroll/salary-structures/{salaryStructure}', [EmployeeSalaryStructureController::class, 'show'])->middleware('can:erp.salary-structures.view')->name('salary-structures.show');
    Route::get('payroll/salary-structures/{salaryStructure}/edit', [EmployeeSalaryStructureController::class, 'edit'])->middleware('can:erp.salary-structures.edit')->name('salary-structures.edit');
    Route::put('payroll/salary-structures/{salaryStructure}', [EmployeeSalaryStructureController::class, 'update'])->middleware('can:erp.salary-structures.edit')->name('salary-structures.update');
    Route::delete('payroll/salary-structures/{salaryStructure}', [EmployeeSalaryStructureController::class, 'destroy'])->middleware('can:erp.salary-structures.delete')->name('salary-structures.destroy');
    Route::get('payroll/settings', [PayrollSettingController::class, 'index'])->middleware('can:erp.payroll-settings.view')->name('payroll-settings.index');
    Route::get('payroll/settings/{company}/edit', [PayrollSettingController::class, 'edit'])->middleware('can:erp.payroll-settings.edit')->name('payroll-settings.edit');
    Route::put('payroll/settings/{company}', [PayrollSettingController::class, 'update'])->middleware('can:erp.payroll-settings.edit')->name('payroll-settings.update');
    Route::get('payroll/periods', [PayrollPeriodController::class, 'index'])->middleware('can:erp.payroll-periods.view')->name('payroll-periods.index');
    Route::get('payroll/periods/create', [PayrollPeriodController::class, 'create'])->middleware('can:erp.payroll-periods.create')->name('payroll-periods.create');
    Route::post('payroll/periods', [PayrollPeriodController::class, 'store'])->middleware('can:erp.payroll-periods.create')->name('payroll-periods.store');
    Route::get('payroll/periods/{payrollPeriod}/edit', [PayrollPeriodController::class, 'edit'])->middleware('can:erp.payroll-periods.edit')->name('payroll-periods.edit');
    Route::put('payroll/periods/{payrollPeriod}', [PayrollPeriodController::class, 'update'])->middleware('can:erp.payroll-periods.edit')->name('payroll-periods.update');
    Route::delete('payroll/periods/{payrollPeriod}', [PayrollPeriodController::class, 'destroy'])->middleware('can:erp.payroll-periods.delete')->name('payroll-periods.destroy');

    /*
    |--------------------------------------------------------------------------
    | CRM Lead Management System
    |--------------------------------------------------------------------------
    */

    Route::get('crm/lead-sources', [LeadSourceController::class, 'index'])->middleware('can:erp.lead-sources.view')->name('lead-sources.index');
    Route::get('crm/lead-sources/create', [LeadSourceController::class, 'create'])->middleware('can:erp.lead-sources.create')->name('lead-sources.create');
    Route::post('crm/lead-sources', [LeadSourceController::class, 'store'])->middleware('can:erp.lead-sources.create')->name('lead-sources.store');
    Route::get('crm/lead-sources/{leadSource}/edit', [LeadSourceController::class, 'edit'])->middleware('can:erp.lead-sources.edit')->name('lead-sources.edit');
    Route::put('crm/lead-sources/{leadSource}', [LeadSourceController::class, 'update'])->middleware('can:erp.lead-sources.edit')->name('lead-sources.update');
    Route::delete('crm/lead-sources/{leadSource}', [LeadSourceController::class, 'destroy'])->middleware('can:erp.lead-sources.delete')->name('lead-sources.destroy');

    Route::get('crm/lead-statuses', [LeadStatusController::class, 'index'])->middleware('can:erp.lead-statuses.view')->name('lead-statuses.index');
    Route::get('crm/lead-statuses/create', [LeadStatusController::class, 'create'])->middleware('can:erp.lead-statuses.create')->name('lead-statuses.create');
    Route::post('crm/lead-statuses', [LeadStatusController::class, 'store'])->middleware('can:erp.lead-statuses.create')->name('lead-statuses.store');
    Route::get('crm/lead-statuses/{leadStatus}/edit', [LeadStatusController::class, 'edit'])->middleware('can:erp.lead-statuses.edit')->name('lead-statuses.edit');
    Route::put('crm/lead-statuses/{leadStatus}', [LeadStatusController::class, 'update'])->middleware('can:erp.lead-statuses.edit')->name('lead-statuses.update');
    Route::delete('crm/lead-statuses/{leadStatus}', [LeadStatusController::class, 'destroy'])->middleware('can:erp.lead-statuses.delete')->name('lead-statuses.destroy');
    Route::get('crm/leads', [LeadController::class, 'index'])->middleware('can:erp.leads.view')->name('leads.index');
    Route::get('crm/leads/create', [LeadController::class, 'create'])->middleware('can:erp.leads.create')->name('leads.create');
    Route::post('crm/leads', [LeadController::class, 'store'])->middleware('can:erp.leads.create')->name('leads.store');
    Route::get('crm/leads/{lead}', [LeadController::class, 'show'])->middleware('can:erp.leads.view')->name('leads.show');
    Route::get('crm/leads/{lead}/edit', [LeadController::class, 'edit'])->middleware('can:erp.leads.edit')->name('leads.edit');
    Route::put('crm/leads/{lead}', [LeadController::class, 'update'])->middleware('can:erp.leads.edit')->name('leads.update');
    Route::delete('crm/leads/{lead}', [LeadController::class, 'destroy'])->middleware('can:erp.leads.delete')->name('leads.destroy');
    Route::get('crm/activities', [CrmActivityController::class, 'index'])->middleware('can:erp.crm-activities.view')->name('crm-activities.index');
    Route::get('crm/activities/create', [CrmActivityController::class, 'create'])->middleware('can:erp.crm-activities.create')->name('crm-activities.create');
    Route::post('crm/activities', [CrmActivityController::class, 'store'])->middleware('can:erp.crm-activities.create')->name('crm-activities.store');
    Route::get('crm/activities/{crmActivity}', [CrmActivityController::class, 'show'])->middleware('can:erp.crm-activities.view')->name('crm-activities.show');
    Route::get('crm/activities/{crmActivity}/edit', [CrmActivityController::class, 'edit'])->middleware('can:erp.crm-activities.edit')->name('crm-activities.edit');
    Route::put('crm/activities/{crmActivity}', [CrmActivityController::class, 'update'])->middleware('can:erp.crm-activities.edit')->name('crm-activities.update');
    Route::delete('crm/activities/{crmActivity}', [CrmActivityController::class, 'destroy'])->middleware('can:erp.crm-activities.delete')->name('crm-activities.destroy');

    /*
    |--------------------------------------------------------------------------
    | Notification Management System
    |--------------------------------------------------------------------------
    */

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');


    /*
    |--------------------------------------------------------------------------
    | Depot Management System
    |--------------------------------------------------------------------------
    */

    Route::get('crm/depots', [DepotController::class, 'index'])->middleware('can:erp.depots.view')->name('depots.index');
    Route::get('crm/depots/{depot}', [DepotController::class, 'show'])->middleware('can:erp.depots.view')->name('depots.show');
    Route::get('crm/depot-applications', [DepotApplicationController::class, 'index'])->middleware('can:erp.depot-applications.view')->name('depot-applications.index');
    Route::get('crm/depot-applications/{depotApplication}', [DepotApplicationController::class, 'show'])->middleware('can:erp.depot-applications.view')->name('depot-applications.show');
    Route::post('crm/depot-applications/{depotApplication}/under-review', [DepotApplicationController::class, 'markUnderReview'])->middleware('can:erp.depot-applications.review')->name('depot-applications.under-review');
    Route::post('crm/depot-applications/{depotApplication}/approve', [DepotApplicationController::class, 'approve'])->middleware('can:erp.depot-applications.approve')->name('depot-applications.approve');
    Route::post('crm/depot-applications/{depotApplication}/reject', [DepotApplicationController::class, 'reject'])->middleware('can:erp.depot-applications.reject')->name('depot-applications.reject');
    Route::get('crm/depot-applications/{depotApplication}/documents/{document}/download', [DepotApplicationController::class, 'downloadDocument'])->middleware('can:erp.depot-applications.documents')->name('depot-applications.documents.download');
    Route::post('crm/depot-applications/{depotApplication}/documents/{document}/verify', [DepotApplicationController::class, 'verifyDocument'])->middleware('can:erp.depot-applications.documents')->name('depot-applications.documents.verify');

    /*
    |--------------------------------------------------------------------------
    | Geography Management System
    |--------------------------------------------------------------------------
    */
    Route::get('geography/divisions', [DivisionController::class, 'index'])->middleware('can:erp.divisions.view')->name('divisions.index');
    Route::get('geography/divisions/create', [DivisionController::class, 'create'])->middleware('can:erp.divisions.create')->name('divisions.create');
    Route::post('geography/divisions', [DivisionController::class, 'store'])->middleware('can:erp.divisions.create')->name('divisions.store');
    Route::get('geography/divisions/{division}/edit', [DivisionController::class, 'edit'])->middleware('can:erp.divisions.edit')->name('divisions.edit');
    Route::put('geography/divisions/{division}', [DivisionController::class, 'update'])->middleware('can:erp.divisions.edit')->name('divisions.update');
    Route::delete('geography/divisions/{division}', [DivisionController::class, 'destroy'])->middleware('can:erp.divisions.delete')->name('divisions.destroy');

    Route::get('geography/districts', [DistrictController::class, 'index'])->middleware('can:erp.districts.view')->name('districts.index');
    Route::get('geography/districts/create', [DistrictController::class, 'create'])->middleware('can:erp.districts.create')->name('districts.create');
    Route::post('geography/districts', [DistrictController::class, 'store'])->middleware('can:erp.districts.create')->name('districts.store');
    Route::get('geography/districts/{district}/edit', [DistrictController::class, 'edit'])->middleware('can:erp.districts.edit')->name('districts.edit');
    Route::put('geography/districts/{district}', [DistrictController::class, 'update'])->middleware('can:erp.districts.edit')->name('districts.update');
    Route::delete('geography/districts/{district}', [DistrictController::class, 'destroy'])->middleware('can:erp.districts.delete')->name('districts.destroy');

    Route::get('geography/upazilas', [UpazilaController::class, 'index'])->middleware('can:erp.upazilas.view')->name('upazilas.index');
    Route::get('geography/upazilas/create', [UpazilaController::class, 'create'])->middleware('can:erp.upazilas.create')->name('upazilas.create');
    Route::post('geography/upazilas', [UpazilaController::class, 'store'])->middleware('can:erp.upazilas.create')->name('upazilas.store');
    Route::get('geography/upazilas/{upazila}/edit', [UpazilaController::class, 'edit'])->middleware('can:erp.upazilas.edit')->name('upazilas.edit');
    Route::put('geography/upazilas/{upazila}', [UpazilaController::class, 'update'])->middleware('can:erp.upazilas.edit')->name('upazilas.update');
    Route::delete('geography/upazilas/{upazila}', [UpazilaController::class, 'destroy'])->middleware('can:erp.upazilas.delete')->name('upazilas.destroy');


    Route::get('crm/dealers', [DealerController::class, 'index'])->middleware('can:erp.dealers.view')->name('dealers.index');
    Route::get('crm/dealers/{dealer}', [DealerController::class, 'show'])->middleware('can:erp.dealers.view')->name('dealers.show');

    Route::get('crm/dealer-applications', [DealerApplicationController::class, 'index'])->middleware('can:erp.dealer-applications.view')->name('dealer-applications.index');
    Route::get('crm/dealer-applications/{dealerApplication}', [DealerApplicationController::class, 'show'])->middleware('can:erp.dealer-applications.view')->name('dealer-applications.show');
    Route::post('crm/dealer-applications/{dealerApplication}/under-review', [DealerApplicationController::class, 'markUnderReview'])->middleware('can:erp.dealer-applications.review')->name('dealer-applications.under-review');
    Route::post('crm/dealer-applications/{dealerApplication}/approve', [DealerApplicationController::class, 'approve'])->middleware('can:erp.dealer-applications.approve')->name('dealer-applications.approve');
    Route::post('crm/dealer-applications/{dealerApplication}/reject', [DealerApplicationController::class, 'reject'])->middleware('can:erp.dealer-applications.reject')->name('dealer-applications.reject');
    Route::post('crm/dealer-applications/{dealerApplication}/documents/{document}/verify', [DealerApplicationController::class, 'verifyDocument'])->middleware('can:erp.dealer-applications.documents')->name('dealer-applications.documents.verify');
    Route::get('crm/dealer-applications/{dealerApplication}/documents/{document}/download', [DealerApplicationController::class, 'downloadDocument'])->middleware('can:erp.dealer-applications.documents')->name('dealer-applications.documents.download');

    Route::get('crm/sellers', [SellerController::class, 'index'])->middleware('can:erp.sellers.view')->name('sellers.index');
    Route::get('crm/sellers/create', [SellerController::class, 'create'])->middleware('can:erp.sellers.create')->name('sellers.create');
    Route::post('crm/sellers', [SellerController::class, 'store'])->middleware('can:erp.sellers.create')->name('sellers.store');
    Route::get('crm/sellers/{seller}', [SellerController::class, 'show'])->middleware('can:erp.sellers.view')->name('sellers.show');
    Route::get('crm/sellers/{seller}/edit', [SellerController::class, 'edit'])->middleware('can:erp.sellers.edit')->name('sellers.edit');
    Route::put('crm/sellers/{seller}', [SellerController::class, 'update'])->middleware('can:erp.sellers.edit')->name('sellers.update');
    Route::delete('crm/sellers/{seller}', [SellerController::class, 'destroy'])->middleware('can:erp.sellers.delete')->name('sellers.destroy');
    Route::get('crm/sellers/{seller}/proprietor-photo', [SellerController::class, 'proprietorPhoto'])->middleware('can:erp.sellers.view')->name('sellers.proprietor-photo');

    /*
    |--------------------------------------------------------------------------
    | CRM - Approved Depots
    |--------------------------------------------------------------------------
    */

    Route::get('crm/depots/{depot}/edit', [DepotAdminEditController::class, 'edit'])->middleware('can:erp.depots.edit')->name('depots.edit');
    Route::put('crm/depots/{depot}', [DepotAdminEditController::class, 'update'])->middleware('can:erp.depots.edit')->name('depots.update');
    Route::get('crm/depots/{depot}/proprietor-photo', [DepotAdminEditController::class, 'proprietorPhoto'])->middleware('can:erp.depots.view')->name('depots.proprietor-photo');

    /*
    |--------------------------------------------------------------------------
    | CRM - Approved Depot Documents
    |--------------------------------------------------------------------------
    */
    Route::get('crm/depots/{depot}/documents', [DepotDocumentController::class, 'index'])->middleware('can:erp.depot-documents.view')->name('depot-documents.index');
    Route::post('crm/depots/{depot}/documents', [DepotDocumentController::class, 'store'])->middleware('can:erp.depot-documents.create')->name('depot-documents.store');
    Route::get('crm/depots/{depot}/documents/{document}/preview', [DepotDocumentController::class, 'preview'])->middleware('can:erp.depot-documents.view')->name('depot-documents.preview');
    Route::get('crm/depots/{depot}/documents/{document}/download', [DepotDocumentController::class, 'download'])->middleware('can:erp.depot-documents.view')->name('depot-documents.download');
    Route::post('crm/depots/{depot}/documents/{document}/verify', [DepotDocumentController::class, 'verify'])->middleware('can:erp.depot-documents.verify')->name('depot-documents.verify');
    Route::post('crm/depots/{depot}/documents/{document}/unverify', [DepotDocumentController::class, 'unverify'])->middleware('can:erp.depot-documents.verify')->name('depot-documents.unverify');
    Route::delete('crm/depots/{depot}/documents/{document}', [DepotDocumentController::class, 'remove'])->middleware('can:erp.depot-documents.delete')->name('depot-documents.remove');

    /*
    |--------------------------------------------------------------------------
    | CRM - Approved Dealer Admin Edit
    |--------------------------------------------------------------------------
    */

    Route::get('crm/dealers/{dealer}/edit', [DealerController::class, 'edit'])->middleware('can:erp.dealers.edit')->name('dealers.edit');
    Route::put('crm/dealers/{dealer}', [DealerController::class, 'update'])->middleware('can:erp.dealers.edit')->name('dealers.update');
    Route::get('crm/dealers/{dealer}/proprietor-photo', [DealerController::class, 'proprietorPhoto'])->middleware('can:erp.dealers.view')->name('dealers.proprietor-photo');

    /*
    |--------------------------------------------------------------------------
    | CRM - Approved Dealer Documents
    |--------------------------------------------------------------------------
    */

    Route::get('crm/dealers/{dealer}/documents', [DealerDocumentController::class, 'index'])->middleware('can:erp.dealer-documents.view')->name('dealer-documents.index');
    Route::post('crm/dealers/{dealer}/documents', [DealerDocumentController::class, 'store'])->middleware('can:erp.dealer-documents.create')->name('dealer-documents.store');
    Route::get('crm/dealers/{dealer}/documents/{document}/preview', [DealerDocumentController::class, 'preview'])->middleware('can:erp.dealer-documents.view')->name('dealer-documents.preview');
    Route::get('crm/dealers/{dealer}/documents/{document}/download', [DealerDocumentController::class, 'download'])->middleware('can:erp.dealer-documents.view')->name('dealer-documents.download');
    Route::post('crm/dealers/{dealer}/documents/{document}/verify', [DealerDocumentController::class, 'verify'])->middleware('can:erp.dealer-documents.verify')->name('dealer-documents.verify');
    Route::post('crm/dealers/{dealer}/documents/{document}/unverify', [DealerDocumentController::class, 'unverify'])->middleware('can:erp.dealer-documents.verify')->name('dealer-documents.unverify');
    Route::delete('crm/dealers/{dealer}/documents/{document}', [DealerDocumentController::class, 'remove'])->middleware('can:erp.dealer-documents.delete')->name('dealer-documents.remove');

    /*
    |--------------------------------------------------------------------------
    | CRM - Master Profile Activity History
    |--------------------------------------------------------------------------
    |
    */

    Route::get('crm/depots/{depot}/activity', [MasterProfileActivityController::class, 'depot'])->middleware('can:erp.depots.activity.view')->name('depots.activity');
    Route::get('crm/dealers/{dealer}/activity', [MasterProfileActivityController::class, 'dealer'])->middleware('can:erp.dealers.activity.view')->name('dealers.activity');

    /*
    |--------------------------------------------------------------------------
    | Customer management
    |--------------------------------------------------------------------------
    |
    */

    Route::get('crm/customers', [CustomerController::class, 'index'])->middleware('can:erp.customers.view')->name('customers.index');
    Route::get('crm/customers/create', [CustomerController::class, 'create'])->middleware('can:erp.customers.create')->name('customers.create');
    Route::post('crm/customers', [CustomerController::class, 'store'])->middleware('can:erp.customers.create')->name('customers.store');
    Route::get('crm/customers/{customer}', [CustomerController::class, 'show'])->middleware('can:erp.customers.view')->name('customers.show');
    Route::get('crm/customers/{customer}/edit', [CustomerController::class, 'edit'])->middleware('can:erp.customers.edit')->name('customers.edit');
    Route::put('crm/customers/{customer}', [CustomerController::class, 'update'])->middleware('can:erp.customers.edit')->name('customers.update');
    Route::delete('crm/customers/{customer}', [CustomerController::class, 'destroy'])->middleware('can:erp.customers.delete')->name('customers.destroy');

    Route::get('crm/leads/{lead}/convert', [LeadConversionController::class, 'create'])->middleware('can:erp.leads.convert')->name('leads.convert');
    Route::post('crm/leads/{lead}/convert', [LeadConversionController::class, 'store'])->middleware('can:erp.leads.convert')->name('leads.convert.store');

    /*
    |--------------------------------------------------------------------------
    | Opportunity Stages
    |--------------------------------------------------------------------------
    */

    Route::get('crm/opportunity-stages', [OpportunityStageController::class, 'index'])->middleware('can:erp.opportunity-stages.view')->name('opportunity-stages.index');
    Route::post('crm/opportunity-stages', [OpportunityStageController::class, 'store'])->middleware('can:erp.opportunity-stages.create')->name('opportunity-stages.store');
    Route::put('crm/opportunity-stages/{opportunityStage}', [OpportunityStageController::class, 'update'])->middleware('can:erp.opportunity-stages.edit')->name('opportunity-stages.update');
    Route::delete('crm/opportunity-stages/{opportunityStage}', [OpportunityStageController::class, 'destroy'])->middleware('can:erp.opportunity-stages.delete')->name('opportunity-stages.destroy');

    /*
    |--------------------------------------------------------------------------
    | Opportunities
    |--------------------------------------------------------------------------
    */
    Route::get('crm/opportunities', [OpportunityController::class, 'index'])->middleware('can:erp.opportunities.view')->name('opportunities.index');
    Route::get('crm/opportunities/create', [OpportunityController::class, 'create'])->middleware('can:erp.opportunities.create')->name('opportunities.create');
    Route::post('crm/opportunities', [OpportunityController::class, 'store'])->middleware('can:erp.opportunities.create')->name('opportunities.store');
    Route::get('crm/opportunities/{opportunity}', [OpportunityController::class, 'show'])->middleware('can:erp.opportunities.view')->name('opportunities.show');
    Route::get('crm/opportunities/{opportunity}/edit', [OpportunityController::class, 'edit'])->middleware('can:erp.opportunities.edit')->name('opportunities.edit');
    Route::put('crm/opportunities/{opportunity}', [OpportunityController::class, 'update'])->middleware('can:erp.opportunities.edit')->name('opportunities.update');
    Route::delete('crm/opportunities/{opportunity}', [OpportunityController::class, 'destroy'])->middleware('can:erp.opportunities.delete')->name('opportunities.destroy');

    Route::get('crm/analytics', [CrmAnalyticsController::class, 'index'])->middleware('can:erp.crm-analytics.view')->name('crm-analytics.index');

    Route::get('inventory/product-categories', [ProductCategoryController::class, 'index'])->middleware('can:erp.product-categories.view')->name('inventory.product-categories.index');
    Route::post('inventory/product-categories', [ProductCategoryController::class, 'store'])->middleware('can:erp.product-categories.create')->name('inventory.product-categories.store');
    Route::put('inventory/product-categories/{productCategory}', [ProductCategoryController::class, 'update'])->middleware('can:erp.product-categories.edit')->name('inventory.product-categories.update');
    Route::delete('inventory/product-categories/{productCategory}', [ProductCategoryController::class, 'destroy'])->middleware('can:erp.product-categories.delete')->name('inventory.product-categories.destroy');
    Route::get('inventory/brands', [BrandController::class, 'index'])->middleware('can:erp.brands.view')->name('inventory.brands.index');
    Route::post('inventory/brands', [BrandController::class, 'store'])->middleware('can:erp.brands.create')->name('inventory.brands.store');
    Route::put('inventory/brands/{brand}', [BrandController::class, 'update'])->middleware('can:erp.brands.edit')->name('inventory.brands.update');
    Route::delete('inventory/brands/{brand}', [BrandController::class, 'destroy'])->middleware('can:erp.brands.delete')->name('inventory.brands.destroy');
    Route::get('inventory/units', [UnitController::class, 'index'])->middleware('can:erp.units.view')->name('inventory.units.index');
    Route::post('inventory/units', [UnitController::class, 'store'])->middleware('can:erp.units.create')->name('inventory.units.store');
    Route::put('inventory/units/{unit}', [UnitController::class, 'update'])->middleware('can:erp.units.edit')->name('inventory.units.update');
    Route::delete('inventory/units/{unit}', [UnitController::class, 'destroy'])->middleware('can:erp.units.delete')->name('inventory.units.destroy');
    Route::get('inventory/products', [ProductController::class, 'index'])->middleware('can:erp.products.view')->name('inventory.products.index');
    Route::get('inventory/products/create', [ProductController::class, 'create'])->middleware('can:erp.products.create')->name('inventory.products.create');
    Route::post('inventory/products', [ProductController::class, 'store'])->middleware('can:erp.products.create')->name('inventory.products.store');
    Route::get('inventory/products/{product}', [ProductController::class, 'show'])->middleware('can:erp.products.view')->name('inventory.products.show');
    Route::get('inventory/products/{product}/edit', [ProductController::class, 'edit'])->middleware('can:erp.products.edit')->name('inventory.products.edit');
    Route::put('inventory/products/{product}', [ProductController::class, 'update'])->middleware('can:erp.products.edit')->name('inventory.products.update');
    Route::delete('inventory/products/{product}', [ProductController::class, 'destroy'])->middleware('can:erp.products.delete')->name('inventory.products.destroy');

    /*
    |--------------------------------------------------------------------------
    | Warehouses
    |--------------------------------------------------------------------------
    */
    Route::get('inventory/warehouses', [WarehouseController::class, 'index'])->middleware('can:erp.warehouses.view')->name('inventory.warehouses.index');
    Route::get('inventory/warehouses/create', [WarehouseController::class, 'create'])->middleware('can:erp.warehouses.create')->name('inventory.warehouses.create');
    Route::post('inventory/warehouses', [WarehouseController::class, 'store'])->middleware('can:erp.warehouses.create')->name('inventory.warehouses.store');
    Route::get('inventory/warehouses/{warehouse}', [WarehouseController::class, 'show'])->middleware('can:erp.warehouses.view')->name('inventory.warehouses.show');
    Route::get('inventory/warehouses/{warehouse}/edit', [WarehouseController::class, 'edit'])->middleware('can:erp.warehouses.edit')->name('inventory.warehouses.edit');
    Route::put('inventory/warehouses/{warehouse}', [WarehouseController::class, 'update'])->middleware('can:erp.warehouses.edit')->name('inventory.warehouses.update');
    Route::delete('inventory/warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->middleware('can:erp.warehouses.delete')->name('inventory.warehouses.destroy');

    Route::get('inventory/stock', [InventoryStockController::class, 'index'])->middleware('can:erp.inventory-stock.view')->name('inventory.stock.index');
    Route::get('inventory/stock-movements', [StockMovementController::class, 'index'])->middleware('can:erp.stock-movements.view')->name('inventory.stock-movements.index');

    /*
     |--------------------------------------------------------------------------
     | Inventory - Low-Stock Report & Reorder Settings
     |--------------------------------------------------------------------------
     */
    Route::prefix('inventory/low-stock')->name('inventory.low-stock.')->group(function () {
        Route::get('/', [LowStockReportController::class, 'index'])
            ->middleware('can:erp.low-stock-reports.view')
            ->name('index');

        Route::put('/reorder-settings', [InventoryReorderSettingController::class, 'update'])
            ->middleware('can:erp.inventory-reorder-settings.manage')
            ->name('reorder-settings.update');
    });

    /*
    |--------------------------------------------------------------------------
    | Opening Stock Management
    |--------------------------------------------------------------------------
    */

    // Inventory - Stock Transfers
    Route::prefix('inventory/stock-transfers')->name('inventory.stock-transfers.')->group(function () {
        Route::get('/', [StockTransferController::class, 'index'])->middleware('can:erp.stock-transfers.view')->name('index');
        Route::get('/create', [StockTransferController::class, 'create'])->middleware('can:erp.stock-transfers.create')->name('create');
        Route::post('/', [StockTransferController::class, 'store'])->middleware('can:erp.stock-transfers.create')->name('store');
        Route::get('/{stockTransfer}', [StockTransferController::class, 'show'])->middleware('can:erp.stock-transfers.view')->name('show');
        Route::get('/{stockTransfer}/edit', [StockTransferController::class, 'edit'])->middleware('can:erp.stock-transfers.edit')->name('edit');
        Route::put('/{stockTransfer}', [StockTransferController::class, 'update'])->middleware('can:erp.stock-transfers.edit')->name('update');
        Route::delete('/{stockTransfer}', [StockTransferController::class, 'destroy'])->middleware('can:erp.stock-transfers.delete')->name('destroy');
        Route::post('/{stockTransfer}/post', [StockTransferController::class, 'post'])->middleware('can:erp.stock-transfers.post')->name('post');
    });

    Route::prefix('inventory/opening-stocks')->name('inventory.opening-stocks.')->group(function () {
        Route::get('/', [OpeningStockController::class, 'index'])->middleware('can:erp.opening-stocks.view')->name('index');
        Route::get('/create', [OpeningStockController::class, 'create'])->middleware('can:erp.opening-stocks.create')->name('create');
        Route::post('/', [OpeningStockController::class, 'store'])->middleware('can:erp.opening-stocks.create')->name('store');
        Route::get('/{openingStock}', [OpeningStockController::class, 'show'])->middleware('can:erp.opening-stocks.view')->name('show');
        Route::get('/{openingStock}/edit', [OpeningStockController::class, 'edit'])->middleware('can:erp.opening-stocks.edit')->name('edit');
        Route::put('/{openingStock}', [OpeningStockController::class, 'update'])->middleware('can:erp.opening-stocks.edit')->name('update');
        Route::delete('/{openingStock}', [OpeningStockController::class, 'destroy'])->middleware('can:erp.opening-stocks.delete')->name('destroy');
        Route::post('/{openingStock}/post', [OpeningStockController::class, 'post'])->middleware('can:erp.opening-stocks.post')->name('post');
    });

});

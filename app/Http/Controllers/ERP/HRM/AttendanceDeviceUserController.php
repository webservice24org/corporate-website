<?php

namespace App\Http\Controllers\ERP\HRM;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceUser;
use App\Models\Company;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use App\Services\Attendance\AttendancePunchResolver;
use App\Models\AttendanceRawPunch;

class AttendanceDeviceUserController extends Controller
{
    public function index(
        Request $request
    ): Response {
        $query =
    AttendanceDeviceUser::query()
        ->with([
            'device:id,company_id,branch_id,name,code,provider,model',
            'device.company:id,name,code',

            'employee:id,company_id,branch_id,department_id,designation_id,employee_number,first_name,middle_name,last_name,employment_status',

            'employee.branch:id,name,code',
            'employee.department:id,name,code',
            'employee.designation:id,name,code',

            'mappedBy:id,name',
        ])
        ->addSelect([
            'unmapped_punches_count' =>
                AttendanceRawPunch::query()
                    ->selectRaw(
                        'COUNT(*)'
                    )
                    ->whereColumn(
                        'attendance_raw_punches.device_id',
                        'attendance_device_users.device_id'
                    )
                    ->whereColumn(
                        'attendance_raw_punches.external_user_id',
                        'attendance_device_users.external_user_id'
                    )
                    ->whereNull(
                        'attendance_raw_punches.employee_id'
                    )
                    ->where(
                        'attendance_raw_punches.processing_status',
                        'unmapped'
                    ),
        ]);

        if (
            $request->filled(
                'company_id'
            )
        ) {
            $companyId =
                $request->integer(
                    'company_id'
                );

            $query->whereHas(
                'device',
                fn (Builder $deviceQuery) =>
                    $deviceQuery->where(
                        'company_id',
                        $companyId
                    )
            );
        }

        if (
            $request->filled(
                'device_id'
            )
        ) {
            $query->where(
                'device_id',
                $request->integer(
                    'device_id'
                )
            );
        }

        if (
            $request->filled(
                'status'
            )
        ) {
            $query->where(
                'status',
                $request->input(
                    'status'
                )
            );
        }

        if (
            $request->filled(
                'search'
            )
        ) {
            $search =
                trim(
                    (string) $request->input(
                        'search'
                    )
                );

            $query->where(
                function (
                    Builder $builder
                ) use ($search) {
                    $builder
                        ->where(
                            'external_user_id',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'card_number',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhereHas(
                            'employee',
                            function (
                                Builder $employeeQuery
                            ) use ($search) {
                                $employeeQuery
                                    ->where(
                                        'employee_number',
                                        'like',
                                        "%{$search}%"
                                    )
                                    ->orWhere(
                                        'first_name',
                                        'like',
                                        "%{$search}%"
                                    )
                                    ->orWhere(
                                        'last_name',
                                        'like',
                                        "%{$search}%"
                                    );
                            }
                        );
                }
            );
        }

        return Inertia::render(
            'ERP/HRM/Attendance/DeviceUsers/Index',
            [
                'mappings' =>
                    $query
                        ->orderByDesc('id')
                        ->paginate(15)
                        ->withQueryString(),

                'companies' =>
                    $this->companies(),

                'devices' =>
                    $this->devices(),

                'filters' => [
                    'search' =>
                        $request->input(
                            'search',
                            ''
                        ),

                    'company_id' =>
                        $request->input(
                            'company_id',
                            ''
                        ),

                    'device_id' =>
                        $request->input(
                            'device_id',
                            ''
                        ),

                    'status' =>
                        $request->input(
                            'status',
                            ''
                        ),
                ],
            ]
        );
    }

    public function create(
        Request $request
    ): Response {
        return Inertia::render(
            'ERP/HRM/Attendance/DeviceUsers/Create',
            [
                'companies' =>
                    $this->companies(),

                'devices' =>
                    $this->devices(),

                'employees' =>
                    $this->employees(),

                'selectedDeviceId' =>
                    $request->filled(
                        'device_id'
                    )
                        ? $request->integer(
                            'device_id'
                        )
                        : null,

                'selectedEmployeeId' =>
                    $request->filled(
                        'employee_id'
                    )
                        ? $request->integer(
                            'employee_id'
                        )
                        : null,
            ]
        );
    }

    public function store(
    Request $request,
    AttendancePunchResolver $resolver
): RedirectResponse {
    $validated =
        $this->validateMapping(
            $request
        );

    $device =
        AttendanceDevice::findOrFail(
            $validated[
                'device_id'
            ]
        );

    $employee =
        Employee::findOrFail(
            $validated[
                'employee_id'
            ]
        );

    $this->ensureSameCompany(
        $device,
        $employee
    );

    $mapping =
        AttendanceDeviceUser::create([
            ...$validated,

            'mapped_by' =>
                $request
                    ->user()
                    ?->id,

            'sync_status' =>
                'pending',
        ]);

    $resolved =
        $resolver->resolveForMapping(
            $mapping
        );

    $message =
        'Device employee mapping created successfully.';

    if ($resolved > 0) {
        $message .=
            " {$resolved} historical unmapped punch" .
            ($resolved === 1 ? '' : 'es') .
            ' resolved.';
    }

    return to_route(
        'erp.attendance-device-users.index'
    )->with(
        'success',
        $message
    );
}

    public function edit(
        AttendanceDeviceUser $attendanceDeviceUser
    ): Response {
        $attendanceDeviceUser->load([
            'device:id,company_id,name,code',
            'employee:id,company_id,employee_number,first_name,middle_name,last_name',
        ]);

        return Inertia::render(
            'ERP/HRM/Attendance/DeviceUsers/Edit',
            [
                'mapping' =>
                    $attendanceDeviceUser,

                'companies' =>
                    $this->companies(
                        $attendanceDeviceUser
                            ->device
                            ->company_id
                    ),

                'devices' =>
                    $this->devices(
                        $attendanceDeviceUser
                            ->device_id
                    ),

                'employees' =>
                    $this->employees(
                        $attendanceDeviceUser
                            ->employee_id
                    ),
            ]
        );
    }

public function update(
    Request $request,
    AttendanceDeviceUser $attendanceDeviceUser,
    AttendancePunchResolver $resolver
): RedirectResponse {
    $validated =
        $this->validateMapping(
            $request,
            $attendanceDeviceUser
        );

    $device =
        AttendanceDevice::findOrFail(
            $validated[
                'device_id'
            ]
        );

    $employee =
        Employee::findOrFail(
            $validated[
                'employee_id'
            ]
        );

    $this->ensureSameCompany(
        $device,
        $employee
    );

    $attendanceDeviceUser->update([
        ...$validated,

        'sync_status' =>
            'pending',

        'sync_error' =>
            null,
    ]);

    /*
    |--------------------------------------------------------------------------
    | Reload updated values
    |--------------------------------------------------------------------------
    */

    $attendanceDeviceUser->refresh();

    $resolved =
        $resolver->resolveForMapping(
            $attendanceDeviceUser
        );

    $message =
        'Device employee mapping updated successfully.';

    if ($resolved > 0) {
        $message .=
            " {$resolved} historical unmapped punch" .
            ($resolved === 1 ? '' : 'es') .
            ' resolved.';
    }

    return to_route(
        'erp.attendance-device-users.index'
    )->with(
        'success',
        $message
    );
}
    public function destroy(
        AttendanceDeviceUser $attendanceDeviceUser
    ): RedirectResponse {
        $attendanceDeviceUser->delete();

        return to_route(
            'erp.attendance-device-users.index'
        )->with(
            'success',
            'Device employee mapping deleted successfully.'
        );
    }

    private function validateMapping(
        Request $request,
        ?AttendanceDeviceUser $mapping = null
    ): array {
        $deviceId =
            $request->integer(
                'device_id'
            );

        $externalIdRule =
            Rule::unique(
                'attendance_device_users',
                'external_user_id'
            )->where(
                fn ($query) =>
                    $query->where(
                        'device_id',
                        $deviceId
                    )
            );

        $employeeRule =
            Rule::unique(
                'attendance_device_users',
                'employee_id'
            )->where(
                fn ($query) =>
                    $query->where(
                        'device_id',
                        $deviceId
                    )
            );

        if ($mapping) {
            $externalIdRule->ignore(
                $mapping->id
            );

            $employeeRule->ignore(
                $mapping->id
            );
        }

        return $request->validate([
            'device_id' => [
                'required',
                'integer',
                'exists:attendance_devices,id',
            ],

            'employee_id' => [
                'required',
                'integer',
                'exists:employees,id',
                $employeeRule,
            ],

            'external_user_id' => [
                'required',
                'string',
                'max:100',
                $externalIdRule,
            ],

            'card_number' => [
                'nullable',
                'string',
                'max:100',
            ],

            'status' => [
                'required',

                Rule::in([
                    'active',
                    'inactive',
                ]),
            ],

            'notes' => [
                'nullable',
                'string',
            ],
        ]);
    }

    private function ensureSameCompany(
        AttendanceDevice $device,
        Employee $employee
    ): void {
        if (
            $device->company_id !==
            $employee->company_id
        ) {
            throw ValidationException::withMessages([
                'employee_id' =>
                    'The employee and attendance device must belong to the same company.',
            ]);
        }
    }

    private function companies(
        ?int $includeId = null
    ) {
        return Company::query()
            ->where(
                function (
                    Builder $query
                ) use ($includeId) {
                    $query->where(
                        'status',
                        'active'
                    );

                    if ($includeId) {
                        $query->orWhere(
                            'id',
                            $includeId
                        );
                    }
                }
            )
            ->orderByDesc(
                'is_default'
            )
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'code',
            ]);
    }

    private function devices(
        ?int $includeId = null
    ) {
        return AttendanceDevice::query()
            ->where(
                function (
                    Builder $query
                ) use ($includeId) {
                    $query->where(
                        'status',
                        'active'
                    );

                    if ($includeId) {
                        $query->orWhere(
                            'id',
                            $includeId
                        );
                    }
                }
            )
            ->orderBy('name')
            ->get([
                'id',
                'company_id',
                'branch_id',
                'name',
                'code',
                'provider',
                'model',
                'status',
            ]);
    }

    private function employees(
        ?int $includeId = null
    ) {
        return Employee::query()
            ->with([
                'company:id,name,code',
                'branch:id,name,code',
                'department:id,name,code',
                'designation:id,name,code',
            ])
            ->where(
                function (
                    Builder $query
                ) use ($includeId) {
                    $query->whereIn(
                        'employment_status',
                        [
                            'active',
                            'probation',
                        ]
                    );

                    if ($includeId) {
                        $query->orWhere(
                            'id',
                            $includeId
                        );
                    }
                }
            )
            ->orderBy(
                'employee_number'
            )
            ->get([
                'id',
                'company_id',
                'branch_id',
                'department_id',
                'designation_id',

                'employee_number',

                'first_name',
                'middle_name',
                'last_name',

                'employment_status',
            ]);
    }

    public function resolvePunches(
    AttendanceDeviceUser $attendanceDeviceUser,
    AttendancePunchResolver $resolver
): RedirectResponse {
    if (
        $attendanceDeviceUser->status !==
        'active'
    ) {
        return back()->with(
            'error',
            'Inactive device employee mappings cannot resolve punches.'
        );
    }

    $resolved =
        $resolver->resolveForMapping(
            $attendanceDeviceUser
        );

    if ($resolved === 0) {
        return back()->with(
            'success',
            'No unmapped punches were found for this device user.'
        );
    }

    return back()->with(
        'success',
        "{$resolved} unmapped punch" .
        ($resolved === 1 ? '' : 'es') .
        ' resolved successfully.'
    );
}


}
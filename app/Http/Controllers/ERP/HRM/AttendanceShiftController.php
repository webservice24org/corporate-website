<?php

namespace App\Http\Controllers\ERP\HRM;

use App\Http\Controllers\Controller;
use App\Models\AttendanceShift;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceShiftController extends Controller
{
    public function index(
        Request $request
    ): Response {
        $query =
            AttendanceShift::query()
                ->with([
                    'company:id,name,code',
                ]);

        if (
            $request->filled(
                'company_id'
            )
        ) {
            $query->where(
                'company_id',
                $request->integer(
                    'company_id'
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
                $request->string(
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
                    $request->string(
                        'search'
                    )
                );

            $query->where(
                function (
                    $builder
                ) use ($search) {
                    $builder
                        ->where(
                            'name',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'code',
                            'like',
                            "%{$search}%"
                        );
                }
            );
        }

        return Inertia::render(
            'ERP/HRM/Attendance/Shifts/Index',
            [
                'shifts' =>
                    $query
                        ->orderBy(
                            'company_id'
                        )
                        ->orderBy(
                            'start_time'
                        )
                        ->paginate(15)
                        ->withQueryString(),

                'companies' =>
                    $this->companies(),

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

                    'status' =>
                        $request->input(
                            'status',
                            ''
                        ),
                ],
            ]
        );
    }

    public function create(): Response
    {
        return Inertia::render(
            'ERP/HRM/Attendance/Shifts/Create',
            [
                'companies' =>
                    $this->companies(),
            ]
        );
    }

    public function store(
        Request $request
    ): RedirectResponse {
        $validated =
            $this->validateShift(
                $request
            );

        $validated[
            'code'
        ] = strtoupper(
            $validated[
                'code'
            ]
        );

        AttendanceShift::create(
            $validated
        );

        return to_route(
            'erp.shifts.index'
        )->with(
            'success',
            'Shift created successfully.'
        );
    }

    public function edit(
        AttendanceShift $shift
    ): Response {
        return Inertia::render(
            'ERP/HRM/Attendance/Shifts/Edit',
            [
                'shift' =>
                    $shift,

                'companies' =>
                    $this->companies(
                        $shift->company_id
                    ),
            ]
        );
    }

    public function update(
        Request $request,
        AttendanceShift $shift
    ): RedirectResponse {
        $validated =
            $this->validateShift(
                $request,
                $shift
            );

        $validated[
            'code'
        ] = strtoupper(
            $validated[
                'code'
            ]
        );

        $shift->update(
            $validated
        );

        return to_route(
            'erp.shifts.index'
        )->with(
            'success',
            'Shift updated successfully.'
        );
    }

    
    public function destroy(
        AttendanceShift $shift
    ): RedirectResponse {
        if (
            $shift
                ->employeeAssignments()
                ->exists()
        ) {
            return back()->with(
                'error',
                'This shift cannot be deleted because it has employee assignments.'
            );
        }

        $shift->delete();

        return to_route(
            'erp.shifts.index'
        )->with(
            'success',
            'Shift deleted successfully.'
        );
    }

    private function validateShift(
        Request $request,
        ?AttendanceShift $shift = null
    ): array {
        $companyId =
            $request->integer(
                'company_id'
            );

        $uniqueCode =
            Rule::unique(
                'attendance_shifts',
                'code'
            )->where(
                fn ($query) =>
                    $query->where(
                        'company_id',
                        $companyId
                    )
            );

        if ($shift) {
            $uniqueCode->ignore(
                $shift->id
            );
        }

        return $request->validate([
            'company_id' => [
                'required',
                'integer',
                'exists:companies,id',
            ],

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'code' => [
                'required',
                'string',
                'max:50',
                $uniqueCode,
            ],

            'start_time' => [
                'required',
                'date_format:H:i',
            ],

            'end_time' => [
                'required',
                'date_format:H:i',
            ],

            'crosses_midnight' => [
                'required',
                'boolean',
            ],

            'check_in_window_before_minutes' => [
                'required',
                'integer',
                'min:0',
                'max:1440',
            ],

            'check_in_window_after_minutes' => [
                'required',
                'integer',
                'min:0',
                'max:1440',
            ],

            'check_out_window_before_minutes' => [
                'required',
                'integer',
                'min:0',
                'max:1440',
            ],

            'check_out_window_after_minutes' => [
                'required',
                'integer',
                'min:0',
                'max:1440',
            ],

            'grace_minutes' => [
                'required',
                'integer',
                'min:0',
                'max:180',
            ],

            'early_leave_grace_minutes' => [
                'required',
                'integer',
                'min:0',
                'max:180',
            ],

            'minimum_work_minutes' => [
                'required',
                'integer',
                'min:0',
                'max:1440',
            ],

            'half_day_minutes' => [
                'required',
                'integer',
                'min:0',
                'max:1440',
            ],

            'overtime_after_minutes' => [
                'required',
                'integer',
                'min:0',
                'max:1440',
            ],

            'break_minutes' => [
                'required',
                'integer',
                'min:0',
                'max:1440',
            ],

            'break_is_paid' => [
                'required',
                'boolean',
            ],

            'working_days' => [
                'required',
                'array',
                'min:1',
            ],

            'working_days.*' => [
                'required',
                'integer',
                'between:0,6',
                'distinct',
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

    private function companies(
        ?int $includeCompanyId = null
    ) {
        return Company::query()
            ->where(
                function (
                    $query
                ) use (
                    $includeCompanyId
                ) {
                    $query
                        ->where(
                            'status',
                            'active'
                        );

                    if (
                        $includeCompanyId
                    ) {
                        $query->orWhere(
                            'id',
                            $includeCompanyId
                        );
                    }
                }
            )
            ->orderByDesc(
                'is_default'
            )
            ->orderBy(
                'name'
            )
            ->get([
                'id',
                'name',
                'code',
            ]);
    }
}
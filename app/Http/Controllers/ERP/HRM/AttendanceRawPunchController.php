<?php

namespace App\Http\Controllers\ERP\HRM;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDevice;
use App\Models\AttendanceRawPunch;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceRawPunchController extends Controller
{
    public function index(
        Request $request
    ): Response {
        $query =
            AttendanceRawPunch::query()
                ->with([
                    'company:id,name,code',

                    'device:id,company_id,branch_id,name,code,provider,model',

                    'employee:id,employee_number,first_name,middle_name,last_name',
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
                'processing_status'
            )
        ) {
            $query->where(
                'processing_status',
                $request->input(
                    'processing_status'
                )
            );
        }

        if (
            $request->filled(
                'source'
            )
        ) {
            $query->where(
                'source',
                $request->input(
                    'source'
                )
            );
        }

        if (
            $request->filled(
                'date_from'
            )
        ) {
            $query->whereDate(
                'punched_at',
                '>=',
                $request->input(
                    'date_from'
                )
            );
        }

        if (
            $request->filled(
                'date_to'
            )
        ) {
            $query->whereDate(
                'punched_at',
                '<=',
                $request->input(
                    'date_to'
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
                            'source_record_id',
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
            'ERP/HRM/Attendance/RawPunches/Index',
            [
                'punches' => $query
                    ->orderByDesc(
                        'punched_at'
                    )
                    ->orderByDesc(
                        'id'
                    )
                    ->paginate(25)
                    ->withQueryString(),

                'companies' => Company::query()
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
                    ]),

                'devices' => AttendanceDevice::query()
                    ->orderBy('name')
                    ->get([
                        'id',
                        'company_id',
                        'name',
                        'code',
                    ]),

                'filters' => [
                    'search' => $request->input(
                        'search',
                        ''
                    ),

                    'company_id' => $request->input(
                        'company_id',
                        ''
                    ),

                    'device_id' => $request->input(
                        'device_id',
                        ''
                    ),

                    'processing_status' => $request->input(
                        'processing_status',
                        ''
                    ),

                    'source' => $request->input(
                        'source',
                        ''
                    ),

                    'date_from' => $request->input(
                        'date_from',
                        ''
                    ),

                    'date_to' => $request->input(
                        'date_to',
                        ''
                    ),
                ],
            ]
        );
    }

    public function show(
        AttendanceRawPunch $attendanceRawPunch
    ): Response {
        $attendanceRawPunch->load([
            'company:id,name,code',

            'device:id,company_id,branch_id,name,code,provider,model,serial_number,timezone',

            'employee:id,employee_number,first_name,middle_name,last_name,company_id,branch_id,department_id,designation_id',

            'employee.company:id,name,code',

            'employee.branch:id,name,code',

            'employee.department:id,name,code',

            'employee.designation:id,name,code',
        ]);

        return Inertia::render(
            'ERP/HRM/Attendance/RawPunches/Show',
            [
                'punch' => $attendanceRawPunch,
            ]
        );
    }
}

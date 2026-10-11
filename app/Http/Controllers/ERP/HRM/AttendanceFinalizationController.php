<?php

namespace App\Http\Controllers\ERP\HRM;

use App\Http\Controllers\Controller;
use App\Models\AttendanceFinalizationRun;
use App\Models\AttendanceSetting;
use App\Models\Company;
use App\Services\Attendance\AttendanceDayFinalizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceFinalizationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render(
            'ERP/HRM/Attendance/Finalization/Index',
            [
                'companies' =>
                    Company::query()
                        ->where(
                            'status',
                            'active'
                        )
                        ->orderBy('name')
                        ->get([
                            'id',
                            'name',
                            'timezone',
                        ]),

                'runs' =>
                    AttendanceFinalizationRun::query()
                        ->with([
                            'company:id,name',
                            'runBy:id,name',
                        ])
                        ->orderByDesc(
                            'created_at'
                        )
                        ->paginate(20),
            ]
        );
    }

    public function run(
        Request $request,
        AttendanceDayFinalizer $finalizer
    ): RedirectResponse {
        $data =
            $request->validate([
                'company_id' => [
                    'required',
                    'integer',
                    'exists:companies,id',
                ],

                'attendance_date' => [
                    'required',
                    'date',
                ],
            ]);

        $company =
            Company::query()
                ->findOrFail(
                    $data['company_id']
                );

        $setting =
            AttendanceSetting::query()
                ->where(
                    'company_id',
                    $company->id
                )
                ->where(
                    'is_active',
                    true
                )
                ->first();

        $timezone =
            $setting?->timezone
            ?: $company->timezone
            ?: 'Asia/Dhaka';

        $target =
            CarbonImmutable::parse(
                $data[
                    'attendance_date'
                ],
                $timezone
            )->startOfDay();

        $today =
            CarbonImmutable::now(
                $timezone
            )->startOfDay();

        if (
            $target
                ->greaterThanOrEqualTo(
                    $today
                )
        ) {
            throw ValidationException::withMessages([
                'attendance_date' =>
                    'Only completed past attendance dates can be finalized.',
            ]);
        }

        $run =
            $finalizer->finalize(
                companyId:
                    (int)
                    $data[
                        'company_id'
                    ],

                attendanceDate:
                    $data[
                        'attendance_date'
                    ],

                runBy:
                    $request->user()?->id
            );

        return back()->with(
            'success',
            sprintf(
                'Attendance finalized. %d absent, %d off-day, %d off-day-work reconciled.',
                $run->absent_created,
                $run->off_day_created,
                $run->off_day_work_updated
            )
        );
    }
}
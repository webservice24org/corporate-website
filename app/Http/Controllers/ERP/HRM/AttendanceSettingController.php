<?php

namespace App\Http\Controllers\ERP\HRM;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSetting;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceSettingController extends Controller
{
    public function index(
        Request $request
    ): Response {
        $companies =
            Company::query()
                ->orderByDesc(
                    'is_default'
                )
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'code',
                    'timezone',
                ]);

        $companyId =
            $request->integer(
                'company_id'
            );

        if (
            ! $companyId &&
            $companies->isNotEmpty()
        ) {
            $companyId =
                $companies
                    ->first()
                    ->id;
        }

        $company =
            $companies->firstWhere(
                'id',
                $companyId
            );

        $setting = null;

        if ($company) {
            $setting =
                AttendanceSetting::firstOrCreate(
                    [
                        'company_id' => $company->id,
                    ],
                    [
                        'timezone' => $company->timezone
                            ?: 'Asia/Dhaka',
                    ]
                );
        }

        return Inertia::render(
            'ERP/HRM/Attendance/Settings/Index',
            [
                'companies' => $companies,

                'selectedCompanyId' => $company?->id,

                'setting' => $setting,
            ]
        );
    }

    public function update(
        Request $request,
        AttendanceSetting $attendanceSetting
    ): RedirectResponse {
        $validated =
            $request->validate([
                'timezone' => [
                    'required',
                    'timezone',
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

                'half_day_minutes' => [
                    'required',
                    'integer',
                    'min:0',
                    'max:1440',
                ],

                'minimum_full_day_minutes' => [
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

                'duplicate_punch_window_seconds' => [
                    'required',
                    'integer',
                    'min:0',
                    'max:3600',
                ],

                'auto_pair_punches' => [
                    'required',
                    'boolean',
                ],

                'allow_manual_attendance' => [
                    'required',
                    'boolean',
                ],

                'allow_multiple_checkins' => [
                    'required',
                    'boolean',
                ],

                'mark_absent_automatically' => [
                    'required',
                    'boolean',
                ],

                'count_weekends_as_absent' => [
                    'required',
                    'boolean',
                ],

                'count_holidays_as_absent' => [
                    'required',
                    'boolean',
                ],

                'device_attendance_enabled' => [
                    'required',
                    'boolean',
                ],

                'default_punch_source' => [
                    'required',

                    Rule::in([
                        'manual',
                        'device',
                        'api',
                        'import',
                    ]),
                ],

                'is_active' => [
                    'required',
                    'boolean',
                ],
            ]);

        $attendanceSetting->update(
            $validated
        );

        return back()->with(
            'success',
            'Attendance settings updated successfully.'
        );
    }
}

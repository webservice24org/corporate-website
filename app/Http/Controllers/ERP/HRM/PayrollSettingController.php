<?php

namespace App\Http\Controllers\ERP\HRM;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PayrollSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PayrollSettingController extends Controller
{
    public function index(): Response
    {
        $companies = Company::query()
            ->with('payrollSetting')
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'status',
            ]);

        return Inertia::render(
            'ERP/HRM/Payroll/Settings/Index',
            [
                'companies' => $companies,
            ]
        );
    }

    public function edit(
        Company $company
    ): Response {
        $setting =
            $company->payrollSetting;

        return Inertia::render(
            'ERP/HRM/Payroll/Settings/Edit',
            [
                'company' => [
                    'id' => $company->id,
                    'name' => $company->name,
                ],

                'setting' =>
                    $setting
                        ? $setting->only([
                            'id',
                            'company_id',
                            'pay_frequency',
                            'salary_proration_basis',
                            'default_currency',
                            'default_pay_day',
                            'attendance_integration_enabled',
                            'require_finalized_attendance',
                            'attendance_deduction_enabled',
                            'unpaid_leave_deduction_enabled',
                            'overtime_enabled',
                            'overtime_rate_multiplier',
                            'auto_include_recurring_components',
                            'rounding_method',
                            'rounding_precision',
                            'status',
                            'notes',
                        ])
                        : $this->defaults(
                            $company->id
                        ),
            ]
        );
    }

    public function update(
        Request $request,
        Company $company
    ): RedirectResponse {
        $data =
            $request->validate([
                'pay_frequency' => [
                    'required',
                    Rule::in([
                        'monthly',
                        'biweekly',
                        'weekly',
                    ]),
                ],

                'salary_proration_basis' => [
                    'required',
                    Rule::in([
                        'calendar_days',
                        'working_days',
                        'fixed_30_days',
                    ]),
                ],

                'default_currency' => [
                    'required',
                    'string',
                    'size:3',
                ],

                'default_pay_day' => [
                    'nullable',
                    'integer',
                    'between:1,31',
                ],

                'attendance_integration_enabled' => [
                    'required',
                    'boolean',
                ],

                'require_finalized_attendance' => [
                    'required',
                    'boolean',
                ],

                'attendance_deduction_enabled' => [
                    'required',
                    'boolean',
                ],

                'unpaid_leave_deduction_enabled' => [
                    'required',
                    'boolean',
                ],

                'overtime_enabled' => [
                    'required',
                    'boolean',
                ],

                'overtime_rate_multiplier' => [
                    'required',
                    'numeric',
                    'min:0',
                    'max:100',
                ],

                'auto_include_recurring_components' => [
                    'required',
                    'boolean',
                ],

                'rounding_method' => [
                    'required',
                    Rule::in([
                        'none',
                        'nearest',
                        'up',
                        'down',
                    ]),
                ],

                'rounding_precision' => [
                    'required',
                    'integer',
                    Rule::in([
                        0,
                        2,
                    ]),
                ],

                'status' => [
                    'required',
                    'boolean',
                ],

                'notes' => [
                    'nullable',
                    'string',
                    'max:5000',
                ],
            ]);

        /*
         * A monthly payroll can use a preferred
         * calendar payment day.
         *
         * Weekly/biweekly payroll dates will later
         * come from generated payroll periods.
         */
        if (
            $data['pay_frequency'] !==
            'monthly'
        ) {
            $data['default_pay_day'] =
                null;
        }

        if (
            !$data[
                'attendance_integration_enabled'
            ]
        ) {
            $data[
                'require_finalized_attendance'
            ] = false;

            $data[
                'attendance_deduction_enabled'
            ] = false;

            $data[
                'unpaid_leave_deduction_enabled'
            ] = false;

            $data[
                'overtime_enabled'
            ] = false;
        }

        if (
            !$data['overtime_enabled']
        ) {
            $data[
                'overtime_rate_multiplier'
            ] = 0;
        }

        $data['default_currency'] =
            strtoupper(
                trim(
                    $data[
                        'default_currency'
                    ]
                )
            );

        PayrollSetting::updateOrCreate(
            [
                'company_id' =>
                    $company->id,
            ],
            $data
        );

        return redirect()
            ->route(
                'erp.payroll-settings.index'
            )
            ->with(
                'success',
                'Payroll settings updated successfully.'
            );
    }

    private function defaults(
        int $companyId
    ): array {
        return [
            'id' => null,

            'company_id' =>
                $companyId,

            'pay_frequency' =>
                'monthly',

            'salary_proration_basis' =>
                'calendar_days',

            'default_currency' =>
                'BDT',

            'default_pay_day' =>
                25,

            'attendance_integration_enabled' =>
                true,

            'require_finalized_attendance' =>
                true,

            'attendance_deduction_enabled' =>
                false,

            'unpaid_leave_deduction_enabled' =>
                true,

            'overtime_enabled' =>
                true,

            'overtime_rate_multiplier' =>
                '1.5000',

            'auto_include_recurring_components' =>
                true,

            'rounding_method' =>
                'none',

            'rounding_precision' =>
                2,

            'status' =>
                true,

            'notes' =>
                null,
        ];
    }
}
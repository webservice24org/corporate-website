<?php

namespace App\Http\Controllers\ERP\HRM;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PayrollPeriod;
use App\Models\PayrollSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PayrollPeriodController extends Controller
{
    public function index(
        Request $request
    ): Response {
        $filters =
            $request->validate([
                'company_id' => [
                    'nullable',
                    'integer',
                    'exists:companies,id',
                ],

                'status' => [
                    'nullable',
                    Rule::in([
                        'draft',
                        'open',
                        'processing',
                        'closed',
                    ]),
                ],

                'year' => [
                    'nullable',
                    'integer',
                    'between:2000,2100',
                ],

                'search' => [
                    'nullable',
                    'string',
                    'max:100',
                ],
            ]);

        $periods =
            PayrollPeriod::query()
                ->with([
                    'company:id,name',
                ])
                ->when(
                    $filters[
                        'company_id'
                    ] ?? null,
                    fn (
                        $query,
                        $companyId
                    ) =>
                        $query->where(
                            'company_id',
                            $companyId
                        )
                )
                ->when(
                    $filters[
                        'status'
                    ] ?? null,
                    fn (
                        $query,
                        $status
                    ) =>
                        $query->where(
                            'status',
                            $status
                        )
                )
                ->when(
                    $filters[
                        'year'
                    ] ?? null,
                    fn (
                        $query,
                        $year
                    ) =>
                        $query->whereYear(
                            'start_date',
                            $year
                        )
                )
                ->when(
                    $filters[
                        'search'
                    ] ?? null,
                    function (
                        $query,
                        $search
                    ) {
                        $query->where(
                            function (
                                $query
                            ) use (
                                $search
                            ) {
                                $query
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
                )
                ->orderByDesc(
                    'start_date'
                )
                ->orderByDesc(
                    'id'
                )
                ->paginate(25)
                ->withQueryString();

        return Inertia::render(
            'ERP/HRM/Payroll/Periods/Index',
            [
                'periods' =>
                    $periods,

                'companies' =>
                    $this->companies(),

                'filters' =>
                    $filters,
            ]
        );
    }

    public function create(
        Request $request
    ): Response {
        $companyId =
            $request->integer(
                'company_id'
            ) ?: null;

        return Inertia::render(
            'ERP/HRM/Payroll/Periods/Create',
            [
                'companies' =>
                    $this->companies(),

                'payrollSettings' =>
                    $this->payrollSettings(),

                'selectedCompanyId' =>
                    $companyId,
            ]
        );
    }

    public function store(
        Request $request
    ): RedirectResponse {
        $data =
            $this->validateData(
                $request
            );

        $this->validateNoOverlap(
            (int)
            $data['company_id'],
            $data['start_date'],
            $data['end_date']
        );

        PayrollPeriod::create(
            $this->normalizeData(
                $data
            )
        );

        return redirect()
            ->route(
                'erp.payroll-periods.index'
            )
            ->with(
                'success',
                'Payroll period created successfully.'
            );
    }

    public function edit(
        PayrollPeriod $payrollPeriod
    ): Response {
        return Inertia::render(
            'ERP/HRM/Payroll/Periods/Edit',
            [
                'payrollPeriod' =>
                    $payrollPeriod,

                'companies' =>
                    $this->companies(),

                'payrollSettings' =>
                    $this->payrollSettings(),
            ]
        );
    }

    public function update(
        Request $request,
        PayrollPeriod $payrollPeriod
    ): RedirectResponse {
        if (
            $payrollPeriod
                ->is_locked
        ) {
            throw ValidationException::withMessages([
                'period' =>
                    'This payroll period is locked and cannot be edited.',
            ]);
        }

        if (
            $payrollPeriod
                ->status ===
            'processing'
        ) {
            throw ValidationException::withMessages([
                'period' =>
                    'A payroll period currently being processed cannot be edited.',
            ]);
        }

        $data =
            $this->validateData(
                $request
            );

        $this->validateNoOverlap(
            (int)
            $data['company_id'],
            $data['start_date'],
            $data['end_date'],
            $payrollPeriod->id
        );

        $payrollPeriod->update(
            $this->normalizeData(
                $data
            )
        );

        return redirect()
            ->route(
                'erp.payroll-periods.index'
            )
            ->with(
                'success',
                'Payroll period updated successfully.'
            );
    }

    public function destroy(
        PayrollPeriod $payrollPeriod
    ): RedirectResponse {
        if (
            $payrollPeriod
                ->is_locked
        ) {
            throw ValidationException::withMessages([
                'period' =>
                    'A locked payroll period cannot be deleted.',
            ]);
        }

        if (
            $payrollPeriod
                ->status !==
            'draft'
        ) {
            throw ValidationException::withMessages([
                'period' =>
                    'Only draft payroll periods can be deleted.',
            ]);
        }

        $payrollPeriod->delete();

        return redirect()
            ->route(
                'erp.payroll-periods.index'
            )
            ->with(
                'success',
                'Payroll period deleted successfully.'
            );
    }

    private function validateData(
        Request $request
    ): array {
        return $request->validate([
            'company_id' => [
                'required',
                'integer',
                'exists:companies,id',
            ],

            'name' => [
                'required',
                'string',
                'max:100',
            ],

            'code' => [
                'required',
                'string',
                'max:50',
            ],

            'start_date' => [
                'required',
                'date',
            ],

            'end_date' => [
                'required',
                'date',
                'after_or_equal:start_date',
            ],

            'payment_date' => [
                'nullable',
                'date',
            ],

            'status' => [
                'required',
                Rule::in([
                    'draft',
                    'open',
                    'closed',
                ]),
            ],

            'notes' => [
                'nullable',
                'string',
                'max:5000',
            ],
        ]);
    }

    private function normalizeData(
        array $data
    ): array {
        return [
            'company_id' =>
                $data[
                    'company_id'
                ],

            'name' =>
                trim(
                    $data[
                        'name'
                    ]
                ),

            'code' =>
                strtoupper(
                    trim(
                        $data[
                            'code'
                        ]
                    )
                ),

            'start_date' =>
                $data[
                    'start_date'
                ],

            'end_date' =>
                $data[
                    'end_date'
                ],

            'payment_date' =>
                $data[
                    'payment_date'
                ]
                ?? null,

            'status' =>
                $data[
                    'status'
                ],

            'notes' =>
                $data[
                    'notes'
                ]
                ?? null,
        ];
    }

    private function validateNoOverlap(
        int $companyId,
        string $startDate,
        string $endDate,
        ?int $ignoreId = null
    ): void {
        $overlap =
            PayrollPeriod::query()
                ->where(
                    'company_id',
                    $companyId
                )
                ->when(
                    $ignoreId,
                    fn (
                        $query
                    ) =>
                        $query->where(
                            'id',
                            '!=',
                            $ignoreId
                        )
                )
                ->where(
                    'start_date',
                    '<=',
                    $endDate
                )
                ->where(
                    'end_date',
                    '>=',
                    $startDate
                )
                ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'start_date' =>
                    'This company already has a payroll period overlapping the selected date range.',
            ]);
        }
    }

    private function companies()
    {
        return Company::query()
            ->where(
                'status',
                'active'
            )
            ->orderBy(
                'name'
            )
            ->get([
                'id',
                'name',
            ]);
    }

    private function payrollSettings()
    {
        return PayrollSetting::query()
            ->where(
                'status',
                true
            )
            ->get([
                'company_id',
                'pay_frequency',
                'default_currency',
                'default_pay_day',
            ]);
    }
}
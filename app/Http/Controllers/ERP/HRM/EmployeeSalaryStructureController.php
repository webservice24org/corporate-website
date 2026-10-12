<?php

namespace App\Http\Controllers\ERP\HRM;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalaryStructure;
use App\Models\SalaryComponent;
use App\Services\Payroll\SalaryStructureCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeSalaryStructureController extends Controller
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

                'employee_id' => [
                    'nullable',
                    'integer',
                    'exists:employees,id',
                ],

                'status' => [
                    'nullable',
                    Rule::in([
                        'active',
                        'inactive',
                    ]),
                ],

                'search' => [
                    'nullable',
                    'string',
                    'max:100',
                ],
            ]);

        $structures =
            EmployeeSalaryStructure::query()
                ->with([
                    'company:id,name',

                    'employee:id,employee_number,first_name,middle_name,last_name,company_id,branch_id,department_id,designation_id',

                    'employee.branch:id,name',

                    'employee.department:id,name',

                    'employee.designation:id,name',
                ])
                ->when(
                    $filters[
                        'company_id'
                    ] ?? null,
                    fn (
                        $query,
                        $companyId
                    ) => $query->where(
                        'company_id',
                        $companyId
                    )
                )
                ->when(
                    $filters[
                        'employee_id'
                    ] ?? null,
                    fn (
                        $query,
                        $employeeId
                    ) => $query->where(
                        'employee_id',
                        $employeeId
                    )
                )
                ->when(
                    $filters[
                        'status'
                    ] ?? null,
                    fn (
                        $query,
                        $status
                    ) => $query->where(
                        'status',
                        $status
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
                        $query->whereHas(
                            'employee',
                            function (
                                $query
                            ) use (
                                $search
                            ) {
                                $query
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
                                        'middle_name',
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
                )
                ->orderByDesc(
                    'effective_from'
                )
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString();

        return Inertia::render(
            'ERP/HRM/Payroll/SalaryStructures/Index',
            [
                'structures' => $structures,

                'companies' => $this->companies(),

                'employees' => $this->employees(),

                'filters' => $filters,
            ]
        );
    }

    public function create(): Response
    {
        return Inertia::render(
            'ERP/HRM/Payroll/SalaryStructures/Create',
            [
                'companies' => $this->companies(),

                'employees' => $this->employees(),

                'components' => $this->components(),
            ]
        );
    }

    public function store(
        Request $request,
        SalaryStructureCalculator $calculator
    ): RedirectResponse {
        $data =
            $this->validateData(
                $request
            );

        $employee =
            Employee::query()
                ->findOrFail(
                    $data[
                        'employee_id'
                    ]
                );

        $this->validateEmployeeCompany(
            $employee,
            (int)
            $data['company_id']
        );

        $this->validateNoOverlap(
            (int)
            $data['employee_id'],
            $data['effective_from'],
            $data['effective_to']
                ?? null
        );

        $components =
            $this->loadSubmittedComponents(
                (int)
                $data['company_id'],
                $data['items']
            );

        $calculation =
            $calculator->calculate(
                $components,
                $data['items']
            );

        DB::transaction(
            function () use (
                $data,
                $calculation
            ) {
                $structure =
                    EmployeeSalaryStructure::create([
                        'company_id' => $data[
                                'company_id'
                            ],

                        'employee_id' => $data[
                                'employee_id'
                            ],

                        'effective_from' => $data[
                                'effective_from'
                            ],

                        'effective_to' => $data[
                                'effective_to'
                            ]
                            ?? null,

                        'status' => $data[
                                'status'
                            ],

                        'currency' => strtoupper(
                            $data[
                                'currency'
                            ]
                        ),

                        'gross_salary' => $calculation[
                                'gross_salary'
                            ],

                        'total_earnings' => $calculation[
                                'total_earnings'
                            ],

                        'total_deductions' => $calculation[
                                'total_deductions'
                            ],

                        'net_salary' => $calculation[
                                'net_salary'
                            ],

                        'notes' => $data[
                                'notes'
                            ]
                            ?? null,
                    ]);

                $structure
                    ->items()
                    ->createMany(
                        $calculation[
                            'items'
                        ]
                    );
            }
        );

        return redirect()
            ->route(
                'erp.salary-structures.index'
            )
            ->with(
                'success',
                'Employee salary structure created successfully.'
            );
    }

    public function show(
        EmployeeSalaryStructure $salaryStructure
    ): Response {
        $salaryStructure->load([
            'company:id,name',

            'employee:id,employee_number,first_name,middle_name,last_name,company_id,branch_id,department_id,designation_id',

            'employee.branch:id,name',

            'employee.department:id,name',

            'employee.designation:id,name',

            'items.salaryComponent:id,name,code',

            'items.percentageOfComponent:id,name,code',
        ]);

        return Inertia::render(
            'ERP/HRM/Payroll/SalaryStructures/Show',
            [
                'salaryStructure' => $salaryStructure,
            ]
        );
    }

    public function edit(
        EmployeeSalaryStructure $salaryStructure
    ): Response {
        $salaryStructure->load(
            'items'
        );

        return Inertia::render(
            'ERP/HRM/Payroll/SalaryStructures/Edit',
            [
                'salaryStructure' => $salaryStructure,

                'companies' => $this->companies(),

                'employees' => $this->employees(),

                'components' => $this->components(),
            ]
        );
    }

    public function update(
        Request $request,
        EmployeeSalaryStructure $salaryStructure,
        SalaryStructureCalculator $calculator
    ): RedirectResponse {
        $data =
            $this->validateData(
                $request
            );

        $employee =
            Employee::query()
                ->findOrFail(
                    $data[
                        'employee_id'
                    ]
                );

        $this->validateEmployeeCompany(
            $employee,
            (int)
            $data['company_id']
        );

        $this->validateNoOverlap(
            (int)
            $data['employee_id'],
            $data['effective_from'],
            $data['effective_to']
                ?? null,
            $salaryStructure->id
        );

        $components =
            $this->loadSubmittedComponents(
                (int)
                $data['company_id'],
                $data['items']
            );

        $calculation =
            $calculator->calculate(
                $components,
                $data['items']
            );

        DB::transaction(
            function () use (
                $salaryStructure,
                $data,
                $calculation
            ) {
                $salaryStructure->update([
                    'company_id' => $data[
                            'company_id'
                        ],

                    'employee_id' => $data[
                            'employee_id'
                        ],

                    'effective_from' => $data[
                            'effective_from'
                        ],

                    'effective_to' => $data[
                            'effective_to'
                        ]
                        ?? null,

                    'status' => $data[
                            'status'
                        ],

                    'currency' => strtoupper(
                        $data[
                            'currency'
                        ]
                    ),

                    'gross_salary' => $calculation[
                            'gross_salary'
                        ],

                    'total_earnings' => $calculation[
                            'total_earnings'
                        ],

                    'total_deductions' => $calculation[
                            'total_deductions'
                        ],

                    'net_salary' => $calculation[
                            'net_salary'
                        ],

                    'notes' => $data[
                            'notes'
                        ]
                        ?? null,
                ]);

                $salaryStructure
                    ->items()
                    ->delete();

                $salaryStructure
                    ->items()
                    ->createMany(
                        $calculation[
                            'items'
                        ]
                    );
            }
        );

        return redirect()
            ->route(
                'erp.salary-structures.show',
                $salaryStructure
            )
            ->with(
                'success',
                'Employee salary structure updated successfully.'
            );
    }

    public function destroy(
        EmployeeSalaryStructure $salaryStructure
    ): RedirectResponse {
        $salaryStructure->delete();

        return redirect()
            ->route(
                'erp.salary-structures.index'
            )
            ->with(
                'success',
                'Employee salary structure deleted successfully.'
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

            'employee_id' => [
                'required',
                'integer',
                'exists:employees,id',
            ],

            'effective_from' => [
                'required',
                'date',
            ],

            'effective_to' => [
                'nullable',
                'date',
                'after_or_equal:effective_from',
            ],

            'status' => [
                'required',
                Rule::in([
                    'active',
                    'inactive',
                ]),
            ],

            'currency' => [
                'required',
                'string',
                'size:3',
            ],

            'notes' => [
                'nullable',
                'string',
            ],

            'items' => [
                'required',
                'array',
                'min:1',
            ],

            'items.*.salary_component_id' => [
                'required',
                'integer',
                'distinct',
                'exists:salary_components,id',
            ],

            'items.*.amount' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'items.*.percentage' => [
                'nullable',
                'numeric',
                'min:0',
            ],
        ]);
    }

    private function validateEmployeeCompany(
        Employee $employee,
        int $companyId
    ): void {
        if (
            (int)
            $employee->company_id !==
            $companyId
        ) {
            throw ValidationException::withMessages([
                'employee_id' => 'The selected employee does not belong to the selected company.',
            ]);
        }
    }

    private function validateNoOverlap(
        int $employeeId,
        string $effectiveFrom,
        ?string $effectiveTo,
        ?int $ignoreId = null
    ): void {
        $newEnd =
            $effectiveTo ??
            '9999-12-31';

        $overlap =
            EmployeeSalaryStructure::query()
                ->where(
                    'employee_id',
                    $employeeId
                )
                ->where(
                    'status',
                    'active'
                )
                ->when(
                    $ignoreId,
                    fn ($query) => $query->where(
                        'id',
                        '!=',
                        $ignoreId
                    )
                )
                ->where(
                    'effective_from',
                    '<=',
                    $newEnd
                )
                ->where(
                    function (
                        $query
                    ) use (
                        $effectiveFrom
                    ) {
                        $query
                            ->whereNull(
                                'effective_to'
                            )
                            ->orWhere(
                                'effective_to',
                                '>=',
                                $effectiveFrom
                            );
                    }
                )
                ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'effective_from' => 'This employee already has an active salary structure overlapping the selected effective period.',
            ]);
        }
    }

    private function loadSubmittedComponents(
        int $companyId,
        array $items
    ) {
        $ids =
            collect($items)
                ->pluck(
                    'salary_component_id'
                )
                ->map(
                    fn ($id) => (int) $id
                )
                ->unique()
                ->values();

        $components =
            SalaryComponent::query()
                ->where(
                    'company_id',
                    $companyId
                )
                ->whereIn(
                    'id',
                    $ids
                )
                ->get();

        if (
            $components->count() !==
            $ids->count()
        ) {
            throw ValidationException::withMessages([
                'items' => 'Every salary component must belong to the selected company.',
            ]);
        }

        /*
         * New structures should normally use
         * active components. Existing structure
         * snapshots remain valid if a component
         * is later disabled.
         */
        $inactive =
            $components
                ->where(
                    'status',
                    false
                )
                ->first();

        if ($inactive) {
            throw ValidationException::withMessages([
                'items' => "Salary component {$inactive->name} is inactive.",
            ]);
        }

        return $components;
    }

    private function companies()
    {
        return Company::query()
            ->where(
                'status',
                'active'
            )
            ->orderBy('name')
            ->get([
                'id',
                'name',
            ]);
    }

    private function employees()
    {
        return Employee::query()
            ->where(
                'employment_status',
                'active'
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
            ]);
    }

    private function components()
    {
        return SalaryComponent::query()
            ->where(
                'status',
                true
            )
            ->orderBy(
                'company_id'
            )
            ->orderBy(
                'sort_order'
            )
            ->orderBy('name')
            ->get([
                'id',
                'company_id',
                'name',
                'code',
                'component_type',
                'calculation_type',
                'percentage_of_component_id',
                'default_amount',
                'default_percentage',
                'is_taxable',
                'affects_gross_salary',
                'is_recurring',
                'sort_order',
            ]);
    }
}

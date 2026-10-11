<?php

namespace App\Http\Controllers\ERP\HRM;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\SalaryComponent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SalaryComponentController extends Controller
{
    public function index(
        Request $request
    ): Response {
        $filters = $request->validate([
            'company_id' => [
                'nullable',
                'integer',
                'exists:companies,id',
            ],

            'component_type' => [
                'nullable',
                Rule::in([
                    'earning',
                    'deduction',
                ]),
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

        $companies = Company::query()
            ->where(
                'status',
                'active'
            )
            ->orderBy('name')
            ->get([
                'id',
                'name',
            ]);

        $components =
            SalaryComponent::query()
                ->with([
                    'company:id,name',
                    'percentageOfComponent:id,name,code',
                ])
                ->when(
                    $filters['company_id']
                        ?? null,
                    fn ($query, $companyId) =>
                        $query->where(
                            'company_id',
                            $companyId
                        )
                )
                ->when(
                    $filters['component_type']
                        ?? null,
                    fn ($query, $type) =>
                        $query->where(
                            'component_type',
                            $type
                        )
                )
                ->when(
                    $filters['status']
                        ?? null,
                    function (
                        $query,
                        $status
                    ) {
                        $query->where(
                            'status',
                            $status ===
                                'active'
                        );
                    }
                )
                ->when(
                    $filters['search']
                        ?? null,
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
                ->orderBy(
                    'sort_order'
                )
                ->orderBy('name')
                ->paginate(25)
                ->withQueryString();

        return Inertia::render(
            'ERP/HRM/Payroll/SalaryComponents/Index',
            [
                'components' =>
                    $components,

                'companies' =>
                    $companies,

                'filters' =>
                    $filters,
            ]
        );
    }

    public function create(): Response
    {
        return Inertia::render(
            'ERP/HRM/Payroll/SalaryComponents/Create',
            [
                'companies' =>
                    $this->companies(),

                'baseComponents' =>
                    $this->baseComponents(),
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

        SalaryComponent::create(
            $data
        );

        return redirect()
            ->route(
                'erp.salary-components.index'
            )
            ->with(
                'success',
                'Salary component created successfully.'
            );
    }

    public function edit(
        SalaryComponent $salaryComponent
    ): Response {
        return Inertia::render(
            'ERP/HRM/Payroll/SalaryComponents/Edit',
            [
                'salaryComponent' =>
                    $salaryComponent,

                'companies' =>
                    $this->companies(),

                'baseComponents' =>
                    $this->baseComponents(
                        $salaryComponent
                    ),
            ]
        );
    }

    public function update(
        Request $request,
        SalaryComponent $salaryComponent
    ): RedirectResponse {
        $data =
            $this->validateData(
                $request,
                $salaryComponent
            );

        $salaryComponent->update(
            $data
        );

        return redirect()
            ->route(
                'erp.salary-components.index'
            )
            ->with(
                'success',
                'Salary component updated successfully.'
            );
    }

    public function destroy(
        SalaryComponent $salaryComponent
    ): RedirectResponse {
        if (
            $salaryComponent
                ->dependentComponents()
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'salary_component' =>
                    'This component is used as the percentage base for another salary component and cannot be deleted.',
            ]);
        }

        if (
            $salaryComponent
                ->salaryStructureItems()
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'salary_component' =>
                    'This component is already used in an employee salary structure. Deactivate it instead of deleting it.',
            ]);
        }

        $salaryComponent->delete();

        return redirect()
            ->route(
                'erp.salary-components.index'
            )
            ->with(
                'success',
                'Salary component deleted successfully.'
            );
    }

    private function validateData(
        Request $request,
        ?SalaryComponent $salaryComponent = null
    ): array {
        $companyId =
            (int)
            $request->input(
                'company_id'
            );

        $data =
            $request->validate([
                'company_id' => [
                    'required',
                    'integer',
                    'exists:companies,id',
                ],

                'name' => [
                    'required',
                    'string',
                    'max:150',
                ],

                'code' => [
                    'required',
                    'string',
                    'max:50',

                    Rule::unique(
                        'salary_components',
                        'code'
                    )
                        ->where(
                            fn ($query) =>
                                $query->where(
                                    'company_id',
                                    $companyId
                                )
                        )
                        ->ignore(
                            $salaryComponent
                                ?->id
                        ),
                ],

                'component_type' => [
                    'required',
                    Rule::in([
                        'earning',
                        'deduction',
                    ]),
                ],

                'calculation_type' => [
                    'required',
                    Rule::in([
                        'fixed',
                        'percentage',
                    ]),
                ],

                'percentage_of_component_id' => [
                    'nullable',
                    'integer',
                    'exists:salary_components,id',
                ],

                'default_amount' => [
                    'nullable',
                    'numeric',
                    'min:0',
                ],

                'default_percentage' => [
                    'nullable',
                    'numeric',
                    'min:0',
                ],

                'is_taxable' => [
                    'required',
                    'boolean',
                ],

                'affects_gross_salary' => [
                    'required',
                    'boolean',
                ],

                'is_recurring' => [
                    'required',
                    'boolean',
                ],

                'status' => [
                    'required',
                    'boolean',
                ],

                'sort_order' => [
                    'nullable',
                    'integer',
                    'min:0',
                ],

                'description' => [
                    'nullable',
                    'string',
                ],
            ]);

        $data['code'] =
            strtoupper(
                trim(
                    $data['code']
                )
            );

        if (
            $data['calculation_type']
                === 'percentage'
        ) {
            if (
                empty(
                    $data[
                        'percentage_of_component_id'
                    ]
                )
            ) {
                throw ValidationException::withMessages([
                    'percentage_of_component_id' =>
                        'Select the salary component on which this percentage is calculated.',
                ]);
            }

            $baseComponent =
                SalaryComponent::query()
                    ->whereKey(
                        $data[
                            'percentage_of_component_id'
                        ]
                    )
                    ->where(
                        'company_id',
                        $data[
                            'company_id'
                        ]
                    )
                    ->first();

            if (!$baseComponent) {
                throw ValidationException::withMessages([
                    'percentage_of_component_id' =>
                        'The percentage base component must belong to the same company.',
                ]);
            }

            if (
                $salaryComponent &&
                $baseComponent->id ===
                    $salaryComponent->id
            ) {
                throw ValidationException::withMessages([
                    'percentage_of_component_id' =>
                        'A salary component cannot calculate a percentage of itself.',
                ]);
            }

            $data['default_amount'] =
                0;
        } else {
            $data[
                'percentage_of_component_id'
            ] = null;

            $data[
                'default_percentage'
            ] = 0;
        }

        $data['default_amount'] =
            $data['default_amount']
                ?? 0;

        $data['default_percentage'] =
            $data['default_percentage']
                ?? 0;

        $data['sort_order'] =
            $data['sort_order']
                ?? 0;

        return $data;
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

    private function baseComponents(
        ?SalaryComponent $exclude = null
    ) {
        return SalaryComponent::query()
            ->where(
                'status',
                true
            )
            ->when(
                $exclude,
                fn ($query) =>
                    $query->where(
                        'id',
                        '!=',
                        $exclude->id
                    )
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
            ]);
    }
}
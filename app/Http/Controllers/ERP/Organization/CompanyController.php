<?php

namespace App\Http\Controllers\ERP\Organization;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function index(
        Request $request
    ): Response {
        $search = trim(
            (string) $request->input(
                'search',
                ''
            )
        );

        $status = $request->input(
            'status'
        );

        $companies = Company::query()
            ->when(
                $search !== '',
                function ($query) use ($search) {
                    $query->where(
                        function ($query) use ($search) {
                            $query
                                ->where(
                                    'name',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'legal_name',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'code',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'email',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'phone',
                                    'like',
                                    "%{$search}%"
                                );
                        }
                    );
                }
            )
            ->when(
                in_array(
                    $status,
                    ['active', 'inactive'],
                    true
                ),
                fn ($query) => $query->where(
                    'status',
                    $status
                )
            )
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render(
            'ERP/Organization/Companies/Index',
            [
                'companies' => $companies,

                'filters' => [
                    'search' => $search,
                    'status' => $status,
                ],
            ]
        );
    }

    public function create(): Response
    {
        return Inertia::render(
            'ERP/Organization/Companies/Create'
        );
    }

    public function store(
        Request $request
    ): RedirectResponse {
        $validated =
            $this->validateCompany(
                $request
            );

        DB::transaction(
            function () use ($validated) {
                $isFirstCompany =
                    ! Company::query()
                        ->exists();

                $makeDefault =
                    $isFirstCompany ||
                    (
                        $validated[
                            'is_default'
                        ] ?? false
                    );

                if ($makeDefault) {
                    Company::query()
                        ->update([
                            'is_default' => false,
                        ]);
                }

                Company::create([
                    ...$validated,

                    'code' => strtoupper(
                        $validated['code']
                    ),

                    'is_default' => $makeDefault,
                ]);
            }
        );

        return to_route(
            'erp.companies.index'
        )->with(
            'success',
            'Company created successfully.'
        );
    }

    public function edit(
        Company $company
    ): Response {
        return Inertia::render(
            'ERP/Organization/Companies/Edit',
            [
                'company' => $company,
            ]
        );
    }

    public function update(
        Request $request,
        Company $company
    ): RedirectResponse {
        $validated =
            $this->validateCompany(
                $request,
                $company
            );

        DB::transaction(
            function () use (
                $validated,
                $company
            ) {
                $makeDefault =
                    (bool) (
                        $validated[
                            'is_default'
                        ] ?? false
                    );

                /*
                 * Do not allow the current default
                 * company to leave the system with
                 * no default company.
                 */
                if (
                    $company->is_default &&
                    ! $makeDefault
                ) {
                    $anotherDefaultExists =
                        Company::query()
                            ->whereKeyNot(
                                $company->id
                            )
                            ->where(
                                'is_default',
                                true
                            )
                            ->exists();

                    if (
                        ! $anotherDefaultExists
                    ) {
                        $makeDefault = true;
                    }
                }

                if ($makeDefault) {
                    Company::query()
                        ->whereKeyNot(
                            $company->id
                        )
                        ->update([
                            'is_default' => false,
                        ]);
                }

                $company->update([
                    ...$validated,

                    'code' => strtoupper(
                        $validated['code']
                    ),

                    'is_default' => $makeDefault,
                ]);
            }
        );

        return to_route(
            'erp.companies.index'
        )->with(
            'success',
            'Company updated successfully.'
        );
    }

    public function destroy(
        Company $company
    ): RedirectResponse {
        if ($company->is_default) {
            return back()->with(
                'error',
                'The default company cannot be deleted.'
            );
        }

        $company->delete();

        return to_route(
            'erp.companies.index'
        )->with(
            'success',
            'Company deleted successfully.'
        );
    }

    private function validateCompany(
        Request $request,
        ?Company $company = null
    ): array {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'legal_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'code' => [
                'required',
                'string',
                'max:50',

                Rule::unique(
                    'companies',
                    'code'
                )->ignore(
                    $company?->id
                ),
            ],

            'email' => [
                'nullable',
                'email',
                'max:255',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:50',
            ],

            'website' => [
                'nullable',
                'url',
                'max:255',
            ],

            'registration_number' => [
                'nullable',
                'string',
                'max:255',
            ],

            'tax_number' => [
                'nullable',
                'string',
                'max:255',
            ],

            'address' => [
                'nullable',
                'string',
            ],

            'city' => [
                'nullable',
                'string',
                'max:255',
            ],

            'state' => [
                'nullable',
                'string',
                'max:255',
            ],

            'postal_code' => [
                'nullable',
                'string',
                'max:30',
            ],

            'country' => [
                'nullable',
                'string',
                'max:255',
            ],

            'currency_code' => [
                'required',
                'string',
                'max:10',
            ],

            'timezone' => [
                'required',
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

            'is_default' => [
                'boolean',
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers\ERP\Organization;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
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

        $companyId = $request->input(
            'company_id'
        );

        $status = $request->input(
            'status'
        );

        $branches = Branch::query()
            ->with([
                'company:id,name,code',
            ])
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
                $companyId,
                fn ($query) => $query->where(
                    'company_id',
                    $companyId
                )
            )
            ->when(
                in_array(
                    $status,
                    [
                        'active',
                        'inactive',
                    ],
                    true
                ),
                fn ($query) => $query->where(
                    'status',
                    $status
                )
            )
            ->orderBy('company_id')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render(
            'ERP/Organization/Branches/Index',
            [
                'branches' => $branches,

                'companies' => Company::query()
                    ->orderByDesc(
                        'is_default'
                    )
                    ->orderBy('name')
                    ->get([
                        'id',
                        'name',
                        'code',
                    ]),

                'filters' => [
                    'search' => $search,
                    'company_id' => $companyId,
                    'status' => $status,
                ],
            ]
        );
    }

    public function create(): Response
    {
        return Inertia::render(
            'ERP/Organization/Branches/Create',
            [
                'companies' => Company::query()
                    ->active()
                    ->orderByDesc(
                        'is_default'
                    )
                    ->orderBy('name')
                    ->get([
                        'id',
                        'name',
                        'code',
                        'timezone',
                        'country',
                    ]),
            ]
        );
    }

    public function store(
        Request $request
    ): RedirectResponse {
        $validated =
            $this->validateBranch(
                $request
            );

        DB::transaction(
            function () use ($validated) {
                $isFirstBranch =
                    ! Branch::query()
                        ->where(
                            'company_id',
                            $validated[
                                'company_id'
                            ]
                        )
                        ->exists();

                $makeDefault =
                    $isFirstBranch ||
                    (
                        $validated[
                            'is_default'
                        ] ?? false
                    );

                if ($makeDefault) {
                    Branch::query()
                        ->where(
                            'company_id',
                            $validated[
                                'company_id'
                            ]
                        )
                        ->update([
                            'is_default' => false,
                        ]);
                }

                Branch::create([
                    ...$validated,

                    'code' => strtoupper(
                        $validated['code']
                    ),

                    'is_default' => $makeDefault,
                ]);
            }
        );

        return to_route(
            'erp.branches.index'
        )->with(
            'success',
            'Branch created successfully.'
        );
    }

    public function edit(
        Branch $branch
    ): Response {
        return Inertia::render(
            'ERP/Organization/Branches/Edit',
            [
                'branch' => $branch->load(
                    'company:id,name,code'
                ),

                'companies' => Company::query()
                    ->where(
                        function ($query) use ($branch) {
                            $query
                                ->where(
                                    'status',
                                    'active'
                                )
                                ->orWhere(
                                    'id',
                                    $branch->company_id
                                );
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
                        'timezone',
                        'country',
                    ]),
            ]
        );
    }

    public function update(
        Request $request,
        Branch $branch
    ): RedirectResponse {
        $validated =
            $this->validateBranch(
                $request,
                $branch
            );

        DB::transaction(
            function () use (
                $validated,
                $branch
            ) {
                $oldCompanyId =
                    $branch->company_id;

                $wasDefault =
                    (bool) $branch->is_default;

                $newCompanyId =
                    (int) $validated[
                        'company_id'
                    ];

                $companyChanged =
                    $oldCompanyId !==
                    $newCompanyId;

                $makeDefault =
                    (bool) (
                        $validated[
                            'is_default'
                        ] ?? false
                    );

                /*
                 * If the branch is moved to another
                 * company and that company has no
                 * other branches, this branch must
                 * automatically become default.
                 */
                if ($companyChanged) {
                    $hasBranchInNewCompany =
                        Branch::query()
                            ->where(
                                'company_id',
                                $newCompanyId
                            )
                            ->whereKeyNot(
                                $branch->id
                            )
                            ->exists();

                    if (
                        ! $hasBranchInNewCompany
                    ) {
                        $makeDefault = true;
                    }
                }

                /*
                 * If this branch will become default,
                 * remove default status from every
                 * other branch of the new company.
                 */
                if ($makeDefault) {
                    Branch::query()
                        ->where(
                            'company_id',
                            $newCompanyId
                        )
                        ->whereKeyNot(
                            $branch->id
                        )
                        ->update([
                            'is_default' => false,
                        ]);
                }

                /*
                 * If this branch is currently the
                 * default branch and remains in the
                 * same company, do not allow the
                 * company to lose its only default.
                 */
                if (
                    ! $companyChanged &&
                    $wasDefault &&
                    ! $makeDefault
                ) {
                    $anotherDefaultExists =
                        Branch::query()
                            ->where(
                                'company_id',
                                $oldCompanyId
                            )
                            ->whereKeyNot(
                                $branch->id
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

                $branch->update([
                    ...$validated,

                    'company_id' => $newCompanyId,

                    'code' => strtoupper(
                        $validated['code']
                    ),

                    'is_default' => $makeDefault,
                ]);

                /*
                 * If a default branch was moved
                 * away from its old company,
                 * promote another remaining branch
                 * in that old company to default.
                 */
                if (
                    $companyChanged &&
                    $wasDefault
                ) {
                    $replacement =
                        Branch::query()
                            ->where(
                                'company_id',
                                $oldCompanyId
                            )
                            ->orderBy('id')
                            ->first();

                    if ($replacement) {
                        Branch::query()
                            ->where(
                                'company_id',
                                $oldCompanyId
                            )
                            ->update([
                                'is_default' => false,
                            ]);

                        $replacement->update([
                            'is_default' => true,
                        ]);
                    }
                }
            }
        );

        return to_route(
            'erp.branches.index'
        )->with(
            'success',
            'Branch updated successfully.'
        );
    }

    public function destroy(
        Branch $branch
    ): RedirectResponse {
        if ($branch->is_default) {
            return back()->with(
                'error',
                'The default branch cannot be deleted.'
            );
        }

        $branch->delete();

        return to_route(
            'erp.branches.index'
        )->with(
            'success',
            'Branch deleted successfully.'
        );
    }

    private function validateBranch(
        Request $request,
        ?Branch $branch = null
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
                'max:255',
            ],

            'code' => [
                'required',
                'string',
                'max:50',

                Rule::unique(
                    'branches',
                    'code'
                )
                    ->where(
                        fn ($query) => $query->where(
                            'company_id',
                            $request->input(
                                'company_id'
                            )
                        )
                    )
                    ->ignore(
                        $branch?->id
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

<?php

namespace App\Http\Controllers\ERP\Geography;

use App\Http\Controllers\Controller;
use App\Models\Division;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DivisionController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => [
                'nullable',
                'string',
                'max:255',
            ],

            'status' => [
                'nullable',
                Rule::in([
                    'active',
                    'inactive',
                ]),
            ],
        ]);

        $divisions = Division::query()
            ->withCount('districts')

            ->when(
                $filters['search'] ?? null,
                function ($query, $search) {
                    $query->where(
                        function ($query) use ($search) {
                            $query
                                ->where(
                                    'name',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'name_bn',
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

            ->when(
                ($filters['status'] ?? null) === 'active',
                fn ($query) =>
                    $query->where(
                        'is_active',
                        true
                    )
            )

            ->when(
                ($filters['status'] ?? null) === 'inactive',
                fn ($query) =>
                    $query->where(
                        'is_active',
                        false
                    )
            )

            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render(
            'ERP/Geography/Divisions/Index',
            [
                'divisions' => $divisions,
                'filters' => $filters,
            ]
        );
    }

    public function create(): Response
    {
        return Inertia::render(
            'ERP/Geography/Divisions/Create'
        );
    }

    public function store(
        Request $request
    ): RedirectResponse {
        $validated = $this->validateData(
            $request
        );

        Division::create(
            $validated
        );

        return redirect()
            ->route(
                'erp.divisions.index'
            )
            ->with(
                'success',
                'Division created successfully.'
            );
            
    }

    public function edit(
        Division $division
    ): Response {
        return Inertia::render(
            'ERP/Geography/Divisions/Edit',
            [
                'division' => $division,
            ]
        );
    }

    public function update(
        Request $request,
        Division $division
    ): RedirectResponse {
        $validated = $this->validateData(
            $request,
            $division
        );

        $division->update(
            $validated
        );

        return redirect()
            ->route(
                'erp.divisions.index'
            )
            ->with(
                'success',
                'Division updated successfully.'
            );
    }

    public function destroy(
        Division $division
    ): RedirectResponse {
        if (
            $division
                ->districts()
                ->exists()
        ) {
            return back()->withErrors([
                'division' =>
                    'This division cannot be deleted because it has districts assigned to it.',
            ]);
        }

        $division->delete();

        return redirect()
            ->route(
                'erp.divisions.index'
            )
            ->with(
                'success',
                'Division deleted successfully.'
            );
    }

    private function validateData(
        Request $request,
        ?Division $division = null
    ): array {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',

                Rule::unique(
                    'divisions',
                    'name'
                )->ignore(
                    $division?->id
                ),
            ],

            'name_bn' => [
                'nullable',
                'string',
                'max:255',
            ],

            'code' => [
                'nullable',
                'string',
                'max:50',

                Rule::unique(
                    'divisions',
                    'code'
                )->ignore(
                    $division?->id
                ),
            ],

            'is_active' => [
                'required',
                'boolean',
            ],
        ]);
    }
}
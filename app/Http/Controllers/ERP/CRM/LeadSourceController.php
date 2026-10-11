<?php

namespace App\Http\Controllers\ERP\CRM;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\LeadSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LeadSourceController extends Controller
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

        $sources =
            LeadSource::query()
                ->with([
                    'company:id,name',
                ])
                ->when(
                    $filters['company_id'] ?? null,
                    fn ($query, $companyId) =>
                        $query->where(
                            'company_id',
                            $companyId
                        )
                )
                ->when(
                    $filters['status'] ?? null,
                    fn ($query, $status) =>
                        $query->where(
                            'status',
                            $status === 'active'
                        )
                )
                ->when(
                    $filters['search'] ?? null,
                    function (
                        $query,
                        $search
                    ) {
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
                                    );
                            }
                        );
                    }
                )
                ->orderBy('company_id')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->paginate(25)
                ->withQueryString();

        return Inertia::render(
            'ERP/CRM/LeadSources/Index',
            [
                'sources' => $sources,

                'companies' =>
                    $this->companies(),

                'filters' => $filters,
            ]
        );
    }

    public function create(): Response
    {
        return Inertia::render(
            'ERP/CRM/LeadSources/Create',
            [
                'companies' =>
                    $this->companies(),
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

        LeadSource::create(
            $this->normalizeData(
                $data
            )
        );

        return redirect()
            ->route(
                'erp.lead-sources.index'
            )
            ->with(
                'success',
                'Lead source created successfully.'
            );
    }

    public function edit(
        LeadSource $leadSource
    ): Response {
        return Inertia::render(
            'ERP/CRM/LeadSources/Edit',
            [
                'leadSource' =>
                    $leadSource,

                'companies' =>
                    $this->companies(),
            ]
        );
    }

    public function update(
        Request $request,
        LeadSource $leadSource
    ): RedirectResponse {
        $data =
            $this->validateData(
                $request,
                $leadSource
            );

        $leadSource->update(
            $this->normalizeData(
                $data
            )
        );

        return redirect()
            ->route(
                'erp.lead-sources.index'
            )
            ->with(
                'success',
                'Lead source updated successfully.'
            );
    }

    public function destroy(
        LeadSource $leadSource
    ): RedirectResponse {
        if (
            $leadSource
                ->leads()
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'lead_source' =>
                    'This lead source is already used by leads. Deactivate it instead of deleting it.',
            ]);
        }

        $leadSource->delete();

        return redirect()
            ->route(
                'erp.lead-sources.index'
            )
            ->with(
                'success',
                'Lead source deleted successfully.'
            );
    }

    private function validateData(
        Request $request,
        ?LeadSource $leadSource = null
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

                Rule::unique(
                    'lead_sources',
                    'code'
                )
                    ->where(
                        fn ($query) =>
                            $query->where(
                                'company_id',
                                $request->integer(
                                    'company_id'
                                )
                            )
                    )
                    ->ignore(
                        $leadSource?->id
                    ),
            ],

            'description' => [
                'nullable',
                'string',
                'max:5000',
            ],

            'sort_order' => [
                'required',
                'integer',
                'min:0',
            ],

            'status' => [
                'required',
                'boolean',
            ],
        ]);
    }

    private function normalizeData(
        array $data
    ): array {
        return [
            'company_id' =>
                $data['company_id'],

            'name' =>
                trim(
                    $data['name']
                ),

            'code' =>
                strtoupper(
                    trim(
                        $data['code']
                    )
                ),

            'description' =>
                $data['description']
                ?? null,

            'sort_order' =>
                $data['sort_order'],

            'status' =>
                $data['status'],
        ];
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
}
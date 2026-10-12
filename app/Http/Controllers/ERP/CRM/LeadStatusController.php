<?php

namespace App\Http\Controllers\ERP\CRM;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\LeadStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LeadStatusController extends Controller
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

        $statuses =
            LeadStatus::query()
                ->with([
                    'company:id,name',
                ])
                ->when(
                    $filters['company_id'] ?? null,
                    fn ($query, $companyId) => $query->where(
                        'company_id',
                        $companyId
                    )
                )
                ->when(
                    $filters['status'] ?? null,
                    fn ($query, $status) => $query->where(
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
            'ERP/CRM/LeadStatuses/Index',
            [
                'statuses' => $statuses,

                'companies' => $this->companies(),

                'filters' => $filters,
            ]
        );
    }

    public function create(): Response
    {
        return Inertia::render(
            'ERP/CRM/LeadStatuses/Create',
            [
                'companies' => $this->companies(),
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

        LeadStatus::create(
            $this->normalizeData(
                $data
            )
        );

        return redirect()
            ->route(
                'erp.lead-statuses.index'
            )
            ->with(
                'success',
                'Lead status created successfully.'
            );
    }

    public function edit(
        LeadStatus $leadStatus
    ): Response {
        return Inertia::render(
            'ERP/CRM/LeadStatuses/Edit',
            [
                'leadStatus' => $leadStatus,

                'companies' => $this->companies(),
            ]
        );
    }

    public function update(
        Request $request,
        LeadStatus $leadStatus
    ): RedirectResponse {
        $data =
            $this->validateData(
                $request,
                $leadStatus
            );

        $leadStatus->update(
            $this->normalizeData(
                $data
            )
        );

        return redirect()
            ->route(
                'erp.lead-statuses.index'
            )
            ->with(
                'success',
                'Lead status updated successfully.'
            );
    }

    public function destroy(
        LeadStatus $leadStatus
    ): RedirectResponse {
        if (
            $leadStatus
                ->leads()
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'lead_status' => 'This lead status is already used by leads. Deactivate it instead of deleting it.',
            ]);
        }

        $leadStatus->delete();

        return redirect()
            ->route(
                'erp.lead-statuses.index'
            )
            ->with(
                'success',
                'Lead status deleted successfully.'
            );
    }

    private function validateData(
        Request $request,
        ?LeadStatus $leadStatus = null
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
                    'lead_statuses',
                    'code'
                )
                    ->where(
                        fn ($query) => $query->where(
                            'company_id',
                            $request->integer(
                                'company_id'
                            )
                        )
                    )
                    ->ignore(
                        $leadStatus?->id
                    ),
            ],

            'probability' => [
                'required',
                'integer',
                'between:0,100',
            ],

            'is_won' => [
                'required',
                'boolean',
            ],

            'is_lost' => [
                'required',
                'boolean',
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
        if (
            $data['is_won'] &&
            $data['is_lost']
        ) {
            throw ValidationException::withMessages([
                'is_won' => 'A lead status cannot be both Won and Lost.',
            ]);
        }

        return [
            'company_id' => $data['company_id'],

            'name' => trim(
                $data['name']
            ),

            'code' => strtoupper(
                trim(
                    $data['code']
                )
            ),

            'probability' => $data['probability'],

            'is_won' => $data['is_won'],

            'is_lost' => $data['is_lost'],

            'description' => $data['description']
                ?? null,

            'sort_order' => $data['sort_order'],

            'status' => $data['status'],
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

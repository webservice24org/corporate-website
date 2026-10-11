<?php

namespace App\Http\Controllers\ERP\CRM;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LeadController extends Controller
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

                'lead_source_id' => [
                    'nullable',
                    'integer',
                    'exists:lead_sources,id',
                ],

                'lead_status_id' => [
                    'nullable',
                    'integer',
                    'exists:lead_statuses,id',
                ],

                'owner_user_id' => [
                    'nullable',
                    'integer',
                    'exists:users,id',
                ],

                'lead_type' => [
                    'nullable',
                    Rule::in([
                        'individual',
                        'company',
                    ]),
                ],

                'active' => [
                    'nullable',
                    Rule::in([
                        'active',
                        'inactive',
                    ]),
                ],

                'search' => [
                    'nullable',
                    'string',
                    'max:150',
                ],
            ]);

        $leads =
            Lead::query()
                ->with([
                    'company:id,name',

                    'source:id,name,code',

                    'status:id,name,code,probability,is_won,is_lost',

                    'owner:id,name,email',
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
                    $filters['lead_source_id'] ?? null,
                    fn ($query, $sourceId) =>
                        $query->where(
                            'lead_source_id',
                            $sourceId
                        )
                )

                ->when(
                    $filters['lead_status_id'] ?? null,
                    fn ($query, $statusId) =>
                        $query->where(
                            'lead_status_id',
                            $statusId
                        )
                )

                ->when(
                    $filters['owner_user_id'] ?? null,
                    fn ($query, $ownerId) =>
                        $query->where(
                            'owner_user_id',
                            $ownerId
                        )
                )

                ->when(
                    $filters['lead_type'] ?? null,
                    fn ($query, $type) =>
                        $query->where(
                            'lead_type',
                            $type
                        )
                )

                ->when(
                    $filters['active'] ?? null,
                    fn ($query, $active) =>
                        $query->where(
                            'is_active',
                            $active === 'active'
                        )
                )

                ->when(
                    $filters['search'] ?? null,
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
                                        'lead_number',
                                        'like',
                                        "%{$search}%"
                                    )
                                    ->orWhere(
                                        'contact_name',
                                        'like',
                                        "%{$search}%"
                                    )
                                    ->orWhere(
                                        'company_name',
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

                ->orderByDesc('id')

                ->paginate(25)

                ->withQueryString();

        return Inertia::render(
            'ERP/CRM/Leads/Index',
            [
                'leads' =>
                    $leads,

                'companies' =>
                    $this->companies(),

                'sources' =>
                    $this->sources(),

                'statuses' =>
                    $this->statuses(),

                'users' =>
                    $this->users(),

                'filters' =>
                    $filters,
            ]
        );
    }

    public function create(): Response
    {
        return Inertia::render(
            'ERP/CRM/Leads/Create',
            [
                'companies' =>
                    $this->companies(),

                'sources' =>
                    $this->sources(
                        activeOnly: true
                    ),

                'statuses' =>
                    $this->statuses(
                        activeOnly: true
                    ),

                'users' =>
                    $this->users(),
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

        $lead =
            Lead::create(
                $this->normalizeData(
                    $data
                )
            );

        return redirect()
            ->route(
                'erp.leads.show',
                $lead
            )
            ->with(
                'success',
                'Lead created successfully.'
            );
    }

    public function show(
        Lead $lead
            ): Response {
                $lead->load([
            'company:id,name',

            'source:id,name,code',

            'status:id,name,code,probability,is_won,is_lost',

            'owner:id,name,email',

            'activities' => function ($query) {
                $query
                    ->with([
                        'assignedTo:id,name,email',
                        'createdBy:id,name,email',
                    ])
                    ->orderByDesc(
                        'created_at'
                    );
            },
        ]);

        return Inertia::render(
            'ERP/CRM/Leads/Show',
            [
                'lead' =>
                    $lead,
            ]
        );
    }

    public function edit(
        Lead $lead
    ): Response {
        return Inertia::render(
            'ERP/CRM/Leads/Edit',
            [
                'lead' =>
                    $lead,

                'companies' =>
                    $this->companies(),

                /*
                 * Include active options plus
                 * currently selected inactive
                 * options so old records remain
                 * editable.
                 */
                'sources' =>
                    $this->sourcesForLead(
                        $lead
                    ),

                'statuses' =>
                    $this->statusesForLead(
                        $lead
                    ),

                'users' =>
                    $this->users(),
            ]
        );
    }

    public function update(
        Request $request,
        Lead $lead
    ): RedirectResponse {
        $data =
            $this->validateData(
                $request,
                $lead
            );

        $lead->update(
            $this->normalizeData(
                $data
            )
        );

        return redirect()
            ->route(
                'erp.leads.show',
                $lead
            )
            ->with(
                'success',
                'Lead updated successfully.'
            );
    }

    public function destroy(
        Lead $lead
    ): RedirectResponse {
        $lead->delete();

        return redirect()
            ->route(
                'erp.leads.index'
            )
            ->with(
                'success',
                'Lead deleted successfully.'
            );
    }

    private function validateData(
        Request $request,
        ?Lead $lead = null
    ): array {
        $companyId =
            $request->integer(
                'company_id'
            );

        return $request->validate([
            'company_id' => [
                'required',
                'integer',
                'exists:companies,id',
            ],

            'lead_number' => [
                'required',
                'string',
                'max:50',

                Rule::unique(
                    'leads',
                    'lead_number'
                )
                    ->where(
                        fn ($query) =>
                            $query->where(
                                'company_id',
                                $companyId
                            )
                    )
                    ->ignore(
                        $lead?->id
                    ),
            ],

            'lead_source_id' => [
                'nullable',
                'integer',

                Rule::exists(
                    'lead_sources',
                    'id'
                )->where(
                    fn ($query) =>
                        $query->where(
                            'company_id',
                            $companyId
                        )
                ),
            ],

            'lead_status_id' => [
                'required',
                'integer',

                Rule::exists(
                    'lead_statuses',
                    'id'
                )->where(
                    fn ($query) =>
                        $query->where(
                            'company_id',
                            $companyId
                        )
                ),
            ],

            'owner_user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
            ],

            'lead_type' => [
                'required',
                Rule::in([
                    'individual',
                    'company',
                ]),
            ],

            'company_name' => [
                Rule::requiredIf(
                    $request->input(
                        'lead_type'
                    ) === 'company'
                ),

                'nullable',
                'string',
                'max:255',
            ],

            'contact_name' => [
                'required',
                'string',
                'max:255',
            ],

            'job_title' => [
                'nullable',
                'string',
                'max:255',
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

            'alternate_phone' => [
                'nullable',
                'string',
                'max:50',
            ],

            'website' => [
                'nullable',
                'string',
                'max:255',
            ],

            'expected_value' => [
                'required',
                'numeric',
                'min:0',
            ],

            'currency' => [
                'required',
                'string',
                'size:3',
            ],

            'next_follow_up_at' => [
                'nullable',
                'date',
            ],

            'address_line_1' => [
                'nullable',
                'string',
                'max:255',
            ],

            'address_line_2' => [
                'nullable',
                'string',
                'max:255',
            ],

            'city' => [
                'nullable',
                'string',
                'max:100',
            ],

            'state' => [
                'nullable',
                'string',
                'max:100',
            ],

            'postal_code' => [
                'nullable',
                'string',
                'max:30',
            ],

            'country' => [
                'nullable',
                'string',
                'max:100',
            ],

            'notes' => [
                'nullable',
                'string',
                'max:10000',
            ],

            'is_active' => [
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

            'lead_source_id' =>
                $data['lead_source_id']
                ?? null,

            'lead_status_id' =>
                $data['lead_status_id'],

            'owner_user_id' =>
                $data['owner_user_id']
                ?? null,

            'lead_number' =>
                strtoupper(
                    trim(
                        $data['lead_number']
                    )
                ),

            'lead_type' =>
                $data['lead_type'],

            'company_name' =>
                $data['lead_type'] ===
                'company'
                    ? trim(
                        $data['company_name']
                    )
                    : null,

            'contact_name' =>
                trim(
                    $data['contact_name']
                ),

            'job_title' =>
                $data['job_title']
                ?? null,

            'email' =>
                $data['email']
                ?? null,

            'phone' =>
                $data['phone']
                ?? null,

            'alternate_phone' =>
                $data['alternate_phone']
                ?? null,

            'website' =>
                $data['website']
                ?? null,

            'expected_value' =>
                $data['expected_value'],

            'currency' =>
                strtoupper(
                    trim(
                        $data['currency']
                    )
                ),

            'next_follow_up_at' =>
                $data['next_follow_up_at']
                ?? null,

            'address_line_1' =>
                $data['address_line_1']
                ?? null,

            'address_line_2' =>
                $data['address_line_2']
                ?? null,

            'city' =>
                $data['city']
                ?? null,

            'state' =>
                $data['state']
                ?? null,

            'postal_code' =>
                $data['postal_code']
                ?? null,

            'country' =>
                $data['country']
                ?? null,

            'notes' =>
                $data['notes']
                ?? null,

            'is_active' =>
                $data['is_active'],
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

    private function sources(
        bool $activeOnly = false
    ) {
        return LeadSource::query()
            ->when(
                $activeOnly,
                fn ($query) =>
                    $query->where(
                        'status',
                        true
                    )
            )
            ->orderBy('company_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get([
                'id',
                'company_id',
                'name',
                'code',
                'status',
            ]);
    }

    private function statuses(
        bool $activeOnly = false
    ) {
        return LeadStatus::query()
            ->when(
                $activeOnly,
                fn ($query) =>
                    $query->where(
                        'status',
                        true
                    )
            )
            ->orderBy('company_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get([
                'id',
                'company_id',
                'name',
                'code',
                'probability',
                'is_won',
                'is_lost',
                'status',
            ]);
    }

    private function sourcesForLead(
        Lead $lead
    ) {
        return LeadSource::query()
            ->where(
                'company_id',
                $lead->company_id
            )
            ->where(
                function ($query) use ($lead) {
                    $query->where(
                        'status',
                        true
                    );

                    if (
                        $lead->lead_source_id
                    ) {
                        $query->orWhere(
                            'id',
                            $lead->lead_source_id
                        );
                    }
                }
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get([
                'id',
                'company_id',
                'name',
                'code',
                'status',
            ]);
    }

    private function statusesForLead(
        Lead $lead
    ) {
        return LeadStatus::query()
            ->where(
                'company_id',
                $lead->company_id
            )
            ->where(
                function ($query) use ($lead) {
                    $query
                        ->where(
                            'status',
                            true
                        )
                        ->orWhere(
                            'id',
                            $lead->lead_status_id
                        );
                }
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get([
                'id',
                'company_id',
                'name',
                'code',
                'probability',
                'is_won',
                'is_lost',
                'status',
            ]);
    }

    private function users()
    {
        return User::query()
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'email',
            ]);
    }
}
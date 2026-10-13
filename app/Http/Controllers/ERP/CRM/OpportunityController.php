<?php

namespace App\Http\Controllers\ERP\CRM;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Dealer;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\OpportunityStage;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OpportunityController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'stage_id' => ['nullable', 'integer', 'exists:opportunity_stages,id'],
            'salesperson_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'party_type' => ['nullable', Rule::in(['customer', 'dealer', 'seller'])],
        ]);

        $opportunities = Opportunity::query()
            ->with([
                'company:id,name',
                'stage:id,company_id,name,code,probability,is_won,is_lost',
                'salesperson:id,name,email',
                'lead:id,lead_number,contact_name,company_name',
                'party',
            ])
            ->when(
                $filters['search'] ?? null,
                fn ($query, $search) => $query->where(function ($query) use ($search) {
                    $query
                        ->where('title', 'like', "%{$search}%")
                        ->orWhere('opportunity_number', 'like', "%{$search}%");
                })
            )
            ->when(
                $filters['company_id'] ?? null,
                fn ($query, $companyId) => $query->where('company_id', $companyId)
            )
            ->when(
                $filters['stage_id'] ?? null,
                fn ($query, $stageId) => $query->where('opportunity_stage_id', $stageId)
            )
            ->when(
                $filters['salesperson_user_id'] ?? null,
                fn ($query, $userId) => $query->where('salesperson_user_id', $userId)
            )
            ->when(
                $filters['party_type'] ?? null,
                fn ($query, $partyType) => $query->where(
                    'party_type',
                    $this->partyClass($partyType)
                )
            )
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('ERP/CRM/Opportunities/Index', [
            'opportunities' => $opportunities,
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
            'stages' => OpportunityStage::query()
                ->where('is_active', true)
                ->orderBy('company_id')
                ->orderBy('sort_order')
                ->get(['id', 'company_id', 'name', 'code', 'probability', 'is_won', 'is_lost']),
            'salespeople' => User::query()->orderBy('name')->get(['id', 'name', 'email']),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render(
            'ERP/CRM/Opportunities/Create',
            $this->formOptions($request)
        );
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateOpportunity($request);
        $this->assertCompanyConsistency($data);

        $stage = OpportunityStage::query()
            ->whereKey($data['opportunity_stage_id'])
            ->where('company_id', $data['company_id'])
            ->firstOrFail();

        $timestamps = $this->stageTimestamps($stage);

        $opportunity = Opportunity::create([
            ...$data,
            'opportunity_number' => null,
            'party_type' => $this->partyClass($data['party_type']),
            'probability' => $data['probability'] ?? $stage->probability,
            'won_at' => $timestamps['won_at'],
            'lost_at' => $timestamps['lost_at'],
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('erp.opportunities.show', $opportunity)
            ->with('success', 'Opportunity created successfully.');
    }

    public function show(Opportunity $opportunity): Response
    {
        $opportunity->load([
            'company:id,name',
            'stage:id,company_id,name,code,probability,is_won,is_lost',
            'salesperson:id,name,email',
            'lead:id,lead_number,contact_name,company_name',
            'party',
            'creator:id,name,email',
            'updater:id,name,email',
            'activities.assignedTo:id,name,email',
            'activities.createdBy:id,name,email',
        ]);

        return Inertia::render('ERP/CRM/Opportunities/Show', [
            'opportunity' => $opportunity,
        ]);
    }

    public function edit(Request $request, Opportunity $opportunity): Response
    {
        $opportunity->load('party');

        return Inertia::render(
            'ERP/CRM/Opportunities/Edit',
            [
                'opportunity' => $opportunity,
                ...$this->formOptions($request, $opportunity),
            ]
        );
    }

    public function update(
        Request $request,
        Opportunity $opportunity
    ): RedirectResponse {
        $data = $this->validateOpportunity($request);
        $this->assertCompanyConsistency($data);

        $stage = OpportunityStage::query()
            ->whereKey($data['opportunity_stage_id'])
            ->where('company_id', $data['company_id'])
            ->firstOrFail();

        $timestamps = $this->stageTimestamps($stage, $opportunity);

        $opportunity->update([
            ...$data,
            'party_type' => $this->partyClass($data['party_type']),
            'probability' => $data['probability'] ?? $stage->probability,
            'won_at' => $timestamps['won_at'],
            'lost_at' => $timestamps['lost_at'],
            'updated_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('erp.opportunities.show', $opportunity)
            ->with('success', 'Opportunity updated successfully.');
    }

    public function destroy(Opportunity $opportunity): RedirectResponse
    {
        $opportunity->delete();

        return redirect()
            ->route('erp.opportunities.index')
            ->with('success', 'Opportunity deleted successfully.');
    }

    private function validateOpportunity(Request $request): array
    {
        return $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'party_type' => ['required', Rule::in(['customer', 'dealer', 'seller'])],
            'party_id' => ['required', 'integer'],
            'opportunity_stage_id' => ['required', 'integer', 'exists:opportunity_stages,id'],
            'salesperson_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'expected_value' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'max:10'],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'expected_close_date' => ['nullable', 'date'],
            'lost_reason' => ['nullable', 'string', 'max:3000'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function formOptions(
        Request $request,
        ?Opportunity $opportunity = null
    ): array {
        $leadId = $request->integer('lead_id') ?: $opportunity?->lead_id;

        return [
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
            'leads' => Lead::query()
                ->orderByDesc('id')
                ->limit(500)
                ->get([
                    'id',
                    'company_id',
                    'lead_number',
                    'contact_name',
                    'company_name',
                    'expected_value',
                    'currency',
                    'converted_to_type',
                    'converted_to_id',
                ]),
            'customers' => Customer::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'company_id', 'customer_code', 'name', 'business_name']),
            'dealers' => Dealer::query()
                ->where('is_active', true)
                ->orderBy('business_name')
                ->get(['id', 'company_id', 'dealer_code', 'business_name']),
            'sellers' => Seller::query()
                ->where('is_active', true)
                ->orderBy('business_name')
                ->get(['id', 'company_id', 'seller_code', 'business_name']),
            'stages' => OpportunityStage::query()
                ->where('is_active', true)
                ->orderBy('company_id')
                ->orderBy('sort_order')
                ->get([
                    'id',
                    'company_id',
                    'name',
                    'code',
                    'probability',
                    'is_won',
                    'is_lost',
                ]),
            'salespeople' => User::query()
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'selectedLeadId' => $leadId,
        ];
    }

    private function assertCompanyConsistency(array $data): void
    {
        $party = $this->partyQuery($data['party_type'])
            ->whereKey($data['party_id'])
            ->first();

        if (! $party) {
            throw ValidationException::withMessages([
                'party_id' => 'The selected commercial party does not exist.',
            ]);
        }

        if ((int) $party->company_id !== (int) $data['company_id']) {
            throw ValidationException::withMessages([
                'party_id' => 'The selected commercial party does not belong to this company.',
            ]);
        }

        if (! empty($data['lead_id'])) {
            $leadValid = Lead::query()
                ->whereKey($data['lead_id'])
                ->where('company_id', $data['company_id'])
                ->exists();

            if (! $leadValid) {
                throw ValidationException::withMessages([
                    'lead_id' => 'The selected Lead does not belong to this company.',
                ]);
            }
        }
    }

    private function partyQuery(string $type)
    {
        return match ($type) {
            'customer' => Customer::query(),
            'dealer' => Dealer::query(),
            'seller' => Seller::query(),
            default => throw ValidationException::withMessages([
                'party_type' => 'Invalid commercial party type.',
            ]),
        };
    }

    private function partyClass(string $type): string
    {
        return match ($type) {
            'customer' => Customer::class,
            'dealer' => Dealer::class,
            'seller' => Seller::class,
            default => throw ValidationException::withMessages([
                'party_type' => 'Invalid commercial party type.',
            ]),
        };
    }

    private function stageTimestamps(
        OpportunityStage $stage,
        ?Opportunity $opportunity = null
    ): array {
        return [
            'won_at' => $stage->is_won
                ? (($opportunity !== null ? $opportunity->won_at : null) ?? now())
                : null,

            'lost_at' => $stage->is_lost
                ? (($opportunity !== null ? $opportunity->lost_at : null) ?? now())
                : null,
        ];
    }
}

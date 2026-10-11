<?php

namespace App\Http\Controllers\ERP\CRM;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\OpportunityStage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OpportunityStageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('ERP/CRM/OpportunityStages/Index', [
            'stages' => OpportunityStage::query()
                ->with('company:id,name')
                ->orderBy('company_id')
                ->orderBy('sort_order')
                ->get(),
            'companies' => Company::query()
                ->orderBy('name')
                ->get(['id','name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateStage($request);

        if ($data['is_won'] && $data['is_lost']) {
            throw ValidationException::withMessages([
                'is_won' => 'A stage cannot be both Won and Lost.',
            ]);
        }

        OpportunityStage::create($data);

        return back()->with('success', 'Opportunity stage created.');
    }

    public function update(
        Request $request,
        OpportunityStage $opportunityStage
    ): RedirectResponse {
        $data = $this->validateStage($request, $opportunityStage);

        if ($data['is_won'] && $data['is_lost']) {
            throw ValidationException::withMessages([
                'is_won' => 'A stage cannot be both Won and Lost.',
            ]);
        }

        $opportunityStage->update($data);

        return back()->with('success', 'Opportunity stage updated.');
    }

    public function destroy(
        OpportunityStage $opportunityStage
    ): RedirectResponse {
        if ($opportunityStage->opportunities()->exists()) {
            throw ValidationException::withMessages([
                'stage' => 'This stage is already used by opportunities and cannot be deleted.',
            ]);
        }

        $opportunityStage->delete();

        return back()->with('success', 'Opportunity stage deleted.');
    }

    private function validateStage(
        Request $request,
        ?OpportunityStage $stage = null
    ): array {
        return $request->validate([
            'company_id' => ['required','integer','exists:companies,id'],
            'name' => ['required','string','max:255'],
            'code' => [
                'required','string','max:100',
                Rule::unique('opportunity_stages','code')
                    ->where(fn ($query) =>
                        $query->where('company_id', $request->integer('company_id'))
                    )
                    ->ignore($stage?->id),
            ],
            'probability' => ['required','integer','min:0','max:100'],
            'sort_order' => ['required','integer','min:0'],
            'is_won' => ['required','boolean'],
            'is_lost' => ['required','boolean'],
            'is_active' => ['required','boolean'],
        ]);
    }
}

<?php

namespace App\Http\Controllers\ERP\CRM;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Dealer;
use App\Models\Depot;
use App\Models\District;
use App\Models\Division;
use App\Models\Lead;
use App\Models\Upazila;
use App\Models\User;
use App\Services\ERP\CRM\LeadConversionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LeadConversionController extends Controller
{
    public function create(Lead $lead): Response
    {
        abort_if(
            $lead->getAttribute('converted_at'),
            409,
            'This lead has already been converted.'
        );

        return Inertia::render('ERP/CRM/Leads/Convert', [
            'lead' => [
                'id' => $lead->id,
                'name' =>
                    $lead->getAttribute('name')
                    ?? $lead->getAttribute('contact_name')
                    ?? $lead->getAttribute('title')
                    ?? '',
                'business_name' =>
                    $lead->getAttribute('company_name')
                    ?? $lead->getAttribute('business_name')
                    ?? '',
                'phone' => $lead->getAttribute('phone') ?? '',
                'email' => $lead->getAttribute('email') ?? '',
                'address' =>
                    $lead->getAttribute('address')
                    ?? $lead->getAttribute('business_address')
                    ?? '',
            ],
            'companies' => Company::query()
                ->orderBy('name')
                ->get(['id','name']),
            'depots' => Depot::query()
                ->where('is_active', true)
                ->with('district:id,division_id,name')
                ->orderBy('name')
                ->get(['id','company_id','district_id','name','depot_code']),
            'dealers' => Dealer::query()
                ->where('is_active', true)
                ->with('depot:id,name')
                ->orderBy('business_name')
                ->get(['id','company_id','depot_id','business_name','dealer_code']),
            'divisions' => Division::query()
                ->orderBy('name')
                ->get(['id','name']),
            'districts' => District::query()
                ->orderBy('name')
                ->get(['id','division_id','name']),
            'upazilas' => Upazila::query()
                ->orderBy('name')
                ->get(['id','district_id','name']),
            'managers' => User::query()
                ->orderBy('name')
                ->get(['id','name','email']),
        ]);
    }

    public function store(
        Request $request,
        Lead $lead,
        LeadConversionService $service
    ): RedirectResponse {
        $base = $request->validate([
            'target_type' => ['required', Rule::in(['customer','dealer','seller'])],
            'conversion_notes' => ['nullable','string','max:5000'],
        ]);

        $payload = match ($base['target_type']) {
            'customer' => $this->validateCustomer($request),
            'dealer' => $this->validateDealer($request),
            'seller' => $this->validateSeller($request),
        };

        $target = $service->convert(
            lead: $lead,
            targetType: $base['target_type'],
            payload: $payload,
            actor: $request->user(),
            notes: $base['conversion_notes'] ?? null
        );

        $route = match ($base['target_type']) {
            'customer' => 'erp.customers.show',
            'dealer' => 'erp.dealers.show',
            'seller' => 'erp.sellers.show',
        };

        return redirect()
            ->route($route, $target)
            ->with('success', 'Lead converted successfully.');
    }

    private function validateCustomer(Request $request): array
    {
        return $request->validate([
            'company_id' => ['required','integer','exists:companies,id'],
            'customer_type' => ['required',Rule::in(['individual','company'])],
            'name' => ['required','string','max:255'],
            'business_name' => ['nullable','string','max:255'],
            'contact_person' => ['nullable','string','max:255'],
            'phone' => ['required','string','max:50'],
            'alternate_phone' => ['nullable','string','max:50'],
            'email' => ['nullable','email','max:255'],
            'nid_number' => ['nullable','string','max:100'],
            'tin_number' => ['nullable','string','max:100'],
            'vat_number' => ['nullable','string','max:100'],
            'credit_limit' => ['nullable','numeric','min:0'],
            'payment_terms_days' => ['nullable','integer','min:0'],
            'division_id' => ['nullable','integer','exists:divisions,id'],
            'district_id' => ['nullable','integer','exists:districts,id'],
            'upazila_id' => ['nullable','integer','exists:upazilas,id'],
            'address_type' => ['nullable',Rule::in(['billing','shipping','other'])],
            'address' => ['nullable','string','max:3000'],
            'postal_code' => ['nullable','string','max:50'],
            'notes' => ['nullable','string','max:5000'],
        ]);
    }

    private function validateDealer(Request $request): array
    {
        return $request->validate([
            'depot_id' => ['required','integer','exists:depots,id'],
            'upazila_id' => ['required','integer','exists:upazilas,id'],
            'business_name' => ['required','string','max:255'],
            'proprietor_name' => ['required','string','max:255'],
            'phone' => ['required','string','max:50'],
            'alternate_phone' => ['nullable','string','max:50'],
            'email' => ['nullable','email','max:255'],
            'trade_license_number' => ['nullable','string','max:100'],
            'nid_number' => ['nullable','string','max:100'],
            'tin_number' => ['nullable','string','max:100'],
            'vat_number' => ['nullable','string','max:100'],
            'address' => ['required','string','max:3000'],
            'postal_code' => ['nullable','string','max:50'],
            'credit_limit' => ['nullable','numeric','min:0'],
            'payment_terms_days' => ['nullable','integer','min:0'],
            'manager_user_id' => ['nullable','integer','exists:users,id'],
            'notes' => ['nullable','string','max:5000'],
        ]);
    }

    private function validateSeller(Request $request): array
    {
        return $request->validate([
            'dealer_id' => ['required','integer','exists:dealers,id'],
            'seller_type' => ['required',Rule::in(['retailer','general_seller'])],
            'business_name' => ['required','string','max:255'],
            'proprietor_name' => ['required','string','max:255'],
            'phone' => ['required','string','max:50'],
            'alternate_phone' => ['nullable','string','max:50'],
            'email' => ['nullable','email','max:255'],
            'trade_license_number' => ['nullable','string','max:100'],
            'nid_number' => ['nullable','string','max:100'],
            'tin_number' => ['nullable','string','max:100'],
            'vat_number' => ['nullable','string','max:100'],
            'address' => ['required','string','max:3000'],
            'postal_code' => ['nullable','string','max:50'],
            'credit_limit' => ['nullable','numeric','min:0'],
            'payment_terms_days' => ['nullable','integer','min:0'],
            'manager_user_id' => ['nullable','integer','exists:users,id'],
            'notes' => ['nullable','string','max:5000'],
        ]);
    }
}

<?php

namespace App\Http\Controllers\ERP\CRM;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\District;
use App\Models\Division;
use App\Models\Upazila;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $customers = Customer::query()
            ->with('company:id,name')
            ->when($request->string('search')->toString(), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('business_name', 'like', "%{$search}%")
                        ->orWhere('customer_code', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('customer_type'), fn ($q) => $q->where('customer_type', $request->string('customer_type')->toString()))
            ->when($request->filled('status'), fn ($q) => $q->where('is_active', $request->string('status')->toString() === 'active'))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('ERP/CRM/Customers/Index', [
            'customers' => $customers,
            'filters' => $request->only(['search', 'customer_type', 'status']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('ERP/CRM/Customers/Create', $this->formOptions());
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateCustomer($request);

        $customer = DB::transaction(function () use ($validated) {
            $customer = Customer::create($validated['customer']);
            $this->syncChildren($customer, $validated);

            return $customer;
        });

        return redirect()
            ->route('erp.customers.show', $customer)
            ->with('success', 'Customer created successfully.');
    }

    public function show(Customer $customer): Response
    {
        $customer->load([
            'company:id,name',
            'contacts',
            'addresses.division:id,name',
            'addresses.district:id,name',
            'addresses.upazila:id,name',
        ]);

        return Inertia::render('ERP/CRM/Customers/Show', [
            'customer' => $customer,
        ]);
    }

    public function edit(Customer $customer): Response
    {
        $customer->load(['contacts', 'addresses']);

        return Inertia::render('ERP/CRM/Customers/Edit', [
            ...$this->formOptions(),
            'customer' => $customer,
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $validated = $this->validateCustomer($request);

        DB::transaction(function () use ($customer, $validated) {
            $customer->update($validated['customer']);
            $this->syncChildren($customer, $validated);
        });

        return redirect()
            ->route('erp.customers.show', $customer)
            ->with('success', 'Customer updated successfully.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $customer->delete();

        return redirect()
            ->route('erp.customers.index')
            ->with('success', 'Customer removed successfully.');
    }

    private function validateCustomer(Request $request): array
    {
        $customer = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'customer_type' => ['required', Rule::in(['individual', 'company'])],
            'name' => ['required', 'string', 'max:255'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'alternate_phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'nid_number' => ['nullable', 'string', 'max:100'],
            'tin_number' => ['nullable', 'string', 'max:100'],
            'vat_number' => ['nullable', 'string', 'max:100'],
            'credit_limit' => ['required', 'numeric', 'min:0'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $contacts = $request->validate([
            'contacts' => ['array'],
            'contacts.*.name' => ['required', 'string', 'max:255'],
            'contacts.*.designation' => ['nullable', 'string', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:50'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.is_primary' => ['boolean'],
            'contacts.*.notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $addresses = $request->validate([
            'addresses' => ['array'],
            'addresses.*.address_type' => ['required', Rule::in(['billing', 'shipping', 'other'])],
            'addresses.*.label' => ['nullable', 'string', 'max:255'],
            'addresses.*.division_id' => ['nullable', 'integer', 'exists:divisions,id'],
            'addresses.*.district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'addresses.*.upazila_id' => ['nullable', 'integer', 'exists:upazilas,id'],
            'addresses.*.address' => ['required', 'string', 'max:3000'],
            'addresses.*.postal_code' => ['nullable', 'string', 'max:50'],
            'addresses.*.is_primary' => ['boolean'],
        ]);

        foreach (($addresses['addresses'] ?? []) as $address) {
            if (! empty($address['district_id']) && ! empty($address['division_id'])) {
                $valid = District::query()
                    ->whereKey($address['district_id'])
                    ->where('division_id', $address['division_id'])
                    ->exists();

                abort_unless($valid, 422, 'Selected district does not belong to the selected division.');
            }

            if (! empty($address['upazila_id']) && ! empty($address['district_id'])) {
                $valid = Upazila::query()
                    ->whereKey($address['upazila_id'])
                    ->where('district_id', $address['district_id'])
                    ->exists();

                abort_unless($valid, 422, 'Selected upazila does not belong to the selected district.');
            }
        }

        return [
            'customer' => $customer,
            'contacts' => $contacts['contacts'] ?? [],
            'addresses' => $addresses['addresses'] ?? [],
        ];
    }

    private function syncChildren(Customer $customer, array $validated): void
    {
        $customer->contacts()->delete();
        $customer->addresses()->delete();

        $contacts = collect($validated['contacts'] ?? []);
        $primaryContactUsed = false;

        foreach ($contacts as $contact) {
            $requestedPrimary = (bool) ($contact['is_primary'] ?? false);
            $contact['is_primary'] = $requestedPrimary && ! $primaryContactUsed;
            $primaryContactUsed = $primaryContactUsed || $contact['is_primary'];
            $customer->contacts()->create($contact);
        }

        $addresses = collect($validated['addresses'] ?? []);
        $primaryByType = [];

        foreach ($addresses as $address) {
            $type = $address['address_type'];
            $requestedPrimary = (bool) ($address['is_primary'] ?? false);
            $address['is_primary'] = $requestedPrimary && ! ($primaryByType[$type] ?? false);
            $primaryByType[$type] = ($primaryByType[$type] ?? false) || $address['is_primary'];
            $customer->addresses()->create($address);
        }
    }

    private function formOptions(): array
    {
        return [
            'companies' => Company::query()
                ->orderBy('name')
                ->get(['id', 'name']),
            'divisions' => Division::query()
                ->orderBy('name')
                ->get(['id', 'name']),
            'districts' => District::query()
                ->orderBy('name')
                ->get(['id', 'division_id', 'name']),
            'upazilas' => Upazila::query()
                ->orderBy('name')
                ->get(['id', 'district_id', 'name']),
        ];
    }
}

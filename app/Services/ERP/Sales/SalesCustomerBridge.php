<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ERP\CRM\LeadConversionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SalesCustomerBridge
{
    public function __construct(
        private readonly SalesCompanyAccess $access,
        private readonly SalesSourceGuard $sources,
        private readonly LeadConversionService $conversion,
    ) {}

    public function resolve(User $actor, int $companyId, ?int $customerId = null, ?int $leadId = null, ?int $opportunityId = null): Customer
    {
        $company = $this->access->authorize($actor, $companyId, 'erp.sales-customers.select', true);
        $this->sources->currency((string) $company->getAttribute('currency_code'), (string) $company->getAttribute('currency_code'));
        $resolvedId = $customerId;
        if ($leadId !== null) {
            $this->crmPermission($actor, 'erp.leads.view');
            $lead = Lead::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($leadId);
            $this->active($lead->getAttribute('is_active'), 'lead_id');
            $this->sources->currency((string) $company->getAttribute('currency_code'), (string) $lead->getAttribute('currency'));
            $targetId = $this->convertedCustomerId($lead);
            $resolvedId = $this->sameCustomer($resolvedId, $targetId);
        }
        if ($opportunityId !== null) {
            $this->crmPermission($actor, 'erp.opportunities.view');
            $opportunity = Opportunity::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($opportunityId);
            $this->active($opportunity->getAttribute('is_active'), 'opportunity_id');
            $this->sources->currency((string) $company->getAttribute('currency_code'), (string) $opportunity->getAttribute('currency'));
            if (! $this->customerType((string) $opportunity->getAttribute('party_type'))) {
                throw ValidationException::withMessages(['opportunity_id' => 'The opportunity must belong to a customer.']);
            }
            if ($leadId !== null && (int) $opportunity->getAttribute('lead_id') !== $leadId) {
                throw ValidationException::withMessages(['opportunity_id' => 'The opportunity does not originate from the selected lead.']);
            }
            $resolvedId = $this->sameCustomer($resolvedId, (int) $opportunity->getAttribute('party_id'));
        }
        if ($resolvedId === null || $resolvedId < 1) {
            throw ValidationException::withMessages(['customer_id' => 'Select a customer or a CRM source resolving to a customer.']);
        }

        return $this->sources->customer($companyId, $resolvedId);
    }

    /** @param array<string, mixed> $payload */
    public function convertLead(User $actor, int $companyId, int $leadId, array $payload): Customer
    {
        return DB::transaction(function () use ($actor, $companyId, $leadId, $payload): Customer {
            $company = $this->access->authorize($actor, $companyId, 'erp.sales-customers.select', true);
            $this->crmPermission($actor, 'erp.leads.convert');
            $this->crmPermission($actor, 'erp.customers.create');
            $lead = Lead::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($leadId);
            $this->active($lead->getAttribute('is_active'), 'lead_id');
            $this->sources->currency((string) $company->getAttribute('currency_code'), (string) $lead->getAttribute('currency'));
            if ($lead->getAttribute('converted_at') !== null || $lead->getAttribute('converted_to_id') !== null || $lead->getAttribute('converted_to_type') !== null) {
                return $this->sources->customer($companyId, $this->convertedCustomerId($lead));
            }
            $validated = Validator::make($payload, [
                'company_id' => ['prohibited'], 'customer_type' => ['required', 'in:individual,company'],
                'name' => ['required', 'string', 'max:255'], 'phone' => ['required', 'string', 'max:50'],
                'business_name' => ['nullable', 'string', 'max:255'], 'contact_person' => ['nullable', 'string', 'max:255'],
                'email' => ['nullable', 'email', 'max:255'], 'conversion_notes' => ['nullable', 'string', 'max:2000'],
            ])->validate();
            // No arbitrary identity, credit, geography or company fields pass into CRM conversion.
            $target = $this->conversion->convert($lead, 'customer', [
                'company_id' => $companyId, 'customer_type' => $validated['customer_type'],
                'name' => $validated['name'], 'phone' => $validated['phone'],
                'business_name' => $validated['business_name'] ?? null, 'contact_person' => $validated['contact_person'] ?? null,
                'email' => $validated['email'] ?? null,
            ], $actor, $validated['conversion_notes'] ?? null);
            if (! $target instanceof Customer) {
                throw ValidationException::withMessages(['lead_id' => 'CRM conversion did not produce a customer.']);
            }

            return $this->sources->customer($companyId, (int) $target->getKey());
        });
    }

    /** @return array<string, mixed> */
    public function snapshot(User $actor, int $companyId, int $customerId, ?int $contactId = null, ?int $billingId = null, ?int $shippingId = null): array
    {
        $customer = $this->resolve($actor, $companyId, $customerId);
        $credit = (string) $customer->getAttribute('credit_limit');
        $days = (int) $customer->getAttribute('payment_terms_days');
        if (preg_match('/\A\d{1,16}\.\d{2}\z/', $credit) !== 1 || $days < 0) {
            throw ValidationException::withMessages(['customer_id' => 'Customer commercial terms require review.']);
        }
        $contact = $contactId === null ? $this->primaryContact($customer) : $this->sources->contact($customer, $contactId);
        $billing = $billingId === null ? $this->primaryAddress($customer, 'billing') : $this->sources->address($customer, $billingId, 'billing');
        $shipping = $shippingId === null ? $this->primaryAddress($customer, 'shipping') : $this->sources->address($customer, $shippingId, 'shipping');

        return [
            'customer_snapshot' => [...$customer->only(['id', 'customer_code', 'customer_type', 'name', 'business_name', 'contact_person', 'phone', 'email']),
                'selected_contact' => $contact?->only(['id', 'name', 'designation', 'phone', 'email'])],
            'billing_address_snapshot' => $billing?->only(['id', 'label', 'address', 'postal_code', 'division_id', 'district_id', 'upazila_id']),
            'shipping_address_snapshot' => $shipping?->only(['id', 'label', 'address', 'postal_code', 'division_id', 'district_id', 'upazila_id']),
            'terms_snapshot' => ['credit_limit' => $credit, 'payment_terms_days' => $days],
        ];
    }

    private function primaryContact(Customer $customer): ?CustomerContact
    {
        $rows = CustomerContact::query()->where('customer_id', $customer->getKey())->where('is_primary', true)->lockForUpdate()->limit(2)->get();
        if ($rows->count() > 1) {
            throw ValidationException::withMessages(['contact_id' => 'Multiple primary contacts exist. Select one explicitly.']);
        }

        return $rows->first();
    }

    private function primaryAddress(Customer $customer, string $type): ?CustomerAddress
    {
        $rows = CustomerAddress::query()->where('customer_id', $customer->getKey())->where('address_type', $type)->where('is_primary', true)->lockForUpdate()->limit(2)->get();
        if ($rows->count() > 1) {
            throw ValidationException::withMessages([$type.'_address_id' => 'Multiple primary addresses exist. Select one explicitly.']);
        }

        return $rows->first();
    }

    private function convertedCustomerId(Lead $lead): int
    {
        if ($lead->getAttribute('converted_at') === null || ! $this->customerType((string) $lead->getAttribute('converted_to_type')) || (int) $lead->getAttribute('converted_to_id') < 1) {
            throw ValidationException::withMessages(['lead_id' => 'Convert this lead to a customer through an authorized CRM flow first.']);
        }

        return (int) $lead->getAttribute('converted_to_id');
    }

    private function customerType(string $type): bool
    {
        return in_array($type, [Customer::class, (new Customer)->getMorphClass()], true);
    }

    private function sameCustomer(?int $selected, int $source): int
    {
        if ($source < 1 || ($selected !== null && $selected !== $source)) {
            throw ValidationException::withMessages(['customer_id' => 'The CRM sources do not resolve to the selected customer.']);
        }

        return $source;
    }

    private function active(mixed $active, string $field): void
    {
        if (! $active) {
            throw ValidationException::withMessages([$field => 'Select an active CRM source.']);
        }
    }

    private function crmPermission(User $actor, string $permission): void
    {
        if (! $actor->can($permission)) {
            throw new AuthorizationException('This CRM source requires its operational permission.');
        }
    }
}

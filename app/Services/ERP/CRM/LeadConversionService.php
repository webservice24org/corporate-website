<?php

namespace App\Services\ERP\CRM;

use App\Models\Customer;
use App\Models\Dealer;
use App\Models\Depot;
use App\Models\Lead;
use App\Models\Seller;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeadConversionService
{
    public function convert(Lead $lead, string $targetType, array $payload, User $actor, ?string $notes = null): Model
    {
        return DB::transaction(function () use ($lead, $targetType, $payload, $actor, $notes) {
            $lockedLead = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());

            if ($lockedLead->getAttribute('converted_at')) {
                throw ValidationException::withMessages([
                    'conversion' => 'This lead has already been converted.',
                ]);
            }

            $target = match ($targetType) {
                'customer' => $this->createCustomer($payload),
                'dealer' => $this->createDealer($payload),
                'seller' => $this->createSeller($payload),
                default => throw ValidationException::withMessages([
                    'target_type' => 'Invalid conversion target.',
                ]),
            };

            $lockedLead->forceFill([
                'converted_to_type' => $target->getMorphClass(),
                'converted_to_id' => $target->getKey(),
                'converted_at' => now(),
                'converted_by' => $actor->getKey(),
                'conversion_notes' => $notes,
            ])->save();

            return $target;
        });
    }

    private function createCustomer(array $p): Customer
    {
        $customer = Customer::create([
            'company_id' => $p['company_id'],
            'customer_code' => null,
            'customer_type' => $p['customer_type'],
            'name' => $p['name'],
            'business_name' => $p['business_name'] ?? null,
            'contact_person' => $p['contact_person'] ?? null,
            'phone' => $p['phone'],
            'alternate_phone' => $p['alternate_phone'] ?? null,
            'email' => $p['email'] ?? null,
            'nid_number' => $p['nid_number'] ?? null,
            'tin_number' => $p['tin_number'] ?? null,
            'vat_number' => $p['vat_number'] ?? null,
            'credit_limit' => $p['credit_limit'] ?? 0,
            'payment_terms_days' => $p['payment_terms_days'] ?? 0,
            'is_active' => true,
            'notes' => $p['notes'] ?? null,
        ]);

        if (! empty($p['address'])) {
            $customer->addresses()->create([
                'address_type' => $p['address_type'] ?? 'shipping',
                'label' => 'Lead conversion',
                'division_id' => $p['division_id'] ?? null,
                'district_id' => $p['district_id'] ?? null,
                'upazila_id' => $p['upazila_id'] ?? null,
                'address' => $p['address'],
                'postal_code' => $p['postal_code'] ?? null,
                'is_primary' => true,
            ]);
        }

        return $customer;
    }

    private function createDealer(array $p): Dealer
    {
        $depot = Depot::query()->whereKey($p['depot_id'])->where('is_active', true)->firstOrFail();
        $upazila = Upazila::query()->whereKey($p['upazila_id'])->firstOrFail();

        if ((int) $upazila->district_id !== (int) $depot->district_id) {
            throw ValidationException::withMessages([
                'upazila_id' => 'Selected Upazila must belong to the selected Depot district.',
            ]);
        }

        return Dealer::create([
            'company_id' => $depot->company_id,
            'depot_id' => $depot->id,
            'upazila_id' => $upazila->id,
            'dealer_code' => null,
            'business_name' => $p['business_name'],
            'proprietor_name' => $p['proprietor_name'],
            'phone' => $p['phone'],
            'alternate_phone' => $p['alternate_phone'] ?? null,
            'email' => $p['email'] ?? null,
            'trade_license_number' => $p['trade_license_number'] ?? null,
            'nid_number' => $p['nid_number'] ?? null,
            'tin_number' => $p['tin_number'] ?? null,
            'vat_number' => $p['vat_number'] ?? null,
            'address' => $p['address'],
            'postal_code' => $p['postal_code'] ?? null,
            'credit_limit' => $p['credit_limit'] ?? 0,
            'payment_terms_days' => $p['payment_terms_days'] ?? 0,
            'manager_user_id' => $p['manager_user_id'] ?? null,
            'is_active' => true,
            'notes' => $p['notes'] ?? null,
        ]);
    }

    private function createSeller(array $p): Seller
    {
        $dealer = Dealer::query()->whereKey($p['dealer_id'])->where('is_active', true)->firstOrFail();

        return Seller::create([
            'company_id' => $dealer->company_id,
            'dealer_id' => $dealer->id,
            'seller_code' => null,
            'seller_type' => $p['seller_type'],
            'business_name' => $p['business_name'],
            'proprietor_name' => $p['proprietor_name'],
            'phone' => $p['phone'],
            'alternate_phone' => $p['alternate_phone'] ?? null,
            'email' => $p['email'] ?? null,
            'trade_license_number' => $p['trade_license_number'] ?? null,
            'nid_number' => $p['nid_number'] ?? null,
            'tin_number' => $p['tin_number'] ?? null,
            'vat_number' => $p['vat_number'] ?? null,
            'business_address' => $p['address'],
            'postal_code' => $p['postal_code'] ?? null,
            'credit_limit' => $p['credit_limit'] ?? 0,
            'payment_terms_days' => $p['payment_terms_days'] ?? 0,
            'manager_user_id' => $p['manager_user_id'] ?? null,
            'is_active' => true,
            'notes' => $p['notes'] ?? null,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;

/** Internal source checks; callers first authorize and lock their company/document. */
final class SalesSourceGuard
{
    public function __construct(private readonly SalesCompanyAccess $access) {}

    public function customer(int $companyId, int $customerId): Customer
    {
        $this->access->requireTransaction();
        $customer = Customer::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($customerId);
        if (! $customer->getAttribute('is_active')) {
            throw ValidationException::withMessages(['customer_id' => 'Select an active customer.']);
        }

        return $customer;
    }

    public function contact(Customer $customer, int $contactId): CustomerContact
    {
        $this->access->requireTransaction();

        return CustomerContact::query()->where('customer_id', $customer->getKey())->lockForUpdate()->findOrFail($contactId);
    }

    public function address(Customer $customer, int $addressId, string $addressType): CustomerAddress
    {
        $this->access->requireTransaction();
        if (! in_array($addressType, ['billing', 'shipping'], true)) {
            throw ValidationException::withMessages(['address_type' => 'Select billing or shipping.']);
        }

        return CustomerAddress::query()->where('customer_id', $customer->getKey())
            ->where('address_type', $addressType)->lockForUpdate()->findOrFail($addressId);
    }

    public function product(int $companyId, int $productId, ?int $variantId = null): Product
    {
        $this->access->requireTransaction();
        $product = Product::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($productId);
        if (! $product->getAttribute('is_active')) {
            throw ValidationException::withMessages(['product_id' => 'Select an active product.']);
        }
        if ((bool) $product->getAttribute('has_variants') !== ($variantId !== null)) {
            throw ValidationException::withMessages(['product_variant_id' => 'The selected variant does not match the product configuration.']);
        }
        if ($variantId !== null) {
            $variant = ProductVariant::query()->where('product_id', $product->getKey())->lockForUpdate()->findOrFail($variantId);
            if (! $variant->getAttribute('is_active')) {
                throw ValidationException::withMessages(['product_variant_id' => 'Select an active variant.']);
            }
        }

        return $product;
    }

    public function warehouse(int $companyId, int $warehouseId): Warehouse
    {
        $this->access->requireTransaction();
        $warehouse = Warehouse::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($warehouseId);
        if (! $warehouse->getAttribute('is_active')) {
            throw ValidationException::withMessages(['warehouse_id' => 'Select an active warehouse.']);
        }

        return $warehouse;
    }

    public function currency(string $companyCurrency, string $documentCurrency): void
    {
        if (preg_match('/\A[A-Z]{3}\z/', $companyCurrency) !== 1 || $documentCurrency !== $companyCurrency) {
            throw ValidationException::withMessages(['currency_code' => 'Sales must use the valid company currency.']);
        }
    }
}

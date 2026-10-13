<?php

declare(strict_types=1);

namespace App\Http\Requests\ERP\Inventory;

use App\Models\Company;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LowStockReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && $this->user()->can('erp.low-stock-reports.view');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('companies', 'id'),
            ],

            'warehouse_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('warehouses', 'id'),
            ],

            'product_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('products', 'id'),
            ],

            'category_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('product_categories', 'id'),
            ],

            'status' => [
                'nullable',
                'string',
                Rule::in([
                    'out_of_stock',
                    'critical',
                    'low_stock',
                    'healthy',
                ]),
            ],

            'include_healthy' => [
                'nullable',
                'boolean',
            ],
        ];
    }

    /**
     * Company IDs accessible to the authenticated user.
     *
     * @return array<int, int>
     */
    public function allowedCompanyIds(): array
    {
        $user = $this->user();

        abort_unless($user !== null, 403);

        if ($user->hasRole('super-admin')) {
            return Company::query()
                ->pluck('id')
                ->map(
                    fn ($id): int => (int) $id
                )
                ->all();
        }

        return $user->employee()
            ->pluck('company_id')
            ->map(
                fn ($id): int => (int) $id
            )
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Resolve and authorize the selected company.
     *
     * Call only after validation succeeds.
     */
    public function resolvedCompanyId(): int
    {
        $data = $this->validated();

        $allowedIds = $this->allowedCompanyIds();

        abort_if(
            $allowedIds === [],
            403,
            'You do not have access to any company.'
        );

        $requestedId = $data['company_id'] ?? null;

        if ($requestedId !== null) {
            $companyId = (int) $requestedId;

            if (! in_array($companyId, $allowedIds, true)) {
                abort(
                    403,
                    'You cannot access inventory for this company.'
                );
            }

            return $companyId;
        }

        return $allowedIds[0];
    }

    /**
     * Validate that optional warehouse and product filters
     * belong to the authorized company.
     */
    public function validateCompanyFilters(int $companyId): void
    {
        $data = $this->validated();

        $errors = [];

        if (
            isset($data['warehouse_id']) &&
            ! Warehouse::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->whereKey((int) $data['warehouse_id'])
                ->exists()
        ) {
            $errors['warehouse_id'] =
                'The selected warehouse is not available for this company.';
        }

        if (
            isset($data['product_id']) &&
            ! Product::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where('product_type', 'physical')
                ->where('track_inventory', true)
                ->whereKey((int) $data['product_id'])
                ->exists()
        ) {
            $errors['product_id'] =
                'The selected product is not available for this company.';
        }

        if (
            isset($data['category_id']) &&
            ! Product::query()
                ->where('company_id', $companyId)
                ->where('product_category_id', (int) $data['category_id'])
                ->exists()
        ) {
            $errors['category_id'] =
                'The selected category has no products in this company.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function reportFilters(): array
    {
        $data = $this->validated();

        return [
            'warehouse_id' => isset($data['warehouse_id'])
                ? (int) $data['warehouse_id']
                : null,

            'product_id' => isset($data['product_id'])
                ? (int) $data['product_id']
                : null,

            'category_id' => isset($data['category_id'])
                ? (int) $data['category_id']
                : null,

            'status' => $data['status'] ?? null,

            'include_healthy' => $this->boolean('include_healthy'),
        ];
    }
}

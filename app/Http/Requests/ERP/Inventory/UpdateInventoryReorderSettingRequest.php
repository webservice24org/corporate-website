<?php

declare(strict_types=1);

namespace App\Http\Requests\ERP\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInventoryReorderSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && $this->user()->can(
                'erp.inventory-reorder-settings.manage'
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('companies', 'id'),
            ],

            'warehouse_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('warehouses', 'id'),
            ],

            'product_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('products', 'id'),
            ],

            'product_variant_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('product_variants', 'id'),
            ],

            'reorder_level' => [
                'required',
                'regex:/^\d{1,14}(?:\.\d{1,4})?$/D',
            ],

            'minimum_stock' => [
                'required',
                'regex:/^\d{1,14}(?:\.\d{1,4})?$/D',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reorder_level.regex' =>
                'Reorder level must be a nonnegative number with at most four decimal places.',

            'minimum_stock.regex' =>
                'Minimum stock must be a nonnegative number with at most four decimal places.',
        ];
    }
}

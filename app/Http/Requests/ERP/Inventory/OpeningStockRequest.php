<?php

namespace App\Http\Requests\ERP\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class OpeningStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        /*
         * Route middleware will enforce create/edit
         * permissions.
         */
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => [
                'required',
                'integer',
                'exists:companies,id',
            ],

            'warehouse_id' => [
                'required',
                'integer',
                'exists:warehouses,id',
            ],

            'document_date' => [
                'required',
                'date',
            ],

            'notes' => [
                'nullable',
                'string',
                'max:5000',
            ],

            'items' => [
                'required',
                'array',
                'min:1',
            ],

            'items.*.product_id' => [
                'required',
                'integer',
                'exists:products,id',
            ],

            'items.*.product_variant_id' => [
                'nullable',
                'integer',
                'exists:product_variants,id',
            ],

            'items.*.quantity' => [
                'required',
                'numeric',
                'gt:0',
            ],

            'items.*.unit_cost' => [
                'required',
                'numeric',
                'gte:0',
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'company_id' => 'company',

            'warehouse_id' => 'warehouse',

            'document_date' => 'document date',

            'items.*.product_id' => 'product',

            'items.*.product_variant_id' => 'product variant',

            'items.*.quantity' => 'quantity',

            'items.*.unit_cost' => 'unit cost',
        ];
    }
}

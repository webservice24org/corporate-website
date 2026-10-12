<?php

namespace App\Http\Requests\ERP\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->route('stockTransfer') ? 'erp.stock-transfers.edit' : 'erp.stock-transfers.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')],
            'source_warehouse_id' => ['required', 'integer', 'different:destination_warehouse_id', Rule::exists('warehouses', 'id')],
            'destination_warehouse_id' => ['required', 'integer', 'different:source_warehouse_id', Rule::exists('warehouses', 'id')],
            'transfer_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'items.*.product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')],
            'items.*.quantity' => ['required', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/', 'numeric', 'gt:0'],
        ];
    }
}

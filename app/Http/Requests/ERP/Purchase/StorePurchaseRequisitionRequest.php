<?php

declare(strict_types=1);

namespace App\Http\Requests\ERP\Purchase;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRequisitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('erp.purchase-requisitions.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'min:1'],
            'department_id' => ['nullable', 'integer', 'min:1'],
            'required_at' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['array:product_id,product_variant_id,quantity,estimated_unit_price,notes'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.product_variant_id' => ['nullable', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000', 'regex:/^[0-9]+(?:\.[0-9]{1,4})?$/D'],
            'items.*.estimated_unit_price' => ['required', 'numeric', 'min:0', 'max:1000000', 'regex:/^[0-9]+(?:\.[0-9]{1,2})?$/D'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['prohibited'], 'number' => ['prohibited'],
            'requested_by' => ['prohibited'], 'approved_by' => ['prohibited'],
            'currency_code' => ['prohibited'],
        ];
    }
}

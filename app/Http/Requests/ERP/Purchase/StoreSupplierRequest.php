<?php

declare(strict_types=1);

namespace App\Http\Requests\ERP\Purchase;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('erp.suppliers.create') ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['code', 'name', 'currency_code'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $this->merge([$field => $field === 'currency_code' ? strtoupper(trim($value)) : trim($value)]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'code' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:200'],
            'currency_code' => ['required', 'string', 'size:3', 'alpha'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:255'],
            'payment_terms_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\ERP\Purchase;

class UpdateSupplierRequest extends StoreSupplierRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('erp.suppliers.edit') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...parent::rules(), 'company_id' => ['prohibited']];
    }
}

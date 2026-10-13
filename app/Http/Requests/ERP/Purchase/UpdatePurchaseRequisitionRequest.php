<?php

declare(strict_types=1);

namespace App\Http\Requests\ERP\Purchase;

final class UpdatePurchaseRequisitionRequest extends StorePurchaseRequisitionRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('erp.purchase-requisitions.edit') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...parent::rules(), 'company_id' => ['prohibited'], 'revision' => ['required', 'integer', 'min:1']];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Sales;

use App\Models\Company;
use App\Models\CustomerReceiptAllocation;
use App\Models\User;
use App\Services\ERP\Accounting\Source\AccountingPreparedSourceEvent;
use App\Services\ERP\Accounting\Source\AccountingSourceAdapter;
use App\Services\ERP\Sales\SalesCompanyAccess;
use Illuminate\Validation\ValidationException;

final class CustomerReceiptAllocationAccountingAdapter implements AccountingSourceAdapter
{
    public function __construct(private readonly SalesCompanyAccess $access, private readonly AccountingReceiptEvidence $evidence) {}

    public function authorize(User $actor, Company $company, int $sourceId, string $eventKind, string $eventToken): void
    {
        $source = CustomerReceiptAllocation::query()->where('company_id', $company->getKey())->findOrFail($sourceId);
        $reversed = $source->getAttribute('reverses_allocation_id') !== null;
        $this->access->authorize($actor, (int) $company->getKey(), 'erp.customer-receipts.'.($reversed ? 'reverse' : 'allocate'), true);
        if ($eventToken !== 'original' || $eventKind !== ($reversed ? 'reversed' : 'created')) {
            throw ValidationException::withMessages(['source_identity' => 'Receipt finance identity differs from its persisted source transition.']);
        }
    }

    public function prepare(User $actor, Company $company, int $sourceId, string $eventKind, string $eventToken): AccountingPreparedSourceEvent
    {
        return $this->evidence->prepare((int) $company->getKey(), 'customer_receipt_allocation', $sourceId);
    }
}

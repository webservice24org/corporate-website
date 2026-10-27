<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Purchase;

use App\Models\Company;
use App\Models\SupplierPaymentAllocation;
use App\Models\User;
use App\Services\ERP\Accounting\Source\AccountingPreparedSourceEvent;
use App\Services\ERP\Accounting\Source\AccountingSourceAdapter;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Validation\ValidationException;

final class SupplierPaymentAllocationAccountingAdapter implements AccountingSourceAdapter
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingSupplierPaymentEvidence $evidence) {}

    public function authorize(User $actor, Company $company, int $sourceId, string $eventKind, string $eventToken): void
    {
        $source = SupplierPaymentAllocation::query()->where('company_id', $company->getKey())->findOrFail($sourceId);
        $this->access->authorize($actor, (int) $company->getKey(), str_contains((string) $source->getAttribute('idempotency_key'), ':reverse:') ? 'erp.supplier-payments.reverse' : 'erp.supplier-payments.allocate');
        if (str_contains((string) $source->getAttribute('idempotency_key'), ':post:')) {
            $this->access->authorize($actor, (int) $company->getKey(), 'erp.supplier-payments.post');
        }
        if ($eventKind !== ($source->getAttribute('reverses_allocation_id') === null ? 'created' : 'reversed') || $eventToken !== 'original') {
            throw ValidationException::withMessages(['source_identity' => 'Allocation event differs from its original or exact compensation identity.']);
        }
    }

    public function prepare(User $actor, Company $company, int $sourceId, string $eventKind, string $eventToken): AccountingPreparedSourceEvent
    {
        return $this->evidence->prepare((int) $company->getKey(), 'supplier_payment_allocation', $sourceId);
    }
}

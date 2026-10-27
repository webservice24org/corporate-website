<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Sales;

use App\Models\Company;
use App\Models\CustomerCreditAllocation;
use App\Models\User;
use App\Services\ERP\Accounting\Source\AccountingPreparedSourceEvent;
use App\Services\ERP\Accounting\Source\AccountingSourceAdapter;
use App\Services\ERP\Sales\SalesCompanyAccess;
use Illuminate\Validation\ValidationException;

final class CustomerCreditAllocationAccountingAdapter implements AccountingSourceAdapter
{
    public function __construct(private readonly SalesCompanyAccess $access, private readonly AccountingSalesEvidence $evidence) {}

    public function authorize(User $actor, Company $company, int $sourceId, string $eventKind, string $eventToken): void
    {
        $row = CustomerCreditAllocation::query()->where('company_id', $company->getKey())->findOrFail($sourceId);
        $reverse = $row->getAttribute('reverses_allocation_id') !== null;
        $this->access->authorize($actor, (int) $company->getKey(), $reverse ? 'erp.customer-credits.reverse' : 'erp.customer-credits.allocate', true);
        if ($eventKind !== ($reverse ? 'reversed' : 'created') || $eventToken !== 'original') {
            throw ValidationException::withMessages(['source_identity' => 'This adapter recognizes only the original persisted Sales transition.']);
        }
    }

    public function prepare(User $actor, Company $company, int $sourceId, string $eventKind, string $eventToken): AccountingPreparedSourceEvent
    {
        return $this->evidence->prepare((int) $company->getKey(), 'customer_credit_allocation', $sourceId);
    }
}

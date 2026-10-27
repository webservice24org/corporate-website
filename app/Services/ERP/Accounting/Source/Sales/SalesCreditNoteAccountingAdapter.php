<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Sales;

use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\Source\AccountingPreparedSourceEvent;
use App\Services\ERP\Accounting\Source\AccountingSourceAdapter;
use App\Services\ERP\Sales\SalesCompanyAccess;
use Illuminate\Validation\ValidationException;

final class SalesCreditNoteAccountingAdapter implements AccountingSourceAdapter
{
    public function __construct(private readonly SalesCompanyAccess $access, private readonly AccountingSalesEvidence $evidence) {}

    public function authorize(User $actor, Company $company, int $sourceId, string $eventKind, string $eventToken): void
    {
        $this->access->authorize($actor, (int) $company->getKey(), 'erp.sales-credit-notes.issue', true);
        if ($eventKind !== 'issued' || $eventToken !== 'original') {
            throw ValidationException::withMessages(['source_identity' => 'This adapter recognizes only the original persisted Sales transition.']);
        }
    }

    public function prepare(User $actor, Company $company, int $sourceId, string $eventKind, string $eventToken): AccountingPreparedSourceEvent
    {
        return $this->evidence->prepare((int) $company->getKey(), 'sales_credit_note', $sourceId);
    }
}

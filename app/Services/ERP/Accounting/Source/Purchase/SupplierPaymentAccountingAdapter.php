<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Purchase;

use App\Models\Company;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\ERP\Accounting\Source\AccountingPreparedSourceEvent;
use App\Services\ERP\Accounting\Source\AccountingSourceAdapter;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Validation\ValidationException;

final class SupplierPaymentAccountingAdapter implements AccountingSourceAdapter
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingSupplierPaymentEvidence $evidence) {}

    public function authorize(User $actor, Company $company, int $sourceId, string $eventKind, string $eventToken): void
    {
        $source = SupplierPayment::query()->where('company_id', $company->getKey())->findOrFail($sourceId);
        $reversal = $source->getAttribute('reverses_payment_id') !== null;
        $this->access->authorize($actor, (int) $company->getKey(), $reversal ? 'erp.supplier-payments.reverse' : 'erp.supplier-payments.post');
        if ($eventKind !== ($reversal ? 'reversed' : 'posted') || $eventToken !== 'original' || $source->getAttribute('status') !== 'posted') {
            throw ValidationException::withMessages(['source_identity' => 'Only the installed original initial-payment transition is supported.']);
        }
    }

    public function prepare(User $actor, Company $company, int $sourceId, string $eventKind, string $eventToken): AccountingPreparedSourceEvent
    {
        return $this->evidence->prepare((int) $company->getKey(), 'supplier_payment', $sourceId);
    }
}

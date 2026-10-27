<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Purchase;

use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\Source\AccountingPreparedSourceEvent;
use App\Services\ERP\Accounting\Source\AccountingSourceAdapter;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Validation\ValidationException;

final class GoodsReceiptAccountingAdapter implements AccountingSourceAdapter
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingPurchaseEvidence $evidence) {}

    public function authorize(User $actor, Company $company, int $sourceId, string $eventKind, string $eventToken): void
    {
        $this->access->authorize($actor, (int) $company->getKey(), 'erp.goods-receipts.post');
        if ($eventKind !== 'posted' || $eventToken !== 'original') {
            throw ValidationException::withMessages(['source_identity' => 'This adapter accepts only the original persisted source transition.']);
        }
    }

    public function prepare(User $actor, Company $company, int $sourceId, string $eventKind, string $eventToken): AccountingPreparedSourceEvent
    {
        return $this->evidence->prepare((int) $company->getKey(), 'goods_receipt', $sourceId);
    }
}

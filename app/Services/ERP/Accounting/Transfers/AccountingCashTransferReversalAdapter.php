<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Transfers;

use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\Source\AccountingPreparedSourceEvent;
use App\Services\ERP\Accounting\Source\AccountingSourceAdapter;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Validation\ValidationException;

final class AccountingCashTransferReversalAdapter implements AccountingSourceAdapter
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingCashTransferReversalNativeProof $native) {}

    public function authorize(User $actor, Company $company, int $sourceId, string $eventKind, string $eventToken): void
    {
        $this->access->authorize($actor, (int) $company->getKey(), 'erp.cash-transfer-reversals.post');
        $row = $this->native->verify((int) $company->getKey(), $sourceId)['request'];
        if ($eventKind !== 'posted' || (int) $row->getAttribute('posted_by') !== (int) $actor->getKey() || $row->getAttribute('posting_key_hash') !== $eventToken) {
            throw ValidationException::withMessages(['cash_transfer_reversal' => 'The adapter requires the native reversal posting actor, event and key.']);
        }
    }

    public function prepare(User $actor, Company $company, int $sourceId, string $eventKind, string $eventToken): AccountingPreparedSourceEvent
    {
        $this->authorize($actor, $company, $sourceId, $eventKind, $eventToken);

        return $this->native->event($this->native->verify((int) $company->getKey(), $sourceId));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Transfers;

use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingCashTransfer;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\Source\AccountingPreparedSourceEvent;
use App\Services\ERP\Accounting\Source\AccountingSourceAdapter;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Validation\ValidationException;

/** Closed native adapter. Requires the persisted, independently approved posting transition. */
final class AccountingCashTransferAdapter implements AccountingSourceAdapter
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingCashTransferFinancialContract $contract, private readonly AccountingMappingService $mappings) {}

    public function authorize(User $actor, Company $company, int $sourceId, string $eventKind, string $eventToken): void
    {
        $this->access->authorize($actor, (int) $company->getKey(), 'erp.cash-transfers.post');
        $row = AccountingCashTransfer::query()->where('company_id', $company->getKey())->findOrFail($sourceId);
        if ($eventKind !== 'posted' || ! hash_equals((string) $row->getAttribute('posting_key_hash'), $eventToken) || (int) $row->getAttribute('posted_by') !== (int) $actor->getKey()) {
            $this->fail();
        }
        $this->contract->event($row);
    }

    public function prepare(User $actor, Company $company, int $sourceId, string $eventKind, string $eventToken): AccountingPreparedSourceEvent
    {
        $this->authorize($actor, $company, $sourceId, $eventKind, $eventToken);
        $row = AccountingCashTransfer::query()->where('company_id', $company->getKey())->findOrFail($sourceId);
        $event = $this->contract->event($row);
        $roles = [];
        foreach ([$row->getAttribute('source_snapshot'), $row->getAttribute('destination_snapshot')] as $snapshot) {
            $binding = AccountingCashAccount::query()->where('company_id', $company->getKey())->lockForUpdate()->findOrFail($snapshot['id']);
            if ($binding->getAttribute('status') !== 'active') {
                $this->fail();
            }
            $roles[] = $snapshot['role'];
        }
        $mapping = $this->mappings->resolveForSource($company, $event->accountingDate, $roles);
        if ($mapping['version_id'] !== (int) $row->getAttribute('mapping_version_id') || ! hash_equals($mapping['payload_hash'], (string) $row->getAttribute('mapping_hash')) || $event->currency !== $company->getAttribute('currency_code')) {
            $this->fail();
        }
        foreach ([$row->getAttribute('source_snapshot'), $row->getAttribute('destination_snapshot')] as $snapshot) {
            if (($mapping['accounts'][$snapshot['role']] ?? null) !== $snapshot['account_id']) {
                $this->fail();
            }
        }

        return $event;
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['cash_transfer' => 'Transfer posting requires its native identity, active bindings and unchanged reviewed profile.']);
    }
}

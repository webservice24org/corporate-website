<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Adjustments;

use App\Models\Accounting\AccountingBankAdjustment;
use App\Models\Accounting\AccountingBankAdjustmentAuditEvent;
use App\Models\Accounting\AccountingBankAdjustmentOperation;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntryLine;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\Source\AccountingPreparedSourceEvent;
use App\Services\ERP\Accounting\Source\AccountingSourceAdapter;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Validation\ValidationException;

final class AccountingBankAdjustmentAdapter implements AccountingSourceAdapter
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingBankAdjustmentProof $proof) {}

    public function authorize(User $actor, Company $company, int $sourceId, string $eventKind, string $eventToken): void
    {
        foreach (['erp.bank-adjustments.view', 'erp.bank-adjustments.post', 'erp.bank-statements.view', 'erp.cash-books.view', 'erp.bank-reconciliations.view'] as $permission) {
            $this->access->authorize($actor, (int) $company->getKey(), $permission);
        }
        $row = AccountingBankAdjustment::query()->where('company_id', $company->getKey())->findOrFail($sourceId);
        if ($eventKind !== 'posted' || $row->getAttribute('posting_key_hash') !== $eventToken || (int) $row->getAttribute('posted_by') !== (int) $actor->getKey()) {
            throw ValidationException::withMessages(['bank_adjustment' => 'Use the independently approved native bank posting transition.']);
        }
        $this->proof->event($row);
        $companyId = (int) $company->getKey();
        $existing = AccountingBankAdjustmentOperation::query()->where('company_id', $companyId)->where('adjustment_id', $sourceId)->exists()
            || AccountingSourceEffect::query()->where('company_id', $companyId)->where('source_kind', 'bank_adjustment')->where('source_id', $sourceId)->exists()
            || JournalEntryLine::query()->where('company_id', $companyId)->where('source_line_type', 'bank_adjustment')->where('source_line_id', $sourceId)->exists()
            || AccountingBankAdjustmentAuditEvent::query()->where('company_id', $companyId)->where('adjustment_id', $sourceId)->where('action', 'posted')->exists();
        if ($existing) {
            app(AccountingBankAdjustmentEvidence::class)->family($companyId, $sourceId);
        }

    }

    public function prepare(User $actor, Company $company, int $sourceId, string $eventKind, string $eventToken): AccountingPreparedSourceEvent
    {
        $this->authorize($actor, $company, $sourceId, $eventKind, $eventToken);

        return $this->proof->event(AccountingBankAdjustment::query()->where('company_id', $company->getKey())->findOrFail($sourceId));
    }
}

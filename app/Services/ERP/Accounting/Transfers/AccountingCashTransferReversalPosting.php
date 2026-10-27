<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Transfers;

use App\Models\Accounting\AccountingCashTransfer;
use App\Models\Accounting\AccountingCashTransferReversalAuditEvent;
use App\Models\Accounting\AccountingCashTransferReversalOperation;
use App\Models\Accounting\AccountingCashTransferReversalRequest;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingConfigurationGuard;
use App\Services\ERP\Accounting\Source\AccountingSourceOrchestrator;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** One authorized approved reversal, one original, one balanced compensating journal and complete operation. */
final class AccountingCashTransferReversalPosting
{
    public function __construct(private readonly AccountingCashTransferReversalWorkflow $workflow, private readonly PurchaseCompanyAccess $access, private readonly AccountingConfigurationGuard $configuration, private readonly AccountingBusinessDate $dates, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingSourceOrchestrator $orchestrator, private readonly AccountingCashTransferReversalEvidence $evidence) {}

    public function post(User $actor, int $id, int $revision, string $key): AccountingCashTransferReversalRequest
    {
        if ($revision < 1 || ! preg_match('/\A[A-Za-z0-9_-]{16,64}\z/', $key)) {
            $this->fail('Use the approved request revision and a stable posting key of 16–64 allowed characters.');
        }
        $found = $this->workflow->find($actor, $id, 'erp.cash-transfer-reversals.post');
        $companyId = (int) $found->getAttribute('company_id');
        $originalId = (int) $found->getAttribute('original_transfer_id');
        $keyHash = hash('sha256', $key);

        return DB::transaction(function () use ($actor, $id, $revision, $companyId, $originalId, $keyHash): AccountingCashTransferReversalRequest {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $companyId, 'erp.cash-transfer-reversals.post');
            AccountingCashTransfer::query()->where('company_id', $companyId)->whereKey($originalId)->lockForUpdate()->firstOrFail();
            $row = AccountingCashTransferReversalRequest::query()->where('company_id', $companyId)->where('original_transfer_id', $originalId)->lockForUpdate()->findOrFail($id);
            if ($row->getAttribute('status') === 'posted') {
                if ($row->getAttribute('posting_key_hash') !== $keyHash || (int) $row->getAttribute('posting_revision') !== $revision || (int) $row->getAttribute('posted_by') !== (int) $actor->getKey()) {
                    $this->fail('Reversal posting retry differs from the original request or actor.');
                }

                return $this->evidence->verify($companyId, $id)['request'];
            }
            if ($row->getAttribute('status') !== 'approved' || (int) $row->getAttribute('revision') !== $revision) {
                $this->fail('Reload the independently approved reversal revision.');
            }
            if (AccountingCashTransferReversalOperation::query()->where('company_id', $companyId)->where(function ($query) use ($id, $originalId): void {
                $query->where('reversal_request_id', $id)->orWhere('original_transfer_id', $originalId);
            })->exists()
                || AccountingSourceEffect::query()->where('company_id', $companyId)->where('source_kind', 'cash_transfer_reversal')->where('source_id', $id)->exists()
                || JournalEntryLine::query()->where('company_id', $companyId)->where('source_line_type', 'cash_transfer_reversal')->where('source_line_id', $id)->exists()) {
                $this->fail('A financial member already exists. Reconcile incomplete history; do not fill it in as a new reversal.');
            }
            $this->evidence->verifyForOriginal($companyId, $originalId);
            $setting = $this->configuration->lockForSetup($company);
            if ($setting->getAttribute('state') !== 'enabled') {
                $this->fail('New reversal posting requires enabled accounting.');
            }
            if ($row->getAttribute('document_date')->format('Y-m-d') > $this->dates->today($company)) {
                $this->fail('Reversal date cannot be in the company future.');
            }
            $row->forceFill(['status' => 'posted', 'revision' => $revision + 1, 'posting_revision' => $revision, 'posting_key_hash' => $keyHash, 'posted_by' => (int) $actor->getKey(), 'posted_at' => CarbonImmutable::now('UTC'), 'updated_by' => (int) $actor->getKey()])->save();
            $result = $this->orchestrator->execute($actor, $companyId, 'cash_transfer_reversal', $id, 'posted', $keyHash);
            if ($result['mode'] !== 'recorded' || count($result['effect_ids']) !== 1) {
                $this->fail('A new reversal must record exactly one complete cash effect.');
            }
            $effect = AccountingSourceEffect::query()->where('company_id', $companyId)->findOrFail($result['effect_ids'][0]);
            $journal = JournalEntry::query()->where('company_id', $companyId)->findOrFail((int) $effect->getAttribute('journal_entry_id'));
            $payload = $this->evidence->operationPayload($row, $effect, $journal);
            AccountingCashTransferReversalOperation::query()->create(['company_id' => $companyId, 'reversal_request_id' => $id, 'original_transfer_id' => $originalId, 'original_journal_id' => (int) $row->getAttribute('original_journal_id'), 'source_effect_id' => (int) $effect->getKey(), 'journal_entry_id' => (int) $journal->getKey(), 'actor_id' => (int) $actor->getKey(), 'key_hash' => $keyHash, 'request_revision' => $revision, 'contract_version' => AccountingCashTransferReversalNativeProof::VERSION, 'payload' => $payload, 'payload_hash' => $this->canonical->hash($payload), 'occurred_at' => $row->getAttribute('posted_at')]);
            $this->evidence->verifyForOriginal($companyId, $originalId);
            AccountingCashTransferReversalAuditEvent::query()->create(['company_id' => $companyId, 'reversal_request_id' => $id, 'actor_id' => (int) $actor->getKey(), 'action' => 'posted', 'reason' => null, 'before_payload' => ['status' => 'approved', 'revision' => $revision], 'after_payload' => ['status' => 'posted', 'revision' => $revision + 1, 'original_transfer_id' => $originalId, 'journal_id' => (int) $journal->getKey(), 'amount' => (string) $row->getAttribute('amount'), 'currency_code' => (string) $row->getAttribute('currency_code')], 'occurred_at' => CarbonImmutable::now('UTC')]);

            return $row;
        }, 3);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['cash_transfer_reversal' => $message]);
    }
}

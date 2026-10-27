<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Transfers;

use App\Models\Accounting\AccountingCashTransfer;
use App\Models\Accounting\AccountingCashTransferAuditEvent;
use App\Models\Accounting\AccountingCashTransferOperation;
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

/** Authorized native command. One approved transfer, one journal, one immutable complete operation. */
final class AccountingCashTransferPosting
{
    public function __construct(private readonly AccountingCashTransferWorkflow $workflow, private readonly PurchaseCompanyAccess $access, private readonly AccountingConfigurationGuard $configuration, private readonly AccountingBusinessDate $dates, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingSourceOrchestrator $orchestrator, private readonly AccountingCashTransferEvidence $evidence) {}

    public function post(User $actor, int $id, int $revision, string $key): AccountingCashTransfer
    {
        if ($revision < 1 || ! preg_match('/\A[A-Za-z0-9_-]{16,64}\z/', $key)) {
            $this->fail('Provide the approved revision and a stable posting key of 16–64 allowed characters.');
        }
        $found = $this->workflow->find($actor, $id, 'erp.cash-transfers.post');
        $companyId = (int) $found->getAttribute('company_id');
        $keyHash = hash('sha256', $key);

        return DB::transaction(function () use ($actor, $id, $revision, $companyId, $keyHash): AccountingCashTransfer {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $companyId, 'erp.cash-transfers.post');
            $row = AccountingCashTransfer::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            // Historical replay precedes configuration/activation/period/current-binding gates.
            if ($row->getAttribute('status') === 'posted') {
                if ($row->getAttribute('posting_key_hash') !== $keyHash || (int) $row->getAttribute('posting_revision') !== $revision || (int) $row->getAttribute('posted_by') !== (int) $actor->getKey()) {
                    $this->fail('This transfer was posted by a different request or actor.');
                }

                return $this->evidence->verify($companyId, $id)['transfer'];
            }
            if ($row->getAttribute('status') !== 'approved' || (int) $row->getAttribute('revision') !== $revision) {
                $this->fail('Reload the independently approved transfer revision before posting.');
            }
            if (AccountingCashTransferOperation::query()->where('company_id', $companyId)->where('cash_transfer_id', $id)->exists() || AccountingSourceEffect::query()->where('company_id', $companyId)->where('source_kind', 'cash_transfer')->where('source_id', $id)->exists() || JournalEntryLine::query()->where('company_id', $companyId)->where('source_line_type', 'cash_transfer')->where('source_line_id', $id)->exists()) {
                $this->fail('Unposted transfer has conflicting financial evidence; do not fill in partial history.');
            }
            $setting = $this->configuration->lockForSetup($company);
            if ($setting->getAttribute('state') !== 'enabled') {
                $this->fail('Transfer posting requires enabled accounting; preparation remains available separately.');
            }
            if ($row->getAttribute('document_date')->format('Y-m-d') > $this->dates->today($company)) {
                $this->fail('Transfer date cannot be in the company future.');
            }
            $row->forceFill(['status' => 'posted', 'revision' => $revision + 1, 'posting_revision' => $revision, 'posting_key_hash' => $keyHash, 'posted_by' => (int) $actor->getKey(), 'posted_at' => CarbonImmutable::now('UTC'), 'updated_by' => (int) $actor->getKey()])->save();
            // Adapter validates reviewed binding/profile identity. Orchestrator enforces activation, period and balanced posting.
            $result = $this->orchestrator->execute($actor, $companyId, 'cash_transfer', $id, 'posted', $keyHash);
            if ($result['mode'] !== 'recorded' || count($result['effect_ids']) !== 1) {
                $this->fail('A new transfer must record exactly one financial effect.');
            }
            $effect = AccountingSourceEffect::query()->where('company_id', $companyId)->findOrFail($result['effect_ids'][0]);
            $journal = JournalEntry::query()->where('company_id', $companyId)->findOrFail((int) $effect->getAttribute('journal_entry_id'));
            $payload = $this->evidence->operationPayload($row, $effect, $journal);
            AccountingCashTransferOperation::query()->create(['company_id' => $companyId, 'cash_transfer_id' => $id, 'source_effect_id' => (int) $effect->getKey(), 'journal_entry_id' => (int) $journal->getKey(), 'actor_id' => (int) $actor->getKey(), 'key_hash' => $keyHash, 'request_revision' => $revision, 'contract_version' => AccountingCashTransferFinancialContract::VERSION, 'payload' => $payload, 'payload_hash' => $this->canonical->hash($payload), 'occurred_at' => $row->getAttribute('posted_at')]);
            $this->evidence->verify($companyId, $id);
            AccountingCashTransferAuditEvent::query()->create(['company_id' => $companyId, 'cash_transfer_id' => $id, 'actor_id' => (int) $actor->getKey(), 'action' => 'posted', 'reason' => null, 'before_payload' => ['status' => 'approved', 'revision' => $revision], 'after_payload' => ['status' => 'posted', 'revision' => $revision + 1, 'journal_id' => (int) $journal->getKey(), 'amount' => (string) $row->getAttribute('amount'), 'currency_code' => (string) $row->getAttribute('currency_code')], 'occurred_at' => CarbonImmutable::now('UTC')]);

            return $row;
        }, 3);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['cash_transfer' => $message]);
    }
}

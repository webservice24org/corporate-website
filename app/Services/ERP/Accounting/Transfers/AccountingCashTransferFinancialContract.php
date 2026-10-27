<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Transfers;

use App\Models\Accounting\AccountingCashTransfer;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\Source\AccountingPreparedSourceEvent;
use App\Services\ERP\Accounting\Source\AccountingSourceEffectPlan;
use Illuminate\Validation\ValidationException;

/** Pure reconstruction from persisted native intent. Never authorizes, posts, repairs or resolves current setup. */
final class AccountingCashTransferFinancialContract
{
    public const VERSION = 'cash-transfer-post.v1';

    public function __construct(private readonly AccountingCashTransferContract $contract, private readonly AccountingCashTransferIntent $intent, private readonly AccountingCanonicalPayload $canonical) {}

    public function event(AccountingCashTransfer $row): AccountingPreparedSourceEvent
    {
        $hash = $this->intent->hash($row);
        if ($row->getAttribute('status') !== 'posted' || ! $row->getAttribute('posted_at') instanceof \DateTimeInterface || (int) $row->getAttribute('posted_by') < 1 || (int) $row->getAttribute('revision') !== (int) $row->getAttribute('posting_revision') + 1 || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $row->getAttribute('posting_key_hash')) || ! hash_equals($hash, (string) $row->getAttribute('submitted_payload_hash')) || ! hash_equals($hash, (string) $row->getAttribute('approved_payload_hash')) || $row->getAttribute('submitted_at') === null || $row->getAttribute('approved_at') === null || (int) $row->getAttribute('submitted_by') < 1 || (int) $row->getAttribute('approved_by') < 1 || in_array((int) $row->getAttribute('approved_by'), [(int) $row->getAttribute('created_by'), (int) $row->getAttribute('last_edited_by'), (int) $row->getAttribute('submitted_by')], true) || $row->getAttribute('cancelled_at') !== null || $row->getAttribute('cancelled_by') !== null || $row->getAttribute('cancellation_reason') !== null) {
            $this->fail();
        }
        $source = $row->getAttribute('source_snapshot');
        $destination = $row->getAttribute('destination_snapshot');
        if (! is_array($source) || ! is_array($destination)) {
            $this->fail();
        }
        $plan = $this->contract->plan((int) $row->getAttribute('company_id'), (string) $row->getAttribute('currency_code'), (string) $row->getAttribute('amount'), $source, $destination);
        $lines = [];
        foreach ($plan['lines'] as $line) {
            // account_id in the plan is an identity assertion; the writer resolves role to the fixed GL.
            $lines[] = ['role' => $line['role'], 'cash_account_id' => $line['cash_account_id'], 'debit' => $line['debit'], 'credit' => $line['credit'], 'source_line_type' => 'cash_transfer', 'source_line_id' => (int) $row->getKey()];
        }
        $date = $row->getAttribute('document_date')->format('Y-m-d');
        // A single hash binds fixed identities and native posting metadata without exposing private purpose/notes.
        $proof = $this->canonical->hash(['contract' => self::VERSION, 'intent_hash' => $hash, 'source' => $source, 'destination' => $destination, 'key_hash' => (string) $row->getAttribute('posting_key_hash'), 'revision' => (int) $row->getAttribute('posting_revision'), 'posted_by' => (int) $row->getAttribute('posted_by'), 'posted_at' => $row->getAttribute('posted_at')->format('Y-m-d H:i:s')]);

        return new AccountingPreparedSourceEvent((int) $row->getAttribute('company_id'), 'cash_transfer', (int) $row->getKey(), 'posted', (string) $row->getAttribute('posting_key_hash'), (string) $row->getAttribute('currency_code'), $date, $date, $date, ['source_number' => (string) $row->getAttribute('number'), 'amount' => (string) $row->getAttribute('amount'), 'currency_code' => (string) $row->getAttribute('currency_code'), 'evidence_hash' => $proof], [new AccountingSourceEffectPlan('cash', $lines, ['gl_amount' => (string) $row->getAttribute('amount'), 'evidence_hash' => $proof])]);
    }

    /** @return array<string, mixed> */
    public function envelope(AccountingPreparedSourceEvent $event): array
    {
        return ['source_contract' => 'source.v1', 'company_id' => $event->companyId, 'source_kind' => $event->sourceKind, 'source_id' => $event->sourceId, 'event_kind' => $event->eventKind, 'event_token' => $event->eventToken, 'currency' => $event->currency, 'document_date' => $event->documentDate, 'accounting_date' => $event->accountingDate, 'origin_date' => $event->originDate, 'facts' => $event->facts, 'effect_proofs' => ['cash' => $event->effects[0]->proof], 'references' => []];
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['cash_transfer' => 'Persisted posted transfer intent or independent approval does not verify.']);
    }
}

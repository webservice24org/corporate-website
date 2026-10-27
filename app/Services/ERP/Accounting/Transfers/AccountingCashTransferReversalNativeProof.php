<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Transfers;

use App\Models\Accounting\AccountingCashTransferReversalRequest;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\Source\AccountingPreparedSourceEvent;
use App\Services\ERP\Accounting\Source\AccountingSourceEffectPlan;
use Illuminate\Validation\ValidationException;

/** Reconstructs persisted native authorization and original proof without reading any new reversal effect/journal. */
final class AccountingCashTransferReversalNativeProof
{
    public const VERSION = 'cash-transfer-reversal-post.v1';

    public function __construct(private readonly AccountingCashTransferReversalOriginal $original, private readonly AccountingCashTransferReversalIntent $intent, private readonly AccountingCashTransferReversalContract $contract, private readonly AccountingCanonicalPayload $canonical) {}

    /** @return array{request: AccountingCashTransferReversalRequest, original: array<string, mixed>, plan: array<string, mixed>} */
    public function verify(int $company, int $id): array
    {
        $row = AccountingCashTransferReversalRequest::query()->where('company_id', $company)->findOrFail($id);
        $hash = $this->intent->hash($row);
        if ($row->getAttribute('status') !== 'posted' || (int) $row->getAttribute('posting_revision') < 3
            || (int) $row->getAttribute('revision') !== (int) $row->getAttribute('posting_revision') + 1
            || (int) $row->getAttribute('active_transfer_id') !== (int) $row->getAttribute('original_transfer_id')
            || (int) $row->getAttribute('posted_by') < 1 || (int) $row->getAttribute('updated_by') !== (int) $row->getAttribute('posted_by')
            || ! $row->getAttribute('posted_at') instanceof \DateTimeInterface
            || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $row->getAttribute('posting_key_hash'))
            || (int) $row->getAttribute('submitted_by') < 1 || ! $row->getAttribute('submitted_at') instanceof \DateTimeInterface
            || ! $row->getAttribute('approved_at') instanceof \DateTimeInterface
            || ! $this->contract->independent((int) $row->getAttribute('approved_by'), (int) $row->getAttribute('created_by'), (int) $row->getAttribute('last_edited_by'), (int) $row->getAttribute('submitted_by'))
            || ! hash_equals($hash, (string) $row->getAttribute('submitted_payload_hash')) || ! hash_equals($hash, (string) $row->getAttribute('approved_payload_hash'))
            || $row->getAttribute('cancelled_by') !== null || $row->getAttribute('cancelled_at') !== null || $row->getAttribute('cancellation_reason') !== null) {
            $this->fail();
        }
        $other = AccountingCashTransferReversalRequest::query()->where('company_id', $company)->where('original_transfer_id', $row->getAttribute('original_transfer_id'))->where('id', '<>', $id)
            ->where(function ($query): void {
                $query->where('status', '<>', 'cancelled')->orWhereNotNull('active_transfer_id');
            })->exists();
        if ($other) {
            $this->fail();
        }
        $original = $this->original->capture($company, (int) $row->getAttribute('original_transfer_id'));
        if (! hash_equals((string) $row->getAttribute('original_snapshot_hash'), $this->canonical->hash($original))) {
            $this->fail();
        }

        return ['request' => $row, 'original' => $original, 'plan' => $this->contract->plan($original, $row->getAttribute('document_date')->format('Y-m-d'))];
    }

    /** Internal value from verify() under the caller-owned company lock.
     * @param  array{request: AccountingCashTransferReversalRequest, original: array<string, mixed>, plan: array<string, mixed>}  $proof
     */
    public function event(array $proof): AccountingPreparedSourceEvent
    {
        $row = $proof['request'];
        $original = $proof['original'];
        $plan = $proof['plan'];
        $fingerprint = $this->canonical->hash(['contract' => self::VERSION, 'intent_hash' => $this->intent->hash($row), 'original_hash' => (string) $row->getAttribute('original_snapshot_hash'), 'key_hash' => (string) $row->getAttribute('posting_key_hash'), 'revision' => (int) $row->getAttribute('posting_revision'), 'actor_id' => (int) $row->getAttribute('posted_by'), 'posted_at' => $row->getAttribute('posted_at')->format('Y-m-d H:i:s')]);
        $lines = [];
        foreach ($plan['lines'] as $line) {
            $lines[] = ['role' => $line['role'], 'cash_account_id' => $line['cash_account_id'], 'debit' => $line['debit'], 'credit' => $line['credit'], 'source_line_type' => 'cash_transfer_reversal', 'source_line_id' => (int) $row->getKey()];
        }
        $date = $row->getAttribute('document_date')->format('Y-m-d');

        return new AccountingPreparedSourceEvent((int) $row->getAttribute('company_id'), 'cash_transfer_reversal', (int) $row->getKey(), 'posted', (string) $row->getAttribute('posting_key_hash'), (string) $row->getAttribute('currency_code'), $date, $date, $original['document_date'],
            ['source_number' => 'CTR-'.$row->getKey(), 'original_id' => (int) $row->getAttribute('original_transfer_id'), 'amount' => (string) $row->getAttribute('amount'), 'currency_code' => (string) $row->getAttribute('currency_code'), 'evidence_hash' => $fingerprint],
            [new AccountingSourceEffectPlan('cash', $lines, ['gl_amount' => (string) $row->getAttribute('amount'), 'evidence_hash' => $fingerprint])],
            [['source_kind' => 'cash_transfer', 'source_id' => (int) $row->getAttribute('original_transfer_id'), 'origin_date' => $original['document_date']]]);
    }

    /** @return array<string, mixed> */
    public function envelope(AccountingPreparedSourceEvent $event): array
    {
        return ['source_contract' => 'source.v1', 'company_id' => $event->companyId, 'source_kind' => $event->sourceKind, 'source_id' => $event->sourceId, 'event_kind' => $event->eventKind, 'event_token' => $event->eventToken, 'currency' => $event->currency, 'document_date' => $event->documentDate, 'accounting_date' => $event->accountingDate, 'origin_date' => $event->originDate, 'facts' => $event->facts, 'effect_proofs' => ['cash' => $event->effects[0]->proof], 'references' => $event->references];
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['cash_transfer_reversal' => 'Persisted reversal approval, posting identity or original evidence does not verify.']);
    }
}

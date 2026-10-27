<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Transfers;

use App\Models\Accounting\AccountingCashTransferReversalRequest;
use App\Services\ERP\Accounting\AccountingDecimal;
use Illuminate\Support\Facades\DB;

/** Storage invariants only. The scoped workflow owns authorization, locks and original evidence verification. */
final class AccountingCashTransferReversalModelGuard
{
    public function __construct(private readonly AccountingCashTransferReversalIntent $intent, private readonly AccountingCashTransferReversalContract $contract, private readonly AccountingDecimal $decimal) {}

    public function check(AccountingCashTransferReversalRequest $row): void
    {
        if (DB::transactionLevel() < 1) {
            $this->fail('Reversal changes require their native transaction.');
        }
        $attributes = $row->getAttributes();
        $rawAmount = $attributes['amount'] ?? null;
        // Same narrow SQLite hydration boundary as the corrected original transfer guard.
        if ($row->exists && is_float($rawAmount) && $rawAmount === $row->getRawOriginal('amount')) {
            $rawAmount = (string) $rawAmount;
        }
        $amount = $this->decimal->money($rawAmount);
        if (bccomp($amount, '0', 2) <= 0) {
            $this->fail('The original positive amount is required.');
        }
        $row->setAttribute('amount', $amount);
        foreach (['company_id', 'original_transfer_id', 'original_journal_id', 'source_cash_account_id', 'destination_cash_account_id', 'revision', 'created_by', 'updated_by', 'last_edited_by'] as $field) {
            $value = $attributes[$field] ?? null;
            if ((! is_int($value) && (! is_string($value) || ! ctype_digit($value))) || (int) $value < 1) {
                $this->fail('Invalid reversal identity/revision.');
            }
        }
        foreach (['original_snapshot_hash', 'creation_key_hash', 'creation_request_hash'] as $field) {
            if (! preg_match('/\A[a-f0-9]{64}\z/', (string) $row->getAttribute($field))) {
                $this->fail('Invalid reversal fingerprint.');
            }
        }
        $reason = (string) $row->getAttribute('reason');
        if (trim($reason) === '' || $reason !== trim($reason) || mb_strlen($reason) > 2000) {
            $this->fail('A trimmed reversal reason of up to 2,000 characters is required.');
        }
        $hash = $this->intent->hash($row);
        $state = (string) $row->getAttribute('status');
        if (! in_array($state, ['draft', 'submitted', 'approved', 'cancelled', 'posted'], true)) {
            $this->fail('Unsupported native reversal state.');
        }
        if ($state === 'cancelled') {
            if ($row->getAttribute('active_transfer_id') !== null) {
                $this->fail('Cancellation must release the active review slot.');
            }
        } else {
            $slot = $attributes['active_transfer_id'] ?? null;
            if ((! is_int($slot) && (! is_string($slot) || ! ctype_digit($slot))) || (int) $slot !== (int) $row->getAttribute('original_transfer_id')) {
                $this->fail('An active request must claim its original transfer slot.');
            }
        }
        if (! $row->exists) {
            if ($state !== 'draft' || (int) $row->getAttribute('revision') !== 1 || (int) $row->getAttribute('created_by') !== (int) $row->getAttribute('last_edited_by') || (int) $row->getAttribute('created_by') !== (int) $row->getAttribute('updated_by')) {
                $this->fail('A reversal starts as its maker-owned draft at revision one.');
            }
        } else {
            $old = (string) $row->getRawOriginal('status');
            $this->contract->transition($old, $state);
            if ((int) $row->getAttribute('revision') !== (int) $row->getRawOriginal('revision') + 1) {
                $this->fail('Increment the reversal revision exactly once.');
            }
            if ($row->isDirty(['company_id', 'original_transfer_id', 'original_journal_id', 'source_cash_account_id', 'destination_cash_account_id', 'currency_code', 'amount', 'original_snapshot', 'original_snapshot_hash', 'creation_key_hash', 'creation_request_hash', 'created_by', 'created_at'])) {
                $this->fail('Original transfer identities, amount and proof are immutable.');
            }
            if (! ($old === 'draft' && $state === 'draft')) {
                $allowed = ['status', 'revision', 'updated_by', 'updated_at'];
                $allowed = array_merge($allowed, match ($state) {
                    'submitted' => ['submitted_by', 'submitted_at', 'submitted_payload_hash'],
                    'approved' => ['approved_by', 'approved_at', 'approved_payload_hash'],
                    'posted' => ['posted_by', 'posted_at', 'posting_revision', 'posting_key_hash'],
                    'cancelled' => ['active_transfer_id', 'cancelled_by', 'cancelled_at', 'cancellation_reason'],
                    default => ['submitted_by', 'submitted_at', 'submitted_payload_hash', 'approved_by', 'approved_at', 'approved_payload_hash'],
                });
                if (array_diff(array_keys($row->getDirty()), $allowed) !== []) {
                    $this->fail('Submitted/approved reversal intent cannot change.');
                }
            } elseif ($row->isDirty('active_transfer_id')) {
                $this->fail('Editing a draft cannot change its active slot.');
            }
        }
        if ($state === 'draft') {
            foreach (['submitted_by', 'submitted_at', 'submitted_payload_hash', 'approved_by', 'approved_at', 'approved_payload_hash', 'cancelled_by', 'cancelled_at', 'cancellation_reason'] as $field) {
                if ($row->getAttribute($field) !== null) {
                    $this->fail('Draft review and cancellation metadata must be empty.');
                }
            }
        }
        if (in_array($state, ['submitted', 'approved', 'posted'], true)) {
            if ((int) $row->getAttribute('submitted_by') < 1 || ! $row->getAttribute('submitted_at') instanceof \DateTimeInterface || ! hash_equals($hash, (string) $row->getAttribute('submitted_payload_hash'))) {
                $this->fail('Submitted reversal intent does not verify.');
            }
            if ($row->getAttribute('cancelled_by') !== null || $row->getAttribute('cancelled_at') !== null || $row->getAttribute('cancellation_reason') !== null) {
                $this->fail('Active review cannot carry cancellation metadata.');
            }
            if ($state === 'submitted' && ($row->getAttribute('approved_by') !== null || $row->getAttribute('approved_at') !== null || $row->getAttribute('approved_payload_hash') !== null)) {
                $this->fail('Submitted reversals cannot carry approval stamps.');
            }
        }
        if (in_array($state, ['approved', 'posted'], true)) {
            if (! $this->contract->independent((int) $row->getAttribute('approved_by'), (int) $row->getAttribute('created_by'), (int) $row->getAttribute('last_edited_by'), (int) $row->getAttribute('submitted_by')) || ! $row->getAttribute('approved_at') instanceof \DateTimeInterface || ! hash_equals($hash, (string) $row->getAttribute('approved_payload_hash'))) {
                $this->fail('Reversal approval must be independent and match the submitted intent.');
            }
        }
        if ($state !== 'posted') {
            foreach (['posted_by', 'posted_at', 'posting_revision', 'posting_key_hash'] as $field) {
                if ($row->getAttribute($field) !== null) {
                    $this->fail('Unposted reversals cannot carry posting metadata.');
                }
            }
        } else {
            // New rows already require draft state above; this must be the approved transition.
            if ($row->getRawOriginal('status') !== 'approved'
                || (int) $row->getAttribute('posted_by') < 1
                || (int) $row->getAttribute('posted_by') !== (int) $row->getAttribute('updated_by')
                || ! $row->getAttribute('posted_at') instanceof \DateTimeInterface
                || (int) $row->getAttribute('posting_revision') !== (int) $row->getRawOriginal('revision')
                || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $row->getAttribute('posting_key_hash'))) {
                $this->fail('Posting metadata must identify the approved native reversal transition.');
            }
        }
        if ($state === 'cancelled' && ((int) $row->getAttribute('cancelled_by') < 1 || ! $row->getAttribute('cancelled_at') instanceof \DateTimeInterface || trim((string) $row->getAttribute('cancellation_reason')) === '' || mb_strlen((string) $row->getAttribute('cancellation_reason')) > 2000)) {
            $this->fail('Cancellation requires an actor, instant and reason.');
        }
    }

    private function fail(string $message): never
    {
        throw new \LogicException($message);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Transfers;

use App\Models\Accounting\AccountingCashTransfer;
use App\Services\ERP\Accounting\AccountingDecimal;
use Illuminate\Support\Facades\DB;

/** Storage invariants, not user authorization. Workflow service supplies scoped authorization and locks. */
final class AccountingCashTransferModelGuard
{
    public function __construct(private readonly AccountingCashTransferIntent $intent, private readonly AccountingDecimal $decimal) {}

    public function check(AccountingCashTransfer $row): void
    {
        if (DB::transactionLevel() < 1) {
            $this->fail('Transfer changes require a native transaction.');
        }
        $attributes = $row->getAttributes();
        $rawAmount = $attributes['amount'] ?? null;
        // SQLite NUMERIC hydration can return 0.01 / 25.25 as floats. Only an
        // unchanged persisted value may cross this database representation boundary.
        // Do not use isDirty(): decimal casts can consider '100.000' equivalent
        // to '100.00', hiding invalid newly supplied precision before validation.
        if ($row->exists && is_float($rawAmount) && $rawAmount === $row->getRawOriginal('amount')) {
            $rawAmount = (string) $rawAmount;
        }
        $amount = $this->decimal->money($rawAmount);
        if (bccomp($amount, '0', 2) <= 0) {
            $this->fail('Transfer amount must be positive.');
        }
        $row->setAttribute('amount', $amount);
        foreach (['company_id', 'source_cash_account_id', 'destination_cash_account_id', 'mapping_version_id', 'created_by', 'updated_by', 'last_edited_by', 'revision'] as $field) {
            $value = $attributes[$field] ?? null;
            if ((! is_int($value) && (! is_string($value) || ! ctype_digit($value))) || (int) $value < 1) {
                $this->fail('Invalid transfer identity or revision.');
            }
        }
        if (trim((string) $row->getAttribute('purpose')) === '' || mb_strlen((string) $row->getAttribute('purpose')) > 2000 || mb_strlen((string) $row->getAttribute('notes')) > 4000) {
            $this->fail('Transfer purpose/notes exceed their native limits.');
        }
        foreach (['mapping_hash', 'creation_key_hash', 'creation_request_hash'] as $field) {
            if (! preg_match('/\A[a-f0-9]{64}\z/', (string) $row->getAttribute($field))) {
                $this->fail('Transfer fingerprints are invalid.');
            }
        }
        $date = $row->getAttribute('document_date');
        if (! $date instanceof \DateTimeInterface || $date->format('Y-m-d') < '1900-01-01' || ! preg_match('/\ACT-'.$date->format('Y').'-[0-9]{6}\z/', (string) $row->getAttribute('number'))) {
            $this->fail('Transfer number/date identity is invalid.');
        }
        $hash = $this->intent->hash($row);
        $status = (string) $row->getAttribute('status');
        if (! in_array($status, ['draft', 'submitted', 'approved', 'cancelled', 'posted'], true)) {
            $this->fail('Unsupported transfer state.');
        }
        if (! $row->exists) {
            if ($status !== 'draft' || (int) $row->getAttribute('revision') !== 1 || (int) $row->getAttribute('created_by') !== (int) $row->getAttribute('last_edited_by') || (int) $row->getAttribute('created_by') !== (int) $row->getAttribute('updated_by')) {
                $this->fail('A new transfer starts as its maker-owned draft revision one.');
            }
        } else {
            $old = (string) $row->getRawOriginal('status');
            if (in_array($old, ['cancelled', 'posted'], true)) {
                $this->fail('Cancelled and posted transfers are immutable.');
            }
            if ($row->isDirty(['company_id', 'number', 'document_date', 'currency_code', 'creation_key_hash', 'creation_request_hash', 'created_by', 'created_at'])) {
                $this->fail('Numbered transfer identity cannot change.');
            }
            if ((int) $row->getAttribute('revision') !== (int) $row->getRawOriginal('revision') + 1) {
                $this->fail('Each transfer change increments its revision exactly once.');
            }
            $allowed = match ($old) {
                'draft' => ['draft', 'submitted', 'cancelled'], 'submitted' => ['draft', 'approved', 'cancelled'], 'approved' => ['cancelled', 'posted'], default => []
            };
            if (! in_array($status, $allowed, true)) {
                $this->fail('Unsupported transfer state transition.');
            }
            if (! ($old === 'draft' && $status === 'draft')) {
                $workflow = ['status', 'revision', 'updated_by', 'updated_at'];
                $workflow = array_merge($workflow, match ($status) {
                    'submitted' => ['submitted_by', 'submitted_at', 'submitted_payload_hash'],
                    'approved' => ['approved_by', 'approved_at', 'approved_payload_hash'],
                    'posted' => ['posted_by', 'posted_at', 'posting_revision', 'posting_key_hash'],
                    'cancelled' => ['cancelled_by', 'cancelled_at', 'cancellation_reason'],
                    default => ['submitted_by', 'submitted_at', 'submitted_payload_hash', 'approved_by', 'approved_at', 'approved_payload_hash'],
                });
                if (array_diff(array_keys($row->getDirty()), $workflow) !== []) {
                    $this->fail('Submitted/approved financial and private intent is immutable.');
                }
            }
        }
        if ($status === 'draft') {
            foreach (['submitted_by', 'submitted_at', 'submitted_payload_hash', 'approved_by', 'approved_at', 'approved_payload_hash', 'cancelled_by', 'cancelled_at', 'cancellation_reason'] as $field) {
                if ($row->getAttribute($field) !== null) {
                    $this->fail('Draft review/cancellation stamps must be cleared.');
                }
            }
        }
        if (in_array($status, ['submitted', 'approved', 'posted'], true)) {
            if ((int) $row->getAttribute('submitted_by') < 1 || $row->getAttribute('submitted_at') === null || ! hash_equals($hash, (string) $row->getAttribute('submitted_payload_hash'))) {
                $this->fail('Submitted transfer intent does not verify.');
            }
            if ($row->getAttribute('cancelled_at') !== null || $row->getAttribute('cancelled_by') !== null || $row->getAttribute('cancellation_reason') !== null) {
                $this->fail('A reviewed transfer cannot carry cancellation metadata.');
            }
            if ($status === 'submitted' && ($row->getAttribute('approved_by') !== null || $row->getAttribute('approved_at') !== null || $row->getAttribute('approved_payload_hash') !== null)) {
                $this->fail('Submitted transfers cannot carry approval stamps.');
            }
        }
        if (in_array($status, ['approved', 'posted'], true)) {
            $approver = (int) $row->getAttribute('approved_by');
            if ($approver < 1 || in_array($approver, [(int) $row->getAttribute('created_by'), (int) $row->getAttribute('last_edited_by'), (int) $row->getAttribute('submitted_by')], true) || $row->getAttribute('approved_at') === null || ! hash_equals($hash, (string) $row->getAttribute('approved_payload_hash'))) {
                $this->fail('Transfer approval must be independent and match the submitted intent.');
            }
        }
        if ($status !== 'posted') {
            foreach (['posted_by', 'posted_at', 'posting_revision', 'posting_key_hash'] as $field) {
                if ($row->getAttribute($field) !== null) {
                    $this->fail('Unposted transfers cannot carry posting metadata.');
                }
            }
        } else {
            // The new-row branch already rejects every status other than draft.
            if (
                $row->getRawOriginal('status') !== 'approved'
                || (int) $row->getAttribute('posted_by') !== (int) $row->getAttribute('updated_by')
                || (int) $row->getAttribute('posted_by') < 1
                || ! $row->getAttribute('posted_at') instanceof \DateTimeInterface
                || (int) $row->getAttribute('posting_revision') !== (int) $row->getRawOriginal('revision')
                || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $row->getAttribute('posting_key_hash'))
            ) {
                $this->fail('Posted metadata must identify the approved transition.');
            }
        }
        if ($status === 'cancelled' && ((int) $row->getAttribute('cancelled_by') < 1 || $row->getAttribute('cancelled_at') === null || trim((string) $row->getAttribute('cancellation_reason')) === '' || mb_strlen((string) $row->getAttribute('cancellation_reason')) > 2000)) {
            $this->fail('Cancellation requires actor, timestamp and reason.');
        }
    }

    private function fail(string $message): never
    {
        throw new \LogicException($message);
    }
}

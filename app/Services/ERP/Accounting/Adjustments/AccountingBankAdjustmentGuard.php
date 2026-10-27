<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Adjustments;

use App\Models\Accounting\AccountingBankAdjustment;
use App\Services\ERP\Accounting\AccountingDecimal;
use Illuminate\Support\Facades\DB;

final class AccountingBankAdjustmentGuard
{
    public function __construct(private readonly AccountingBankAdjustmentContract $contract, private readonly AccountingDecimal $decimal) {}

    public function check(AccountingBankAdjustment $row): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Bank adjustments require a company transaction.');
        }
        if ($row->exists && in_array($row->getRawOriginal('status'), ['posted', 'cancelled'], true)) {
            throw new \LogicException('Posted/cancelled bank adjustment history is immutable.');
        }
        $attributes = $row->getAttributes();
        $raw = $attributes['amount'] ?? null;
        if ($row->exists && is_float($raw) && $raw === $row->getRawOriginal('amount')) {
            $raw = (string) $raw;
        }
        $row->setAttribute('amount', $this->decimal->money($raw));
        $state = (string) $row->getAttribute('status');
        if (! $row->exists) {
            if ($state !== 'draft' || (int) $row->getAttribute('revision') !== 1 || (int) $row->getAttribute('created_by') !== (int) $row->getAttribute('last_edited_by') || (int) $row->getAttribute('created_by') !== (int) $row->getAttribute('updated_by')) {
                throw new \LogicException('Start bank adjustments as new native drafts.');
            }
        } else {
            $allowed = ['document_date', 'reason', 'revision', 'status', 'updated_by', 'updated_at', 'last_edited_by', 'submitted_by', 'submitted_at', 'submitted_hash', 'approved_by', 'approved_at', 'approved_hash', 'posted_by', 'posted_at', 'posting_revision', 'posting_key_hash', 'cancelled_by', 'cancelled_at', 'cancellation_reason', 'active_statement_line_id', 'active_reversal_of_id'];
            if (array_diff(array_keys($row->getDirty()), $allowed) !== [] || (int) $row->getAttribute('revision') !== (int) $row->getRawOriginal('revision') + 1) {
                throw new \LogicException('Bank adjustment identity/amount/accounts cannot be rewritten and revisions must advance once.');
            }
            $before = (string) $row->getRawOriginal('status');
            $transitions = ['draft' => ['draft', 'submitted', 'cancelled'], 'submitted' => ['draft', 'approved', 'cancelled'], 'approved' => ['posted', 'cancelled']];
            if (! in_array($state, $transitions[$before] ?? [], true) || ($before !== 'draft' && $row->isDirty(['document_date', 'reason', 'last_edited_by']))) {
                throw new \LogicException('Unsupported bank adjustment transition.');
            }
            if ($state === 'draft' && ($before === 'draft') && (int) $row->getAttribute('last_edited_by') !== (int) $row->getAttribute('updated_by')) {
                throw new \LogicException('Draft edits must identify the actual editor.');
            }
        }
        $this->contract->review($row);
        if ($state === 'cancelled') {
            if ($row->getAttribute('active_statement_line_id') !== null || $row->getAttribute('active_reversal_of_id') !== null || (int) $row->getAttribute('cancelled_by') < 1 || $row->getAttribute('cancelled_at') === null || trim((string) $row->getAttribute('cancellation_reason')) === '') {
                throw new \LogicException('Cancellation must release native slots and retain its reason/actor.');
            }
        } elseif ((int) $row->getAttribute('active_statement_line_id') !== (int) $row->getAttribute('statement_line_id') || ($row->getAttribute('reverses_id') === null ? $row->getAttribute('active_reversal_of_id') !== null : (int) $row->getAttribute('active_reversal_of_id') !== (int) $row->getAttribute('reverses_id')) || $row->getAttribute('cancelled_at') !== null || $row->getAttribute('cancelled_by') !== null || $row->getAttribute('cancellation_reason') !== null) {
            throw new \LogicException('Adjustment claim identities changed.');
        }
        if ($state === 'posted') {
            if ((int) $row->getAttribute('posted_by') < 1 || $row->getAttribute('posted_at') === null || (int) $row->getAttribute('posting_revision') !== (int) $row->getRawOriginal('revision') || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $row->getAttribute('posting_key_hash'))) {
                throw new \LogicException('Posting must preserve native revision and key evidence.');
            }
        } else {
            foreach (['posted_by', 'posted_at', 'posting_revision', 'posting_key_hash'] as $field) {
                if ($row->getAttribute($field) !== null) {
                    throw new \LogicException('Unposted adjustments cannot carry financial posting markers.');
                }
            }
        }
    }
}

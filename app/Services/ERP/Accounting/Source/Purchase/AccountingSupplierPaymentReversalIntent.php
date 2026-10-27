<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Purchase;

use App\Models\SupplierPayment;
use App\Models\SupplierPaymentReversalRequest;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Review intent deliberately excludes mutable workflow stamps and posting key. */
final class AccountingSupplierPaymentReversalIntent
{
    public function __construct(private readonly AccountingCanonicalPayload $canonical) {}

    public function hash(SupplierPaymentReversalRequest $row): string
    {
        $date = $row->getAttribute('document_date');
        if (! $date instanceof DateTimeInterface) {
            $this->fail('Reversal date is missing.');
        }
        $body = $row->only(['reason', 'original_payload_hash', 'history_hash']);
        foreach (['id', 'company_id', 'supplier_payment_id', 'original_revision', 'created_by', 'last_edited_by'] as $field) {
            $body[$field] = (int) $row->getAttribute($field);
        }
        $body['document_date'] = $date->format('Y-m-d');

        return $this->canonical->hash(['contract' => 'supplier-payment-reversal.v1', 'request' => $body]);
    }

    public function guard(SupplierPaymentReversalRequest $row): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Reversal request changes require their authorized caller transaction.');
        }
        $old = $row->exists ? SupplierPaymentReversalRequest::query()->findOrFail((int) $row->getKey()) : null;
        if ($old !== null) {
            if (in_array($old->getAttribute('status'), ['posted', 'cancelled'], true)) {
                throw new \LogicException('Posted/cancelled reversal requests are immutable.');
            }
            if ($old->getAttribute('status') !== $row->getRawOriginal('status') || (int) $old->getAttribute('revision') !== (int) $row->getRawOriginal('revision')) {
                $this->fail('Reload the reversal request after a concurrent change.');
            }
            if ($row->isDirty(['company_id', 'supplier_payment_id', 'created_by'])) {
                throw new \LogicException('Reversal request ownership cannot change.');
            }
            if ($old->getAttribute('status') !== 'draft' && $row->isDirty(['reason', 'document_date', 'original_revision', 'original_payload_hash', 'history_hash', 'last_edited_by'])) {
                throw new \LogicException('Submitted reversal intent is immutable.');
            }
            if ($row->getAttribute('status') !== 'draft' && in_array($old->getAttribute('status'), ['submitted', 'approved'], true) && $row->isDirty(['submitted_by', 'submitted_at', 'submitted_payload_hash'])) {
                throw new \LogicException('Reversal submission identity cannot be replaced.');
            }
            if ($old->getAttribute('status') === 'approved' && $row->isDirty(['approved_by', 'approved_at', 'approved_payload_hash'])) {
                throw new \LogicException('Reversal approval identity cannot be replaced.');
            }
        }
        $root = SupplierPayment::query()->where('company_id', $row->getAttribute('company_id'))->where('status', 'posted')->whereNull('reverses_payment_id')->findOrFail((int) $row->getAttribute('supplier_payment_id'));
        if (! in_array($row->getAttribute('status'), ['draft', 'submitted', 'approved', 'posted', 'cancelled'], true) || (int) $row->getAttribute('revision') < 1 || trim((string) $row->getAttribute('reason')) === '' || mb_strlen((string) $row->getAttribute('reason')) > 2000 || ! $row->getAttribute('document_date') instanceof DateTimeInterface) {
            $this->fail('Reversal review state, date or reason is invalid.');
        }
        foreach (['created_by', 'last_edited_by', 'updated_by'] as $field) {
            if ((int) $row->getAttribute($field) < 1) {
                $this->fail('Reversal maker/editor identity is required.');
            }
        }
        if ((int) $root->getAttribute('revision') !== (int) $row->getAttribute('original_revision') || $root->getAttribute('payload_hash') !== $row->getAttribute('original_payload_hash') || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $row->getAttribute('history_hash'))) {
            $this->fail('Reversal original payment identity differs.');
        }
        $state = $row->getAttribute('status');
        if ($state === 'draft') {
            foreach (['submitted_by', 'submitted_at', 'submitted_payload_hash', 'approved_by', 'approved_at', 'approved_payload_hash'] as $field) {
                if ($row->getAttribute($field) !== null) {
                    $this->fail('A reversal draft cannot retain review evidence.');
                }
            }
        }
        if (in_array($state, ['submitted', 'approved', 'posted'], true)) {
            $this->stamp($row, 'submitted');
            if ($row->getAttribute('submitted_payload_hash') !== $this->hash($row)) {
                $this->fail('Submitted reversal intent changed.');
            }
            if ($state === 'submitted' && ($row->getAttribute('approved_by') !== null || $row->getAttribute('approved_at') !== null || $row->getAttribute('approved_payload_hash') !== null)) {
                $this->fail('A submitted request cannot already contain approval.');
            }
            if (in_array($state, ['approved', 'posted'], true)) {
                $this->stamp($row, 'approved');
                if ($row->getAttribute('approved_payload_hash') !== $row->getAttribute('submitted_payload_hash') || in_array((int) $row->getAttribute('approved_by'), [(int) $row->getAttribute('created_by'), (int) $row->getAttribute('last_edited_by')], true)) {
                    $this->fail('Independent approval must cover the exact submitted reversal.');
                }
            }
        }
        if ($state === 'posted') {
            $this->stamp($row, 'posted');
            if ((int) $row->getAttribute('reversal_payment_id') < 1 || ! preg_match('/\A[A-Za-z0-9_-]{16,64}\z/', (string) $row->getAttribute('idempotency_key'))) {
                $this->fail('Posted reversal request needs its linked header and key.');
            }
        } elseif ($row->getAttribute('reversal_payment_id') !== null || $row->getAttribute('idempotency_key') !== null || $row->getAttribute('posted_by') !== null || $row->getAttribute('posted_at') !== null) {
            $this->fail('Unposted review documents cannot carry financial identity.');
        }
        if ($state !== 'cancelled' && ($row->getAttribute('cancelled_by') !== null || $row->getAttribute('cancelled_at') !== null || $row->getAttribute('cancellation_reason') !== null)) {
            $this->fail('Only a cancelled request can carry cancellation identity.');
        }
        if ($state === 'cancelled') {
            $this->stamp($row, 'cancelled');
            if (trim((string) $row->getAttribute('cancellation_reason')) === '') {
                $this->fail('Cancellation requires a reason.');
            }
        }
    }

    private function stamp(SupplierPaymentReversalRequest $row, string $action): void
    {
        if ((int) $row->getAttribute($action.'_by') < 1 || ! $row->getAttribute($action.'_at') instanceof DateTimeInterface) {
            $this->fail('Missing reversal workflow actor or time.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['supplier_payment_reversal' => $message]);
    }
}

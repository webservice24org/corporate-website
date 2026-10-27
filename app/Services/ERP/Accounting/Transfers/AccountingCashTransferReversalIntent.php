<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Transfers;

use App\Models\Accounting\AccountingCashTransferReversalRequest;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use Illuminate\Validation\ValidationException;

/** Immutable original proof and private reversal reason are bound to the maker/editor's reviewed intent. */
final class AccountingCashTransferReversalIntent
{
    public function __construct(private readonly AccountingCashTransferReversalContract $contract, private readonly AccountingCanonicalPayload $canonical) {}

    public function hash(AccountingCashTransferReversalRequest $row): string
    {
        $original = $row->getAttribute('original_snapshot');
        $date = $row->getAttribute('document_date');
        if (! is_array($original) || ! $date instanceof \DateTimeInterface) {
            $this->fail();
        }
        $plan = $this->contract->plan($original, $date->format('Y-m-d'));
        if (! hash_equals((string) $row->getAttribute('original_snapshot_hash'), $this->canonical->hash($original))
            || (int) $row->getAttribute('company_id') !== $original['company_id']
            || (int) $row->getAttribute('original_transfer_id') !== $original['transfer_id']
            || (int) $row->getAttribute('original_journal_id') !== $original['journal_id']
            || (int) $row->getAttribute('source_cash_account_id') !== $original['source']['id']
            || (int) $row->getAttribute('destination_cash_account_id') !== $original['destination']['id']
            || $row->getAttribute('currency_code') !== $plan['currency_code']
            || (string) $row->getAttribute('amount') !== $plan['amount']) {
            $this->fail();
        }

        return $this->canonical->hash([
            'contract' => AccountingCashTransferReversalContract::VERSION, 'original_hash' => $this->canonical->hash($original),
            'document_date' => $date->format('Y-m-d'), 'reason' => (string) $row->getAttribute('reason'),
            'created_by' => (int) $row->getAttribute('created_by'), 'last_edited_by' => (int) $row->getAttribute('last_edited_by'),
        ]);
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['cash_transfer_reversal' => 'Reversal header and original proof do not agree.']);
    }
}

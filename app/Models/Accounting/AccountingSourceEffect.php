<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use App\Services\ERP\Accounting\AccountingCanonicalPayload;

final class AccountingSourceEffect extends AccountingImmutableModel
{
    protected $table = 'accounting_source_effects';

    protected function casts(): array
    {
        return ['accounting_date' => 'date:Y-m-d', 'source_document_date' => 'date:Y-m-d', 'source_snapshot' => 'array'];
    }

    protected static function booted(): void
    {
        parent::booted();
        self::creating(static function (self $row): void {
            $outcome = $row->getAttribute('outcome');
            $journal = $row->getAttribute('journal_entry_id');
            $reason = $row->getAttribute('no_journal_reason');
            if (($outcome === 'posted' && ($journal === null || $reason !== null)) || ($outcome === 'verified_no_journal' && ($journal !== null || ! in_array($reason, ['zero_source_cost', 'nonstock_completion', 'same_customer_credit_transfer', 'billed_damaged_return', 'subcent_valuation', 'same_company_transfer', 'zero_commercial_value', 'zero_cost_release', 'zero_return_valuation'], true))) || ! in_array($outcome, ['posted', 'verified_no_journal'], true)) {
                throw new \InvalidArgumentException('Accounting source effect outcome and journal evidence disagree.');
            }
            foreach (['source_kind' => 40, 'event_kind' => 40, 'event_token' => 64, 'effect_kind' => 40] as $field => $length) {
                if (! preg_match('/\A[A-Za-z0-9_-]{1,'.$length.'}\z/', (string) $row->getAttribute($field))) {
                    throw new \InvalidArgumentException('Invalid accounting event identity.');
                }
            }
            if ((int) $row->getAttribute('source_id') < 1 || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $row->getAttribute('source_payload_hash'))) {
                throw new \InvalidArgumentException('Accounting source ID and hash are required.');
            }
            $snapshot = $row->getAttribute('source_snapshot');
            if (! is_array($snapshot)) {
                throw new \InvalidArgumentException('Accounting source evidence must be an array.');
            }
            app(AccountingCanonicalPayload::class)->hash($snapshot);
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source;

use App\Models\Customer;
use App\Services\ERP\Accounting\AccountingDecimal;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Validation\ValidationException;

final class AccountingNoJournalPolicy
{
    public function __construct(private readonly AccountingDecimal $decimal) {}

    /** @param list<string> $allowed */
    public function verify(int $companyId, AccountingSourceEffectPlan $effect, array $allowed): void
    {
        if ($effect->noJournalReason === null || ! in_array($effect->noJournalReason, $allowed, true) || $effect->lines !== [] || $this->decimal->money($effect->proof['gl_amount'] ?? null) !== '0.00') {
            $this->fail();
        }
        $valid = match ($effect->noJournalReason) {
            'zero_commercial_value' => $this->decimal->money($effect->proof['amount'] ?? null) === '0.00' && $this->decimal->money($effect->proof['net'] ?? null) === '0.00' && $this->decimal->money($effect->proof['tax'] ?? null) === '0.00',
            'zero_return_valuation' => $this->zeroReturn($companyId, $effect),
            'zero_cost_release' => $this->zeroRelease($effect),
            'same_company_transfer' => $this->transfer($companyId, $effect),
            'subcent_valuation' => $this->subcent($effect),
            'zero_source_cost' => $this->decimal->source($effect->proof['source_cost'] ?? null) === '0.0000',
            'nonstock_completion' => ($effect->proof['fulfillment_type'] ?? null) === 'nonstock',
            'same_customer_credit_transfer' => is_int($effect->proof['from_customer_id'] ?? null) && $effect->proof['from_customer_id'] > 0 && $effect->proof['from_customer_id'] === ($effect->proof['to_customer_id'] ?? null) && Customer::query()->withoutGlobalScope(SoftDeletingScope::class)->where('company_id', $companyId)->whereKey($effect->proof['from_customer_id'])->exists(),
            'billed_damaged_return' => ($effect->proof['billed'] ?? null) === true && ($effect->proof['original_cost_recognized'] ?? null) === true && $this->decimal->source($effect->proof['restocked_quantity'] ?? null) === '0.0000',
            default => false,
        };
        if (! $valid) {
            $this->fail();
        }
    }

    private function zeroReturn(int $companyId, AccountingSourceEffectPlan $effect): bool
    {
        $id = $effect->proof['source_line_id'] ?? null;
        if (! is_int($id) || $id < 1) {
            return false;
        }
        $prepared = app(Sales\AccountingSalesEvidence::class)->prepare($companyId, 'sales_return', $id);

        return ($effect->proof['evidence_hash'] ?? null) === ($prepared->effects[0]->proof['evidence_hash'] ?? null);
    }

    private function zeroRelease(AccountingSourceEffectPlan $effect): bool
    {
        $facts = $effect->proof['line_facts'] ?? null;
        if (! is_array($facts) || $facts === [] || count($facts) > 40 || ! is_string($effect->proof['evidence_hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $effect->proof['evidence_hash']) !== 1) {
            return false;
        }
        foreach ($facts as $fact) {
            if (! is_array($fact) || $this->decimal->money($fact['amount'] ?? null) !== '0.00') {
                return false;
            }
        }

        return true;
    }

    private function subcent(AccountingSourceEffectPlan $effect): bool
    {
        $facts = $effect->proof['line_facts'] ?? null;
        if (! is_array($facts) || $facts === [] || count($facts) > 40) {
            return false;
        }
        $total = '0.0000';
        foreach ($facts as $fact) {
            if (! is_array($fact)) {
                return false;
            }
            $cost = $this->decimal->source($fact['source_cost'] ?? null);
            if ($this->decimal->roundedCost($cost) !== '0.00') {
                return false;
            }
            $total = $this->decimal->source(bcadd($total, $cost, 4));
        }

        return $total === $this->decimal->source($effect->proof['source_cost'] ?? null);
    }

    private function transfer(int $companyId, AccountingSourceEffectPlan $effect): bool
    {
        $id = $effect->proof['source_line_id'] ?? null;
        if (! is_int($id) || $id < 1) {
            return false;
        }
        $event = app(Purchase\AccountingPurchaseEvidence::class)->prepare($companyId, 'stock_transfer', $id);

        return ($effect->proof['evidence_hash'] ?? null) === ($event->effects[0]->proof['evidence_hash'] ?? null);
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['no_journal' => 'This event lacks its registered zero-GL proof. Unknown or nonzero effects cannot be skipped.']);
    }
}

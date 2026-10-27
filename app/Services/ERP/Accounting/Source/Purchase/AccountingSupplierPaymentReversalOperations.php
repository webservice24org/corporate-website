<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Purchase;

use App\Models\Accounting\AccountingSupplierPaymentOperation;
use App\Models\Company;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\SupplierPaymentReversalRequest;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Reconstruct native reversal membership without calling financial verification or any writer. */
final class AccountingSupplierPaymentReversalOperations
{
    public function __construct(private readonly AccountingSupplierPaymentOperations $operations, private readonly AccountingSupplierPaymentChangeContract $contract, private readonly AccountingSupplierPaymentReversalIntent $intent, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingBusinessDate $dates) {}

    /** @return array<string, mixed> */
    public function verify(SupplierPayment $root, AccountingSupplierPaymentOperation $operation): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Reversal verification requires its native transaction.');
        }
        $body = $this->operations->body($operation);
        $members = $body['members'];
        $company = (int) $root->getAttribute('company_id');
        if ($body['company_id'] !== $company || $body['supplier_payment_id'] !== (int) $root->getKey() || $body['kind'] !== 'reverse' || $body['contract_version'] !== AccountingSupplierPaymentContract::VERSION || ! is_array($members) || ! array_is_list($members) || $members === [] || count($members) > 201 || ! hash_equals((string) $operation->getAttribute('payload_hash'), $this->canonical->hash($body))) {
            $this->fail('Full reversal manifest is damaged or unsupported.');
        }
        $reviews = SupplierPaymentReversalRequest::query()->where('company_id', $company)->where('supplier_payment_id', $root->getKey())->where('status', 'posted')->lockForUpdate()->get();
        if ($reviews->count() !== 1) {
            $this->fail('Full reversal has no unique posted independent review document.');
        } $review = $reviews->firstOrFail();
        $cash = SupplierPayment::query()->where('company_id', $company)->where('reverses_payment_id', $root->getKey())->where('status', 'posted')->lockForUpdate()->findOrFail((int) $review->getAttribute('reversal_payment_id'));
        $hash = $this->intent->hash($review);
        $key = (string) $review->getAttribute('idempotency_key');
        if ($review->getAttribute('submitted_payload_hash') !== $hash || $review->getAttribute('approved_payload_hash') !== $hash || $cash->getAttribute('payload_hash') !== $hash || $review->getAttribute('original_payload_hash') !== $root->getAttribute('payload_hash') || (int) $review->getAttribute('original_revision') !== (int) $root->getAttribute('revision') || $key !== $cash->getAttribute('idempotency_key') || ! preg_match('/\A[A-Za-z0-9_-]{16,64}\z/', $key) || ! hash_equals($body['key_hash'], hash('sha256', $key)) || (int) $review->getAttribute('posted_by') !== (int) $operation->getAttribute('created_by')) {
            $this->fail('Reversal approval, original or posting identity changed.');
        }
        if (in_array((int) $review->getAttribute('approved_by'), [(int) $review->getAttribute('created_by'), (int) $review->getAttribute('last_edited_by')], true)) {
            $this->fail('Reversal maker and last editor cannot approve their own request.');
        }
        foreach (['supplier_id', 'currency_code', 'amount', 'advance_amount', 'cash_account_id', 'payment_method'] as $field) {
            if ((string) $cash->getAttribute($field) !== (string) $root->getAttribute($field)) {
                $this->fail('Reversal cannot change original cash, supplier, currency or amount.');
            }
        }
        foreach (['created_by', 'last_edited_by', 'submitted_by', 'approved_by', 'posted_by'] as $field) {
            if ((int) $review->getAttribute($field) < 1 || (int) $cash->getAttribute($field) !== (int) $review->getAttribute($field)) {
                $this->fail('Reversal review actor changed.');
            }
        }
        foreach (['submitted_at', 'approved_at', 'posted_at'] as $field) {
            if (self::time($cash->getAttribute($field)) !== self::time($review->getAttribute($field))) {
                $this->fail('Reversal review timestamp changed.');
            }
        }
        if ($cash->getAttribute('document_date')->format('Y-m-d') !== $review->getAttribute('document_date')->format('Y-m-d') || $cash->getAttribute('reason') !== $review->getAttribute('reason') || $cash->getAttribute('submitted_payload_hash') !== $hash || $cash->getAttribute('approved_payload_hash') !== $hash || self::time($review->getAttribute('submitted_at')) > self::time($review->getAttribute('approved_at')) || self::time($review->getAttribute('approved_at')) > self::time($review->getAttribute('posted_at'))) {
            $this->fail('Reversal reviewed date, reason or chronology changed.');
        }
        $companyRow = Company::query()->findOrFail($company);
        if ($cash->getAttribute('document_date')->format('Y-m-d') !== $this->dates->fromInstant($companyRow, CarbonImmutable::parse(self::time($review->getAttribute('posted_at'))))) {
            $this->fail('Reversal cash date differs from its native company business date.');
        }
        foreach (['supplier_snapshot', 'issuer_snapshot'] as $field) {
            if ($this->canonical->hash(['value' => $cash->getAttribute($field)]) !== $this->canonical->hash(['value' => $root->getAttribute($field)])) {
                $this->fail('Reversal native issuer/supplier snapshot changed.');
            }
        }
        if ($cash->getAttribute('payment_reference') !== null || $cash->getAttribute('notes') !== null) {
            $this->fail('Reversal cash cannot introduce unreviewed private references.');
        }
        if (DB::table('supplier_payment_targets')->where('supplier_payment_id', $cash->getKey())->exists() || SupplierPaymentAllocation::query()->where('supplier_payment_id', $cash->getKey())->exists()) {
            $this->fail('Reversal headers cannot introduce payment targets or their own allocations.');
        }
        $allocationIds = [];
        $originalIds = [];
        $seen = [];
        foreach ($members as $ordinal => $member) {
            if (! is_array($member)) {
                $this->fail('Reversal member is malformed.');
            } $keys = array_keys($member);
            sort($keys);
            if ($keys !== ['event_kind', 'source_id', 'source_kind'] || ! is_int($member['source_id']) || $member['source_id'] < 1) {
                $this->fail('Reversal member identity is malformed.');
            }
            if ($ordinal === count($members) - 1) {
                if ($member['source_kind'] !== 'supplier_payment' || $member['source_id'] !== (int) $cash->getKey() || $member['event_kind'] !== 'reversed') {
                    $this->fail('Reversal cash must be the last exact member.');
                }

continue;
            }
            if ($member['source_kind'] !== 'supplier_payment_allocation' || $member['event_kind'] !== 'reversed' || isset($seen[$member['source_id']])) {
                $this->fail('Full reversal may contain only distinct compensations before cash.');
            }
            $row = SupplierPaymentAllocation::query()->where('company_id', $company)->where('supplier_payment_id', $root->getKey())->whereNotNull('reverses_allocation_id')->lockForUpdate()->findOrFail($member['source_id']);
            if ($row->getAttribute('idempotency_key') !== $key.':reverse:'.$ordinal || $row->getAttribute('payload_hash') !== $body['request_hash'] || (int) $row->getAttribute('posted_by') !== (int) $review->getAttribute('posted_by') || self::time($row->getAttribute('posted_at')) !== self::time($review->getAttribute('posted_at')) || $row->getAttribute('reason') !== $review->getAttribute('reason')) {
                $this->fail('Reversal compensation request, actor, time or key changed.');
            }
            $seen[$member['source_id']] = true;
            $allocationIds[] = $member['source_id'];
            $originalIds[] = (int) $row->getAttribute('reverses_allocation_id');
        }
        $all = $this->operations->history($root);
        $before = [];
        $after = [];
        foreach ($all as $row) {
            if ($allocationIds === [] || $row['id'] < min($allocationIds)) {
                $before[] = $row;
            } else {
                $after[] = $row['id'];
            }
        }
        if ($after !== $allocationIds) {
            $this->fail('Full reversal compensation segment is incomplete or later history exists.');
        }
        $plan = $this->contract->reversal($this->operations->root($root), $before, ['revision' => (int) $review->getAttribute('original_revision'), 'history_hash' => (string) $review->getAttribute('history_hash'), 'key' => $key, 'reason' => (string) $review->getAttribute('reason')]);
        if ($plan['compensate_ids'] !== $originalIds || ! hash_equals($body['request_hash'], $plan['request_hash'])) {
            $this->fail('Full reversal differs from the exact approved active-allocation history.');
        }
        $this->operations->position($root);
        foreach ($before as $row) {
            $prior = SupplierPaymentAllocation::query()->findOrFail($row['id']);
            if (self::time($prior->getAttribute('posted_at')) > self::time($review->getAttribute('posted_at'))) {
                $this->fail('Reversal cannot precede prior allocation history.');
            }
        }

        return ['body' => $body, 'plan' => $plan, 'review' => $review, 'cash' => $cash, 'history_before' => $before];
    }

    /** @return array<string, mixed> */
    public function forPayment(int $company, int $rootId): array
    {
        $root = SupplierPayment::query()->where('company_id', $company)->whereNull('reverses_payment_id')->findOrFail($rootId);
        $ops = AccountingSupplierPaymentOperation::query()->where('company_id', $company)->where('supplier_payment_id', $rootId)->where('kind', 'reverse')->lockForUpdate()->get();
        if ($ops->count() !== 1) {
            $this->fail('Full reversal has no unique complete operation manifest.');
        }

        return $this->verify($root, $ops->firstOrFail());
    }

    /** @return array<string, mixed> */
    public function allocation(SupplierPayment $root, int $id): array
    {
        $proof = $this->forPayment((int) $root->getAttribute('company_id'), (int) $root->getKey());
        $found = 0;
        foreach ($proof['body']['members'] as $member) {
            if ($member['source_kind'] === 'supplier_payment_allocation' && $member['source_id'] === $id) {
                $found++;
            }
        }
        if ($found !== 1) {
            $this->fail('Compensation does not belong to the complete reversal batch.');
        }

        return ['operation_hash' => $this->canonical->hash($proof['body']), 'history_hash' => $proof['plan']['history_hash'], 'reversal_request_id' => (int) $proof['review']->getKey()];
    }

    public static function time(mixed $value): string
    {
        if (! $value instanceof DateTimeInterface) {
            throw ValidationException::withMessages(['supplier_payment_reversal' => 'Native reversal event time is missing.']);
        }

return $value->format('Y-m-d\TH:i:sP');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['supplier_payment_reversal' => $message]);
    }
}

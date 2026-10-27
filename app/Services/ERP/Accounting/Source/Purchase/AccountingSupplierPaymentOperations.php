<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Purchase;

use App\Models\Accounting\AccountingSupplierPaymentOperation;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Native operation reconstruction only. No financial recognition and no writer dependency. */
final class AccountingSupplierPaymentOperations
{
    public function __construct(private readonly AccountingSupplierPaymentChangeContract $contract, private readonly AccountingCanonicalPayload $canonical) {}

    /** @return array<string, mixed> */
    public function root(SupplierPayment $payment): array
    {
        return ['company_id' => (int) $payment->getAttribute('company_id'), 'payment_id' => (int) $payment->getKey(), 'supplier_id' => (int) $payment->getAttribute('supplier_id'), 'cash_account_id' => (int) $payment->getAttribute('cash_account_id'), 'currency_code' => (string) $payment->getAttribute('currency_code'), 'revision' => (int) $payment->getAttribute('revision'), 'amount' => (string) $payment->getAttribute('amount')];
    }

    /** @return list<array<string, mixed>> */
    public function history(SupplierPayment $payment): array
    {
        $this->transaction();
        $rows = [];
        foreach (SupplierPaymentAllocation::query()->where('supplier_payment_id', $payment->getKey())->orderBy('id')->lockForUpdate()->get() as $row) {
            $rows[] = ['id' => (int) $row->getKey(), 'company_id' => (int) $row->getAttribute('company_id'), 'supplier_payment_id' => (int) $row->getAttribute('supplier_payment_id'), 'supplier_bill_id' => (int) $row->getAttribute('supplier_bill_id'), 'supplier_payable_id' => (int) $row->getAttribute('supplier_payable_id'), 'amount' => (string) $row->getAttribute('amount'), 'reverses_allocation_id' => $row->getAttribute('reverses_allocation_id') === null ? null : (int) $row->getAttribute('reverses_allocation_id')];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function position(SupplierPayment $payment): array
    {
        return $this->contract->position($this->root($payment), $this->history($payment));
    }

    /** @return array<string, mixed> */
    public function body(AccountingSupplierPaymentOperation $operation): array
    {
        return ['company_id' => (int) $operation->getAttribute('company_id'), 'supplier_payment_id' => (int) $operation->getAttribute('supplier_payment_id'), 'kind' => (string) $operation->getAttribute('kind'), 'contract_version' => (string) $operation->getAttribute('contract_version'), 'key_hash' => (string) $operation->getAttribute('key_hash'), 'request_hash' => (string) $operation->getAttribute('request_hash'), 'members' => $operation->getAttribute('members')];
    }

    /** Rebuild every request field from immutable native members and the preceding native history.
     * @return array<string, mixed>
     */
    public function verifyChange(SupplierPayment $payment, AccountingSupplierPaymentOperation $operation): array
    {
        $this->transaction();
        $body = $this->body($operation);
        $members = $body['members'];
        if ($body['company_id'] !== (int) $payment->getAttribute('company_id') || $body['supplier_payment_id'] !== (int) $payment->getKey() || $body['kind'] !== 'change' || $body['contract_version'] !== AccountingSupplierPaymentContract::VERSION || ! is_array($members) || ! array_is_list($members) || $members === [] || count($members) > 200 || ! hash_equals((string) $operation->getAttribute('payload_hash'), $this->canonical->hash($body))) {
            $this->fail('Later payment operation envelope is damaged or unsupported.');
        }
        $ids = [];
        $compensate = [];
        $targets = [];
        $rows = [];
        $key = null;
        $reason = null;
        $instant = null;
        $seenNew = false;
        foreach ($members as $ordinal => $member) {
            if (! is_array($member)) {
                $this->fail('Operation member is malformed.');
            }
            $keys = array_keys($member);
            sort($keys);
            if ($keys !== ['event_kind', 'source_id', 'source_kind'] || $member['source_kind'] !== 'supplier_payment_allocation' || ! is_int($member['source_id']) || $member['source_id'] < 1 || isset($ids[$member['source_id']])) {
                $this->fail('Operation membership is invalid or duplicated.');
            }
            $row = SupplierPaymentAllocation::query()->where('company_id', $body['company_id'])->where('supplier_payment_id', $payment->getKey())->lockForUpdate()->findOrFail($member['source_id']);
            $original = $row->getAttribute('reverses_allocation_id');
            $event = $original === null ? 'created' : 'reversed';
            if ($member['event_kind'] !== $event || ! preg_match('/\A([A-Za-z0-9_-]{16,64}):change:([0-9]+)\z/', (string) $row->getAttribute('idempotency_key'), $match) || $match[2] !== (string) $ordinal) {
                $this->fail('Operation member order or native event key differs.');
            }
            $time = $row->getAttribute('posted_at');
            if (! $time instanceof DateTimeInterface) {
                $this->fail('Operation member has no event time.');
            }
            $stamp = $time->format('Y-m-d\TH:i:sP');
            if ($key !== null && ($key !== $match[1] || $reason !== trim((string) $row->getAttribute('reason')) || $instant !== $stamp)) {
                $this->fail('Operation members have conflicting key, reason or event time.');
            }
            $key = $match[1];
            $reason = trim((string) $row->getAttribute('reason'));
            $instant = $stamp;
            if ((int) $row->getAttribute('posted_by') !== (int) $operation->getAttribute('created_by') || (int) $operation->getAttribute('created_by') < 1 || $row->getAttribute('payload_hash') !== $body['request_hash'] || $row->getAttribute('posted_at') < $payment->getAttribute('posted_at')) {
                $this->fail('Operation native actor, request proof or chronology differs.');
            }
            if ($original !== null) {
                if ($seenNew) {
                    $this->fail('Compensations must precede new allocations.');
                } $compensate[] = (int) $original;
            } else {
                $seenNew = true;
                $targets[] = ['supplier_bill_id' => (int) $row->getAttribute('supplier_bill_id'), 'amount' => (string) $row->getAttribute('amount')];
            }
            $ids[$member['source_id']] = true;
            $rows[] = $row;
        }
        if (! hash_equals($body['key_hash'], hash('sha256', (string) $key))) {
            $this->fail('Operation key differs from its recorded digest.');
        }
        $orderedIds = array_keys($ids);
        $sortedIds = $orderedIds;
        sort($sortedIds, SORT_NUMERIC);
        if ($orderedIds !== $sortedIds) {
            $this->fail('Operation native member chronology changed.');
        }
        $all = $this->history($payment);
        $before = [];
        $segment = [];
        foreach ($all as $row) {
            if ($row['id'] < $sortedIds[0]) {
                $before[] = $row;
            } elseif ($row['id'] <= $sortedIds[count($sortedIds) - 1]) {
                $segment[] = $row['id'];
            }
        }
        if ($segment !== $sortedIds) {
            $this->fail('Operation does not contain its complete native history segment.');
        }
        $first = $rows[0]->getAttribute('posted_at');
        if (SupplierPaymentAllocation::query()->where('supplier_payment_id', $payment->getKey())->where('id', '<', $sortedIds[0])->where('posted_at', '>', $first)->lockForUpdate()->first(['id']) !== null) {
            $this->fail('Operation event time predates previous allocation history.');
        }
        $position = $this->contract->position($this->root($payment), $before);
        $request = ['revision' => $position['root']['revision'], 'history_hash' => $position['history_hash'], 'key' => (string) $key, 'reason' => (string) $reason, 'compensate_ids' => $compensate, 'targets' => $targets];
        $plan = $this->contract->change($this->root($payment), $before, $request);
        if ($plan['compensate_ids'] !== $compensate || $plan['targets'] !== $targets || ! hash_equals($body['request_hash'], $plan['request_hash'])) {
            $this->fail('Operation request or deterministic member ordering changed.');
        }

        return ['body' => $body, 'plan' => $plan, 'history_before' => $before, 'rows' => $rows];
    }

    /** Locate exactly one native manifest, without calling a financial verifier recursively.
     * @return array<string, mixed>
     */
    public function allocation(SupplierPayment $payment, int $id): array
    {
        $matches = [];
        foreach (AccountingSupplierPaymentOperation::query()->where('company_id', $payment->getAttribute('company_id'))->where('supplier_payment_id', $payment->getKey())->where('kind', 'change')->lockForUpdate()->get() as $operation) {
            $members = $operation->getAttribute('members');
            if (! is_array($members)) {
                $this->fail('Later operation member envelope is malformed.');
            }
            foreach ($members as $member) {
                if (is_array($member) && ($member['source_kind'] ?? null) === 'supplier_payment_allocation' && ($member['source_id'] ?? null) === $id) {
                    $matches[] = $operation;
                }
            }
        }
        if (count($matches) !== 1) {
            $this->fail('Later native allocation has no unique complete operation manifest.');
        }
        $proof = $this->verifyChange($payment, $matches[0]);

        return ['operation_hash' => (string) $matches[0]->getAttribute('payload_hash'), 'history_hash' => $proof['plan']['history_hash']];
    }

    private function transaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Payment operation verification requires its caller transaction.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['supplier_payment' => $message]);
    }
}

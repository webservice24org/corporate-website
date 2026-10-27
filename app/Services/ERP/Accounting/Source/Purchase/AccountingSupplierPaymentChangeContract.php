<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Purchase;

use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingDecimal;
use Illuminate\Validation\ValidationException;

/** Pure operation intent and native-history arithmetic. This is never financial recognition or authorization. */
final class AccountingSupplierPaymentChangeContract
{
    public const VERSION = 'supplier-payment-change.v1';

    public const MAX_REVERSAL_ALLOCATIONS = 200;

    public function __construct(private readonly AccountingDecimal $decimal, private readonly AccountingCanonicalPayload $canonical) {}

    /** IDs here are normalized by the trusted reader, not accepted from a client as source proof.
     * @param  array<string, mixed>  $root
     * @param  array<array-key, array<string, mixed>>  $history
     * @return array<string, mixed>
     */
    public function position(array $root, array $history): array
    {
        $root = $this->root($root);
        if (! array_is_list($history)) {
            $this->fail('Native allocation history must be a list.');
        }
        $rows = [];
        $originals = [];
        $compensated = [];
        $payables = [];
        foreach ($history as $row) {
            $this->fields($row, ['id', 'company_id', 'supplier_payment_id', 'supplier_bill_id', 'supplier_payable_id', 'amount', 'reverses_allocation_id']);
            foreach (['id', 'company_id', 'supplier_payment_id', 'supplier_bill_id', 'supplier_payable_id'] as $field) {
                $this->id($row[$field]);
            }
            if ($row['company_id'] !== $root['company_id'] || $row['supplier_payment_id'] !== $root['payment_id'] || isset($rows[$row['id']])) {
                $this->fail('Native history has foreign or duplicate allocation identity.');
            }
            if ($row['reverses_allocation_id'] !== null) {
                $this->id($row['reverses_allocation_id']);
            }
            $amount = $this->positive($row['amount']);
            $bill = $row['supplier_bill_id'];
            $payable = $row['supplier_payable_id'];
            if ((isset($payables[$bill]) && $payables[$bill] !== $payable) || (in_array($payable, $payables, true) && ! isset($payables[$bill]))) {
                $this->fail('A bill and payable must retain one exact identity.');
            }
            $payables[$bill] = $payable;
            $normalized = ['id' => $row['id'], 'company_id' => $row['company_id'], 'supplier_payment_id' => $row['supplier_payment_id'], 'supplier_bill_id' => $bill, 'supplier_payable_id' => $payable, 'amount' => $amount, 'reverses_allocation_id' => $row['reverses_allocation_id']];
            $rows[$row['id']] = $normalized;
            if ($row['reverses_allocation_id'] === null) {
                $originals[$row['id']] = $normalized;
            }
        }
        ksort($rows, SORT_NUMERIC);
        ksort($originals, SORT_NUMERIC);
        foreach ($rows as $row) {
            $id = $row['reverses_allocation_id'];
            if ($id === null) {
                continue;
            }
            $original = $originals[$id] ?? null;
            if ($original === null || isset($compensated[$id]) || $row['id'] <= $id || $original['supplier_bill_id'] !== $row['supplier_bill_id'] || $original['supplier_payable_id'] !== $row['supplier_payable_id'] || $original['amount'] !== $row['amount']) {
                $this->fail('Compensation must exactly reverse one earlier original allocation once.');
            }
            $compensated[$id] = true;
        }
        // Check every chronological prefix. A later compensation cannot conceal an earlier overspend.
        $allocated = '0.00';
        foreach ($rows as $row) {
            $allocated = $row['reverses_allocation_id'] === null ? $this->decimal->total([$allocated, $row['amount']]) : bcsub($allocated, $row['amount'], 2);
            if (bccomp($allocated, '0', 2) < 0 || bccomp($allocated, $root['amount'], 2) > 0) {
                $this->fail('Allocation history spends more than the original payment or compensates unspent funds.');
            }
        }
        $active = [];
        foreach ($originals as $id => $row) {
            if (! isset($compensated[$id])) {
                $active[] = $row;
            }
        }
        // Hash a chronological chain so a long-lived payment does not exceed the canonical array-width limit.
        $chain = $this->canonical->hash(['contract_version' => self::VERSION, 'root' => $root]);
        foreach ($rows as $row) {
            $chain = $this->canonical->hash(['previous_hash' => $chain, 'allocation' => $row]);
        }
        $body = ['contract_version' => self::VERSION, 'root' => $root, 'chain_hash' => $chain, 'history_count' => count($rows)];

        return ['root' => $root, 'history_hash' => $this->canonical->hash($body), 'active' => $active, 'allocated' => $allocated, 'available' => bcsub($root['amount'], $allocated, 2), 'history_count' => count($rows)];
    }

    /** One atomic change may compensate selected originals, allocate available funds, or do both.
     * @param  array<string, mixed>  $root
     * @param  array<array-key, array<string, mixed>>  $history
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function change(array $root, array $history, array $request): array
    {
        $this->fields($request, ['revision', 'history_hash', 'key', 'reason', 'compensate_ids', 'targets']);
        $position = $this->position($root, $history);
        $identity = $this->request($position, $request);
        if (! is_array($request['compensate_ids']) || ! array_is_list($request['compensate_ids']) || count($request['compensate_ids']) > 100 || ! is_array($request['targets']) || ! array_is_list($request['targets']) || count($request['targets']) > 100) {
            $this->fail('Use lists of at most one hundred compensations and new bill targets.');
        }
        $active = [];
        foreach ($position['active'] as $row) {
            $active[$row['id']] = $row;
        }
        $ids = [];
        $compensations = [];
        $restored = '0.00';
        foreach ($request['compensate_ids'] as $id) {
            $this->id($id);
            if (isset($ids[$id]) || ! isset($active[$id])) {
                $this->fail('Compensation requires distinct, still-active original allocation IDs.');
            }
            $ids[$id] = true;
            $compensations[] = $active[$id];
            $restored = $this->decimal->total([$restored, $active[$id]['amount']]);
        }
        usort($compensations, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        $targets = [];
        $seen = [];
        $allocated = '0.00';
        foreach ($request['targets'] as $target) {
            if (! is_array($target)) {
                $this->fail('Each bill target must be an object with only bill ID and amount.');
            }
            $this->fields($target, ['supplier_bill_id', 'amount']);
            $this->id($target['supplier_bill_id']);
            $id = $target['supplier_bill_id'];
            if (isset($seen[$id])) {
                $this->fail('New bill targets must be distinct.');
            }
            $amount = $this->positive($target['amount']);
            $seen[$id] = true;
            $targets[] = ['supplier_bill_id' => $id, 'amount' => $amount];
            $allocated = $this->decimal->total([$allocated, $amount]);
        }
        usort($targets, static fn (array $a, array $b): int => $a['supplier_bill_id'] <=> $b['supplier_bill_id']);
        if ($targets === [] && $compensations === []) {
            $this->fail('An allocation change must contain at least one operation.');
        }
        $available = $this->decimal->total([$position['available'], $restored]);
        if (bccomp($allocated, $available, 2) > 0) {
            $this->fail('New allocations exceed original available funds plus exact compensations.');
        }
        $body = [...$identity, 'kind' => 'change', 'compensate_ids' => array_column($compensations, 'id'), 'targets' => $targets];

        return [...$body, 'request_hash' => $this->canonical->hash($body), 'compensations' => $compensations, 'restored' => $restored, 'new_allocated' => $allocated, 'available_after' => bcsub($available, $allocated, 2), 'cash_effects' => 0, 'required_permissions' => ['erp.supplier-payments.allocate']];
    }

    /** Full reversal compensates EVERY active original before reversing the original complete cash amount.
     * @param  array<string, mixed>  $root
     * @param  array<array-key, array<string, mixed>>  $history
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function reversal(array $root, array $history, array $request): array
    {
        $this->fields($request, ['revision', 'history_hash', 'key', 'reason']);
        $position = $this->position($root, $history);
        $identity = $this->request($position, $request);
        // The installed operation envelope permits 201 members: at most 200 compensations and one cash reversal.
        // Refuse the entire operation beyond that boundary; never return a partial cash refund.
        if (count($position['active']) > self::MAX_REVERSAL_ALLOCATIONS) {
            $this->fail('Full reversal exceeds the installed two-hundred-allocation operation boundary; no partial reversal is permitted.');
        }
        $body = [...$identity, 'kind' => 'reverse', 'compensate_ids' => array_column($position['active'], 'id')];

        return [...$body, 'request_hash' => $this->canonical->hash($body), 'compensations' => $position['active'], 'restored' => $position['allocated'], 'cash_amount' => $position['root']['amount'], 'cash_account_id' => $position['root']['cash_account_id'], 'available_after' => '0.00', 'cash_effects' => 1, 'required_permissions' => ['erp.supplier-payments.reverse']];
    }

    /** @param array<string, mixed> $position
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function request(array $position, array $request): array
    {
        $this->id($request['revision']);
        if ($request['revision'] !== $position['root']['revision'] || ! is_string($request['history_hash']) || ! preg_match('/\A[a-f0-9]{64}\z/', $request['history_hash']) || ! hash_equals($position['history_hash'], $request['history_hash'])) {
            $this->fail('Payment or allocation history changed; reload before a new operation.');
        }
        if (! is_string($request['key']) || ! preg_match('/\A[A-Za-z0-9_-]{16,64}\z/', $request['key'])) {
            $this->fail('Use an operation key of sixteen to sixty-four ASCII letters, digits, underscores or hyphens.');
        }
        if (! is_string($request['reason']) || trim($request['reason']) === '' || mb_strlen($request['reason']) > 2000) {
            $this->fail('Provide a reason of up to two thousand characters.');
        }

        return ['contract_version' => self::VERSION, 'company_id' => $position['root']['company_id'], 'supplier_payment_id' => $position['root']['payment_id'], 'revision' => $request['revision'], 'history_hash' => $position['history_hash'], 'key_hash' => hash('sha256', $request['key']), 'reason_hash' => hash('sha256', trim($request['reason']))];
    }

    /** @param array<string, mixed> $root
     * @return array<string, mixed>
     */
    private function root(array $root): array
    {
        $this->fields($root, ['company_id', 'payment_id', 'supplier_id', 'cash_account_id', 'currency_code', 'revision', 'amount']);
        foreach (['company_id', 'payment_id', 'supplier_id', 'cash_account_id', 'revision'] as $field) {
            $this->id($root[$field]);
        }
        if (! is_string($root['currency_code']) || ! preg_match('/\A[A-Z]{3}\z/', $root['currency_code'])) {
            $this->fail('Use the original payment currency identity.');
        }

        return ['company_id' => $root['company_id'], 'payment_id' => $root['payment_id'], 'supplier_id' => $root['supplier_id'], 'cash_account_id' => $root['cash_account_id'], 'currency_code' => $root['currency_code'], 'revision' => $root['revision'], 'amount' => $this->positive($root['amount'])];
    }

    private function positive(mixed $value): string
    {
        $amount = $this->decimal->money($value);
        if ($amount === '0.00') {
            $this->fail('Operation amounts must be positive exact decimals.');
        }

return $amount;
    }

    private function id(mixed $value): void
    {
        if (! is_int($value) || $value < 1) {
            $this->fail('Operation identities and revisions must be positive integers.');
        }
    }

    /** @param array<string, mixed> $data
     * @param  list<string>  $expected
     */
    private function fields(array $data, array $expected): void
    {
        $keys = array_keys($data);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            $this->fail('Operation fields are missing or contain unsupported input.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['supplier_payment' => $message]);
    }
}

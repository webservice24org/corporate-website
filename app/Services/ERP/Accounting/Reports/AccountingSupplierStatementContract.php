<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Services\ERP\Accounting\AccountingDecimal;
use Illuminate\Validation\ValidationException;

/** Exact reporting arithmetic. Trusted readers must prove source ownership/recognition before using it. */
final class AccountingSupplierStatementContract
{
    public const VERSION = 'supplier-statement.v1';

    public function __construct(private readonly AccountingDecimal $decimal) {}

    /** @return array{ap: string, advance: string, net: string, cash_paid: string, cash_reversed: string} */
    public function effect(string $event, mixed $amount): array
    {
        $value = $this->decimal->money($amount);
        $negative = bcsub('0.00', $value, 2);
        $zero = '0.00';
        [$ap, $advance, $paid, $reversed] = match ($event) {
            'bill_approved' => [$value, $zero, $zero, $zero], 'credit_issued' => [$negative, $zero, $zero, $zero],
            'cash_paid' => [$zero, $value, $value, $zero], 'allocated' => [$negative, $negative, $zero, $zero],
            'allocation_compensated' => [$value, $value, $zero, $zero], 'cash_reversed' => [$zero, $negative, $zero, $value],
            default => throw ValidationException::withMessages(['supplier_statement' => 'Unsupported supplier statement event.']),
        };

        return ['ap' => $ap, 'advance' => $advance, 'net' => bcsub($ap, $advance, 2), 'cash_paid' => $paid, 'cash_reversed' => $reversed];
    }

    /** @param array<array-key, array<string, mixed>> $events
     * @return array<string, mixed>
     */
    public function project(array $events, string $from, string $to, int $page = 1, int $perPage = 25, bool $allRows = false): array
    {
        $this->date($from);
        $this->date($to);
        if ($from > $to || $page < 1 || $perPage < 1 || $perPage > 100) {
            $this->fail('Use a valid inclusive date range and page size of one to one hundred.');
        }
        $events = $this->ordered($events);
        $parties = array_unique(array_column($events, 'supplier_id'));
        if (count($parties) > 1) {
            $this->fail('A supplier statement cannot mix parties.');
        }
        $opening = $this->empty();
        $closing = $this->empty();
        $activity = $this->empty();
        $rows = [];
        foreach ($events as $event) {
            if ($event['date'] > $to) {
                continue;
            } $effect = $this->effect($event['event'], $event['amount']);
            $closing = $this->add($closing, $effect);
            if ($event['date'] < $from) {
                $opening = $this->add($opening, $effect);

                continue;
            }
            $activity = $this->add($activity, $effect);
            $row = [];
            foreach (['source_kind', 'source_id', 'supplier_id', 'bill_id', 'payment_id', 'date', 'event', 'number', 'amount', 'source_url', 'journal_url'] as $field) {
                $row[$field] = $event[$field];
            }
            $rows[] = [...$row, 'ap_delta' => $effect['ap'], 'advance_delta' => $effect['advance'], 'net_delta' => $effect['net'], 'ap_balance' => $closing['ap'], 'advance_balance' => $closing['advance'], 'net_balance' => $closing['net']];
        }
        $total = count($rows);
        $last = max(1, (int) ceil($total / $perPage));

        return ['contract_version' => self::VERSION, 'from' => $from, 'to' => $to, 'opening' => $opening, 'activity' => $activity, 'closing' => $closing, 'rows' => $allRows ? $rows : array_slice($rows, ($page - 1) * $perPage, $perPage), 'pagination' => ['current_page' => $page, 'per_page' => $perPage, 'last_page' => $last, 'total' => $total]];
    }

    /** Full-result dated position, independent of statement pagination. Multiple suppliers in one company/currency are allowed.
     * @param  array<array-key, array<string, mixed>>  $events
     * @return array{ap: string, advance: string, net: string, cash_paid: string, cash_reversed: string}
     */
    public function position(array $events, string $asOf): array
    {
        $this->date($asOf);
        $total = $this->empty();
        foreach ($this->ordered($events) as $event) {
            if ($event['date'] <= $asOf) {
                $total = $this->add($total, $this->effect($event['event'], $event['amount']));
            }
        }

        return $total;
    }

    /** @param array<array-key, array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    public function ordered(array $events): array
    {
        if (! array_is_list($events)) {
            $this->fail('Statement events must be a list.');
        } $seen = [];
        $sequences = [];
        $scope = null;
        foreach ($events as &$event) {
            foreach (['company_id', 'supplier_id', 'source_id', 'sequence'] as $field) {
                if (! is_int($event[$field] ?? null) || $event[$field] < 1) {
                    $this->fail('Statement identities and sequence must be positive normalized integers.');
                }
            }
            foreach (['bill_id', 'payment_id'] as $field) {
                if (! array_key_exists($field, $event) || ($event[$field] !== null && (! is_int($event[$field]) || $event[$field] < 1))) {
                    $this->fail('Statement parent identity is invalid.');
                }
            }
            foreach (['source_url', 'journal_url'] as $field) {
                if (! array_key_exists($field, $event) || ($event[$field] !== null && ! is_string($event[$field]))) {
                    $this->fail('Statement link projection is invalid.');
                }
            }
            if (! is_string($event['currency'] ?? null) || ! preg_match('/\A[A-Z]{3}\z/', $event['currency']) || ! is_string($event['date'] ?? null) || ! is_string($event['event'] ?? null) || ! is_string($event['number'] ?? null) || ! in_array($event['source_kind'] ?? null, ['supplier_bill', 'supplier_credit', 'supplier_payment', 'supplier_payment_allocation'], true)) {
                $this->fail('Statement date, event, currency or source kind is invalid.');
            }
            $this->date($event['date']);
            $event['amount'] = $this->decimal->money($event['amount'] ?? null);
            $this->effect($event['event'], $event['amount']);
            $kind = match ($event['event']) {
                'bill_approved' => 'supplier_bill', 'credit_issued' => 'supplier_credit', 'cash_paid', 'cash_reversed' => 'supplier_payment', 'allocated', 'allocation_compensated' => 'supplier_payment_allocation', default => $this->fail('Unsupported statement event.')
            };
            if ($event['source_kind'] !== $kind || (in_array($kind, ['supplier_bill', 'supplier_credit', 'supplier_payment_allocation'], true) && $event['bill_id'] === null) || (in_array($kind, ['supplier_payment', 'supplier_payment_allocation'], true) && $event['payment_id'] === null) || ($kind === 'supplier_bill' && $event['bill_id'] !== $event['source_id'])) {
                $this->fail('Statement event and native parent identity disagree.');
            }
            $key = $event['company_id'].':'.$event['source_kind'].':'.$event['source_id'];
            $identity = $event['company_id'].':'.$event['currency'];
            if (($scope !== null && $scope !== $identity) || isset($seen[$key]) || isset($sequences[$event['sequence']])) {
                $this->fail('Statement cannot mix companies/currencies or duplicate a source/sequence.');
            }
            $scope = $identity;
            $seen[$key] = true;
            $sequences[$event['sequence']] = true;
        } unset($event);
        usort($events, static fn (array $a, array $b): int => [$a['date'], $a['sequence']] <=> [$b['date'], $b['sequence']]);

        return $events;
    }

    /** @return array{ap: string, advance: string, net: string, cash_paid: string, cash_reversed: string} */
    public function empty(): array
    {
        return ['ap' => '0.00', 'advance' => '0.00', 'net' => '0.00', 'cash_paid' => '0.00', 'cash_reversed' => '0.00'];
    }

    /** @param array<string, string> $a
     * @param  array<string, string>  $b
     * @return array{ap: string, advance: string, net: string, cash_paid: string, cash_reversed: string}
     */
    private function add(array $a, array $b): array
    {
        return ['ap' => bcadd($a['ap'], $b['ap'], 2), 'advance' => bcadd($a['advance'], $b['advance'], 2), 'net' => bcadd($a['net'], $b['net'], 2), 'cash_paid' => bcadd($a['cash_paid'], $b['cash_paid'], 2), 'cash_reversed' => bcadd($a['cash_reversed'], $b['cash_reversed'], 2)];
    }

    private function date(string $value): void
    {
        if (! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value) || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) || $value < '1900-01-01') {
            $this->fail('Use a real YYYY-MM-DD reporting date from 1900 onward.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['supplier_statement' => $message]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Services\ERP\Accounting\AccountingDecimal;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

/** Trusted admitted documents plus complete dated events. Never substitutes today's paid counters for history. */
final class AccountingAgingContract
{
    public function __construct(private readonly AccountingDecimal $decimal) {}

    /** @param array<array-key, array<string, mixed>> $documents @param array<array-key, array<string, mixed>> $events @return array<string, mixed> */
    public function project(int $company, string $currency, array $documents, array $events, string $asOf, string $today): array
    {
        $this->date($asOf);
        $this->date($today);
        if ($company < 1 || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1 || $asOf > $today || ! array_is_list($documents) || ! array_is_list($events) || count($documents) > 10000 || count($events) > 100000) {
            $this->fail('Use a bounded, single-company/currency dated snapshot.');
        }
        $index = [];
        $positions = [];
        $rows = [];
        $issues = [];
        $unknown = 0;
        foreach ($documents as $doc) {
            foreach (['id', 'company_id', 'party_id', 'currency', 'number', 'recognized_on', 'due_date', 'due_basis', 'amount', 'verified'] as $field) {
                if (! array_key_exists($field, $doc)) {
                    $this->fail('Normalized document fields are missing.');
                }
            }
            $id = $doc['id'] ?? null;
            if (! is_int($id) || $id < 1 || isset($index[$id]) || ($doc['company_id'] ?? null) !== $company || ($doc['currency'] ?? null) !== $currency || ! is_int($doc['party_id'] ?? null) || $doc['party_id'] < 1 || ! is_bool($doc['verified'] ?? null)) {
                $this->fail('Document ownership, identity or recognition state is invalid.');
            }
            if ($doc['recognized_on'] !== null) {
                $this->date($doc['recognized_on']);
            }
            if ($doc['due_date'] !== null) {
                $this->date($doc['due_date']);
            }
            if (! in_array($doc['due_basis'], ['admitted', 'current_native_terms', 'unknown'], true)) {
                $this->fail('The due-date evidence basis must be explicit.');
            }
            $index[$id] = $doc;
            $positions[$id] = $doc['verified'] ? $this->decimal->money($doc['amount']) : null;
        }
        foreach ($events as $event) {
            foreach (['id', 'document_id', 'company_id', 'currency', 'party_id', 'date', 'sequence', 'direction', 'amount', 'reverses_id'] as $field) {
                if (! array_key_exists($field, $event)) {
                    $this->fail('Normalized history event fields are missing.');
                }
            }
            $this->date($event['date']);
            if (! is_int($event['sequence']) || $event['sequence'] < 1 || ! is_string($event['id']) || ($event['reverses_id'] !== null && ! is_string($event['reverses_id']))) {
                $this->fail('Normalized event identity/sequence is required.');
            }
        }
        usort($events, static fn (array $a, array $b): int => [$a['date'], $a['sequence'], $a['id']] <=> [$b['date'], $b['sequence'], $b['id']]);
        $seen = [];
        $reversed = [];
        foreach ($events as $event) {
            $id = $event['id'];
            $docId = $event['document_id'] ?? null;

            if (
                preg_match('/\A[A-Za-z0-9_:-]{1,160}\z/', $id) !== 1
                || isset($seen[$id])
                || ! is_int($docId)
                || ! isset($index[$docId])
                || ($event['company_id'] ?? null) !== $company
                || ($event['currency'] ?? null) !== $currency
                || ($event['party_id'] ?? null) !== $index[$docId]['party_id']
                || ! in_array($event['direction'], ['increase', 'decrease'], true)
            ) {
                $this->fail(
                    'Dated event ownership, sequence, direction or membership is invalid.'
                );
            }
            $this->date($event['date']);
            $amount = $this->decimal->money($event['amount']);
            if ($amount === '0.00' || ($index[$docId]['recognized_on'] !== null && $event['date'] < $index[$docId]['recognized_on'])) {
                $this->fail('Events need positive exact amounts and cannot predate recognition.');
            }
            $parent = $event['reverses_id'];
            if ($parent !== null) {
                $original = $seen[$parent] ?? null;
                if ($original === null || isset($reversed[$parent]) || $original['reverses_id'] !== null || $original['document_id'] !== $docId || $original['amount'] !== $amount || $original['direction'] === $event['direction']) {
                    $this->fail('Compensation must exactly reverse one earlier original event in the same document.');
                }
                $reversed[$parent] = true;
            }
            $seen[$id] = [...$event, 'amount' => $amount];
            if ($event['date'] <= $asOf && $positions[$docId] !== null) {
                $positions[$docId] = $event['direction'] === 'increase' ? bcadd($positions[$docId], $amount, 2) : bcsub($positions[$docId], $amount, 2);
            }
        }
        $buckets = array_fill_keys(['current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_91_120', 'over_120', 'unknown_due'], '0.00');
        $open = '0.00';
        $credits = '0.00';
        $signed = '0.00';
        foreach ($index as $id => $doc) {
            if ($doc['recognized_on'] !== null && $doc['recognized_on'] > $asOf) {
                continue;
            }
            $value = $positions[$id];
            $bucket = null;
            $days = null;
            if ($value === null || $doc['recognized_on'] === null) {
                $unknown++;
                $issues[] = ['issue' => 'document_recognition_or_legacy_coverage_unverified', 'document_id' => $id];
                $value = null;
            } else {
                $signed = bcadd($signed, $value, 2);
                if (bccomp($value, '0', 2) < 0) {
                    $credits = bcsub($credits, $value, 2);
                } elseif (bccomp($value, '0', 2) > 0) {
                    $open = bcadd($open, $value, 2);
                    if ($doc['due_date'] === null) {
                        $bucket = 'unknown_due';
                        $issues[] = ['issue' => 'due_date_missing', 'document_id' => $id];
                    } else {
                        $days = (int) (new DateTimeImmutable($doc['due_date'].'T00:00:00+00:00'))->diff(new DateTimeImmutable($asOf.'T00:00:00+00:00'))->format('%r%a');
                        $bucket = match (true) {
                            $days <= 0 => 'current', $days <= 30 => 'days_1_30', $days <= 60 => 'days_31_60', $days <= 90 => 'days_61_90', $days <= 120 => 'days_91_120', default => 'over_120'
                        };
                    }
                    if ($doc['due_basis'] === 'unknown' || ($doc['due_basis'] === 'current_native_terms' && $asOf < $today)) {
                        $issues[] = ['issue' => 'historical_due_terms_unverified', 'document_id' => $id];
                    }
                    $buckets[$bucket] = bcadd($buckets[$bucket], $value, 2);
                }
            }
            $rows[] = ['document_id' => $id, 'party_id' => $doc['party_id'], 'number' => $doc['number'], 'recognized_on' => $doc['recognized_on'], 'due_date' => $doc['due_date'], 'due_basis' => $doc['due_basis'], 'days_overdue' => $days, 'bucket' => $bucket, 'outstanding' => $value, 'balance_kind' => $value === null ? 'unknown' : (bccomp($value, '0', 2) < 0 ? 'document_credit' : (bccomp($value, '0', 2) === 0 ? 'settled' : 'open'))];
        }
        $total = ['open_documents' => $open, 'document_credits' => $credits, 'signed_documents' => $signed, 'buckets' => $buckets, 'unknown_documents' => $unknown];

        return ['as_of' => $asOf, 'rows' => $rows, 'totals' => $total, 'confirmed_totals' => $issues === [] ? $total : null, 'complete' => $issues === [], 'issues' => $issues];
    }

    private function date(mixed $value): void
    {
        if (! is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value) !== 1 || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) || $value < '1900-01-01') {
            $this->fail('Use real reporting calendar dates from 1900 onward.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['aging' => $message]);
    }
}

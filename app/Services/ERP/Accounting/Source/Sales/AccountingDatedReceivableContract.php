<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Sales;

use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingDecimal;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

/**
 * Pure preparation contract, never recognition or posting authority.
 * Callers must supply complete native history; declared hashes are not verified here.
 * Cash recognition, invoice fingerprint verification and independently approved opening
 * evidence must be established by a future trusted adapter before this can fund settlement.
 */
final class AccountingDatedReceivableContract
{
    public function __construct(private readonly AccountingDecimal $decimal, private readonly AccountingCanonicalPayload $canonical) {}

    /**
     * Only cash allocations and their exact compensations are represented in this version.
     * Credit notes/transfers require a separate contract; callers must detect and reject them.
     * Timestamps are explicit-offset, second-precision instants. Cutover is local midnight,
     * exclusive: events exactly at midnight belong to the new accounting era.
     *
     * @param  array<string, mixed>  $invoice
     * @param  array<array-key, array<string, mixed>>  $events  Complete allocation history; list shape is validated below.
     * @return array<string, mixed>
     */
    public function project(array $invoice, array $events, string $cutover, string $timezone): array
    {
        $this->keys($invoice, ['company_id', 'invoice_id', 'customer_id', 'currency', 'document_date', 'issued_at', 'gross', 'payload_hash']);
        foreach (['company_id', 'invoice_id', 'customer_id'] as $field) {
            $this->id($invoice[$field]);
        }
        $this->currency($invoice['currency']);
        $this->digest($invoice['payload_hash']);
        $date = $this->date($invoice['document_date']);
        $issued = $this->instant($invoice['issued_at']);
        $cutover = $this->date($cutover);
        if (! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            $this->fail('A named company timezone is required.');
        }
        $zone = new DateTimeZone($timezone);
        $boundary = new DateTimeImmutable($cutover.' 00:00:00', $zone);
        // A skipped local date is not silently normalized into a different cutover day.
        if ($boundary->format('Y-m-d H:i:s') !== $cutover.' 00:00:00') {
            $this->fail('Cutover midnight does not exist in the company timezone.');
        }
        $gross = $this->decimal->money($invoice['gross']);
        if ($gross === '0.00' || $date >= $cutover || $issued >= $boundary || $issued->setTimezone($zone)->format('Y-m-d') < $date) {
            $this->fail('Positive original invoice evidence genuinely preceding cutover is required.');
        }
        if (! array_is_list($events) || count($events) > 1000) {
            $this->fail('Use a complete ordered list of at most one thousand history events.');
        }
        $normalized = [];
        $ids = [];
        $receipts = [];
        foreach ($events as $event) {
            $this->keys($event, ['id', 'invoice_id', 'company_id', 'customer_id', 'currency', 'amount', 'posted_at', 'reverses_id', 'payload_hash', 'receipt']);
            foreach (['id', 'invoice_id', 'company_id', 'customer_id'] as $field) {
                $this->id($event[$field]);
            }
            if (isset($ids[$event['id']])) {
                $this->fail('Duplicate allocation identity.');
            }
            $ids[$event['id']] = true;
            foreach (['company_id', 'customer_id', 'invoice_id', 'currency'] as $field) {
                if ($event[$field] !== $invoice[$field]) {
                    $this->fail('History must belong to the same company, customer, currency and invoice.');
                }
            }
            $this->digest($event['payload_hash']);
            $amount = $this->decimal->money($event['amount']);
            if ($amount === '0.00') {
                $this->fail('History amounts must be positive.');
            }
            if ($event['reverses_id'] !== null) {
                $this->id($event['reverses_id']);
            }
            if (! is_array($event['receipt'])) {
                $this->fail('Original receipt identity is required.');
            }
            $receipt = $event['receipt'];
            $this->keys($receipt, ['id', 'company_id', 'customer_id', 'currency', 'document_date', 'posted_at', 'amount', 'payload_hash']);
            foreach (['id', 'company_id', 'customer_id'] as $field) {
                $this->id($receipt[$field]);
            }
            foreach (['company_id', 'customer_id', 'currency'] as $field) {
                if ($receipt[$field] !== $invoice[$field]) {
                    $this->fail('Original receipt ownership or currency differs.');
                }
            }
            $this->digest($receipt['payload_hash']);
            $receiptDate = $this->date($receipt['document_date']);
            $cashTime = $this->instant($receipt['posted_at']);
            $time = $this->instant($event['posted_at']);
            $cash = $this->decimal->money($receipt['amount']);
            if ($cash === '0.00' || bccomp($amount, $cash, 2) > 0 || $time < $issued || $time < $cashTime || $receiptDate > $cashTime->setTimezone($zone)->format('Y-m-d')) {
                $this->fail('Allocation chronology or original receipt amount is invalid.');
            }
            $receipt = ['id' => $receipt['id'], 'company_id' => $receipt['company_id'], 'customer_id' => $receipt['customer_id'], 'currency' => $receipt['currency'], 'document_date' => $receiptDate, 'posted_at' => $cashTime->format('Y-m-d\TH:i:sP'), 'amount' => $cash, 'payload_hash' => $receipt['payload_hash']];
            $receiptHash = $this->canonical->hash($receipt);
            if (isset($receipts[$receipt['id']]) && $receipts[$receipt['id']] !== $receiptHash) {
                $this->fail('One receipt identity has conflicting original evidence.');
            }
            $receipts[$receipt['id']] = $receiptHash;
            $normalized[] = ['id' => $event['id'], 'amount' => $amount, 'posted_at' => $time->format('Y-m-d\TH:i:sP'), 'reverses_id' => $event['reverses_id'], 'payload_hash' => $event['payload_hash'], 'receipt' => $receipt, 'instant' => $time];
        }
        usort($normalized, static fn (array $a, array $b): int => ($a['instant'] <=> $b['instant']) ?: ($a['id'] <=> $b['id']));
        $active = [];
        $seen = [];
        $openingEvents = [];
        $settled = '0.00';
        $openingSettled = '0.00';
        $receiptUse = [];
        foreach ($normalized as $event) {
            $id = $event['id'];
            $reverse = $event['reverses_id'];
            $receiptId = $event['receipt']['id'];
            $amount = $event['amount'];
            if ($reverse === null) {
                $active[$id] = $event;
                $settled = bcadd($settled, $amount, 2);
                $receiptUse[$receiptId] = bcadd($receiptUse[$receiptId] ?? '0.00', $amount, 2);
                if (bccomp($receiptUse[$receiptId], $event['receipt']['amount'], 2) > 0) {
                    $this->fail('Allocations on this invoice exceed the original receipt cash.');
                }
            } else {
                if (! isset($seen[$reverse], $active[$reverse]) || $active[$reverse]['receipt']['id'] !== $receiptId || $active[$reverse]['amount'] !== $amount) {
                    $this->fail('Compensation must exactly reverse one earlier active allocation on the same receipt.');
                }
                unset($active[$reverse]);
                $settled = bcsub($settled, $amount, 2);
                $receiptUse[$receiptId] = bcsub($receiptUse[$receiptId], $amount, 2);
            }
            if (bccomp($settled, $gross, 2) > 0 || bccomp($settled, '0', 2) < 0) {
                $this->fail('Dated settlements exceed the original invoice or produce negative paid AR.');
            }
            $seen[$id] = true;
            if ($event['instant'] < $boundary) {
                $openingSettled = $settled;
                unset($event['instant']);
                $openingEvents[] = $event;
            }
        }
        $payload = ['contract' => 'legacy-ar-dated-preparation.v1', 'recognition_state' => 'unverified_preparation', 'cutover_date' => $cutover, 'cutover_timezone' => $timezone, 'boundary_utc' => $boundary->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP'), 'invoice' => ['company_id' => $invoice['company_id'], 'invoice_id' => $invoice['invoice_id'], 'customer_id' => $invoice['customer_id'], 'currency' => $invoice['currency'], 'document_date' => $date, 'issued_at' => $issued->format('Y-m-d\TH:i:sP'), 'gross' => $gross, 'payload_hash' => $invoice['payload_hash']], 'history' => $openingEvents, 'settled_at_cutover' => $openingSettled, 'opening_ar' => bcsub($gross, $openingSettled, 2)];

        return ['snapshot' => $payload, 'payload_hash' => $this->canonical->hash($payload)];
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $expected
     */
    private function keys(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            $this->fail('History contract fields are missing or unsupported.');
        }
    }

    private function id(mixed $value): void
    {
        if (! is_int($value) || $value < 1) {
            $this->fail('Positive integer source identities are required.');
        }
    }

    private function currency(mixed $value): void
    {
        if (! is_string($value) || ! preg_match('/\A[A-Z]{3}\z/', $value)) {
            $this->fail('A three-letter functional currency is required.');
        }
    }

    private function digest(mixed $value): void
    {
        if (! is_string($value) || ! preg_match('/\A[a-f0-9]{64}\z/', $value)) {
            $this->fail('Original payload digest is required.');
        }
    }

    private function date(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value) || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            $this->fail('An exact calendar date is required.');
        }

        return $value;
    }

    private function instant(mixed $value): DateTimeImmutable
    {
        if (! is_string($value) || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-](?:0[0-9]|1[0-4]):[0-5][0-9])\z/', $value)) {
            $this->fail('Explicit-offset second-precision history timestamps are required.');
        }
        $this->date(substr($value, 0, 10));
        $value = str_ends_with($value, 'Z') ? substr($value, 0, -1).'+00:00' : $value;
        if (preg_match('/[+-]14:(?!00)/', $value)) {
            $this->fail('Timestamp offset is outside its supported range.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        if ($date === false || $date->format('Y-m-d\TH:i:sP') !== $value) {
            $this->fail('History timestamp is not a real calendar instant.');
        }

        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['history' => $message]);
    }
}

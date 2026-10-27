<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source;

use App\Models\Accounting\AccountingReceiptOperation;
use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\Source\Sales\AccountingReceiptEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read-only receipt bundle verification. Never calls an orchestrator or repairs a missing manifest. */
final class AccountingReceiptManifestReader
{
    public function __construct(private readonly AccountingCanonicalPayload $canonical, private readonly AccountingReceiptEvidence $evidence) {}

    public function verify(int $company, int $receiptId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Receipt verification requires an authorized company transaction.');
        }
        $r = CustomerReceipt::query()->where('company_id', $company)->findOrFail($receiptId);
        $root = $r->getAttribute('reverses_receipt_id') === null ? (int) $r->getKey() : (int) $r->getAttribute('reverses_receipt_id');
        $original = CustomerReceipt::query()->where('company_id', $company)->whereNull('reverses_receipt_id')->where('status', 'posted')->findOrFail($root);
        $records = AccountingReceiptOperation::query()->where('company_id', $company)->where('customer_receipt_id', $root)->orderBy('id')->get();
        if ($records->isEmpty()) {
            $this->fail();
        }
        $seen = [];
        $postCount = 0;
        $reverseCount = 0;
        foreach ($records as $record) {
            $body = [];
            foreach (['company_id', 'customer_receipt_id', 'kind', 'key_hash', 'request_hash', 'members'] as $field) {
                $body[$field] = $record->getAttribute($field);
            }
            $body['company_id'] = (int) $body['company_id'];
            $body['customer_receipt_id'] = (int) $body['customer_receipt_id'];
            $members = $body['members'];
            if (! is_array($members) || ! array_is_list($members) || $members === [] || count($members) > 201 || ! in_array($body['kind'], ['post', 'change', 'reverse'], true) || ! hash_equals((string) $record->getAttribute('payload_hash'), $this->canonical->hash($body))) {
                $this->fail();
            }
            $kind = $body['kind'];
            $cashCount = 0;
            $allocationIndex = 0;
            if ($kind === 'post') {
                $postCount++;
            }
            if ($kind === 'reverse') {
                $reverseCount++;
            }
            foreach ($members as $index => $member) {
                if (! is_array($member) || array_diff(array_keys($member), ['source_kind', 'source_id', 'event_kind']) !== [] || ! is_string($member['source_kind'] ?? null) || ! is_int($member['source_id'] ?? null) || $member['source_id'] < 1 || ! is_string($member['event_kind'] ?? null)) {
                    $this->fail();
                }
                $key = $member['source_kind'].':'.$member['source_id'];
                if (isset($seen[$key])) {
                    $this->fail();
                } $seen[$key] = true;
                if ($member['source_kind'] === 'customer_receipt') {
                    $native = CustomerReceipt::query()->where('company_id', $company)->where('status', 'posted')->findOrFail($member['source_id']);
                    $cashCount++;
                    if ($kind === 'post') {
                        if ($index !== 0 || (int) $native->getKey() !== $root || $member['event_kind'] !== 'posted') {
                            $this->fail();
                        }
                    } elseif ($kind === 'reverse') {
                        if ($index !== count($members) - 1 || (int) $native->getAttribute('reverses_receipt_id') !== $root || $member['event_kind'] !== 'reversed') {
                            $this->fail();
                        }
                    } else {
                        $this->fail();
                    }
                    if (hash('sha256', (string) $native->getAttribute('idempotency_key')) !== $body['key_hash']) {
                        $this->fail();
                    }
                } elseif ($member['source_kind'] === 'customer_receipt_allocation') {
                    $native = CustomerReceiptAllocation::query()->where('company_id', $company)->where('customer_receipt_id', $root)->findOrFail($member['source_id']);
                    $reverse = $native->getAttribute('reverses_allocation_id') !== null;
                    if ($member['event_kind'] !== ($reverse ? 'reversed' : 'created') || ($kind === 'post' && $reverse) || ($kind === 'reverse' && ! $reverse)) {
                        $this->fail();
                    }
                    $nativeKey = (string) $native->getAttribute('idempotency_key');
                    if ($kind === 'post') {
                        $expectedKey = (string) $original->getAttribute('idempotency_key').':post:'.$allocationIndex;
                    } elseif ($kind === 'reverse') {
                        $expectedKey = 'rr:'.$root.':'.substr((string) $body['key_hash'], 0, 32).':'.$allocationIndex;
                    } else {
                        $suffix = ':change:'.$allocationIndex;
                        if (! str_ends_with($nativeKey, $suffix)) {
                            $this->fail();
                        }
                        $requestKey = substr($nativeKey, 0, -strlen($suffix));
                        if (hash('sha256', $requestKey) !== $body['key_hash']) {
                            $this->fail();
                        } $expectedKey = $requestKey.$suffix;
                    }
                    if ($nativeKey !== $expectedKey) {
                        $this->fail();
                    } $allocationIndex++;
                } else {
                    $this->fail();
                }
                if ($native->getAttribute('payload_hash') !== $body['request_hash']) {
                    $this->fail();
                }
                $this->evidence->recognized($company, $member['source_kind'], $member['source_id']);
            }
            if ($cashCount !== ($kind === 'change' ? 0 : 1)) {
                $this->fail();
            }
        }
        if ($postCount !== 1 || $reverseCount > 1) {
            $this->fail();
        }
        foreach (CustomerReceiptAllocation::query()->where('company_id', $company)->where('customer_receipt_id', $root)->get() as $allocation) {
            if (! isset($seen['customer_receipt_allocation:'.$allocation->getKey()])) {
                $this->fail();
            }
        }
        foreach (CustomerReceipt::query()->where('company_id', $company)->where('reverses_receipt_id', $root)->get() as $reversal) {
            if (! isset($seen['customer_receipt:'.$reversal->getKey()])) {
                $this->fail();
            }
        }
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['receipt_manifest' => 'Receipt operation manifests do not cover their complete native and financial history.']);
    }
}

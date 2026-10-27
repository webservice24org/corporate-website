<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Sales;

use App\Models\Accounting\AccountingReceiptOperation;
use App\Models\Accounting\AccountingSalesEvent;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntryLine;
use App\Models\Company;
use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\Source\AccountingSourceOrchestrator;
use App\Services\ERP\Accounting\Source\Purchase\AccountingPurchasePosting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** One immutable operation manifest covers every cash/settlement member of a native caller-owned transaction. */
final class AccountingReceiptPosting
{
    public function __construct(private readonly AccountingPurchasePosting $gate, private readonly AccountingReceiptEvidence $evidence, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingSourceOrchestrator $engine) {}

    /** @param array<string, mixed> $data */
    public function post(User $actor, CustomerReceipt $receipt, array $data, string $hash, bool $new): void
    {
        $members = [$this->member('customer_receipt', (int) $receipt->getKey(), 'posted')];
        $rows = $this->rows($receipt, (string) $data['idempotency_key'].':post', $hash, [], $data['allocations']);
        foreach ($rows as $row) {
            $members[] = $this->member('customer_receipt_allocation', (int) $row->getKey(), 'created');
        }
        $this->execute($actor, $receipt, 'post', (string) $data['idempotency_key'], $hash, $members, $new);
    }

    /** @param array<string, mixed> $data */
    public function change(User $actor, CustomerReceipt $receipt, array $data, string $hash, bool $new): void
    {
        $members = [];
        $rows = $this->rows($receipt, (string) $data['idempotency_key'].':change', $hash, $data['reverse_allocation_ids'], $data['allocations']);
        foreach ($rows as $row) {
            $members[] = $this->member('customer_receipt_allocation', (int) $row->getKey(), $row->getAttribute('reverses_allocation_id') === null ? 'created' : 'reversed');
        }
        $this->execute($actor, $receipt, 'change', (string) $data['idempotency_key'], $hash, $members, $new);
    }

    /** @param array<string, mixed> $data
     * @param  list<int>|null  $activeIds
     */
    public function reverse(User $actor, CustomerReceipt $original, CustomerReceipt $reversal, array $data, string $hash, ?array $activeIds, bool $new): void
    {
        if ($activeIds === null) {
            $record = AccountingReceiptOperation::query()->where('company_id', $original->getAttribute('company_id'))->where('customer_receipt_id', $original->getKey())->where('kind', 'reverse')->where('key_hash', hash('sha256', (string) $data['idempotency_key']))->first();
            if ($record === null) {
                $missingMembers = [$this->member('customer_receipt', (int) $reversal->getKey(), 'reversed')];
                $nativePrefix = 'rr:'.$original->getKey().':'.substr(hash('sha256', (string) $data['idempotency_key']), 0, 32).':';
                foreach (CustomerReceiptAllocation::query()->where('company_id', $original->getAttribute('company_id'))->where('customer_receipt_id', $original->getKey())->whereRaw('substr(idempotency_key, 1, ?) = ?', [strlen($nativePrefix), $nativePrefix])->get() as $row) {
                    $missingMembers[] = $this->member('customer_receipt_allocation', (int) $row->getKey(), 'reversed');
                }
                if ($this->hasEvidence((int) $original->getAttribute('company_id'), $missingMembers)) {
                    $this->fail('Recorded receipt-reversal finance is missing its original operation manifest.');
                }
                if (! $this->gate->before((int) $original->getAttribute('company_id'), true)) {
                    return;
                }
                $this->fail('Original receipt-reversal operation evidence is missing.');
            }
            $this->verifyManifest($record);
            $activeIds = [];
            foreach ($record->getAttribute('members') as $member) {
                if ($member['source_kind'] === 'customer_receipt_allocation') {
                    $row = CustomerReceiptAllocation::query()->where('company_id', $original->getAttribute('company_id'))->where('customer_receipt_id', $original->getKey())->findOrFail($member['source_id']);
                    $activeIds[] = (int) $row->getAttribute('reverses_allocation_id');
                }
            }
        }
        sort($activeIds, SORT_NUMERIC);
        $members = [];
        $prefix = 'rr:'.$original->getKey().':'.substr(hash('sha256', (string) $data['idempotency_key']), 0, 32);
        foreach ($this->rows($original, $prefix, $hash, $activeIds, []) as $row) {
            $members[] = $this->member('customer_receipt_allocation', (int) $row->getKey(), 'reversed');
        }
        $members[] = $this->member('customer_receipt', (int) $reversal->getKey(), 'reversed');
        $this->execute($actor, $original, 'reverse', (string) $data['idempotency_key'], $hash, $members, $new);
    }

    /** @param list<int> $undoIds
     * @param  list<array{sales_invoice_id: int, amount: string}>  $targets
     * @return list<CustomerReceiptAllocation>
     */
    private function rows(CustomerReceipt $receipt, string $prefix, string $hash, array $undoIds, array $targets): array
    {
        $expected = [];
        foreach ($undoIds as $id) {
            $original = CustomerReceiptAllocation::query()->where('company_id', $receipt->getAttribute('company_id'))->where('customer_receipt_id', $receipt->getKey())->whereNull('reverses_allocation_id')->findOrFail($id);
            $expected[] = ['invoice' => (int) $original->getAttribute('sales_invoice_id'), 'amount' => (string) $original->getAttribute('amount'), 'original' => $id];
        }
        foreach ($targets as $target) {
            $expected[] = ['invoice' => $target['sales_invoice_id'], 'amount' => $target['amount'], 'original' => null];
        }
        if (count($expected) > 200) {
            $this->fail('A finance operation supports at most 200 allocation/compensation rows.');
        }
        $prefix .= ':';
        $rows = CustomerReceiptAllocation::query()->where('company_id', $receipt->getAttribute('company_id'))->where('customer_receipt_id', $receipt->getKey())->whereRaw('substr(idempotency_key, 1, ?) = ?', [strlen($prefix), $prefix])->orderBy('id')->get();
        if ($rows->count() !== count($expected)) {
            $this->fail('Native receipt operation rows are missing or contain unexpected members.');
        }
        $result = [];
        foreach ($rows->values() as $index => $row) {
            $e = $expected[$index];
            if ($row->getAttribute('idempotency_key') !== $prefix.$index || $row->getAttribute('payload_hash') !== $hash || (int) $row->getAttribute('sales_invoice_id') !== $e['invoice'] || (string) $row->getAttribute('amount') !== $e['amount'] || ($row->getAttribute('reverses_allocation_id') === null ? null : (int) $row->getAttribute('reverses_allocation_id')) !== $e['original']) {
                $this->fail('Native operation membership, amount, lineage or request hash changed.');
            }
            $result[] = $row;
        }

        return $result;
    }

    /** @param list<array{source_kind: string, source_id: int, event_kind: string}> $members */
    private function execute(User $actor, CustomerReceipt $receipt, string $kind, string $key, string $hash, array $members, bool $new): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Receipt finance belongs inside the native writer transaction.');
        }
        $company = (int) $receipt->getAttribute('company_id');
        $receiptId = (int) $receipt->getKey();
        $record = AccountingReceiptOperation::query()->where('company_id', $company)->where('customer_receipt_id', $receiptId)->where('kind', $kind)->where('key_hash', hash('sha256', $key))->first();
        if ($record !== null) {
            $this->verifyManifest($record);
            if ($record->getAttribute('request_hash') !== $hash || ! $this->sameMembers($record->getAttribute('members'), $members)) {
                $this->fail('Receipt operation differs from its original immutable finance manifest.');
            }
            foreach ($members as $member) {
                // A fully missing event must not be silently reposted by the generic new-event path.
                $this->evidence->recognized($company, $member['source_kind'], $member['source_id']);
                $result = $this->engine->execute($actor, $company, $member['source_kind'], $member['source_id'], $member['event_kind'], 'original');
                if ($result['mode'] !== 'replayed') {
                    $this->fail('Receipt operation replay unexpectedly attempted new recognition.');
                }
            }

            return;
        }
        if ($this->hasEvidence($company, $members)) {
            $this->fail('Existing finance evidence is missing its original operation manifest; ordinary posting cannot repair it.');
        }
        if ($new && $kind !== 'post') {
            app(AccountingLegacyReceiptHistory::class)->assertReceiptMutationAllowed($company, $receiptId);
        }
        if (! $this->gate->before($company, true)) {
            return;
        }
        if (! $new || $members === [] || count($members) > 201) {
            $this->fail('A final native operation without its original complete financial manifest requires reconciliation.');
        }
        $newAllocationIds = [];
        foreach ($members as $member) {
            if (AccountingSalesEvent::query()->where('company_id', $company)->where('source_kind', $member['source_kind'])->where('source_id', $member['source_id'])->exists() || AccountingSourceEffect::query()->where('company_id', $company)->where('source_kind', $member['source_kind'])->where('source_id', $member['source_id'])->exists()) {
                $this->fail('An orphan or partial financial member exists; ordinary posting cannot repair it.');
            }
            if ($member['source_kind'] === 'customer_receipt_allocation') {
                $newAllocationIds[] = $member['source_id'];
            }
        }
        if ($kind !== 'post') {
            $this->evidence->recognized($company, 'customer_receipt', $receiptId);
        }
        foreach (CustomerReceiptAllocation::query()->where('company_id', $company)->where('customer_receipt_id', $receiptId)->whereNotIn('id', $newAllocationIds)->orderBy('id')->get() as $old) {
            $this->evidence->recognized($company, 'customer_receipt_allocation', (int) $old->getKey());
        }
        $legacy = app(AccountingLegacyReceivableCoverage::class);
        $checkedInvoices = [];
        foreach (CustomerReceiptAllocation::query()->where('company_id', $company)->whereIn('id', $newAllocationIds)->get() as $allocation) {
            $invoiceId = (int) $allocation->getAttribute('sales_invoice_id');
            if (isset($checkedInvoices[$invoiceId]) || ! $legacy->isLegacy($company, $invoiceId)) {
                continue;
            }
            $checkedInvoices[$invoiceId] = true;
            $legacy->assertHistory(Company::query()->findOrFail($company), $invoiceId, $newAllocationIds);
        }
        $body = ['company_id' => $company, 'customer_receipt_id' => $receiptId, 'kind' => $kind, 'key_hash' => hash('sha256', $key), 'request_hash' => $hash, 'members' => $members];
        AccountingReceiptOperation::query()->create([...$body, 'payload_hash' => $this->canonical->hash($body), 'created_by' => $actor->getKey()]);
        foreach ($members as $member) {
            $this->evidence->capture($actor, $company, $member['source_kind'], $member['source_id']);
            $result = $this->engine->execute($actor, $company, $member['source_kind'], $member['source_id'], $member['event_kind'], 'original');
            if ($result['mode'] !== 'recorded') {
                $this->fail('A new receipt operation failed to record its complete financial member.');
            }
        }
    }

    private function verifyManifest(AccountingReceiptOperation $record): void
    {
        $body = [];
        foreach (['company_id', 'customer_receipt_id', 'kind', 'key_hash', 'request_hash', 'members'] as $key) {
            $body[$key] = $record->getAttribute($key);
        }
        $body['company_id'] = (int) $body['company_id'];
        $body['customer_receipt_id'] = (int) $body['customer_receipt_id'];
        if (! is_array($body['members']) || ! array_is_list($body['members']) || $body['members'] === [] || count($body['members']) > 201 || ! hash_equals((string) $record->getAttribute('payload_hash'), $this->canonical->hash($body))) {
            $this->fail('Receipt operation finance manifest is damaged.');
        }
    }

    /** @return array{source_kind: string, source_id: int, event_kind: string} */
    private function member(string $kind, int $id, string $event): array
    {
        return ['source_kind' => $kind, 'source_id' => $id, 'event_kind' => $event];
    }

    /** @param list<array{source_kind: string, source_id: int, event_kind: string}> $members */
    private function hasEvidence(int $company, array $members): bool
    {
        foreach ($members as $member) {
            if (AccountingSalesEvent::query()->where('company_id', $company)->where('source_kind', $member['source_kind'])->where('source_id', $member['source_id'])->exists() || AccountingSourceEffect::query()->where('company_id', $company)->where('source_kind', $member['source_kind'])->where('source_id', $member['source_id'])->exists() || JournalEntryLine::query()->where('company_id', $company)->where('source_line_type', $member['source_kind'])->where('source_line_id', $member['source_id'])->exists()) {
                return true;
            }
        }

        return false;
    }

    private function sameMembers(mixed $left, mixed $right): bool
    {
        return hash_equals($this->canonical->hash(['members' => $left]), $this->canonical->hash(['members' => $right]));
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['accounting_receipt' => $message]);
    }
}

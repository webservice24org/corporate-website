<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Purchase;

use App\Models\Accounting\AccountingPurchaseEvent;
use App\Models\Accounting\AccountingSalesEvent;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\AccountingSupplierPaymentOperation;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\SupplierPaymentReversalRequest;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\Source\AccountingSourceOrchestrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Whole initial batch. Verification is read-only; an ordinary retry cannot recreate any missing member. */
final class AccountingSupplierPaymentPosting
{
    public function __construct(private readonly AccountingSupplierPaymentIntent $intent, private readonly AccountingSupplierPaymentEvidence $evidence, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingSourceOrchestrator $engine, private readonly AccountingSupplierPaymentOperations $operations) {}

    public function record(User $actor, SupplierPayment $payment): void
    {
        $this->transaction();
        $company = (int) $payment->getAttribute('company_id');
        $members = $this->members($payment);
        if (AccountingSupplierPaymentOperation::query()->where('company_id', $company)->where('supplier_payment_id', $payment->getKey())->exists()) {
            $this->fail('A new posting conflicts with existing operation evidence.');
        }
        foreach ($members as $member) {
            foreach ([AccountingPurchaseEvent::class, AccountingSalesEvent::class, AccountingSourceEffect::class] as $model) {
                if ($model::query()->where('source_kind', $member['source_kind'])->where('source_id', $member['source_id'])->exists()) {
                    $this->fail('Existing or partial financial member cannot be replaced by a new payment post.');
                }
            }
            if (DB::table('journal_entry_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->where('j.entry_kind', 'automatic')->where('l.source_line_type', $member['source_kind'])->where('l.source_line_id', $member['source_id'])->exists()) {
                $this->fail('Orphan automatic payment finance requires reconciliation.');
            }
        }
        $body = $this->body($payment, $members);
        AccountingSupplierPaymentOperation::query()->create([...$body, 'payload_hash' => $this->canonical->hash($body), 'created_by' => $actor->getKey()]);
        foreach ($members as $member) {
            $this->evidence->capture($actor, $company, $member['source_kind'], $member['source_id']);
            $result = $this->engine->execute($actor, $company, $member['source_kind'], $member['source_id'], $member['event_kind'], 'original');
            if ($result['mode'] !== 'recorded') {
                $this->fail('New supplier payment did not record its complete financial batch.');
            }
        }
        $this->verify($company, (int) $payment->getKey());
    }

    public function replay(User $actor, SupplierPayment $payment): void
    {
        $company = (int) $payment->getAttribute('company_id');
        $this->verify($company, (int) $payment->getKey());
        foreach ($this->members($payment) as $member) {
            $result = $this->engine->execute($actor, $company, $member['source_kind'], $member['source_id'], $member['event_kind'], 'original');
            if ($result['mode'] !== 'replayed') {
                $this->fail('Retry attempted new supplier payment recognition.');
            }
        }
    }

    private function verifyInitial(int $company, int $id): void
    {
        $this->transaction();
        $payment = SupplierPayment::query()->where('company_id', $company)->where('status', 'posted')->whereNull('reverses_payment_id')->findOrFail($id);
        $members = $this->members($payment);
        $body = $this->body($payment, $members);
        $records = AccountingSupplierPaymentOperation::query()->where('company_id', $company)->where('supplier_payment_id', $id)->where('kind', 'post')->lockForUpdate()->get();
        if ($records->count() !== 1) {
            $this->fail('Original complete payment operation manifest is missing or conflicting.');
        }
        $record = $records->firstOrFail();
        $actual = [];
        foreach (array_keys($body) as $key) {
            $actual[$key] = $record->getAttribute($key);
        } $actual['company_id'] = (int) $actual['company_id'];
        $actual['supplier_payment_id'] = (int) $actual['supplier_payment_id'];
        if (! hash_equals($this->canonical->hash($body), $this->canonical->hash($actual)) || ! hash_equals((string) $record->getAttribute('payload_hash'), $this->canonical->hash($actual))) {
            $this->fail('Payment operation member envelope changed.');
        }
        foreach ($members as $member) {
            $this->evidence->recognized($company, $member['source_kind'], $member['source_id']);
        }
    }

    /** Verify the original immutable post and every later complete operation, without repair. */
    public function verify(int $company, int $id): void
    {
        $this->verifyInitial($company, $id);
        $payment = SupplierPayment::query()->where('company_id', $company)->whereNull('reverses_payment_id')->findOrFail($id);
        if (AccountingSupplierPaymentOperation::query()->where('supplier_payment_id', $id)->where('company_id', '!=', $company)->exists()) {
            $this->fail('Payment operation history crosses company ownership.');
        }
        $covered = [];
        foreach ($this->members($payment) as $member) {
            if ($member['source_kind'] === 'supplier_payment_allocation') {
                $covered[$member['source_id']] = true;
            }
        }
        foreach (AccountingSupplierPaymentOperation::query()->where('supplier_payment_id', $id)->where('kind', '!=', 'post')->orderBy('id')->lockForUpdate()->get() as $operation) {
            $proof = $operation->getAttribute('kind') === 'reverse' ? app(AccountingSupplierPaymentReversalOperations::class)->verify($payment, $operation) : $this->operations->verifyChange($payment, $operation);
            foreach ($proof['body']['members'] as $member) {
                if ($member['source_kind'] === 'supplier_payment') {
                    $this->evidence->recognized($company, 'supplier_payment', $member['source_id']);

                    continue;
                }
                if (isset($covered[$member['source_id']])) {
                    $this->fail('A native allocation belongs to more than one financial operation.');
                }
                $covered[$member['source_id']] = true;
                $this->evidence->recognized($company, 'supplier_payment_allocation', $member['source_id']);
            }
        }
        $reversals = SupplierPayment::query()->where('reverses_payment_id', $id)->get();
        foreach ($reversals as $reversal) {
            $proof = app(AccountingSupplierPaymentReversalOperations::class)->forPayment($company, $id);
            if ((int) $proof['cash']->getKey() !== (int) $reversal->getKey()) {
                $this->fail('Native reversal header has no complete financial operation.');
            }
        }
        if (SupplierPaymentReversalRequest::query()->where('supplier_payment_id', $id)->where('status', 'posted')->count() !== $reversals->count()) {
            $this->fail('Reversal review and native cash-header evidence disagree.');
        }
        $cashIds = [];
        foreach ($reversals as $reversal) {
            $cashIds[(int) $reversal->getKey()] = true;
        }
        foreach (AccountingPurchaseEvent::query()->where('company_id', $company)->where('source_kind', 'supplier_payment')->get() as $admission) {
            $payload = $admission->getAttribute('payload');
            if (is_array($payload) && ($payload['projection']['payment_id'] ?? null) === $id && ! isset($cashIds[(int) $admission->getAttribute('source_id')])) {
                $this->fail('Orphan full-reversal cash admission requires reconciliation.');
            }
        }
        foreach (AccountingSourceEffect::query()->where('company_id', $company)->where('source_kind', 'supplier_payment')->get() as $effect) {
            $snapshot = $effect->getAttribute('source_snapshot');
            if (is_array($snapshot) && ($snapshot['evidence']['source']['facts']['original_id'] ?? null) === $id && ! isset($cashIds[(int) $effect->getAttribute('source_id')])) {
                $this->fail('Orphan full-reversal cash effect requires reconciliation.');
            }
        }
        $history = $this->operations->history($payment);
        foreach ($history as $row) {
            if (! isset($covered[$row['id']])) {
                $this->fail('Native allocation history has an unrecognized or missing operation manifest.');
            }
        }
        if (count($covered) !== count($history)) {
            $this->fail('Financial operation refers to missing native allocation history.');
        }
        $this->operations->position($payment);
        // Detect surviving admissions/effects whose native operation members have disappeared.
        foreach (AccountingPurchaseEvent::query()->where('company_id', $company)->where('source_kind', 'supplier_payment_allocation')->get() as $admission) {
            $payload = $admission->getAttribute('payload');
            if (is_array($payload) && ($payload['projection']['payment_id'] ?? null) === $id && ! isset($covered[(int) $admission->getAttribute('source_id')])) {
                $this->fail('Orphan supplier allocation admission cannot be repaired by retry.');
            }
        }
        foreach (AccountingSourceEffect::query()->where('company_id', $company)->where('source_kind', 'supplier_payment_allocation')->get() as $effect) {
            $snapshot = $effect->getAttribute('source_snapshot');
            if (is_array($snapshot) && ($snapshot['evidence']['source']['facts']['original_id'] ?? null) === $id && ! isset($covered[(int) $effect->getAttribute('source_id')])) {
                $this->fail('Orphan supplier allocation effect cannot be repaired by retry.');
            }
        }
    }

    public function recordChange(User $actor, SupplierPayment $payment, AccountingSupplierPaymentOperation $operation): void
    {
        $proof = $this->operations->verifyChange($payment, $operation);
        $company = (int) $payment->getAttribute('company_id');
        foreach ($proof['body']['members'] as $member) {
            foreach ([AccountingPurchaseEvent::class, AccountingSalesEvent::class, AccountingSourceEffect::class] as $model) {
                if ($model::query()->where('source_kind', $member['source_kind'])->where('source_id', $member['source_id'])->exists()) {
                    $this->fail('A new allocation operation cannot overwrite existing financial evidence.');
                }
            }
            if (DB::table('journal_entry_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->where('j.entry_kind', 'automatic')->where('l.source_line_type', $member['source_kind'])->where('l.source_line_id', $member['source_id'])->exists()) {
                $this->fail('Orphan allocation journal requires reconciliation.');
            }
        }
        foreach ($proof['body']['members'] as $member) {
            $this->evidence->capture($actor, $company, $member['source_kind'], $member['source_id']);
            $result = $this->engine->execute($actor, $company, $member['source_kind'], $member['source_id'], $member['event_kind'], 'original');
            if ($result['mode'] !== 'recorded') {
                $this->fail('New allocation operation did not record its complete financial batch.');
            }
        }
        $this->verify($company, (int) $payment->getKey());
    }

    public function replayChange(User $actor, SupplierPayment $payment, AccountingSupplierPaymentOperation $operation): void
    {
        $company = (int) $payment->getAttribute('company_id');
        $this->verify($company, (int) $payment->getKey());
        $proof = $this->operations->verifyChange($payment, $operation);
        foreach ($proof['body']['members'] as $member) {
            $result = $this->engine->execute($actor, $company, $member['source_kind'], $member['source_id'], $member['event_kind'], 'original');
            if ($result['mode'] !== 'replayed') {
                $this->fail('Allocation retry attempted new financial recognition.');
            }
        }
    }

    public function recordReverse(User $actor, SupplierPayment $payment, AccountingSupplierPaymentOperation $operation): void
    {
        $proof = app(AccountingSupplierPaymentReversalOperations::class)->verify($payment, $operation);
        $company = (int) $payment->getAttribute('company_id');
        foreach ($proof['body']['members'] as $member) {
            foreach ([AccountingPurchaseEvent::class, AccountingSalesEvent::class, AccountingSourceEffect::class] as $model) {
                if ($model::query()->where('source_kind', $member['source_kind'])->where('source_id', $member['source_id'])->exists()) {
                    $this->fail('New reversal conflicts with existing financial evidence.');
                }
            }
            if (DB::table('journal_entry_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->where('j.entry_kind', 'automatic')->where('l.source_line_type', $member['source_kind'])->where('l.source_line_id', $member['source_id'])->exists()) {
                $this->fail('Orphan reversal journal requires reconciliation.');
            }
        }
        foreach ($proof['body']['members'] as $member) {
            if ($member['source_kind'] === 'supplier_payment') {
                app(AccountingSupplierPaymentReversalEvidence::class)->capture($actor, $company, $member['source_id']);
            } else {
                $this->evidence->capture($actor, $company, $member['source_kind'], $member['source_id']);
            }
            $result = $this->engine->execute($actor, $company, $member['source_kind'], $member['source_id'], $member['event_kind'], 'original');
            if ($result['mode'] !== 'recorded') {
                $this->fail('Full reversal did not record its complete batch.');
            }
        }
        $this->verify($company, (int) $payment->getKey());
    }

    public function replayReverse(User $actor, SupplierPayment $payment, AccountingSupplierPaymentOperation $operation): void
    {
        $company = (int) $payment->getAttribute('company_id');
        $this->verify($company, (int) $payment->getKey());
        $proof = app(AccountingSupplierPaymentReversalOperations::class)->verify($payment, $operation);
        foreach ($proof['body']['members'] as $member) {
            $result = $this->engine->execute($actor, $company, $member['source_kind'], $member['source_id'], $member['event_kind'], 'original');
            if ($result['mode'] !== 'replayed') {
                $this->fail('Reversal retry attempted new financial recognition.');
            }
        }
    }

    /** @return list<array{source_kind: string, source_id: int, event_kind: string}> */
    private function members(SupplierPayment $payment): array
    {
        $targets = $this->intent->targets($payment);
        $hash = $this->intent->hash($payment);
        if ($payment->getAttribute('payload_hash') !== $hash || ! is_string($payment->getAttribute('idempotency_key'))) {
            $this->fail('Posted native payment no longer matches its approved original.');
        }
        $prefix = (string) $payment->getAttribute('idempotency_key').':post:';
        $rows = SupplierPaymentAllocation::query()->where('supplier_payment_id', $payment->getKey())->whereRaw('substr(idempotency_key, 1, ?) = ?', [strlen($prefix), $prefix])->orderBy('id')->limit(101)->get();
        if ($rows->count() !== count($targets)) {
            $this->fail('Original native payment batch is incomplete or has extra members.');
        }
        $members = [['source_kind' => 'supplier_payment', 'source_id' => (int) $payment->getKey(), 'event_kind' => 'posted']];
        foreach ($rows->values() as $index => $row) {
            $target = $targets[$index];
            if ((int) $row->getAttribute('company_id') !== (int) $payment->getAttribute('company_id') || $row->getAttribute('reverses_allocation_id') !== null || $row->getAttribute('idempotency_key') !== $prefix.$index || $row->getAttribute('payload_hash') !== $hash || (int) $row->getAttribute('supplier_bill_id') !== $target['supplier_bill_id'] || (int) $row->getAttribute('supplier_payable_id') !== $target['supplier_payable_id'] || (string) $row->getAttribute('amount') !== $target['amount']) {
                $this->fail('Original native allocation membership or amount differs.');
            }
            $members[] = ['source_kind' => 'supplier_payment_allocation', 'source_id' => (int) $row->getKey(), 'event_kind' => 'created'];
        }

        return $members;
    }

    /** @param list<array{source_kind: string, source_id: int, event_kind: string}> $members
     * @return array<string, mixed> */
    private function body(SupplierPayment $p, array $members): array
    {
        return ['company_id' => (int) $p->getAttribute('company_id'), 'supplier_payment_id' => (int) $p->getKey(), 'kind' => 'post', 'contract_version' => AccountingSupplierPaymentContract::VERSION, 'key_hash' => hash('sha256', (string) $p->getAttribute('idempotency_key')), 'request_hash' => (string) $p->getAttribute('payload_hash'), 'members' => $members];
    }

    private function transaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Payment finance belongs to its native caller-owned transaction.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['supplier_payment' => $message]);
    }
}

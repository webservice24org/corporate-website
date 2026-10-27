<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Purchase;

use App\Models\Accounting\AccountingPurchaseEvent;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Validation\ValidationException;

/** Cash reversal admission only; original payment evidence performs the common recorded-journal verification. */
final class AccountingSupplierPaymentReversalEvidence
{
    public function __construct(private readonly AccountingSupplierPaymentReversalOperations $operations, private readonly AccountingSupplierPaymentEvidence $originals, private readonly AccountingCanonicalPayload $canonical, private readonly PurchaseCompanyAccess $access) {}

    public function capture(User $actor, int $company, int $id): void
    {
        $this->access->authorize($actor, $company, 'erp.supplier-payments.reverse');
        $p = $this->projection($company, $id);
        $root = $this->originals->recognized($company, 'supplier_payment', $p['payment_id']);
        $lines = [['role' => $root['projection']['cash']['role'], 'debit' => $p['amount'], 'credit' => '0.00', 'party_type' => null, 'party_id' => null, 'cash_account_id' => $root['projection']['cash']['id'], 'source_line_type' => 'supplier_payment', 'source_line_id' => $id], ['role' => 'supplier_advances', 'debit' => '0.00', 'credit' => $p['amount'], 'party_type' => 'supplier', 'party_id' => $p['supplier_id'], 'cash_account_id' => null, 'source_line_type' => 'supplier_payment', 'source_line_id' => $id]];
        $payload = ['adapter_contract' => AccountingSupplierPaymentContract::VERSION, 'projection' => $p, 'date' => $p['date'], 'currency' => $p['currency'], 'references' => [['source_kind' => 'supplier_payment', 'source_id' => $p['payment_id'], 'origin_date' => $root['date']]], 'effect_kind' => 'cash', 'lines' => $lines];
        AccountingPurchaseEvent::query()->create(['company_id' => $company, 'source_kind' => 'supplier_payment', 'source_id' => $id, 'payload' => $payload, 'payload_hash' => $this->canonical->hash($payload), 'created_by' => $actor->getKey()]);
    }

    /** @return array<string, mixed> */
    public function projection(int $company, int $id): array
    {
        $cash = SupplierPayment::query()->where('company_id', $company)->where('status', 'posted')->whereNotNull('reverses_payment_id')->findOrFail($id);
        $rootId = (int) $cash->getAttribute('reverses_payment_id');
        $proof = $this->operations->forPayment($company, $rootId);
        $review = $proof['review'];
        if ((int) $proof['cash']->getKey() !== $id) {
            throw ValidationException::withMessages(['supplier_payment_reversal' => 'Cash reversal source differs from its complete batch.']);
        }
        $root = $this->originals->recognized($company, 'supplier_payment', $rootId);
        $date = $cash->getAttribute('document_date')->format('Y-m-d');
        if ($date < $root['date']) {
            throw ValidationException::withMessages(['supplier_payment_reversal' => 'Cash reversal cannot precede original cash.']);
        }
        $p = ['number' => (string) $cash->getAttribute('number'), 'payment_id' => $rootId, 'reversal_request_id' => (int) $review->getKey(), 'date' => $date, 'currency' => (string) $cash->getAttribute('currency_code'), 'amount' => (string) $cash->getAttribute('amount'), 'supplier_id' => (int) $cash->getAttribute('supplier_id'), 'cash' => $root['projection']['cash'], 'reversal' => true, 'review_intent_hash' => (string) $review->getAttribute('approved_payload_hash'), 'operation_hash' => $this->canonical->hash($proof['body']), 'original_admission_hash' => $this->canonical->hash($root), 'native_key_hash' => hash('sha256', (string) $cash->getAttribute('idempotency_key'))];
        foreach (['created_by', 'last_edited_by', 'submitted_by', 'approved_by', 'posted_by'] as $field) {
            $p[$field] = (int) $review->getAttribute($field);
        }
        foreach (['submitted_at', 'approved_at', 'posted_at'] as $field) {
            $p[$field] = AccountingSupplierPaymentReversalOperations::time($review->getAttribute($field));
        }

        return $p;
    }
}

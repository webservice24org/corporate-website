<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Books;

use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntry;
use App\Models\CustomerReceipt;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\ERP\Accounting\Adjustments\AccountingBankAdjustmentEvidence;
use App\Services\ERP\Accounting\Adjustments\AccountingBankAdjustmentProof;
use App\Services\ERP\Accounting\Banking\AccountingBankingWorkspace;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseFinanceEvidence;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseFinanceProof;
use App\Services\ERP\Accounting\Source\AccountingReceiptManifestReader;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentEvidence;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentPosting;
use App\Services\ERP\Accounting\Source\Sales\AccountingReceiptEvidence;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferEvidence;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferReversalEvidence;
use Illuminate\Validation\ValidationException;

/** Closed supported source set. Native verifiers only; no orchestrator, capture or replay path. */
final class AccountingCashBookSourceEvidence
{
    public function __construct(
        private readonly AccountingSupplierPaymentEvidence $payments,
        private readonly AccountingSupplierPaymentPosting $paymentOperations,
        private readonly AccountingReceiptEvidence $receipts,
        private readonly AccountingReceiptManifestReader $receiptOperations,
    ) {}

    /** @return array<string, mixed> */
    public function verify(User $actor, AccountingCashAccount $binding, JournalEntry $journal): array
    {
        $company = (int) $binding->getAttribute('company_id');
        if ($journal->getAttribute('entry_kind') !== 'automatic') {
            $this->fail('Opening/manual/other cash journals require a separately installed verification adapter.');
        }
        $effects = AccountingSourceEffect::query()->where('company_id', $company)->where('journal_entry_id', $journal->getKey())->get();
        if ($effects->count() !== 1) {
            $this->fail('Cash journal source effect identity is missing or ambiguous.');
        }
        $effect = $effects->firstOrFail();
        $kind = (string) $effect->getAttribute('source_kind');
        $id = (int) $effect->getAttribute('source_id');
        if ($kind === 'expense_finance') {
            $reader = app(AccountingExpenseFinanceEvidence::class);
            $proof = $reader->verify($company, $id);
            $s = $proof['plan']->getAttribute('snapshot');
            $reader->balance($company, $s['expense_id']);
            if ((int) $proof['journal']->getKey() !== (int) $journal->getKey() || $s['cash'] === null || $s['cash']['id'] !== (int) $binding->getKey() || $s['currency'] !== $binding->getAttribute('currency_code')) {
                $this->fail('Expense finance cash leg differs from its recorded operation.');
            }
            $cashLine = null;
            foreach (app(AccountingExpenseFinanceProof::class)->lines($proof['plan']) as $line) {
                if ($line['cash_account_id'] === (int) $binding->getKey()) {
                    $cashLine = $line;
                }
            }
            if ($cashLine === null) {
                $this->fail('Verified expense cash leg is missing.');
            }

            return ['source_kind' => $kind, 'source_id' => $id, 'event' => $s['kind'] === 'reversal' ? 'expense_compensated' : ($s['kind'] === 'recognition' ? 'expense_paid_now' : 'expense_payable_settled'), 'source_url' => $actor->can('erp.expense-finance.view') ? '/erp/accounting/expense-finance-workspace/'.$id : null, 'number' => 'EF-'.$id, 'debit' => $cashLine['debit'], 'credit' => $cashLine['credit']];
        }
        if ($effect->getAttribute('effect_kind') !== 'cash') {
            $this->fail('Only recognized cash source effects are supported.');
        }
        if ($kind === 'bank_adjustment') {
            $reader = app(AccountingBankAdjustmentEvidence::class);
            $proof = $reader->verify($company, $id);
            $reader->family($company, $id);
            $native = $proof['adjustment'];
            if ((int) $proof['journal']->getKey() !== (int) $journal->getKey() || (int) $native->getAttribute('cash_account_id') !== (int) $binding->getKey() || $native->getAttribute('currency_code') !== $binding->getAttribute('currency_code')) {
                $this->fail('Bank adjustment bank/journal identity differs.');
            }
            $line = app(AccountingBankAdjustmentProof::class)->lines($native)[0];

            return ['source_kind' => $kind, 'source_id' => $id, 'event' => $native->getAttribute('reverses_id') === null ? 'bank_'.$native->getAttribute('kind') : 'bank_adjustment_refund', 'source_url' => AccountingBankingWorkspace::mayRead($actor, 'adjustments') ? '/erp/accounting/bank-adjustments/'.$id : null, 'number' => 'BA-'.$id, 'debit' => $line['debit'], 'credit' => $line['credit']];
        }
        if ($kind === 'cash_transfer') {
            $proof = app(AccountingCashTransferEvidence::class)->verify($company, $id);
            app(AccountingCashTransferReversalEvidence::class)->verifyForOriginal($company, $id);
            $transfer = $proof['transfer'];
            if ((int) $proof['journal']->getKey() !== (int) $journal->getKey() || $transfer->getAttribute('currency_code') !== $binding->getAttribute('currency_code')) {
                $this->fail('Transfer journal/currency identity differs.');
            }
            $bindingId = (int) $binding->getKey();
            $incoming = (int) $transfer->getAttribute('destination_cash_account_id') === $bindingId;
            if (! $incoming && (int) $transfer->getAttribute('source_cash_account_id') !== $bindingId) {
                $this->fail('Selected binding is not a transfer endpoint.');
            }

            return ['source_kind' => $kind, 'source_id' => $id, 'event' => $incoming ? 'cash_transfer_received' : 'cash_transfer_sent', 'source_url' => $actor->can('erp.cash-transfers.view') ? '/erp/accounting/cash-transfers/'.$id : null, 'number' => (string) $transfer->getAttribute('number'), 'debit' => $incoming ? (string) $transfer->getAttribute('amount') : '0.00', 'credit' => $incoming ? '0.00' : (string) $transfer->getAttribute('amount')];
        }
        if ($kind === 'cash_transfer_reversal') {
            $proof = app(AccountingCashTransferReversalEvidence::class)->verify($company, $id);
            $request = $proof['request'];
            if ((int) $proof['journal']->getKey() !== (int) $journal->getKey() || $request->getAttribute('currency_code') !== $binding->getAttribute('currency_code')) {
                $this->fail('Reversal journal/currency identity differs.');
            }
            $incoming = (int) $request->getAttribute('source_cash_account_id') === (int) $binding->getKey();
            if (! $incoming && (int) $request->getAttribute('destination_cash_account_id') !== (int) $binding->getKey()) {
                $this->fail('Selected binding is not a reversal endpoint.');
            }

            return ['source_kind' => $kind, 'source_id' => $id, 'event' => $incoming ? 'cash_transfer_reversal_received' : 'cash_transfer_reversal_sent', 'source_url' => $actor->can('erp.cash-transfer-reversals.view') ? '/erp/accounting/cash-transfer-reversals/'.$id : null, 'number' => 'CTR-'.$id, 'debit' => $incoming ? (string) $request->getAttribute('amount') : '0.00', 'credit' => $incoming ? '0.00' : (string) $request->getAttribute('amount')];
        }
        if ($kind === 'supplier_payment') {
            $native = SupplierPayment::query()->where('company_id', $company)->findOrFail($id);
            $this->paymentOperations->verify($company, (int) ($native->getAttribute('reverses_payment_id') ?? $id));
            $payload = $this->payments->recognized($company, $kind, $id);
            $reverse = $native->getAttribute('reverses_payment_id') !== null;
            $direction = $reverse ? 'debit' : 'credit';
            $event = $reverse ? 'supplier_payment_refunded' : 'supplier_payment_paid';
            $url = $actor->can('erp.supplier-payments.view') ? '/erp/accounting/supplier-payments/'.$id : null;
        } elseif ($kind === 'customer_receipt') {
            $native = CustomerReceipt::query()->where('company_id', $company)->findOrFail($id);
            $this->receiptOperations->verify($company, $id);
            $payload = $this->receipts->recognized($company, $kind, $id);
            $reverse = $native->getAttribute('reverses_receipt_id') !== null;
            $direction = $reverse ? 'credit' : 'debit';
            $event = $reverse ? 'customer_receipt_refunded' : 'customer_receipt_received';
            $url = $actor->can('erp.customer-receipts.view') ? '/erp/sales/receipts/'.$id : null;
        } else {
            $this->fail('Cash source adapter is not installed for this source kind.');
        }
        if ((int) $native->getAttribute('cash_account_id') !== (int) $binding->getKey() || $payload['currency'] !== $binding->getAttribute('currency_code') || $payload['date'] !== $journal->getAttribute('accounting_date')->format('Y-m-d') || $effect->getAttribute('accounting_date')->format('Y-m-d') !== $payload['date']) {
            $this->fail('Cash source/binding/date/currency identity differs.');
        }

        return [
            'source_kind' => $kind, 'source_id' => $id, 'event' => $event,
            'source_url' => $url, 'number' => (string) $native->getAttribute('number'),
            'debit' => $direction === 'debit' ? (string) $native->getAttribute('amount') : '0.00',
            'credit' => $direction === 'credit' ? (string) $native->getAttribute('amount') : '0.00',
        ];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['cash_book_source' => $message]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Purchase;

use App\Models\Accounting\AccountingSupplierCredit;
use App\Models\Accounting\AccountingSupplierCreditLine;
use App\Models\Company;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\SupplierBill;
use App\Models\SupplierBillItem;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingDecimal;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Linked, independently approved supplier credit against an unpaid bill and an already-posted billed return. */
final class AccountingSupplierCreditService
{
    public function __construct(private readonly AccountingPurchasePosting $posting, private readonly AccountingPurchaseEvidence $evidence, private readonly PurchaseCompanyAccess $access, private readonly AccountingDecimal $decimal, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingBusinessDate $dates) {}

    /** Prices, taxes, stock classification and clearing amounts are all server derived. @param array<string, mixed> $data */
    public function create(User $actor, array $data): AccountingSupplierCredit
    {
        Validator::make($data, ['company_id' => ['required', 'integer', 'min:1'], 'supplier_bill_id' => ['required', 'integer', 'min:1'], 'purchase_return_id' => ['required', 'integer', 'min:1'], 'document_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:2000'], 'items' => ['required', 'array', 'min:1', 'max:40'], 'items.*' => ['array:supplier_bill_item_id,purchase_return_item_id,quantity'], 'items.*.supplier_bill_item_id' => ['required', 'integer', 'min:1'], 'items.*.purchase_return_item_id' => ['required', 'integer', 'min:1'], 'items.*.quantity' => ['required']])->validate();
        $company = (int) $data['company_id'];
        $this->access->authorize($actor, $company, 'erp.supplier-credits.create');

        return DB::transaction(function () use ($actor, $data, $company): AccountingSupplierCredit {
            if (! $this->posting->before($company, true)) {
                $this->fail('This linked financial credit workflow requires enabled, recognized accounting sources.');
            }
            $bill = SupplierBill::query()->where('company_id', $company)->where('status', 'approved')->lockForUpdate()->findOrFail($data['supplier_bill_id']);
            $return = PurchaseReturn::query()->where('company_id', $company)->where('status', 'posted')->lockForUpdate()->findOrFail($data['purchase_return_id']);
            $this->sources($company, $bill, $return);
            $this->validDate($company, (string) $data['document_date'], $bill, $return);
            $prepared = $this->prepareLines($company, $bill, $return, $data['items']);
            $amount = $this->total($prepared);
            $this->available($company, $bill, $amount);
            $credit = AccountingSupplierCredit::query()->create(['company_id' => $company, 'supplier_bill_id' => $bill->getKey(), 'purchase_return_id' => $return->getKey(), 'status' => 'draft', 'revision' => 1, 'document_date' => $data['document_date'], 'currency_code' => $bill->getAttribute('currency_code'), 'amount' => $amount, 'reason' => trim((string) $data['reason']), 'created_by' => $actor->getKey(), 'payload_hash' => str_repeat('0', 64)]);
            foreach ($prepared as $line) {
                AccountingSupplierCreditLine::query()->create(['company_id' => $company, 'supplier_credit_id' => $credit->getKey(), ...$line]);
            }
            $credit->fill(['number' => 'SC-'.$company.'-'.$credit->getKey()])->save();
            $credit->fill(['payload_hash' => $this->canonical->hash($this->financialProjection($company, (int) $credit->getKey()))])->save();

            return $credit;
        }, 5);
    }

    public function approve(User $actor, int $company, int $id, int $revision): AccountingSupplierCredit
    {
        $this->access->authorize($actor, $company, 'erp.supplier-credits.approve');

        return DB::transaction(function () use ($actor, $company, $id, $revision): AccountingSupplierCredit {
            if (! $this->posting->before($company, true)) {
                $this->fail('Enabled accounting is required.');
            }
            $credit = AccountingSupplierCredit::query()->where('company_id', $company)->lockForUpdate()->findOrFail($id);
            if ($credit->getAttribute('status') !== 'draft' || (int) $credit->getAttribute('revision') !== $revision || (int) $credit->getAttribute('created_by') === (int) $actor->getKey()) {
                $this->fail('A current draft and independent approver are required.');
            }
            $this->validateCurrent($credit);
            $credit->fill(['status' => 'approved', 'approved_by' => $actor->getKey(), 'approved_at' => now(), 'revision' => $revision + 1])->save();

            return $credit;
        }, 5);
    }

    public function issue(User $actor, int $company, int $id, int $revision): AccountingSupplierCredit
    {
        $this->access->authorize($actor, $company, 'erp.supplier-credits.issue');

        return DB::transaction(function () use ($actor, $company, $id, $revision): AccountingSupplierCredit {
            $this->posting->before($company);
            $credit = AccountingSupplierCredit::query()->where('company_id', $company)->lockForUpdate()->findOrFail($id);
            if ($credit->getAttribute('status') === 'issued') {
                $this->posting->complete($actor, $company, 'supplier_credit', $id, false);

                return $credit;
            }
            if ($credit->getAttribute('status') !== 'approved' || (int) $credit->getAttribute('revision') !== $revision || $credit->getAttribute('approved_by') === null || (int) $credit->getAttribute('approved_by') === (int) $credit->getAttribute('created_by') || $credit->getAttribute('approved_at') === null) {
                $this->fail('Current independent approval is required before issue.');
            }
            if (! $this->posting->before($company, true)) {
                $this->fail('Enabled accounting is required.');
            }
            $this->validateCurrent($credit);
            $bill = SupplierBill::query()->where('company_id', $company)->findOrFail((int) $credit->getAttribute('supplier_bill_id'));
            $this->available($company, $bill, (string) $credit->getAttribute('amount'));
            $payable = DB::table('supplier_payables')->where('company_id', $company)->where('supplier_bill_id', $bill->getKey())->lockForUpdate()->first();
            if ($payable === null) {
                $this->fail('The linked payable is missing.');
            }
            DB::table('supplier_payables')->where('id', $payable->id)->update(['credited_amount' => $this->decimal->total([(string) $payable->credited_amount, (string) $credit->getAttribute('amount')])]);
            $credit->fill(['status' => 'issued', 'issued_by' => $actor->getKey(), 'issued_at' => now(), 'revision' => $revision + 1])->save();
            $this->posting->complete($actor, $company, 'supplier_credit', $id, true);

            return $credit;
        }, 5);
    }

    /** Financial fingerprint excludes workflow stamps; issue admission separately binds those stamps. @return array<string, mixed> */
    public function financialProjection(int $company, int $id): array
    {
        $credit = AccountingSupplierCredit::query()->where('company_id', $company)->findOrFail($id);
        $lines = [];
        foreach (AccountingSupplierCreditLine::query()->where('company_id', $company)->where('supplier_credit_id', $id)->orderBy('id')->get() as $line) {
            $lines[] = ['id' => (int) $line->getKey(), 'supplier_bill_item_id' => (int) $line->getAttribute('supplier_bill_item_id'), 'purchase_return_item_id' => (int) $line->getAttribute('purchase_return_item_id'), 'quantity' => (string) $line->getAttribute('quantity'), 'net' => (string) $line->getAttribute('net'), 'tax' => (string) $line->getAttribute('tax'), 'clearing_amount' => (string) $line->getAttribute('clearing_amount'), 'stock' => (bool) $line->getAttribute('is_stock_item')];
        }

        return ['source_kind' => 'supplier_credit', 'source_id' => $id, 'number' => (string) $credit->getAttribute('number'), 'document_date' => $credit->getAttribute('document_date')->format('Y-m-d'), 'currency' => (string) $credit->getAttribute('currency_code'), 'supplier_bill_id' => (int) $credit->getAttribute('supplier_bill_id'), 'purchase_return_id' => (int) $credit->getAttribute('purchase_return_id'), 'amount' => (string) $credit->getAttribute('amount'), 'lines' => $lines];
    }

    private function validateCurrent(AccountingSupplierCredit $credit): void
    {
        $company = (int) $credit->getAttribute('company_id');
        $projection = $this->financialProjection($company, (int) $credit->getKey());
        if (! hash_equals((string) $credit->getAttribute('payload_hash'), $this->canonical->hash($projection))) {
            $this->fail('Supplier credit financial evidence changed.');
        }
        $bill = SupplierBill::query()->where('company_id', $company)->where('status', 'approved')->findOrFail((int) $credit->getAttribute('supplier_bill_id'));
        $return = PurchaseReturn::query()->where('company_id', $company)->where('status', 'posted')->findOrFail((int) $credit->getAttribute('purchase_return_id'));
        $this->sources($company, $bill, $return);
        $this->validDate($company, $projection['document_date'], $bill, $return);
        $inputs = array_map(static fn (array $line): array => ['supplier_bill_item_id' => $line['supplier_bill_item_id'], 'purchase_return_item_id' => $line['purchase_return_item_id'], 'quantity' => $line['quantity']], $projection['lines']);
        $expected = $this->prepareLines($company, $bill, $return, $inputs);
        foreach ($expected as $index => $line) {
            $actual = $projection['lines'][$index];
            foreach (['supplier_bill_item_id', 'purchase_return_item_id', 'quantity', 'net', 'tax', 'clearing_amount'] as $key) {
                if ($line[$key] !== $actual[$key]) {
                    $this->fail('Source quantities or cumulative credit shares changed; prepare a fresh credit for independent review.');
                }
            }
            if ($line['is_stock_item'] !== $actual['stock']) {
                $this->fail('Source stock classification changed.');
            }
        }
        if ($this->total($expected) !== $projection['amount']) {
            $this->fail('Credit total changed.');
        }
    }

    private function sources(int $company, SupplierBill $bill, PurchaseReturn $return): void
    {
        if ((int) $bill->getAttribute('goods_receipt_id') !== (int) $return->getAttribute('goods_receipt_id') || (int) $bill->getAttribute('supplier_id') !== (int) $return->getAttribute('supplier_id')) {
            $this->fail('Credit bill and return must have the same company, supplier and receipt.');
        }
        $this->evidence->recognized($company, 'supplier_bill', (int) $bill->getKey());
        $this->evidence->recognized($company, 'purchase_return', (int) $return->getKey());
    }

    /** @param list<array<string, mixed>> $inputs @return list<array<string, mixed>> */
    private function prepareLines(int $company, SupplierBill $bill, PurchaseReturn $return, array $inputs): array
    {
        $prepared = [];
        $seenBill = [];
        $seenReturn = [];
        $returnEvidence = $this->evidence->recognized($company, 'purchase_return', (int) $return->getKey());
        foreach ($inputs as $input) {
            $billLine = SupplierBillItem::query()->where('supplier_bill_id', $bill->getKey())->findOrFail($input['supplier_bill_item_id']);
            $returnLine = PurchaseReturnItem::query()->where('purchase_return_id', $return->getKey())->findOrFail($input['purchase_return_item_id']);
            if ((int) $billLine->getAttribute('goods_receipt_item_id') !== (int) $returnLine->getAttribute('goods_receipt_item_id') || isset($seenBill[(int) $billLine->getKey()]) || isset($seenReturn[(int) $returnLine->getKey()])) {
                $this->fail('Use each matching bill and return line once per credit.');
            }
            $seenBill[(int) $billLine->getKey()] = true;
            $seenReturn[(int) $returnLine->getKey()] = true;
            $slice = null;
            foreach ($returnEvidence['slices'] as $candidate) {
                if ($candidate['source_line_id'] === (int) $returnLine->getKey()) {
                    $slice = $candidate;
                }
            }
            if ($slice === null) {
                $this->fail('The returned line has no recognized billed-quantity allocation.');
            }
            $quantity = $this->decimal->source($input['quantity']);
            $billQuantity = $this->decimal->source((string) $billLine->getAttribute('quantity'));
            $returnQuantity = $slice['billed_return_quantity'];
            if (bccomp($quantity, '0', 4) <= 0) {
                $this->fail('Credit quantity must be positive.');
            }
            $billBefore = $this->creditedQuantity($company, 'supplier_bill_item_id', (int) $billLine->getKey());
            $returnBefore = $this->creditedQuantity($company, 'purchase_return_item_id', (int) $returnLine->getKey());
            if (bccomp(bcadd($billBefore, $quantity, 4), $billQuantity, 4) > 0 || bccomp(bcadd($returnBefore, $quantity, 4), $returnQuantity, 4) > 0) {
                $this->fail('Credit quantity exceeds uncredited billed or physically returned quantity.');
            }
            $net = bcsub((string) $billLine->getAttribute('line_total'), (string) $billLine->getAttribute('tax_amount'), 2);
            $netShare = $this->decimal->costShare(bcadd($net, '0', 4), $billQuantity, $billBefore, bcadd($billBefore, $quantity, 4));
            $tax = $this->decimal->costShare(bcadd((string) $billLine->getAttribute('tax_amount'), '0', 4), $billQuantity, $billBefore, bcadd($billBefore, $quantity, 4));
            $clearing = $this->decimal->costShare(bcadd($slice['return_clearing_amount'], '0', 4), $returnQuantity, $returnBefore, bcadd($returnBefore, $quantity, 4));
            $prepared[] = ['supplier_bill_item_id' => (int) $billLine->getKey(), 'purchase_return_item_id' => (int) $returnLine->getKey(), 'quantity' => $quantity, 'net' => $netShare, 'tax' => $tax, 'clearing_amount' => $clearing, 'is_stock_item' => (bool) $returnLine->getAttribute('is_stock_item')];
        }

        return $prepared;
    }

    private function creditedQuantity(int $company, string $column, int $id): string
    {
        $quantity = '0.0000';
        foreach (DB::table('accounting_supplier_credit_lines as l')->join('accounting_supplier_credits as c', 'c.id', '=', 'l.supplier_credit_id')->where('c.company_id', $company)->where('l.company_id', $company)->where('c.status', 'issued')->where('l.'.$column, $id)->get(['l.quantity', 'c.id']) as $row) {
            $this->evidence->recognized($company, 'supplier_credit', (int) $row->id);
            $quantity = bcadd($quantity, (string) $row->quantity, 4);
        }

        return $quantity;
    }

    /** @param list<array<string, mixed>> $lines */
    private function total(array $lines): string
    {
        $amount = '0.00';
        foreach ($lines as $line) {
            $amount = $this->decimal->total([$amount, $line['net'], $line['tax']]);
        }
        if ($amount === '0.00') {
            $this->fail('A financial supplier credit requires a positive amount.');
        }

return $amount;
    }

    private function available(int $company, SupplierBill $bill, string $amount): void
    {
        try {
            app(AccountingSupplierPaymentLedger::class)->bill($company, (int) $bill->getAttribute('supplier_id'), (string) $bill->getAttribute('currency_code'), (int) $bill->getKey(), true);
        } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
            $this->fail('Payable payment history or original financial evidence requires reconciliation before supplier credit.');
        }
        $payable = DB::table('supplier_payables')->where('company_id', $company)->where('supplier_bill_id', $bill->getKey())->lockForUpdate()->first();
        $credited = '0.00';
        foreach (AccountingSupplierCredit::query()->where('company_id', $company)->where('supplier_bill_id', $bill->getKey())->where('status', 'issued')->get() as $previous) {
            $this->evidence->recognized($company, 'supplier_credit', (int) $previous->getKey());
            $credited = $this->decimal->total([$credited, (string) $previous->getAttribute('amount')]);
        }
        if ($payable !== null && bccomp($this->decimal->total([$this->decimal->money((string) $payable->paid_amount), $this->decimal->money((string) $payable->credited_amount)]), $this->decimal->money((string) $payable->amount), 2) > 0) {
            $this->fail('Payable payments and credits exceed its original amount.');
        }
        if ($payable !== null && $this->decimal->money((string) $payable->credited_amount) !== $credited) {
            $this->fail('The payable credit summary differs from issued immutable credits.');
        }
        if ($payable === null || bccomp($amount, bcsub(bcsub((string) $payable->amount, (string) $payable->paid_amount, 2), (string) $payable->credited_amount, 2), 2) > 0) {
            $this->fail('Credit exceeds unpaid, uncredited AP. Paid-source credits require the later supplier refund workflow.');
        }
    }

    private function validDate(int $companyId, string $date, SupplierBill $bill, PurchaseReturn $return): void
    {
        $company = Company::query()->findOrFail($companyId);
        $billDate = $bill->getAttribute('invoice_date')->format('Y-m-d');
        $returned = $return->getAttribute('posted_at');
        if (! $returned instanceof \DateTimeInterface || $date < $billDate || $date < $this->dates->fromInstant($company, $returned) || $date > $this->dates->today($company)) {
            $this->fail('Credit date must be on or after the bill and physical return, and cannot be in the future.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['supplier_credit' => $message]);
    }
}

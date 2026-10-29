<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Purchase;

use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingSupplierPaymentOperation;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\SupplierPaymentAuditLog;
use App\Models\SupplierPaymentTarget;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingDecimal;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Internal storage invariants only. Native authorization, locking, balances and complete finance belong to A3/B. */
final class AccountingSupplierPaymentModelGuard
{
    public function __construct(private readonly AccountingDecimal $decimal, private readonly AccountingCanonicalPayload $canonical) {}

    public function header(SupplierPayment $row): void
    {
        if ($row->exists) {
            $stored = SupplierPayment::query()->findOrFail((int) $row->getKey());
            if (in_array($stored->getAttribute('status'), ['posted', 'cancelled'], true) || $stored->getAttribute('posted_at') !== null) {
                throw new \LogicException('Posted/cancelled payment headers are immutable.');
            }
            if ($stored->getAttribute('status') !== $row->getRawOriginal('status') || (int) $stored->getAttribute('revision') !== (int) $row->getRawOriginal('revision')) {
                throw new \LogicException('Reload the payment after a concurrent workflow change.');
            }
            if ($row->isDirty(['company_id', 'supplier_id', 'currency_code', 'number', 'document_date', 'created_by', 'reverses_payment_id'])) {
                throw new \LogicException('Numbered payment identity cannot be reassigned.');
            }
            if ($stored->getAttribute('status') !== 'draft' && $row->isDirty(['cash_account_id', 'payment_method', 'amount', 'advance_amount', 'payment_reference', 'notes', 'supplier_snapshot', 'issuer_snapshot', 'last_edited_by'])) {
                throw new \LogicException('Submitted payment intent is frozen; reject or cancel through its workflow.');
            }
            if (in_array($stored->getAttribute('status'), ['submitted', 'approved'], true) && $row->getAttribute('status') !== 'draft' && $row->isDirty(['submitted_by', 'submitted_at', 'submitted_payload_hash'])) {
                throw new \LogicException('Submitted review identity cannot be rewritten.');
            }
            if ($stored->getAttribute('status') === 'approved' && $row->isDirty(['approved_by', 'approved_at', 'approved_payload_hash'])) {
                throw new \LogicException('Approved review identity cannot be rewritten.');
            }
        }
        $company = Company::query()->findOrFail((int) $row->getAttribute('company_id'));
        $supplier = Supplier::query()->where('company_id', $company->getKey())->find((int) $row->getAttribute('supplier_id'));
        $cash = AccountingCashAccount::query()->where('company_id', $company->getKey())->find((int) $row->getAttribute('cash_account_id'));
        if ($supplier === null || $cash === null || $row->getAttribute('currency_code') !== $company->getAttribute('currency_code') || $cash->getAttribute('currency_code') !== $row->getAttribute('currency_code') || ! in_array($row->getAttribute('payment_method'), ['cash', 'bank_transfer', 'cheque', 'other'], true) || $cash->getAttribute('kind') !== ($row->getAttribute('payment_method') === 'cash' ? 'cash' : 'bank')) {
            $this->fail('Payment supplier, currency or finance-account identity differs.');
        }
        $amount = $this->money($row, 'amount');
        $advance = $this->money($row, 'advance_amount');
        if ($amount === '0.00' || bccomp($advance, $amount, 2) > 0 || (int) $row->getAttribute('revision') < 1 || ! in_array($row->getAttribute('status'), ['draft', 'submitted', 'approved', 'posted', 'cancelled'], true)) {
            $this->fail('Payment amount, advance, revision or state is invalid.');
        }
        $row->setAttribute('amount', $amount);
        $row->setAttribute('advance_amount', $advance);
        $date = $row->getAttribute('document_date');
        $number = $row->getAttribute('number');
        if (! $date instanceof DateTimeInterface || ! is_string($number) || ! preg_match('/\ASP-[0-9]{4}-[0-9]{6}\z/', $number) || substr($number, 3, 4) !== $date->format('Y')) {
            $this->fail('Payment number must use its native document-year namespace.');
        }
        foreach (['created_by', 'updated_by', 'last_edited_by'] as $actor) {
            if ((int) $row->getAttribute($actor) < 1) {
                $this->fail('Native maker/editor identity is required.');
            }
        }
        if ($row->getAttribute('status') === 'draft') {
            foreach (['submitted_by', 'submitted_at', 'submitted_payload_hash', 'approved_by', 'approved_at', 'approved_payload_hash'] as $field) {
                if ($row->getAttribute($field) !== null) {
                    $this->fail('A draft cannot retain submitted or approved workflow evidence.');
                }
            }
        } elseif ($row->getAttribute('status') === 'submitted') {
            foreach (['approved_by', 'approved_at', 'approved_payload_hash'] as $field) {
                if ($row->getAttribute($field) !== null) {
                    $this->fail('Submitted intent cannot carry an approval yet.');
                }
            }
        }
        if ($row->getAttribute('reverses_payment_id') !== null) {
            $original = SupplierPayment::query()->where('company_id', $company->getKey())->where('status', 'posted')->whereNull('reverses_payment_id')->find((int) $row->getAttribute('reverses_payment_id'));
            if ($original === null || $row->getAttribute('status') !== 'posted' || trim((string) $row->getAttribute('reason')) === '') {
                $this->fail('A cash reversal needs its posted original and a reason.');
            }
            foreach (['supplier_id', 'currency_code', 'amount', 'advance_amount', 'cash_account_id', 'payment_method'] as $field) {
                if ((string) $original->getAttribute($field) !== (string) $row->getAttribute($field)) {
                    $this->fail('A reversal must retain original supplier, currency, amount and finance binding.');
                }
            }
        } elseif (in_array($row->getAttribute('status'), ['submitted', 'approved', 'posted'], true)) {
            $this->stamp($row, 'submitted');
            $this->digest($row->getAttribute('submitted_payload_hash'));
            if (in_array($row->getAttribute('status'), ['approved', 'posted'], true)) {
                $this->stamp($row, 'approved');
                $this->digest($row->getAttribute('approved_payload_hash'));
                if ($row->getAttribute('approved_payload_hash') !== $row->getAttribute('submitted_payload_hash') || (int) $row->getAttribute('approved_by') === (int) $row->getAttribute('created_by') || (int) $row->getAttribute('approved_by') === (int) $row->getAttribute('last_edited_by')) {
                    $this->fail('Independent approval must cover the submitted intent.');
                }
            }
        }
        if ($row->getAttribute('status') === 'posted') {
            $this->stamp($row, 'posted');
            $this->digest($row->getAttribute('payload_hash'));
            if (! is_string($row->getAttribute('idempotency_key')) || ! preg_match('/\A[A-Za-z0-9_-]{16,64}\z/', $row->getAttribute('idempotency_key'))) {
                $this->fail('Posted payment needs its native operation identity.');
            }
        } elseif ($row->getAttribute('posted_at') !== null || $row->getAttribute('posted_by') !== null || $row->getAttribute('payload_hash') !== null || $row->getAttribute('idempotency_key') !== null) {
            $this->fail('An unposted payment cannot carry a native posting identity.');
        }
        if ($row->getAttribute('status') === 'cancelled') {
            $this->stamp($row, 'cancelled');
            if (trim((string) $row->getAttribute('reason')) === '') {
                $this->fail('Cancellation needs a reason.');
            }
        }
    }

    public function target(SupplierPaymentTarget $row, bool $deleting = false): void
    {
        if ($row->exists && $row->isDirty(['company_id', 'supplier_payment_id', 'supplier_bill_id', 'supplier_payable_id'])) {
            throw new \LogicException('Replace a draft target instead of reassigning its identity.');
        }
        $payment = $this->paymentFor($row, false);
        if ($payment->getAttribute('status') !== 'draft' || $payment->getAttribute('posted_at') !== null) {
            throw new \LogicException('Initial payment targets are editable only on their original draft.');
        }
        if ($deleting) {
            return;
        }
        $this->bill($row, $payment);
        $row->setAttribute('amount', $this->positive($this->money($row, 'amount')));
    }

    public function allocation(SupplierPaymentAllocation $row): void
    {
        $payment = $this->paymentFor($row, true);
        $this->bill($row, $payment);
        $amount = $this->positive($this->money($row, 'amount'));
        $row->setAttribute('amount', $amount);
        $this->stamp($row, 'posted');
        $this->digest($row->getAttribute('payload_hash'));
        if ($row->getAttribute('posted_at') < $payment->getAttribute('posted_at')) {
            $this->fail('Allocation cannot predate native cash posting.');
        }
        if (! is_string($row->getAttribute('idempotency_key')) || ! preg_match('/\A[A-Za-z0-9_:-]{16,120}\z/', $row->getAttribute('idempotency_key'))) {
            $this->fail('Allocation needs its native operation key.');
        }
        if ($row->getAttribute('reverses_allocation_id') !== null) {
            $original = SupplierPaymentAllocation::query()->where('company_id', $row->getAttribute('company_id'))->whereNull('reverses_allocation_id')->find((int) $row->getAttribute('reverses_allocation_id'));
            if ($original === null || trim((string) $row->getAttribute('reason')) === '') {
                $this->fail('Compensation needs its exact original and a reason.');
            }
            foreach (['supplier_payment_id', 'supplier_bill_id', 'supplier_payable_id', 'amount'] as $field) {
                if ((string) $original->getAttribute($field) !== (string) $row->getAttribute($field)) {
                    $this->fail('Compensation differs from its original allocation.');
                }
            }
            if ($row->getAttribute('posted_at') < $original->getAttribute('posted_at')) {
                $this->fail('Compensation cannot predate its original allocation.');
            }
        }
    }

    public function audit(SupplierPaymentAuditLog $row): void
    {
        $this->paymentFor($row, false);
        if (! preg_match('/\A[a-z][a-z_]{0,39}\z/', (string) $row->getAttribute('action')) || ! is_array($row->getAttribute('new_values')) || ! $row->getAttribute('created_at') instanceof DateTimeInterface) {
            $this->fail('Audit action, payload and explicit event time are required.');
        }
    }

    public function operation(AccountingSupplierPaymentOperation $row): void
    {
        $payment = $this->paymentFor($row, true);
        $members = $row->getAttribute('members');
        if ($row->getAttribute('contract_version') !== AccountingSupplierPaymentContract::VERSION || ! in_array($row->getAttribute('kind'), ['post', 'change', 'reverse'], true) || ! is_array($members) || ! array_is_list($members) || $members === [] || count($members) > 201) {
            $this->fail('Operation contract, kind or member list is invalid.');
        }
        foreach (['key_hash', 'request_hash', 'payload_hash'] as $hash) {
            $this->digest($row->getAttribute($hash));
        }
        if ($row->getAttribute('kind') === 'post' && ($row->getAttribute('key_hash') !== hash('sha256', (string) $payment->getAttribute('idempotency_key')) || $row->getAttribute('request_hash') !== $payment->getAttribute('payload_hash'))) {
            $this->fail('Initial operation must retain its original native payment key and request hash.');
        }
        $seen = [];
        $cash = 0;
        foreach ($members as $member) {
            if (! is_array($member)) {
                $this->fail('Invalid native operation member.');
            }
            $keys = array_keys($member);
            sort($keys);
            if ($keys !== ['event_kind', 'source_id', 'source_kind'] || ! is_int($member['source_id']) || $member['source_id'] < 1 || ! is_string($member['source_kind']) || ! is_string($member['event_kind'])) {
                $this->fail('Member identities must be exact owned native IDs.');
            }
            $key = (string) $member['source_kind'].':'.$member['source_id'];
            if (isset($seen[$key])) {
                $this->fail('An operation cannot repeat a native member.');
            } $seen[$key] = true;
            if ($member['source_kind'] === 'supplier_payment') {
                $cash++;
                $native = SupplierPayment::query()->where('company_id', $row->getAttribute('company_id'))->where('status', 'posted')->find($member['source_id']);
                $valid = $native !== null && (($row->getAttribute('kind') === 'post' && $member['event_kind'] === 'posted' && (int) $native->getKey() === (int) $payment->getKey()) || ($row->getAttribute('kind') === 'reverse' && $member['event_kind'] === 'reversed' && (int) $native->getAttribute('reverses_payment_id') === (int) $payment->getKey()));
                if (! $valid) {
                    $this->fail('Cash member does not belong to this operation.');
                }
            } elseif ($member['source_kind'] === 'supplier_payment_allocation') {
                $native = SupplierPaymentAllocation::query()->where('company_id', $row->getAttribute('company_id'))->where('supplier_payment_id', $payment->getKey())->find($member['source_id']);
                if ($native === null || $member['event_kind'] !== ($native->getAttribute('reverses_allocation_id') === null ? 'created' : 'reversed') || ($row->getAttribute('kind') === 'post' && $member['event_kind'] !== 'created') || ($row->getAttribute('kind') === 'reverse' && $member['event_kind'] !== 'reversed')) {
                    $this->fail('Allocation member does not belong to this operation.');
                }
            } else {
                $this->fail('Unregistered native payment member type.');
            }
        }
        if ($cash !== ($row->getAttribute('kind') === 'change' ? 0 : 1)) {
            $this->fail('This operation has an invalid cash-member count.');
        }
        $body = ['company_id' => (int) $row->getAttribute('company_id'), 'supplier_payment_id' => (int) $payment->getKey(), 'kind' => (string) $row->getAttribute('kind'), 'contract_version' => (string) $row->getAttribute('contract_version'), 'key_hash' => (string) $row->getAttribute('key_hash'), 'request_hash' => (string) $row->getAttribute('request_hash'), 'members' => $members];
        if (! hash_equals((string) $row->getAttribute('payload_hash'), $this->canonical->hash($body))) {
            $this->fail('Operation payload hash differs from its immutable member envelope.');
        }
    }

    private function paymentFor(Model $row, bool $posted): SupplierPayment
    {
        $payment = SupplierPayment::query()->where('company_id', $row->getAttribute('company_id'))->find((int) $row->getAttribute('supplier_payment_id'));
        if ($payment === null || ($posted && ($payment->getAttribute('status') !== 'posted' || $payment->getAttribute('posted_at') === null || $payment->getAttribute('reverses_payment_id') !== null))) {
            $this->fail('This row requires its owned original payment in the correct native state.');
        }

        return $payment;
    }

    private function bill(Model $row, SupplierPayment $payment): void
    {
        $bill = SupplierBill::query()->where('company_id', $payment->getAttribute('company_id'))->where('supplier_id', $payment->getAttribute('supplier_id'))->where('currency_code', $payment->getAttribute('currency_code'))->where('status', 'approved')->find((int) $row->getAttribute('supplier_bill_id'));
        $payable = DB::table('supplier_payables')->where('id', $row->getAttribute('supplier_payable_id'))->where('company_id', $payment->getAttribute('company_id'))->where('supplier_id', $payment->getAttribute('supplier_id'))->where('currency_code', $payment->getAttribute('currency_code'))->where('supplier_bill_id', $row->getAttribute('supplier_bill_id'))->exists();
        if ($bill === null || ! $payable) {
            $this->fail('Target bill and payable must match the payment company, supplier and currency.');
        }
    }

    private function money(Model $row, string $field): string
    {
        return $this->decimal->money($row->isDirty($field) ? ($row->getAttributes()[$field] ?? '0.00') : (string) $row->getAttribute($field));
    }

    private function positive(string $amount): string
    {
        if ($amount === '0.00') {
            $this->fail('Allocation amount must be positive.');
        }

        return $amount;
    }

    private function stamp(Model $row, string $action): void
    {
        if ((int) $row->getAttribute($action.'_by') < 1 || ! $row->getAttribute($action.'_at') instanceof DateTimeInterface) {
            $this->fail('Required native actor/time stamp is missing.');
        }
    }

    private function digest(mixed $value): void
    {
        if (! is_string($value) || ! preg_match('/\A[a-f0-9]{64}\z/', $value)) {
            $this->fail('Required SHA-256 identity is missing.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['supplier_payment' => $message]);
    }
}

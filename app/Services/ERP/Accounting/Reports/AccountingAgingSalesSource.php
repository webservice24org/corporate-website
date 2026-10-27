<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Models\Accounting\AccountingAccountMapping;
use App\Models\Accounting\AccountingSalesEvent;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Company;
use App\Models\CustomerCreditAllocation;
use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use App\Services\ERP\Accounting\Source\AccountingReceiptManifestReader;
use App\Services\ERP\Accounting\Source\Sales\AccountingReceiptEvidence;
use App\Services\ERP\Accounting\Source\Sales\AccountingSalesEvidence;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Caller owns company read lock/aging permission. No native mutation, capture, replay or fallback to current counters. */
final class AccountingAgingSalesSource
{
    public function __construct(private readonly AccountingSalesEvidence $sales, private readonly AccountingReceiptEvidence $receipts, private readonly AccountingReceiptManifestReader $manifests) {}

    /** @return array<string, mixed> */
    public function read(Company $company): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('AR aging requires its authorized company transaction.');
        }
        $id = (int) $company->getKey();
        $currency = (string) $company->getAttribute('currency_code');
        $documents = [];
        $events = [];
        $controls = [];
        $issues = [];
        $seen = [];
        $roots = [];
        $classes = ['sales_invoice' => SalesInvoice::class, 'sales_credit_note' => SalesCreditNote::class, 'customer_receipt' => CustomerReceipt::class, 'customer_receipt_allocation' => CustomerReceiptAllocation::class, 'customer_credit_allocation' => CustomerCreditAllocation::class];
        foreach ($classes as $class) {
            if ($class::query()->where('company_id', $id)->count() > 10000) {
                $this->fail('AR source history exceeds its interactive capacity.');
            }
        }
        foreach (SalesInvoice::query()->where('company_id', $id)->where(function ($q): void {
            $q->where('status', 'issued')->orWhereNotNull('issued_at');
        })->orderBy('id')->get() as $row) {
            $key = (int) $row->getKey();
            $seen['sales_invoice:'.$key] = true;
            $date = $row->getAttribute('document_date');
            $document = ['id' => $key, 'company_id' => $id, 'party_id' => (int) $row->getAttribute('customer_id'), 'currency' => $currency, 'number' => (string) $row->getAttribute('number'), 'recognized_on' => $date instanceof DateTimeInterface ? $date->format('Y-m-d') : null, 'due_date' => null, 'due_basis' => 'unknown', 'amount' => null, 'verified' => false];
            try {
                $payload = $this->sales->recognized($id, 'sales_invoice', $key);
                $p = $payload['projection'];
                if ($p['currency'] !== $currency) {
                    $this->fail('Foreign-currency AR is outside this report.');
                }
                $document = [...$document, 'recognized_on' => $p['date'], 'due_date' => $p['due_date'], 'due_basis' => 'admitted', 'amount' => $p['grand_total'], 'verified' => true];
                $controls = [...$controls, ...$this->controls($id, 'sales_invoice', $key, $payload)];
            } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
                $issues[] = $this->issue('invoice_evidence_or_legacy_review', 'sales_invoice', $key);
            }
            $documents[] = $document;
        }
        foreach (SalesCreditNote::query()->where('company_id', $id)->where(function ($q): void {
            $q->where('status', 'issued')->orWhereNotNull('issued_at');
        })->orderBy('id')->get() as $row) {
            $key = (int) $row->getKey();
            $seen['sales_credit_note:'.$key] = true;
            try {
                $payload = $this->sales->recognized($id, 'sales_credit_note', $key);
                $p = $payload['projection'];
                if (bccomp($p['grand_total'], '0', 2) > 0) {
                    $events[] = $this->event($id, $currency, 'sales_credit_note:'.$key, (int) $p['invoice_id'], $p, 'decrease', $p['grand_total'], null, $this->sequence($id, 'sales_credit_note', $key));
                }
                $controls = [...$controls, ...$this->controls($id, 'sales_credit_note', $key, $payload)];
            } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
                $issues[] = $this->issue('credit_note_evidence_review', 'sales_credit_note', $key);
            }
        }
        foreach (CustomerReceipt::query()->where('company_id', $id)->where(function ($q): void {
            $q->where('status', 'posted')->orWhereNotNull('posted_at');
        })->orderBy('id')->get() as $row) {
            $key = (int) $row->getKey();
            $seen['customer_receipt:'.$key] = true;
            $root = (int) ($row->getAttribute('reverses_receipt_id') ?? $key);
            try {
                if (! array_key_exists($root, $roots)) {
                    $roots[$root] = false;
                    $this->manifests->verify($id, $root);
                    $roots[$root] = true;
                }
                if (! $roots[$root]) {
                    $this->fail('Receipt operation family evidence is incomplete.');
                }
                $payload = $this->receipts->recognized($id, 'customer_receipt', $key);
                $controls = [...$controls, ...$this->controls($id, 'customer_receipt', $key, $payload)];
            } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
                $issues[] = $this->issue('receipt_operation_family_review', 'customer_receipt', $key);
            }
        }
        foreach (CustomerReceiptAllocation::query()->where('company_id', $id)->orderBy('id')->get() as $row) {
            $key = (int) $row->getKey();
            $seen['customer_receipt_allocation:'.$key] = true;
            try {
                $payload = $this->receipts->recognized($id, 'customer_receipt_allocation', $key);
                $p = $payload['projection'];
                if (($roots[(int) $p['receipt_id']] ?? false) !== true) {
                    $this->fail('Allocation requires a complete original cash operation family.');
                }
                $p['date'] = $payload['date'];
                $reverse = $p['original_id'];
                $events[] = $this->event($id, $currency, 'customer_receipt_allocation:'.$key, (int) $p['invoice_id'], $p, $reverse === null ? 'decrease' : 'increase', $p['amount'], $reverse === null ? null : 'customer_receipt_allocation:'.$reverse, $this->sequence($id, 'customer_receipt_allocation', $key));
                $controls = [...$controls, ...$this->controls($id, 'customer_receipt_allocation', $key, $payload)];
            } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
                $issues[] = $this->issue('receipt_allocation_evidence_review', 'customer_receipt_allocation', $key);
            }
        }
        foreach (CustomerCreditAllocation::query()->where('company_id', $id)->orderBy('id')->get() as $row) {
            $key = (int) $row->getKey();
            $seen['customer_credit_allocation:'.$key] = true;
            try {
                $payload = $this->sales->recognized($id, 'customer_credit_allocation', $key);
                $p = $payload['projection'];
                $reverse = $p['original_id'];
                $sequence = $this->sequence($id, 'customer_credit_allocation', $key);
                foreach (['source' => (int) $p['source_invoice_id'], 'target' => (int) $p['invoice_id']] as $leg => $invoice) {
                    $increase = ($leg === 'source') === ($reverse === null);
                    $events[] = $this->event($id, $currency, 'customer_credit_allocation:'.$key.':'.$leg, $invoice, $p, $increase ? 'increase' : 'decrease', $p['amount'], $reverse === null ? null : 'customer_credit_allocation:'.$reverse.':'.$leg, $sequence);
                }
                $controls = [...$controls, ...$this->controls($id, 'customer_credit_allocation', $key, $payload)];
            } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
                $issues[] = $this->issue('credit_transfer_evidence_review', 'customer_credit_allocation', $key);
            }
        }
        foreach ([AccountingSalesEvent::class, AccountingSourceEffect::class] as $class) {
            $query = $class::query()->where('company_id', $id)->whereIn('source_kind', array_keys($classes));
            if ((clone $query)->count() > 100000) {
                $this->fail('AR admission history exceeds its interactive capacity.');
            }
            foreach ($query->get() as $row) {
                $key = (string) $row->getAttribute('source_kind').':'.$row->getAttribute('source_id');
                if (! isset($seen[$key])) {
                    $issues[] = $this->issue('orphan_or_uncollected_admission', (string) $row->getAttribute('source_kind'), (int) $row->getAttribute('source_id'));
                }
            }
        }

        return compact('documents', 'events', 'controls', 'issues');
    }

    /** @param array<string, mixed> $payload @return list<array<string, mixed>> */
    private function controls(int $company, string $kind, int $id, array $payload): array
    {
        $plans = isset($payload['effects']) ? $payload['effects'] : [$payload['effect_kind'] => ['lines' => $payload['lines']]];
        $result = [];
        foreach ($plans as $effectKind => $plan) {
            $effect = AccountingSourceEffect::query()->where('company_id', $company)->where('source_kind', $kind)->where('source_id', $id)->where('effect_kind', $effectKind)->sole();
            if ($effect->getAttribute('journal_entry_id') === null && $effect->getAttribute('outcome') === 'verified_no_journal') {
                continue;
            }
            foreach ($plan['lines'] as $line) {
                if (! in_array($line['role'], ['trade_ar', 'unapplied_customer_funds'], true)) {
                    continue;
                }
                $mapping = AccountingAccountMapping::query()->where('company_id', $company)->where('mapping_version_id', $effect->getAttribute('mapping_version_id'))->where('role', $line['role'])->sole();
                if ($effect->getAttribute('journal_entry_id') === null || ($line['party_type'] ?? null) !== 'customer' || ! is_int($line['party_id'] ?? null)) {
                    $this->fail('AR control membership has no owned journal/party identity.');
                }
                $result[] = ['date' => $effect->getAttribute('accounting_date')->format('Y-m-d'), 'journal_id' => (int) $effect->getAttribute('journal_entry_id'), 'account_id' => (int) $mapping->getAttribute('account_id'), 'party_id' => $line['party_id'], 'source_kind' => $kind, 'source_id' => $id, 'role' => $line['role'], 'debit' => $line['debit'], 'credit' => $line['credit']];
            }
        }

        return $result;
    }

    private function sequence(int $company, string $kind, int $id): int
    {
        return (int) AccountingSourceEffect::query()->where('company_id', $company)->where('source_kind', $kind)->where('source_id', $id)->orderBy('id')->firstOrFail()->getKey();
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    private function event(int $company, string $currency, string $id, int $document, array $p, string $direction, mixed $amount, ?string $parent, int $sequence): array
    {
        if ($p['currency'] !== $currency) {
            $this->fail('Foreign currency cannot be merged into AR aging.');
        }

        return ['id' => $id, 'company_id' => $company, 'currency' => $currency, 'document_id' => $document, 'party_id' => (int) $p['customer_id'], 'date' => $p['date'], 'sequence' => $sequence, 'direction' => $direction, 'amount' => $amount, 'reverses_id' => $parent];
    }

    /** @return array<string, mixed> */
    private function issue(string $issue, string $kind, int $id): array
    {
        return ['issue' => $issue, 'source_kind' => $kind, 'source_id' => $id];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['ar_aging' => $message]);
    }
}

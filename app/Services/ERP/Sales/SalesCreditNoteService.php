<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use App\Models\SalesCreditNote;
use App\Models\SalesCreditNoteItem;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class SalesCreditNoteService
{
    public function __construct(private readonly SalesCompanyAccess $access, private readonly SalesOrderService $orders, private readonly SalesInvoiceBalanceService $balances, private readonly SalesCreditNoteLedger $ledger, private readonly CustomerCreditLedger $credits, private readonly SalesCreditMath $math, private readonly SalesDocumentGuard $guard, private readonly SalesDocumentNumberService $numbers, private readonly SalesAuditService $audit) {}

    public function find(User $actor, int $id, string $permission = 'erp.sales-credit-notes.view'): SalesCreditNote
    {
        $note = SalesCreditNote::query()->whereIn('company_id', $this->orders->companies($actor, $permission)->select('id'))->findOrFail($id);
        $this->access->authorize($actor, (int) $note->getAttribute('company_id'), $permission);

        return $note;
    }

    /** @param array<string, mixed> $input */
    public function save(User $actor, array $input, ?int $id = null): SalesCreditNote
    {
        $data = Validator::make($input, ['company_id' => ['required', 'integer', 'min:1'], 'sales_invoice_id' => ['required', 'integer', 'min:1'], 'sales_return_id' => ['nullable', 'integer', 'min:1'], 'document_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u'], 'notes' => ['nullable', 'string', 'max:5000'], 'revision' => [$id === null ? 'nullable' : 'required', 'integer', 'min:1'], 'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*.sales_invoice_item_id' => ['required', 'integer', 'min:1', 'distinct'], 'items.*.sales_return_item_id' => ['nullable', 'integer', 'min:1', 'distinct'], 'items.*.credit_type' => ['required', 'in:quantity,value'], 'items.*.quantity' => ['nullable', 'regex:/\A[0-9]{1,5}(?:\.[0-9]{1,4})?\z/'], 'items.*.amount' => ['nullable', 'regex:/\A[0-9]{1,16}(?:\.[0-9]{1,2})?\z/']])->validate();

        return DB::transaction(function () use ($actor, $data, $id): SalesCreditNote {
            $permission = 'erp.sales-credit-notes.'.($id === null ? 'create' : 'edit');
            $company = $this->access->authorize($actor, (int) $data['company_id'], $permission, true);
            abort_unless($actor->can('erp.sales-invoices.view'), 403);
            $invoice = SalesInvoice::query()->where('company_id', $company->getKey())->lockForUpdate()->findOrFail((int) $data['sales_invoice_id']);
            $note = $id === null ? null : $this->find($actor, $id, $permission);
            if ($note !== null) {
                $note = SalesCreditNote::query()->where('company_id', $company->getKey())->where('sales_invoice_id', $invoice->getKey())->lockForUpdate()->findOrFail($id);
                $this->draft($note);
                $this->guard->assertRevision($note, (int) $data['revision']);
                if ($note->getAttribute('document_date')->format('Y-m-d') !== $data['document_date']) {
                    $this->math->fail('Numbered credit date cannot change.');
                }
            }
            $this->date($invoice, (string) $data['document_date'], (string) $company->getAttribute('timezone'));
            $returnId = empty($data['sales_return_id']) ? null : (int) $data['sales_return_id'];
            if ($returnId !== null) {
                abort_unless($actor->can('erp.sales-returns.view'), 403);
            }
            $prepared = $this->prepare($invoice, $data['items'], $returnId, (string) $data['document_date']);
            $totals = $this->totals($prepared);
            $terms = $invoice->getAttribute('terms_snapshot') ?? [];
            $terms['credit_source'] = ['id' => $invoice->getKey(), 'number' => $invoice->getAttribute('number')];
            $before = $note === null ? [] : ['revision' => $note->getAttribute('revision'), 'grand_total' => $note->getAttribute('grand_total')];
            $attributes = [...$totals, 'terms_snapshot' => $terms, 'sales_return_id' => $returnId, 'reason' => trim((string) $data['reason']), 'notes' => $data['notes'] ?? null, 'updated_by' => $actor->getKey()];
            if ($note === null) {
                $note = SalesCreditNote::query()->create([...$invoice->only(['company_id', 'customer_id', 'currency_code', 'customer_snapshot', 'billing_address_snapshot', 'shipping_address_snapshot']), ...$attributes, 'sales_invoice_id' => $invoice->getKey(), 'number' => $this->numbers->next((int) $company->getKey(), 'credit_note', (string) $data['document_date']), 'document_date' => $data['document_date'], 'status' => 'draft', 'revision' => 1, 'created_by' => $actor->getKey()]);
            } else {
                $note->forceFill([...$attributes, 'revision' => (int) $data['revision'] + 1])->save();
                SalesCreditNoteItem::query()->where('sales_credit_note_id', $id)->delete();
            }
            foreach ($prepared as $index => $line) {
                SalesCreditNoteItem::query()->create([...$line, 'sales_credit_note_id' => $note->getKey(), 'line_number' => $index + 1]);
            }
            $this->audit->record($actor, (int) $company->getKey(), 'credit_note', (int) $note->getKey(), $id === null ? 'create' : 'edit', $before, ['revision' => $note->getAttribute('revision'), 'grand_total' => $note->getAttribute('grand_total')]);

            return $note;
        }, 3);
    }

    public function issue(User $actor, int $id, int $revision, string $key): SalesCreditNote
    {
        Validator::make(['revision' => $revision, 'key' => $key], ['revision' => ['required', 'integer', 'min:1'], 'key' => ['required', 'regex:/\A[a-zA-Z0-9_-]{16,100}\z/']])->validate();

        return DB::transaction(function () use ($actor, $id, $revision, $key): SalesCreditNote {
            [$invoice, $note] = $this->locked($actor, $id, 'erp.sales-credit-notes.issue');
            $items = SalesCreditNoteItem::query()->where('sales_credit_note_id', $id)->orderBy('line_number')->lockForUpdate()->get();
            $hash = $this->fingerprint($note, $items->all());
            if ($note->getAttribute('status') === 'issued') {
                if ($note->getAttribute('idempotency_key') !== $key || ! hash_equals((string) $note->getAttribute('payload_hash'), $hash)) {
                    $this->math->fail('Credit issue retry differs from the original.');
                }
                $this->credits->customer((int) $invoice->getAttribute('company_id'), (int) $invoice->getAttribute('customer_id'));

                return $note;
            }
            $this->draft($note);
            $this->guard->assertRevision($note, $revision);
            $this->guard->assertIndependentApprover($actor, $note);
            if ((int) $note->getAttribute('updated_by') === (int) $actor->getKey()) {
                throw new AuthorizationException('Credit issuer must also differ from the last editor.');
            }
            $company = $this->access->authorize($actor, (int) $note->getAttribute('company_id'), 'erp.sales-credit-notes.issue', true);
            $this->date($invoice, $note->getAttribute('document_date')->format('Y-m-d'), (string) $company->getAttribute('timezone'));
            if (SalesCreditNote::query()->where('company_id', $company->getKey())->where('idempotency_key', $key)->exists()) {
                $this->math->fail('Issue key already belongs to another credit note.');
            }
            foreach (['company_id', 'customer_id', 'currency_code', 'customer_snapshot', 'billing_address_snapshot', 'shipping_address_snapshot'] as $field) {
                if ($this->canonical($note->getAttribute($field)) !== $this->canonical($invoice->getAttribute($field))) {
                    $this->math->fail('Credit party snapshots differ from the source invoice.');
                }
            }
            $expectedTerms = $invoice->getAttribute('terms_snapshot') ?? [];
            $expectedTerms['credit_source'] = ['id' => $invoice->getKey(), 'number' => $invoice->getAttribute('number')];
            if ($this->canonical($note->getAttribute('terms_snapshot')) !== $this->canonical($expectedTerms) || trim((string) $note->getAttribute('reason')) === '') {
                $this->math->fail('Credit terms, source trace or reason changed.');
            }
            $inputs = $items->map(fn (SalesCreditNoteItem $item): array => [...$item->only(['sales_invoice_item_id', 'sales_return_item_id', 'credit_type', 'quantity']), 'amount' => $item->getAttribute('line_total')])->all();
            $prepared = $this->prepare($invoice, $inputs, $note->getAttribute('sales_return_id') === null ? null : (int) $note->getAttribute('sales_return_id'), $note->getAttribute('document_date')->format('Y-m-d'));
            foreach ($items->values() as $index => $item) {
                foreach ($prepared[$index] as $field => $value) {
                    if ($this->canonical($item->getAttribute($field)) !== $this->canonical($value)) {
                        $this->math->fail('Credit progress changed. Re-save the draft to review current quantities and cents.');
                    }
                }
            }
            foreach ($this->totals($prepared) as $field => $value) {
                if ($note->getAttribute($field) !== $value) {
                    $this->math->fail('Credit header changed; re-save for review.');
                }
            }
            foreach ($items as $item) {
                $line = SalesInvoiceItem::query()->where('sales_invoice_id', $invoice->getKey())->lockForUpdate()->findOrFail($item->getAttribute('sales_invoice_item_id'));
                $line->forceFill(['credited_quantity' => bcadd((string) $line->getAttribute('credited_quantity'), (string) $item->getAttribute('quantity'), 4), 'credited_amount' => bcadd((string) $line->getAttribute('credited_amount'), (string) $item->getAttribute('line_total'), 2)])->save();
            }
            $sequence = (int) SalesCreditNote::query()->where('sales_invoice_id', $invoice->getKey())->max('issue_sequence') + 1;
            $note->forceFill(['status' => 'issued', 'revision' => $revision + 1, 'issued_at' => now()->toDateTimeString(), 'issued_by' => $actor->getKey(), 'updated_by' => $actor->getKey(), 'issue_sequence' => $sequence, 'idempotency_key' => $key, 'payload_hash' => $hash])->save();
            $this->balances->snapshot($invoice);
            $this->credits->customer((int) $company->getKey(), (int) $invoice->getAttribute('customer_id'));
            $invoice->forceFill(['revision' => (int) $invoice->getAttribute('revision') + 1, 'updated_by' => $actor->getKey()])->save();
            $this->audit->record($actor, (int) $company->getKey(), 'credit_note', $id, 'issue', ['status' => 'draft', 'revision' => $revision], ['status' => 'issued', 'revision' => $revision + 1, 'approved_and_issued_by' => $actor->getKey(), 'grand_total' => $note->getAttribute('grand_total')]);

            return $note;
        }, 3);
    }

    public function cancel(User $actor, int $id, int $revision, string $reason): SalesCreditNote
    {
        return DB::transaction(function () use ($actor, $id, $revision, $reason): SalesCreditNote {
            [, $note] = $this->locked($actor, $id, 'erp.sales-credit-notes.cancel');
            $this->draft($note);
            $this->guard->assertRevision($note, $revision);
            $note->forceFill(['status' => 'cancelled', 'revision' => $revision + 1, 'updated_by' => $actor->getKey()])->save();
            $this->audit->record($actor, (int) $note->getAttribute('company_id'), 'credit_note', $id, 'cancel', [], ['status' => 'cancelled', 'revision' => $revision + 1], $reason);

            return $note;
        }, 3);
    }

    /** @param array<array-key, array<string, mixed>> $input
     * @return list<array<string, mixed>>
     */
    private function prepare(SalesInvoice $invoice, array $input, ?int $returnId, string $date): array
    {
        if (count($input) < 1 || count($input) > 100 || $invoice->getAttribute('status') !== 'issued' || $invoice->getAttribute('issued_at') === null) {
            $this->math->fail('Credit only issued invoice lines.');
        }
        $this->balances->snapshot($invoice);
        $progress = $this->ledger->progress($invoice, true);
        $lines = SalesInvoiceItem::query()->where('sales_invoice_id', $invoice->getKey())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($returnId !== null) {
            $rh = SalesReturn::query()->where('company_id', $invoice->getAttribute('company_id'))->where('customer_id', $invoice->getAttribute('customer_id'))->where('currency_code', $invoice->getAttribute('currency_code'))->where('status', 'posted')->whereNotNull('posted_at')->findOrFail($returnId);
            if ($date < $rh->getAttribute('document_date')->format('Y-m-d')) {
                $this->math->fail('Credit cannot predate its accepted return.');
            }
        }
        usort($input, static fn (array $a, array $b): int => (int) $a['sales_invoice_item_id'] <=> (int) $b['sales_invoice_item_id']);
        $prepared = [];
        $seen = [];
        foreach ($input as $row) {
            $id = (int) $row['sales_invoice_item_id'];
            $line = $lines->get($id);
            abort_unless($line instanceof SalesInvoiceItem, 404);
            if (isset($seen[$id])) {
                $this->math->fail('Select each invoice line once.');
            } $seen[$id] = true;
            $p = $progress['items'][$id];
            $type = (string) $row['credit_type'];
            if (! in_array($type, ['quantity', 'value'], true)) {
                $this->math->fail('Unsupported credit type.');
            }
            $quantity = $type === 'quantity' ? app(SalesReturnLedger::class)->quantity((string) ($row['quantity'] ?? '0'), true) : '0.0000';
            $amount = $type === 'quantity' ? $this->math->quantityValue($line, $p['quantity'], $quantity) : $this->math->money((string) ($row['amount'] ?? '0'), true);
            $amounts = $this->math->slice($line, $p['amount'], $amount);
            $riId = empty($row['sales_return_item_id']) ? null : (int) $row['sales_return_item_id'];
            if (($returnId === null) !== ($riId === null) || ($returnId !== null && $type !== 'quantity')) {
                $this->math->fail('Return credits require a matching accepted return item and quantity type. Value corrections have no physical-return link.');
            }
            if ($riId !== null) {
                $ri = SalesReturnItem::query()->where('sales_return_id', $returnId)->where('sales_delivery_item_id', $line->getAttribute('sales_delivery_item_id'))->lockForUpdate()->findOrFail($riId);
                $used = $this->ledger->returnUsed($riId);
                if (bccomp(bcadd($used, $quantity, 4), (string) $ri->getAttribute('credit_review_quantity'), 4) > 0) {
                    $this->math->fail('Credit exceeds the accepted billed return quantity still awaiting credit.');
                }
            }
            $prepared[] = [...$line->only(['product_id', 'product_variant_id', 'unit_id', 'product_snapshot', 'description', 'unit_price', 'discount_percent', 'tax_percent']), ...$amounts, 'sales_invoice_item_id' => $id, 'sales_return_item_id' => $riId, 'credit_type' => $type, 'quantity' => $quantity];
        }

        return $prepared;
    }

    /** @param array<array-key, array<string, mixed>> $rows
     * @return array<string, string>
     */
    private function totals(array $rows): array
    {
        $totals = ['subtotal' => '0.00', 'discount_total' => '0.00', 'tax_total' => '0.00', 'grand_total' => '0.00'];
        foreach ($rows as $row) {
            foreach (['subtotal' => 'subtotal', 'discount_total' => 'discount_amount', 'tax_total' => 'tax_amount', 'grand_total' => 'line_total'] as $header => $field) {
                $totals[$header] = $this->math->money(bcadd($totals[$header], (string) $row[$field], 2));
            }
        }

        return $totals;
    }

    /** @return array{SalesInvoice, SalesCreditNote} */
    private function locked(User $actor, int $id, string $permission): array
    {
        $found = $this->find($actor, $id, $permission);
        $this->access->authorize($actor, (int) $found->getAttribute('company_id'), $permission, true);
        $invoice = SalesInvoice::query()->where('company_id', $found->getAttribute('company_id'))->lockForUpdate()->findOrFail($found->getAttribute('sales_invoice_id'));

        return [$invoice, SalesCreditNote::query()->where('company_id', $invoice->getAttribute('company_id'))->lockForUpdate()->findOrFail($id)];
    }

    private function draft(SalesCreditNote $note): void
    {
        if ($note->getAttribute('status') !== 'draft' || $note->getAttribute('issued_at') !== null || $note->getAttribute('idempotency_key') !== null) {
            $this->math->fail('Only unissued credit drafts can change.');
        }
    }

    private function date(SalesInvoice $invoice, string $date, string $timezone): void
    {
        if ($date < $invoice->getAttribute('document_date')->format('Y-m-d') || $date > now($timezone)->toDateString()) {
            $this->math->fail('Credit date must be between invoice date and company-local today.');
        }
    }

    /** @param array<array-key, SalesCreditNoteItem> $items */
    private function fingerprint(SalesCreditNote $note, array $items): string
    {
        return hash('sha256', json_encode($this->canonical(['header' => [...$note->only(['id', 'company_id', 'customer_id', 'sales_invoice_id', 'sales_return_id', 'number', 'currency_code', 'customer_snapshot', 'billing_address_snapshot', 'shipping_address_snapshot', 'terms_snapshot', 'reason', 'notes', 'subtotal', 'discount_total', 'tax_total', 'grand_total']), 'document_date' => $note->getAttribute('document_date')->format('Y-m-d')], 'items' => array_map(static fn (SalesCreditNoteItem $item): array => $item->only(['id', 'line_number', 'sales_invoice_item_id', 'sales_return_item_id', 'product_id', 'product_variant_id', 'unit_id', 'product_snapshot', 'description', 'unit_price', 'discount_percent', 'tax_percent', 'quantity', 'credit_type', 'subtotal', 'discount_amount', 'tax_amount', 'line_total']), $items)]), JSON_THROW_ON_ERROR));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        } if (! array_is_list($value)) {
            ksort($value);
        }

return array_map(fn (mixed $entry): mixed => $this->canonical($entry), $value);
    }
}

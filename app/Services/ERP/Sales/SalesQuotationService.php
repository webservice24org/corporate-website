<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use App\Models\Company;
use App\Models\Lead;
use App\Models\ProductVariant;
use App\Models\SalesQuotation;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SalesQuotationService
{
    public function __construct(
        private readonly SalesCompanyAccess $access,
        private readonly SalesDocumentGuard $guard,
        private readonly SalesSourceGuard $sources,
        private readonly SalesCustomerBridge $customers,
        private readonly SalesDocumentNumberService $numbers,
        private readonly SalesQuotationMath $math,
        private readonly SalesAuditService $audit,
    ) {}

    /** @return Builder<Company> */
    public function companies(User $actor, string $permission): Builder
    {
        if (! $actor->exists || ! $actor->can($permission)) {
            throw new AuthorizationException('Sales permission required.');
        }
        $query = Company::query();
        if (! $actor->hasRole('super-admin')) {
            $id = $actor->employee()->value('company_id');
            if ($id === null) {
                throw new AuthorizationException('Employee company required.');
            }
            $query->whereKey($id);
        }

        return $query;
    }

    public function find(User $actor, int $id, string $permission = 'erp.sales-quotations.view'): SalesQuotation
    {
        $document = SalesQuotation::query()->whereIn('company_id', $this->companies($actor, $permission)->select('id'))->findOrFail($id);
        $this->access->authorize($actor, (int) $document->getAttribute('company_id'), $permission);

        return $document;
    }

    /** @param array<string, mixed> $input */
    public function save(User $actor, array $input, ?int $id = null): SalesQuotation
    {
        $data = $this->validate($input, $id !== null);

        return DB::transaction(function () use ($actor, $data, $id): SalesQuotation {
            $before = [];
            if ($id === null) {
                $companyId = (int) $data['company_id'];
                $company = $this->access->authorize($actor, $companyId, 'erp.sales-quotations.create', true);
                $document = new SalesQuotation;
                $document->forceFill(['company_id' => $companyId, 'number' => $this->numbers->next($companyId, 'quotation', $data['document_date']), 'status' => 'draft', 'created_by' => $actor->getKey(), 'revision' => 1]);
            } else {
                $found = $this->find($actor, $id, 'erp.sales-quotations.edit');
                $companyId = (int) $found->getAttribute('company_id');
                if ($companyId !== (int) $data['company_id']) {
                    throw ValidationException::withMessages(['company_id' => 'Quotation company cannot change.']);
                }
                $company = $this->access->authorize($actor, $companyId, 'erp.sales-quotations.edit', true);
                $locked = $this->guard->lockForAction($actor, $companyId, 'quotation', $id, 'edit');
                if (! $locked instanceof SalesQuotation) {
                    throw new \LogicException('Unexpected quotation model.');
                }
                $document = $locked;
                $this->sources->currency((string) $company->getAttribute('currency_code'), (string) $document->getAttribute('currency_code'));
                $before = ['revision' => $document->getAttribute('revision'), 'grand_total' => $document->getAttribute('grand_total')];
                $this->guard->assertRevision($document, (int) $data['revision']);
                if ($data['document_date'] !== $document->getAttribute('document_date')->format('Y-m-d')) {
                    throw ValidationException::withMessages(['document_date' => 'Document date cannot change after number allocation.']);
                }
                $document->setAttribute('revision', (int) $document->getAttribute('revision') + 1);
            }
            $snapshot = $this->party($actor, $company, $data);
            $snapshot['terms_snapshot'] = [...($snapshot['terms_snapshot'] ?? []), 'issuer' => $company->only(['name', 'address', 'phone', 'email'])];
            $document->forceFill([
                ...$snapshot, 'lead_id' => $data['lead_id'] ?? null, 'opportunity_id' => $data['opportunity_id'] ?? null,
                'currency_code' => $company->getAttribute('currency_code'), 'document_date' => $data['document_date'],
                'valid_until' => $data['valid_until'], 'notes' => $data['notes'] ?? null, 'updated_by' => $actor->getKey(),
            ])->save();
            $document->items()->delete();
            $totals = ['subtotal' => 0, 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => 0];
            $rows = $data['items'];
            // Stable master lock order regardless of the UI line order.
            usort($rows, static fn (array $a, array $b): int => [(int) $a['product_id'], (int) ($a['product_variant_id'] ?? 0)] <=> [(int) $b['product_id'], (int) ($b['product_variant_id'] ?? 0)]);
            foreach ($rows as $index => $row) {
                $variantId = empty($row['product_variant_id']) ? null : (int) $row['product_variant_id'];
                $product = $this->sources->product($companyId, (int) $row['product_id'], $variantId);
                $unit = Unit::query()->where('company_id', $companyId)->where('is_active', true)->lockForUpdate()->findOrFail($product->getAttribute('unit_id'));
                $amounts = $this->math->line((string) $row['quantity'], (string) $row['unit_price'], (string) ($row['discount_percent'] ?? '0'), (string) ($row['tax_percent'] ?? '0'));
                $document->items()->create([
                    ...$amounts, 'line_number' => $index + 1, 'product_id' => $product->getKey(), 'product_variant_id' => $variantId, 'unit_id' => $unit->getKey(),
                    'product_snapshot' => ['name' => $product->getAttribute('name'), 'sku' => $product->getAttribute('sku'), 'variant_name' => $variantId === null ? null : ProductVariant::query()->whereKey($variantId)->value('name'), 'unit' => $unit->getAttribute('short_name'), 'product_type' => $product->getAttribute('product_type')],
                    'description' => $row['description'] ?? null,
                ]);
                foreach (['subtotal' => 'subtotal', 'discount_total' => 'discount_amount', 'tax_total' => 'tax_amount', 'grand_total' => 'line_total'] as $header => $line) {
                    $totals[$header] += $this->math->cents($amounts[$line]);
                }
            }
            foreach ($totals as $field => $amount) {
                $document->setAttribute($field, $this->math->format($amount));
            }
            $document->save();
            $this->audit->record($actor, $companyId, 'quotation', (int) $document->getKey(), $id === null ? 'create' : 'edit', $before, ['revision' => $document->getAttribute('revision'), 'grand_total' => $document->getAttribute('grand_total')]);

            return $document;
        }, 3);
    }

    public function transition(User $actor, int $id, string $action, int $revision, ?string $reason = null): SalesQuotation
    {
        return DB::transaction(function () use ($actor, $id, $action, $revision, $reason): SalesQuotation {
            $permission = app(SalesDomainRegistry::class)->permission('quotation', $action);
            $found = $this->find($actor, $id, $permission);
            $companyId = (int) $found->getAttribute('company_id');
            $company = $this->access->authorize($actor, $companyId, $permission, true);
            $document = SalesQuotation::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            if ($action === 'accept' && $document->getAttribute('status') === 'accepted') {
                return $document;
            }
            $this->guard->lockForAction($actor, $companyId, 'quotation', $id, $action);
            $this->guard->assertRevision($document, $revision);
            $before = (string) $document->getAttribute('status');
            $expired = $this->expired($document, $company);
            if ($action === 'expire' && ! $expired) {
                throw ValidationException::withMessages(['valid_until' => 'Quotation validity has not expired.']);
            }
            if (in_array($action, ['submit', 'approve', 'send', 'accept'], true)) {
                $this->sources->currency((string) $company->getAttribute('currency_code'), (string) $document->getAttribute('currency_code'));
                if ($expired || $document->getAttribute('customer_id') === null) {
                    throw ValidationException::withMessages(['customer_id' => 'Resolve an active customer and valid quotation before this action.']);
                }
                $this->customers->resolve($actor, $companyId, (int) $document->getAttribute('customer_id'), $document->getAttribute('lead_id') === null ? null : (int) $document->getAttribute('lead_id'), $document->getAttribute('opportunity_id') === null ? null : (int) $document->getAttribute('opportunity_id'));
                $lines = $document->items()->orderBy('product_id')->orderBy('product_variant_id')->lockForUpdate()->get();
                if ($lines->isEmpty()) {
                    throw ValidationException::withMessages(['items' => 'Quotation must have at least one item.']);
                }
                foreach ($lines as $line) {
                    $product = $this->sources->product($companyId, (int) $line->getAttribute('product_id'), $line->getAttribute('product_variant_id') === null ? null : (int) $line->getAttribute('product_variant_id'));
                    if ((int) $product->getAttribute('unit_id') !== (int) $line->getAttribute('unit_id')) {
                        throw ValidationException::withMessages(['items' => 'A product unit changed; create a revised quotation.']);
                    }
                    Unit::query()->where('company_id', $companyId)->where('is_active', true)->lockForUpdate()->findOrFail($line->getAttribute('unit_id'));
                }
            }
            $target = match ($action) {
                'submit' => 'submitted', 'approve' => 'approved', 'reject' => 'rejected', 'send' => 'sent',
                'accept' => 'accepted', 'expire' => 'expired', 'cancel' => 'cancelled',
                default => throw ValidationException::withMessages(['action' => 'Unsupported quotation transition.']),
            };
            $document->setAttribute('status', $target);
            $document->setAttribute('revision', $revision + 1);
            if ($action === 'approve') {
                $document->setAttribute('approved_by', $actor->getKey());
            }
            $timestamp = match ($action) {
                'submit' => 'submitted_at', 'approve' => 'approved_at', 'send' => 'sent_at', 'accept' => 'accepted_at', default => null,
            };
            if ($timestamp !== null) {
                $document->setAttribute($timestamp, now()->toDateTimeString());
            }
            $document->save();
            $this->audit->record($actor, $companyId, 'quotation', $id, $action, ['status' => $before, 'revision' => $revision], ['status' => $target, 'revision' => $revision + 1], $reason);

            return $document;
        }, 3);
    }

    public function resolveLead(User $actor, int $id, int $revision): SalesQuotation
    {
        return DB::transaction(function () use ($actor, $id, $revision): SalesQuotation {
            $found = $this->find($actor, $id, 'erp.sales-quotations.edit');
            $companyId = (int) $found->getAttribute('company_id');
            $locked = $this->guard->lockForAction($actor, $companyId, 'quotation', $id, 'edit');
            $this->guard->assertRevision($locked, $revision);
            $leadId = $locked->getAttribute('lead_id');
            if ($leadId === null) {
                throw ValidationException::withMessages(['lead_id' => 'Quotation has no lead.']);
            }
            $lead = Lead::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($leadId);
            $customer = $this->customers->convertLead($actor, $companyId, (int) $leadId, [
                'customer_type' => $lead->getAttribute('lead_type') === 'company' ? 'company' : 'individual',
                'name' => (string) $lead->getAttribute('contact_name'), 'business_name' => $lead->getAttribute('company_name'),
                'phone' => $lead->getAttribute('phone'), 'email' => $lead->getAttribute('email'),
                'conversion_notes' => 'Resolved from Sales quotation '.$locked->getAttribute('number'),
            ]);
            $snapshot = $this->customers->snapshot($actor, $companyId, (int) $customer->getKey());
            $oldTerms = $locked->getAttribute('terms_snapshot') ?? [];
            $snapshot['terms_snapshot'] = [...$snapshot['terms_snapshot'], 'issuer' => $oldTerms['issuer'] ?? null];
            $locked->forceFill([...$snapshot, 'customer_id' => $customer->getKey(), 'revision' => $revision + 1])->save();
            $this->audit->record($actor, $companyId, 'quotation', $id, 'edit', [], ['customer_id' => $customer->getKey(), 'revision' => $revision + 1], 'Lead resolved to customer.');

            return SalesQuotation::query()->findOrFail($id);
        }, 3);
    }

    private function expired(SalesQuotation $document, Company $company): bool
    {
        $until = $document->getAttribute('valid_until');

        return $until === null || $until->format('Y-m-d') < now((string) $company->getAttribute('timezone'))->toDateString();
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function party(User $actor, Company $company, array $data): array
    {
        $companyId = (int) $company->getKey();
        $this->access->authorize($actor, $companyId, 'erp.sales-customers.select', true);
        $this->sources->currency((string) $company->getAttribute('currency_code'), (string) $company->getAttribute('currency_code'));
        $customerId = empty($data['customer_id']) ? null : (int) $data['customer_id'];
        $leadId = empty($data['lead_id']) ? null : (int) $data['lead_id'];
        $opportunityId = empty($data['opportunity_id']) ? null : (int) $data['opportunity_id'];
        if ($customerId !== null || $opportunityId !== null) {
            $customer = $this->customers->resolve($actor, $companyId, $customerId, $leadId, $opportunityId);

            return ['customer_id' => $customer->getKey(), ...$this->customers->snapshot($actor, $companyId, (int) $customer->getKey(), empty($data['contact_id']) ? null : (int) $data['contact_id'], empty($data['billing_address_id']) ? null : (int) $data['billing_address_id'], empty($data['shipping_address_id']) ? null : (int) $data['shipping_address_id'])];
        }
        if ($leadId === null || ! $actor->can('erp.leads.view')) {
            throw ValidationException::withMessages(['customer_id' => 'Select an authorized customer or lead.']);
        }
        $lead = Lead::query()->where('company_id', $companyId)->where('is_active', true)->lockForUpdate()->findOrFail($leadId);
        $this->sources->currency((string) $company->getAttribute('currency_code'), (string) $lead->getAttribute('currency'));
        if ($lead->getAttribute('converted_at') !== null || $lead->getAttribute('converted_to_id') !== null || $lead->getAttribute('converted_to_type') !== null) {
            $customer = $this->customers->resolve($actor, $companyId, leadId: $leadId);

            return ['customer_id' => $customer->getKey(), ...$this->customers->snapshot($actor, $companyId, (int) $customer->getKey())];
        }
        if (! empty($data['contact_id']) || ! empty($data['billing_address_id']) || ! empty($data['shipping_address_id'])) {
            throw ValidationException::withMessages(['customer_id' => 'Customer child selections require a resolved customer.']);
        }

        return ['customer_id' => null, 'customer_snapshot' => ['name' => $lead->getAttribute('contact_name'), 'business_name' => $lead->getAttribute('company_name'), 'phone' => $lead->getAttribute('phone'), 'email' => $lead->getAttribute('email'), 'prospect_lead_id' => $leadId], 'billing_address_snapshot' => null, 'shipping_address_snapshot' => null, 'terms_snapshot' => null];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function validate(array $input, bool $editing): array
    {
        return Validator::make($input, [
            'company_id' => ['required', 'integer', 'min:1'], 'revision' => [$editing ? 'required' : 'nullable', 'integer', 'min:1'],
            'customer_id' => ['nullable', 'integer', 'min:1'], 'lead_id' => ['nullable', 'integer', 'min:1'], 'opportunity_id' => ['nullable', 'integer', 'min:1'],
            'contact_id' => ['nullable', 'integer', 'min:1'], 'billing_address_id' => ['nullable', 'integer', 'min:1'], 'shipping_address_id' => ['nullable', 'integer', 'min:1'],
            'document_date' => ['required', 'date_format:Y-m-d'], 'valid_until' => ['required', 'date_format:Y-m-d', 'after_or_equal:document_date'],
            'notes' => ['nullable', 'string', 'max:5000'], 'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'min:1'], 'items.*.product_variant_id' => ['nullable', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'string'], 'items.*.unit_price' => ['required', 'string'],
            'items.*.discount_percent' => ['nullable', 'string'], 'items.*.tax_percent' => ['nullable', 'string'], 'items.*.description' => ['nullable', 'string', 'max:1000'],
        ])->validate();
    }
}

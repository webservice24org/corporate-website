<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use App\Models\Company;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesQuotation;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class SalesOrderSourceService
{
    public const HEADER = ['customer_id', 'currency_code', 'customer_snapshot', 'billing_address_snapshot', 'shipping_address_snapshot', 'terms_snapshot', 'subtotal', 'discount_total', 'tax_total', 'grand_total'];

    public const LINE = ['product_id', 'product_variant_id', 'unit_id', 'product_snapshot', 'quantity', 'unit_price', 'discount_percent', 'tax_percent', 'subtotal', 'discount_amount', 'tax_amount', 'line_total', 'description'];

    public function __construct(
        private readonly SalesCustomerBridge $customers,
        private readonly SalesSourceGuard $sources,
        private readonly SalesQuotationMath $math,
    ) {}

    public function quotation(User $actor, Company $company, int $id): SalesQuotation
    {
        if (! $actor->can('erp.sales-quotations.view')) {
            throw new AuthorizationException('Accepted quotation source access requires quotation view permission.');
        }
        $companyId = (int) $company->getKey();
        $quote = SalesQuotation::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
        $until = $quote->getAttribute('valid_until');
        $today = now((string) $company->getAttribute('timezone'))->toDateString();
        if ($quote->getAttribute('status') !== 'accepted' || $quote->getAttribute('accepted_at') === null || $quote->getAttribute('customer_id') === null || $until === null || $until->format('Y-m-d') < $today) {
            $this->fail('Select an accepted, unexpired quotation with a resolved customer.');
        }
        $this->sources->currency((string) $company->getAttribute('currency_code'), (string) $quote->getAttribute('currency_code'));
        $this->customers->resolve($actor, $companyId, (int) $quote->getAttribute('customer_id'), $quote->getAttribute('lead_id') === null ? null : (int) $quote->getAttribute('lead_id'), $quote->getAttribute('opportunity_id') === null ? null : (int) $quote->getAttribute('opportunity_id'));
        $identity = $quote->getAttribute('customer_snapshot');
        if (! is_array($identity) || ! is_string($identity['name'] ?? null) || trim($identity['name']) === '') {
            $this->fail('Source quotation customer snapshot is incomplete.');
        }
        $lines = $quote->items()->orderBy('product_id')->orderBy('product_variant_id')->lockForUpdate()->get();
        if ($lines->isEmpty() || $lines->count() > 100) {
            $this->fail('Source quotation must have one to 100 valid lines.');
        }
        $totals = ['subtotal' => 0, 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => 0];
        foreach ($lines as $line) {
            $snapshot = $line->getAttribute('product_snapshot');
            if (! is_array($snapshot) || ! is_string($snapshot['name'] ?? null) || ! is_string($snapshot['unit'] ?? null)) {
                $this->fail('Source quotation product snapshot is incomplete.');
            }
            $product = $this->sources->product($companyId, (int) $line->getAttribute('product_id'), $line->getAttribute('product_variant_id') === null ? null : (int) $line->getAttribute('product_variant_id'));
            if (($snapshot['product_type'] ?? null) !== $product->getAttribute('product_type')) {
                $this->fail('A source product type changed. Use a revised accepted quotation.');
            }
            if ((int) $product->getAttribute('unit_id') !== (int) $line->getAttribute('unit_id')) {
                $this->fail('A source product unit changed. Use a revised accepted quotation.');
            }
            Unit::query()->where('company_id', $companyId)->where('is_active', true)->lockForUpdate()->findOrFail($line->getAttribute('unit_id'));
            $amounts = $this->math->line((string) $line->getAttribute('quantity'), (string) $line->getAttribute('unit_price'), (string) $line->getAttribute('discount_percent'), (string) $line->getAttribute('tax_percent'));
            foreach ($amounts as $field => $amount) {
                if ((string) $line->getAttribute($field) !== $amount) {
                    $this->fail('Source quotation amounts require review.');
                }
            }
            foreach (['subtotal' => 'subtotal', 'discount_total' => 'discount_amount', 'tax_total' => 'tax_amount', 'grand_total' => 'line_total'] as $header => $field) {
                $totals[$header] += $this->math->cents($amounts[$field]);
            }
        }
        foreach ($totals as $field => $amount) {
            if ((string) $quote->getAttribute($field) !== $this->math->format($amount)) {
                $this->fail('Source quotation header and lines do not reconcile.');
            }
        }

        return $quote;
    }

    public function assertOrder(SalesOrder $order, SalesQuotation $quote): void
    {
        $terms = $order->getAttribute('terms_snapshot') ?? [];
        $trace = $terms['sales_order_source'] ?? [];
        if (($trace['id'] ?? null) !== (int) $quote->getKey() || ($trace['number'] ?? null) !== $quote->getAttribute('number') || ($trace['revision'] ?? null) !== (int) $quote->getAttribute('revision') || ($trace['quotation_notes'] ?? null) !== $quote->getAttribute('notes') || ($trace['hash'] ?? '') !== $this->fingerprint($quote) || $this->canonical($trace['configuration'] ?? null) !== $this->canonical($this->configuration($quote))) {
            $this->fail('The accepted quotation changed after order conversion.');
        }
        unset($terms['sales_order_source']);
        foreach (self::HEADER as $field) {
            $actual = $field === 'terms_snapshot' ? $terms : $order->getAttribute($field);
            $expected = $quote->getAttribute($field);
            if ($field === 'customer_id') {
                $actual = (int) $actual;
                $expected = (int) $expected;
            }
            // Null quotation terms are copied as an empty trace-bearing object.
            if ($field === 'terms_snapshot') {
                $expected ??= [];
            }
            if ($this->canonical($actual) !== $this->canonical($expected)) {
                $this->fail('Order commercial terms differ from the accepted quotation.');
            }
        }
        $source = $quote->items()->orderBy('line_number')->get()->keyBy('id');
        $lines = $order->items()->orderBy('line_number')->lockForUpdate()->get();
        if ($lines->count() !== $source->count()) {
            $this->fail('Order must contain the complete source quotation.');
        }
        $seen = [];
        foreach ($lines as $line) {
            $id = (int) $line->getAttribute('sales_quotation_item_id');
            $original = $source->get($id);
            if ($original === null || isset($seen[$id])) {
                $this->fail('Order line source identity is invalid.');
            }
            $seen[$id] = true;
            foreach (self::LINE as $field) {
                if ($this->canonical($line->getAttribute($field)) !== $this->canonical($original->getAttribute($field))) {
                    $this->fail('Order lines differ from the accepted quotation.');
                }
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function configuration(SalesQuotation $quote): array
    {
        return $quote->items()->orderBy('line_number')->get()->map(function ($line) use ($quote): array {
            $product = Product::query()->where('company_id', $quote->getAttribute('company_id'))->findOrFail($line->getAttribute('product_id'));

            return ['source_item_id' => (int) $line->getKey(), 'product_id' => (int) $product->getKey(),
                'product_type' => (string) $product->getAttribute('product_type'), 'track_inventory' => (bool) $product->getAttribute('track_inventory'),
                'has_variants' => (bool) $product->getAttribute('has_variants'), 'unit_id' => (int) $product->getAttribute('unit_id')];
        })->values()->all();
    }

    public function fingerprint(SalesQuotation $quote): string
    {
        $payload = ['header' => [...$quote->only([...self::HEADER, 'id', 'revision', 'number', 'lead_id', 'opportunity_id', 'notes']), 'document_date' => $quote->getAttribute('document_date')->format('Y-m-d'), 'valid_until' => $quote->getAttribute('valid_until')?->format('Y-m-d')],
            'lines' => $quote->items()->orderBy('line_number')->get()->map(fn ($line): array => $line->only([...self::LINE, 'id', 'line_number']))->all()];

        return hash('sha256', json_encode($this->canonical($payload), JSON_THROW_ON_ERROR));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $entry) {
            $value[$key] = $this->canonical($entry);
        }

        return $value;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['sales_quotation_id' => $message]);
    }
}

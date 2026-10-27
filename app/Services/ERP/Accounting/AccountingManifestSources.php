<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\InventoryBalance;
use App\Models\SalesInvoice;
use App\Models\SupplierBill;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/** Safe current source observations. Never computes an as-of settlement/valuation balance. */
final class AccountingManifestSources
{
    public function __construct(private readonly AccountingCanonicalPayload $canonical) {}

    /** @return list<array<string, mixed>> */
    public function collect(int $companyId, string $date): array
    {
        $rows = [];
        foreach (SalesInvoice::query()->where('company_id', $companyId)->where('status', 'issued')->whereDate('document_date', '<', $date)->orderBy('id')->limit(501)->get() as $row) {
            $snapshot = ['company_id' => $companyId, 'id' => (int) $row->getKey(), 'number' => (string) $row->getAttribute('number'), 'party_type' => 'customer', 'party_id' => (int) $row->getAttribute('customer_id'), 'document_date' => $row->getAttribute('document_date')->format('Y-m-d'), 'currency_code' => (string) $row->getAttribute('currency_code'), 'document_total' => (string) $row->getAttribute('grand_total'), 'revision' => (int) $row->getAttribute('revision'), 'status' => 'issued', 'recognition_time' => $this->time($row->getAttribute('issued_at')), 'updated_at' => $this->time($row->getAttribute('updated_at'))];
            $rows[] = $this->row('sales_invoice', (int) $row->getKey(), 'snapshot', $snapshot);
        }
        foreach (SupplierBill::query()->where('company_id', $companyId)->where('status', 'approved')->whereDate('invoice_date', '<', $date)->orderBy('id')->limit(501)->get() as $row) {
            $snapshot = ['company_id' => $companyId, 'id' => (int) $row->getKey(), 'number' => (string) $row->getAttribute('number'), 'party_type' => 'supplier', 'party_id' => (int) $row->getAttribute('supplier_id'), 'document_date' => $row->getAttribute('invoice_date')->format('Y-m-d'), 'currency_code' => (string) $row->getAttribute('currency_code'), 'document_total' => (string) $row->getAttribute('grand_total'), 'revision' => (int) $row->getAttribute('revision'), 'status' => 'approved', 'recognition_time' => $this->time($row->getAttribute('approved_at')), 'updated_at' => $this->time($row->getAttribute('updated_at'))];
            $rows[] = $this->row('supplier_bill', (int) $row->getKey(), 'snapshot', $snapshot);
        }
        foreach (InventoryBalance::query()->where('company_id', $companyId)->orderBy('id')->limit(501)->get() as $row) {
            $snapshot = ['company_id' => $companyId, 'id' => (int) $row->getKey(), 'warehouse_id' => (int) $row->getAttribute('warehouse_id'), 'product_id' => (int) $row->getAttribute('product_id'), 'product_variant_id' => $row->getAttribute('product_variant_id') === null ? null : (int) $row->getAttribute('product_variant_id'), 'quantity_on_hand' => (string) $row->getAttribute('quantity_on_hand'), 'quantity_reserved' => (string) $row->getAttribute('quantity_reserved'), 'average_cost' => (string) $row->getAttribute('average_cost'), 'last_movement_at' => $this->time($row->getAttribute('last_movement_at')), 'updated_at' => $this->time($row->getAttribute('updated_at'))];
            $rows[] = $this->row('inventory_balance', (int) $row->getKey(), 'valuation_observation', $snapshot);
        }
        if (count($rows) > 500) {
            throw ValidationException::withMessages(['manifest' => 'More than 500 candidate rows require a reviewed batched capture workflow. No partial capture was saved.']);
        }
        usort($rows, static fn (array $a, array $b): int => [$a['source_kind'], $a['source_id']] <=> [$b['source_kind'], $b['source_id']]);

        return $rows;
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    private function row(string $kind, int $id, string $coverage, array $snapshot): array
    {
        return ['source_kind' => $kind, 'source_id' => $id, 'coverage_kind' => $coverage, 'covered_through_event' => null, 'source_payload_hash' => $this->canonical->hash($snapshot), 'opening_state' => ['recognition_state' => 'pending_reconciliation', 'snapshot' => $snapshot]];
    }

    private function time(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : null;
    }
}

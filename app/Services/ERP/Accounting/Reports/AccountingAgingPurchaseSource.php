<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Models\Company;
use App\Models\SupplierPaymentAllocation;
use App\Models\User;

final class AccountingAgingPurchaseSource
{
    public function __construct(private readonly AccountingSupplierStatementReader $supplier) {}

    /** @return array<string, mixed> */
    public function read(User $actor, Company $company): array
    {
        $id = (int) $company->getKey();
        $currency = (string) $company->getAttribute('currency_code');
        $loaded = $this->supplier->agingSource($actor, $id);
        $documents = [];
        $events = [];
        $controls = [];
        $issues = [];
        foreach ($loaded['exceptions'] as $issue) {
            $issues[] = [...$issue, 'issue' => $issue['code']];
        }
        $recognitions = [];
        foreach ($loaded['events'] as $event) {
            if ($event['event'] === 'bill_approved') {
                $recognitions[$event['bill_id']] = $event;
            }
        }
        foreach ($loaded['bills'] as $bill) {
            $recognition = $recognitions[$bill['id']] ?? null;
            $documents[] = ['id' => $bill['id'], 'company_id' => $id, 'party_id' => $bill['supplier_id'], 'currency' => $currency, 'number' => $bill['number'], 'recognized_on' => $recognition === null ? $bill['invoice_date'] : $recognition['date'], 'due_date' => $bill['due_date'], 'due_basis' => 'current_native_terms', 'amount' => $recognition === null ? null : $recognition['amount'], 'verified' => $recognition !== null];
        }
        foreach ($loaded['events'] as $event) {
            foreach ($event['control_lines'] as $line) {
                $controls[] = [...$line, 'date' => $event['date'], 'party_id' => $event['supplier_id'], 'source_kind' => $event['source_kind'], 'source_id' => $event['source_id']];
            }
            if (! in_array($event['event'], ['credit_issued', 'allocated', 'allocation_compensated'], true)) {
                continue;
            }
            $parent = null;
            if ($event['event'] === 'allocation_compensated') {
                $row = SupplierPaymentAllocation::query()->where('company_id', $id)->findOrFail($event['source_id']);
                $parent = 'supplier_payment_allocation:'.$row->getAttribute('reverses_allocation_id');
            }
            $events[] = ['id' => $event['source_kind'].':'.$event['source_id'], 'company_id' => $id, 'currency' => $currency, 'document_id' => $event['bill_id'], 'party_id' => $event['supplier_id'], 'date' => $event['date'], 'sequence' => $event['sequence'], 'direction' => $event['event'] === 'allocation_compensated' ? 'increase' : 'decrease', 'amount' => $event['amount'], 'reverses_id' => $parent];
        }

        return compact('documents', 'events', 'controls', 'issues');
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

final class SalesReportCatalog
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return [
            'quotations' => $this->definition('Quotation pipeline', 'Quotation business date; status is current.', false, ['draft', 'submitted', 'approved', 'rejected', 'sent', 'accepted', 'expired', 'cancelled'], ['valid_until' => 'Valid until'], ['amount' => 'Quoted total']),
            'orders' => $this->definition('Confirmed order register', 'Confirmation timestamp in each company timezone; original confirmed value, current status.', false, ['confirmed', 'partially_delivered', 'delivered', 'closed', 'cancelled'], [], ['amount' => 'Original order total']),
            'commitments' => $this->definition('Current unbilled commitments', 'Current snapshot; date range ignored. Unbilled returns/cancellations removed, issued quantities retained.', true, ['confirmed', 'partially_delivered', 'delivered', 'closed', 'cancelled'], ['product' => 'Product', 'variant' => 'Variant', 'unit' => 'Unit'], ['amount' => 'Unbilled commitment'], ['quantity' => 'Unbilled quantity', 'undelivered' => 'Undelivered quantity']),
            'shortages' => $this->definition('Current reservation coverage', 'Current snapshot across warehouses; date range ignored. Gaps are not assigned to a warehouse.', true, ['shortage', 'covered'], ['product' => 'Product', 'variant' => 'Variant', 'unit' => 'Unit'], [], ['quantity' => 'Undelivered physical quantity', 'reserved' => 'Owned active reservation', 'shortage' => 'Reservation shortage']),
            'deliveries' => $this->definition('Delivery fulfillment register', 'Delivery posting timestamp in each company timezone.', false, ['posted'], ['product' => 'Product', 'variant' => 'Variant', 'unit' => 'Unit', 'warehouse' => 'Warehouse', 'fulfillment' => 'Fulfillment'], ['amount' => 'Dispatch stock cost'], ['quantity' => 'Fulfilled quantity']),
            'invoices' => $this->definition('Issued invoice register', 'Invoice issue timestamp in each company timezone; operational invoiced value, not accounting revenue.', false, ['issued'], ['due_date' => 'Due date'], ['amount' => 'Issued invoice total']),
            'receivables' => $this->definition('Current invoice receivables', 'Current snapshot; date range ignored. Overdue uses company-local today, not historical aging.', true, ['open', 'overdue', 'settled'], ['due_date' => 'Due date', 'payment_status' => 'Cash payment status', 'credit_status' => 'Credit status'], ['gross' => 'Original invoice', 'paid' => 'Cash allocated', 'native_credit' => 'Native credits', 'incoming_credit' => 'Credit received', 'amount' => 'Outstanding', 'overdue' => 'Overdue outstanding']),
            'receipts' => $this->definition('Cash receipt activity', 'Receipt posting timestamp in each company timezone. Originals positive; reversal companions negative. Allocation/available fields are current.', false, ['posted', 'reversed', 'reversal'], ['payment_method' => 'Payment method', 'reference' => 'Manual reference'], ['amount' => 'Net cash activity', 'allocated' => 'Current active allocation', 'available' => 'Current unapplied cash']),
            'receipt_allocations' => $this->definition('Receipt allocation activity', 'Allocation posting timestamp in each company timezone. Original entries positive, compensations negative; status is current.', false, ['active', 'reversed', 'compensation'], ['source' => 'Receipt', 'target' => 'Invoice', 'reverses' => 'Compensates row'], ['amount' => 'Net allocation activity']),
            'advances' => $this->definition('Current unapplied cash', 'Current snapshot; date range ignored. Only unreversed original receipts with available funds.', true, ['available'], ['payment_method' => 'Payment method'], ['amount' => 'Available advance']),
            'credits' => $this->definition('Issued credit note register', 'Credit issue timestamp in each company timezone. Separate commercial credit, not cash.', false, ['issued'], ['source' => 'Original invoice'], ['amount' => 'Issued credit total']),
            'credit_allocations' => $this->definition('Customer credit allocation activity', 'Allocation posting timestamp in each company timezone. Original entries positive, compensations negative; status is current.', false, ['active', 'reversed', 'compensation'], ['source' => 'Source invoice', 'target' => 'Target invoice', 'reverses' => 'Compensates row'], ['amount' => 'Net credit transfer activity']),
            'customer_credits' => $this->definition('Current customer credit positions', 'Current snapshot; date range ignored. Available credit is separate from unapplied cash.', true, ['available', 'allocated', 'received'], [], ['capacity' => 'Originating credit', 'incoming_credit' => 'Credit received', 'outgoing_credit' => 'Credit transferred', 'amount' => 'Available customer credit']),
            'returns' => $this->definition('Physical return register', 'Return posting timestamp in each company timezone. Restock cost is separate from financial credit.', false, ['posted'], ['product' => 'Product', 'variant' => 'Variant', 'unit' => 'Unit', 'warehouse' => 'Original warehouse'], ['amount' => 'Restocked stock cost'], ['quantity' => 'Accepted quantity', 'restocked' => 'Restocked', 'damaged' => 'Damaged accepted', 'rejected' => 'Rejected', 'unbilled' => 'Unbilled return', 'credit_review' => 'Original billed-credit review quantity']),
        ];
    }

    /** @param list<string> $statuses @param array<string, string> $texts @param array<string, string> $money @param array<string, string> $quantities @return array<string, mixed> */
    private function definition(string $title, string $basis, bool $current, array $statuses, array $texts, array $money, array $quantities = []): array
    {
        $columns = [];
        foreach (['company_id' => 'Company ID', 'company' => 'Company', 'currency' => 'Currency', 'number' => 'Document / row', 'customer' => 'Customer', 'owner' => 'Created by', 'event_at' => 'Event / snapshot time', 'status' => 'Current status', ...$texts] as $key => $label) {
            $columns[] = ['key' => $key, 'label' => $label, 'kind' => 'text'];
        }
        foreach ($money as $key => $label) {
            $columns[] = ['key' => $key, 'label' => $label, 'kind' => 'money', 'scale' => str_contains($label, 'stock cost') ? 4 : 2];
        }
        foreach ($quantities as $key => $label) {
            $columns[] = ['key' => $key, 'label' => $label, 'kind' => 'quantity'];
        }
        $columns[] = ['key' => 'integrity', 'label' => 'Link / balance check', 'kind' => 'text'];

        return compact('title', 'basis', 'current', 'statuses', 'columns');
    }
}

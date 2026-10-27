<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Purchase;

use App\Services\ERP\Accounting\AccountingDecimal;
use Illuminate\Validation\ValidationException;

/** Pure 6.7 design contract. No source registration, database access, posting or authorization. */
final class AccountingSupplierPaymentContract
{
    public const VERSION = 'supplier-payment.v1';

    public function __construct(private readonly AccountingDecimal $decimal) {}

    /** Planned new tables; existing names must be reconciled before creating any of them.
     * @return list<string>
     */
    public function tables(): array
    {
        return ['supplier_payments', 'supplier_payment_targets', 'supplier_payment_allocations', 'supplier_payment_audit_logs', 'accounting_supplier_payment_operations'];
    }

    /** Future permissions, deliberately not seeded by this audit milestone.
     * @return list<string>
     */
    public function permissions(): array
    {
        return ['erp.supplier-payments.view', 'erp.supplier-payments.create', 'erp.supplier-payments.edit', 'erp.supplier-payments.submit', 'erp.supplier-payments.approve', 'erp.supplier-payments.post', 'erp.supplier-payments.allocate', 'erp.supplier-payments.reverse', 'erp.supplier-payments.cancel', 'erp.supplier-payments.export', 'erp.supplier-statements.view', 'erp.supplier-statements.export'];
    }

    /** Existing schema prerequisites checked by the local CLI audit.
     * @return array<string, list<string>>
     */
    public function requiredSchema(): array
    {
        return [
            'companies' => ['id', 'currency_code', 'status', 'timezone'],
            'suppliers' => ['id', 'company_id', 'currency_code', 'status'],
            'purchase_document_sequences' => ['id', 'company_id', 'document_type', 'year', 'last_number'],
            'supplier_bills' => ['id', 'company_id', 'supplier_id', 'currency_code', 'status', 'grand_total', 'approved_by', 'approved_at'],
            'supplier_payables' => ['id', 'company_id', 'supplier_id', 'supplier_bill_id', 'currency_code', 'amount', 'paid_amount', 'credited_amount', 'due_date'],
            'accounting_supplier_credits' => ['id', 'company_id', 'supplier_bill_id', 'status', 'amount', 'currency_code', 'issued_at'],
            'accounting_cash_accounts' => ['id', 'company_id', 'account_id', 'role', 'kind', 'currency_code', 'status'],
            'accounts' => ['id', 'company_id', 'is_postable', 'status', 'control_role'],
            'accounting_company_settings' => ['id', 'company_id', 'functional_currency', 'state', 'activation_date', 'recognition_policy', 'cutover_locked_at', 'contract_version'],
            'accounting_source_effects' => ['id', 'company_id', 'source_kind', 'source_id', 'event_kind', 'effect_kind', 'outcome', 'journal_entry_id'],
        ];
    }

    /** A role recipe only. The trusted future adapter must supply owned source/party/cash identities and recognized dependencies.
     * @return array{event: string, amount: string, lines: list<array{role: string, debit: string, credit: string}>}
     */
    public function plan(string $event, mixed $amount, ?string $cashRole = null): array
    {
        $value = $this->decimal->money($amount);
        if ($value === '0.00') {
            $this->fail('A supplier payment effect must be positive.');
        }
        $cashEvent = in_array($event, ['cash_paid', 'cash_reversed'], true);
        if ($cashEvent && ($cashRole === null || ! preg_match('/\A(?:cash|bank):[A-Z0-9][A-Z0-9_-]{0,29}\z/', $cashRole))) {
            $this->fail('Cash effects require a concrete mapped cash/bank role.');
        }
        if (! $cashEvent && $cashRole !== null) {
            $this->fail('Allocation effects cannot introduce a cash role.');
        }
        [$debit, $credit] = match ($event) {
            'cash_paid' => ['supplier_advances', $cashRole],
            'allocated' => ['trade_ap', 'supplier_advances'],
            'allocation_compensated' => ['supplier_advances', 'trade_ap'],
            'cash_reversed' => [$cashRole, 'supplier_advances'],
            default => throw ValidationException::withMessages(['supplier_payment' => 'Unsupported supplier payment event.']),
        };

        // Both roles are concrete strings after the event-specific validation above.
        return ['event' => $event, 'amount' => $value, 'lines' => [['role' => (string) $debit, 'debit' => $value, 'credit' => '0.00'], ['role' => (string) $credit, 'debit' => '0.00', 'credit' => $value]]];
    }

    /** Initial approved intent must explicitly account for the complete payment, including any advance.
     * This normalizes intent; it does not prove bill ownership, balances, cash or approval.
     *
     * @param  array<array-key, array<string, mixed>>  $targets
     * @return array{amount: string, allocated: string, advance: string, targets: list<array{supplier_bill_id: int, amount: string}>}
     */
    public function initialSplit(mixed $amount, array $targets, mixed $advance): array
    {
        $paid = $this->decimal->money($amount);
        $unapplied = $this->decimal->money($advance);
        if ($paid === '0.00' || ! array_is_list($targets) || count($targets) > 100) {
            $this->fail('Use a positive payment and at most one hundred initial bill targets.');
        }
        $normalized = [];
        $seen = [];
        $total = '0.00';
        foreach ($targets as $target) {
            $keys = array_keys($target);
            sort($keys);
            if ($keys !== ['amount', 'supplier_bill_id'] || ! is_int($target['supplier_bill_id']) || $target['supplier_bill_id'] < 1) {
                $this->fail('Each target needs only a positive integer bill ID and exact amount.');
            }
            $id = $target['supplier_bill_id'];
            $value = $this->decimal->money($target['amount']);
            if ($value === '0.00' || isset($seen[$id])) {
                $this->fail('Bill targets must be distinct and positive.');
            }
            $seen[$id] = true;
            $total = $this->decimal->total([$total, $value]);
            $normalized[] = ['supplier_bill_id' => $id, 'amount' => $value];
        }
        if ($this->decimal->total([$total, $unapplied]) !== $paid) {
            $this->fail('Initial bill allocations plus the explicitly approved advance must equal the payment.');
        }
        usort($normalized, static fn (array $a, array $b): int => $a['supplier_bill_id'] <=> $b['supplier_bill_id']);

        return ['amount' => $paid, 'allocated' => $total, 'advance' => $unapplied, 'targets' => $normalized];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['supplier_payment' => $message]);
    }
}

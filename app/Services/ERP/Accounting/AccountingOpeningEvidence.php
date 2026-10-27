<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingOpeningWorkspace;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\SupplierBill;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

final class AccountingOpeningEvidence
{
    public function __construct(private readonly AccountingJournalAmounts $amounts, private readonly AccountingCanonicalPayload $canonical) {}

    /** @return list<array<string, mixed>> */
    public function lines(JournalEntry $journal): array
    {
        return JournalEntryLine::query()->where('company_id', $journal->getAttribute('company_id'))->where('journal_entry_id', $journal->getKey())->orderBy('line_number')->get()->map(static fn (JournalEntryLine $row): array => ['line_number' => (int) $row->getAttribute('line_number'), 'account_id' => (int) $row->getAttribute('account_id'), 'debit' => (string) $row->getAttribute('debit'), 'credit' => (string) $row->getAttribute('credit'), 'branch_id' => $row->getAttribute('branch_id') === null ? null : (int) $row->getAttribute('branch_id'), 'department_id' => $row->getAttribute('department_id') === null ? null : (int) $row->getAttribute('department_id'), 'description' => $row->getAttribute('description'), 'party_type' => $row->getAttribute('party_type'), 'party_id' => $row->getAttribute('party_id') === null ? null : (int) $row->getAttribute('party_id'), 'source_line_type' => $row->getAttribute('source_line_type'), 'source_line_id' => $row->getAttribute('source_line_id') === null ? null : (int) $row->getAttribute('source_line_id'), 'cash_account_id' => $row->getAttribute('cash_account_id')])->all();
    }

    /** @param list<array<string, mixed>> $lines @return array<string, mixed> */
    public function payload(AccountingCutover $plan, JournalEntry $journal, string $reference, array $lines): array
    {
        return ['contract_version' => AccountingDomainRegistry::VERSION, 'company_id' => (int) $plan->getAttribute('company_id'), 'cutover_id' => (int) $plan->getKey(), 'opening_payload_version' => '1.1', 'strategy' => (string) $plan->getAttribute('strategy'), 'accounting_period_id' => (int) $journal->getAttribute('accounting_period_id'), 'number' => (string) $journal->getAttribute('number'), 'entry_kind' => 'opening', 'document_date' => $journal->getAttribute('document_date')?->format('Y-m-d'), 'accounting_date' => $journal->getAttribute('accounting_date')->format('Y-m-d'), 'currency_code' => (string) $journal->getAttribute('currency_code'), 'description' => (string) $journal->getAttribute('description'), 'supporting_reference' => $reference, 'lines' => $lines];
    }

    /** @return array<string, mixed> */
    public function verify(AccountingCutover $plan, AccountingOpeningWorkspace $workspace, JournalEntry $journal): array
    {
        if ((int) $workspace->getAttribute('cutover_id') !== (int) $plan->getKey() || (int) $workspace->getAttribute('journal_entry_id') !== (int) $journal->getKey() || (int) $journal->getAttribute('company_id') !== (int) $plan->getAttribute('company_id') || (int) $workspace->getAttribute('company_id') !== (int) $plan->getAttribute('company_id')) {
            $this->fail('opening', 'Opening ownership links are inconsistent.');
        }
        if ($journal->getAttribute('entry_kind') !== 'opening' || $journal->getAttribute('contract_version') !== AccountingDomainRegistry::VERSION || $journal->getAttribute('mapping_version_id') !== null || $journal->getAttribute('reverses_journal_id') !== null || $journal->getAttribute('correction_of_journal_id') !== null || $journal->getAttribute('accounting_date')->format('Y-m-d') !== $plan->getAttribute('cutover_date')->format('Y-m-d')) {
            $this->fail('opening', 'Opening identity/date or reserved metadata is inconsistent.');
        }
        $lines = $this->lines($journal);
        $totals = $this->amounts->balanced($lines);
        $payload = $this->payload($plan, $journal, (string) $workspace->getAttribute('supporting_reference'), $lines);
        if (! hash_equals((string) $journal->getAttribute('payload_hash'), $this->canonical->hash($payload))) {
            // Read compatibility for Green 6.5B unposted drafts; never rewrite on read.
            $legacy = $payload;
            unset($legacy['opening_payload_version']);
            $legacy['cutover_revision'] = (int) $plan->getAttribute('revision');
            if ($journal->getAttribute('posted_at') !== null || $journal->getAttribute('posted_by') !== null || $journal->getAttribute('status') === 'posted' || $plan->getAttribute('manifest_hash') !== null || ! hash_equals((string) $journal->getAttribute('payload_hash'), $this->canonical->hash($legacy))) {
                $this->fail('opening', 'Saved opening evidence does not match its financial hash.');
            }
        }

        return ['lines' => $lines, 'total_debit' => $totals['total_debit'], 'total_credit' => $totals['total_credit']];
    }

    /** Validates reference ownership/identity, NOT historical settlement or valuation. @param list<array<string, mixed>> $lines @return list<string> */
    public function exceptions(int $companyId, string $date, array $lines): array
    {
        $exceptions = [];
        foreach ($lines as $index => $line) {
            $account = Account::query()->where('company_id', $companyId)->findOrFail($line['account_id']);
            $role = $account->getAttribute('control_role');
            if ($line['cash_account_id'] !== null) {
                $this->fail('lines.'.$index, 'Cash account binding belongs to the future cash/bank workflow.');
            }
            if (($line['party_type'] === null) !== ($line['party_id'] === null) || ($line['source_line_type'] === null) !== ($line['source_line_id'] === null)) {
                $this->fail('lines.'.$index, 'Party and source identities each require both type and ID.');
            }
            if ($line['party_type'] !== null) {
                $class = match ($line['party_type']) {
                    'customer' => Customer::class, 'supplier' => Supplier::class, default => null
                };
                if ($class === null || ! $class::query()->where('company_id', $companyId)->whereKey($line['party_id'])->exists()) {
                    $this->fail('lines.'.$index.'.party_id', 'Choose a party owned by this company.');
                }
            }
            if ($role === 'unapplied_customer_funds' && $line['party_type'] !== 'customer') {
                $this->fail('lines.'.$index, 'Customer funds require a customer identity.');
            }
            if ($role === 'supplier_advances' && $line['party_type'] !== 'supplier') {
                $this->fail('lines.'.$index, 'Supplier advances require a supplier identity.');
            }
            if (in_array($role, ['trade_ar', 'trade_ap'], true)) {
                $party = $role === 'trade_ar' ? 'customer' : 'supplier';
                $source = $role === 'trade_ar' ? 'sales_invoice' : 'supplier_bill';
                if ($line['party_type'] !== $party || $line['source_line_type'] !== $source) {
                    $this->fail('lines.'.$index, 'Opening AR/AP requires its party and open-document reference.');
                }
            }
            if ($line['source_line_type'] !== null) {
                $class = match ($line['source_line_type']) {
                    'sales_invoice' => SalesInvoice::class, 'supplier_bill' => SupplierBill::class, 'inventory_balance' => InventoryBalance::class, default => null
                };
                $source = $class === null ? null : $class::query()->where('company_id', $companyId)->find($line['source_line_id']);
                if ($source === null) {
                    $this->fail('lines.'.$index.'.source_line_id', 'Source reference is not in this company or is unsupported.');
                }
                if ($line['source_line_type'] === 'sales_invoice' && ($source->getAttribute('status') !== 'issued' || $line['party_type'] !== 'customer' || (int) $source->getAttribute('customer_id') !== $line['party_id'] || $source->getAttribute('document_date')->format('Y-m-d') >= $date)) {
                    $this->fail('lines.'.$index, 'Use a pre-cutover issued invoice for the same customer.');
                }
                if ($line['source_line_type'] === 'supplier_bill' && ($source->getAttribute('status') !== 'approved' || $line['party_type'] !== 'supplier' || (int) $source->getAttribute('supplier_id') !== $line['party_id'] || $source->getAttribute('invoice_date')->format('Y-m-d') >= $date)) {
                    $this->fail('lines.'.$index, 'Use a pre-cutover approved bill for the same supplier.');
                }
                $exceptions[] = 'Line '.($index + 1).': source ownership is valid; historical open amount/valuation and cutover coverage remain unverified.';
            }
            if ($role !== null || $account->getAttribute('cash_flow_role') === 'cash') {
                $exceptions[] = 'Line '.($index + 1).': protected '.($role ?? 'cash').' balance requires reconciled party, valuation or cash evidence before approval.';
            }
            if ($line['party_type'] !== null && $line['source_line_type'] === null) {
                $exceptions[] = 'Line '.($index + 1).': party balance requires dated source coverage before approval.';
            }
        }

        return array_values(array_unique($exceptions));
    }

    /** @param array<string, mixed> $checked @return array<string, mixed> */
    public function snapshot(JournalEntry $row, array $checked): array
    {
        return ['number' => (string) $row->getAttribute('number'), 'entry_kind' => 'opening', 'status' => (string) $row->getAttribute('status'), 'revision' => (int) $row->getAttribute('revision'), 'accounting_date' => $row->getAttribute('accounting_date')->format('Y-m-d'), 'total_debit' => $checked['total_debit'], 'total_credit' => $checked['total_credit'], 'line_count' => count($checked['lines']), 'payload_hash' => $row->getAttribute('payload_hash'), 'approved_by' => $row->getAttribute('approved_by') === null ? null : (int) $row->getAttribute('approved_by'), 'submitted_by' => $row->getAttribute('submitted_by') === null ? null : (int) $row->getAttribute('submitted_by'), 'submitted_at' => $row->getAttribute('submitted_at') instanceof CarbonInterface ? $row->getAttribute('submitted_at')->toIso8601String() : null, 'approved_at' => $row->getAttribute('approved_at') instanceof CarbonInterface ? $row->getAttribute('approved_at')->toIso8601String() : null];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

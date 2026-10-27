<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Models\Accounting\Account;
use App\Models\Company;
use App\Models\Supplier;
use App\Services\ERP\Accounting\AccountingDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Caller has authorized the report and holds the company lock. Does not post or repair any ledger. */
final class AccountingSupplierGlReconciliation
{
    public function __construct(private readonly AccountingDecimal $decimal) {}

    /** @param array<array-key, array<string, mixed>> $events
     * @return array<string, mixed>
     */
    public function read(Company $company, array $events, string $asOf, ?int $supplierId = null): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Supplier GL reconciliation requires its authorized caller transaction.');
        }
        $companyId = (int) $company->getKey();
        $roles = [];
        $names = [];
        $issues = [];
        foreach (Account::query()->where('company_id', $companyId)->get(['id', 'code', 'name', 'control_role']) as $account) {
            $id = (int) $account->getKey();
            $names[$id] = $account->only(['code', 'name']);
            if (in_array($account->getAttribute('control_role'), ['trade_ap', 'supplier_advances'], true)) {
                $roles[$id][(string) $account->getAttribute('control_role')] = true;
            }
        }
        // Retired mappings remain necessary: current profile alone can hide old control balances.
        $maps = DB::table('accounting_account_mappings as m')->join('accounting_mapping_versions as v', function ($join): void {
            $join->on('v.id', '=', 'm.mapping_version_id')->on('v.company_id', '=', 'm.company_id');
        })->where('m.company_id', $companyId)->whereIn('v.state', ['published', 'retired'])->whereIn('m.role', ['trade_ap', 'supplier_advances'])->get(['m.account_id', 'm.role']);
        foreach ($maps as $map) {
            $id = (int) $map->account_id;
            if (! isset($names[$id])) {
                $issues[] = $this->issue('control_mapping_ownership', null, null, $id);

                continue;
            } $roles[$id][(string) $map->role] = true;
        }
        $present = [];
        foreach ($roles as $set) {
            foreach (array_keys($set) as $role) {
                $present[$role] = true;
            }
        }
        foreach (['trade_ap', 'supplier_advances'] as $role) {
            if (! isset($present[$role])) {
                $issues[] = $this->issue('control_setup_missing', null, null, null);
            }
        }
        $expected = [];
        $sourceTotals = ['ap' => '0.00', 'advance' => '0.00'];
        $accountTotals = [];
        foreach ($events as $event) {
            if ($event['date'] > $asOf || ($supplierId !== null && $event['supplier_id'] !== $supplierId)) {
                continue;
            }
            foreach ($event['control_lines'] as $line) {
                $key = $this->key($line['journal_id'], $event['source_kind'], $event['source_id'], $line['account_id'], $event['supplier_id']);
                $sum = $expected[$key] ?? ['debit' => '0.00', 'credit' => '0.00'];
                $expected[$key] = ['debit' => bcadd($sum['debit'], $line['debit'], 2), 'credit' => bcadd($sum['credit'], $line['credit'], 2)];
                $delta = $line['role'] === 'trade_ap' ? bcsub($line['credit'], $line['debit'], 2) : bcsub($line['debit'], $line['credit'], 2);
                $field = $line['role'] === 'trade_ap' ? 'ap' : 'advance';
                $sourceTotals[$field] = bcadd($sourceTotals[$field], $delta, 2);
                $accountKey = $line['role'].':'.$line['account_id'];
                $accountTotals[$accountKey] = ['role' => $line['role'], 'account_id' => $line['account_id'], 'expected' => bcadd($accountTotals[$accountKey]['expected'] ?? '0.00', $delta, 2), 'actual' => $accountTotals[$accountKey]['actual'] ?? '0.00'];
            }
        }
        $actual = [];
        $gl = ['ap' => '0.00', 'advance' => '0.00'];
        $ownedSuppliers = Supplier::query()->where('company_id', $companyId)->pluck('id')->all();
        $query = DB::table('journal_entry_lines as l')->join('journal_entries as j', function ($join): void {
            $join->on('j.id', '=', 'l.journal_entry_id')->on('j.company_id', '=', 'l.company_id');
        })->where('j.company_id', $companyId)->where(function ($query): void {
            $query->where('j.status', 'posted')->orWhereNotNull('j.posted_at')->orWhereNotNull('j.posted_by');
        })->whereDate('j.accounting_date', '<=', $asOf)->whereIn('l.account_id', array_keys($roles));
        if ($supplierId !== null) {
            $query->where('l.party_type', 'supplier')->where('l.party_id', $supplierId);
        }
        foreach ($query->orderBy('j.accounting_date')->orderBy('l.id')->get(['l.id', 'l.journal_entry_id', 'l.account_id', 'l.party_type', 'l.party_id', 'l.source_line_type', 'l.source_line_id', 'l.debit', 'l.credit', 'j.currency_code', 'j.entry_kind', 'j.status', 'j.posted_at', 'j.posted_by']) as $line) {
            $accountId = (int) $line->account_id;
            $party = $line->party_type === 'supplier' && $line->party_id !== null ? (int) $line->party_id : null;
            // An inconsistent posting marker is review evidence, not a reason to hide a control row.
            if ($line->status !== 'posted') {
                $issues[] = $this->issue('inconsistent_control_posting_state', $party, (int) $line->id, $accountId);

                continue;
            }
            if ($line->posted_at === null || $line->posted_by === null) {
                $issues[] = $this->issue('incomplete_control_posting_markers', $party, (int) $line->id, $accountId);
            }
            if ($line->currency_code !== $company->getAttribute('currency_code')) {
                $issues[] = $this->issue('foreign_currency_control_line', $party, (int) $line->id, $accountId);

                continue;
            }
            if (count($roles[$accountId]) !== 1) {
                $issues[] = $this->issue('ambiguous_control_account', $party, (int) $line->id, $accountId);

                continue;
            }
            $role = array_key_first($roles[$accountId]);
            try {
                $debit = $this->decimal->money((string) $line->debit);
                $credit = $this->decimal->money((string) $line->credit);
            } catch (ValidationException) {
                $issues[] = $this->issue('invalid_control_amount', $party, (int) $line->id, $accountId);

                continue;
            } $field = $role === 'trade_ap' ? 'ap' : 'advance';
            $delta = $role === 'trade_ap' ? bcsub($credit, $debit, 2) : bcsub($debit, $credit, 2);
            $gl[$field] = bcadd($gl[$field], $delta, 2);
            $accountKey = $role.':'.$accountId;
            $accountTotals[$accountKey] = ['role' => $role, 'account_id' => $accountId, 'expected' => $accountTotals[$accountKey]['expected'] ?? '0.00', 'actual' => bcadd($accountTotals[$accountKey]['actual'] ?? '0.00', $delta, 2)];
            if ($party === null || ! in_array($party, $ownedSuppliers, true)) {
                $issues[] = $this->issue('unassigned_or_foreign_supplier', $party, (int) $line->id, $accountId);
            }
            $key = $this->key((int) $line->journal_entry_id, (string) $line->source_line_type, (int) $line->source_line_id, $accountId, $party ?? 0);
            $sum = $actual[$key] ?? ['debit' => '0.00', 'credit' => '0.00'];
            $actual[$key] = ['debit' => bcadd($sum['debit'], $debit, 2), 'credit' => bcadd($sum['credit'], $credit, 2)];
            if ($line->entry_kind !== 'automatic' || ! isset($expected[$key])) {
                $issues[] = $this->issue($line->entry_kind === 'opening' ? 'opening_coverage_not_integrated' : 'unsupported_control_line', $party, (int) $line->id, $accountId);
            }
        }
        foreach (array_unique([...array_keys($expected), ...array_keys($actual)]) as $key) {
            if (($expected[$key] ?? ['debit' => '0.00', 'credit' => '0.00']) !== ($actual[$key] ?? ['debit' => '0.00', 'credit' => '0.00'])) {
                $issues[] = $this->issue('source_gl_line_mismatch', $supplierId, null, null);
            }
        }
        $accounts = [];
        ksort($accountTotals, SORT_STRING);
        foreach ($accountTotals as $row) {
            $accounts[] = [...$row, ...($names[$row['account_id']] ?? ['code' => null, 'name' => null]), 'difference' => bcsub($row['actual'], $row['expected'], 2)];
        }

        return ['as_of' => $asOf, 'scope' => $supplierId === null ? 'company_controls' : 'supplier_assigned_controls', 'source' => $sourceTotals, 'gl' => $gl, 'difference' => ['ap' => bcsub($gl['ap'], $sourceTotals['ap'], 2), 'advance' => bcsub($gl['advance'], $sourceTotals['advance'], 2)], 'accounts' => $accounts, 'exceptions' => $issues, 'numeric_match' => $gl === $sourceTotals, 'reconciled' => $issues === [] && $gl === $sourceTotals];
    }

    private function key(int $journal, string $kind, int $source, int $account, int $supplier): string
    {
        return $journal.':'.$kind.':'.$source.':'.$account.':'.$supplier;
    }

    /** @return array<string, mixed> */
    private function issue(string $code, ?int $supplier, ?int $line, ?int $account): array
    {
        return ['code' => $code, 'supplier_id' => $supplier, 'journal_line_id' => $line, 'account_id' => $account];
    }
}

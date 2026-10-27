<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Models\Accounting\AccountingAccountMapping;
use App\Models\Accounting\AccountingExpenseFinancePlan;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\AccountingSupplierCredit;
use App\Models\Company;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use App\Models\SupplierBill;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseFinanceEvidence;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseFinanceProof;
use App\Services\ERP\Accounting\Source\Purchase\AccountingPurchaseEvidence;
use App\Services\ERP\Accounting\Source\Sales\AccountingSalesEvidence;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Trusted source recipes only. Broad retained native candidate scan prevents a zero GL from hiding missing admissions. */
final class AccountingTaxSources
{
    public function __construct(private readonly AccountingPurchaseEvidence $purchase, private readonly AccountingSalesEvidence $sales, private readonly AccountingExpenseFinanceEvidence $expenses, private readonly AccountingExpenseFinanceProof $expenseProof) {}

    /** @param list<array<string, mixed>> $lines @param array<int, array<string, bool>> $roles @return array<string, mixed> */
    public function collect(Company $company, array $lines, array $roles, string $to): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Tax source verification requires an authorized company transaction.');
        }
        $id = (int) $company->getKey();
        $currency = (string) $company->getAttribute('currency_code');
        $candidates = [];
        $issues = [];
        $expected = [];
        $sources = [];
        $specs = [
            'supplier_bill' => [SupplierBill::class, 'approved', 'approved_at', 'invoice_date'],
            'supplier_credit' => [AccountingSupplierCredit::class, 'issued', 'issued_at', 'document_date'],
            'sales_invoice' => [SalesInvoice::class, 'issued', 'issued_at', 'document_date'],
            'sales_credit_note' => [SalesCreditNote::class, 'issued', 'issued_at', 'document_date'],
            'expense_finance' => [AccountingExpenseFinancePlan::class, 'posted', 'posted_at', 'document_date'],
        ];
        foreach ($specs as $kind => [$class, $state, $stamp, $date]) {
            $query = $class::query()->where('company_id', $id)->where(function ($q) use ($state, $stamp): void {
                $q->where('status', $state)->orWhereNotNull($stamp);
            })->where(function ($q) use ($date, $to): void {
                $q->whereDate($date, '<=', $to)->orWhereNull($date);
            });
            foreach ($query->limit(10001)->get(['id']) as $row) {
                $key = (int) $row->getKey();
                $candidates[$kind.':'.$key] = [$kind, $key];
            }
            if (count($candidates) > 10000) {
                $this->fail('Tax native candidate scope exceeds 10,000 records.');
            }
        }
        $effects = AccountingSourceEffect::query()->where('company_id', $id)->whereIn('source_kind', array_keys($specs))->whereDate('accounting_date', '<=', $to)->limit(100001)->get(['source_kind', 'source_id']);
        if ($effects->count() > 100000) {
            $this->fail('Tax financial evidence scope exceeds its interactive limit.');
        }
        foreach ($effects as $effect) {
            $kind = (string) $effect->getAttribute('source_kind');
            $key = (int) $effect->getAttribute('source_id');
            $candidates[$kind.':'.$key] = [$kind, $key];
        }
        $taxJournals = [];
        foreach ($lines as $line) {
            if (isset($roles[$line['account_id']]) && $line['entry_kind'] === 'automatic') {
                $taxJournals[(int) $line['journal_id']] = true;
            }
        }
        foreach (AccountingSourceEffect::query()->where('company_id', $id)->whereIntegerInRaw('journal_entry_id', array_keys($taxJournals))->get(['source_kind', 'source_id']) as $effect) {
            $kind = (string) $effect->getAttribute('source_kind');
            $key = (int) $effect->getAttribute('source_id');
            if (! isset($specs[$kind])) {
                $issues[] = ['issue' => 'tax_source_adapter_not_supported', 'source_kind' => $kind, 'source_id' => $key];

                continue;
            }
            $candidates[$kind.':'.$key] = [$kind, $key];
        }
        if (count($candidates) > 10000) {
            $this->fail('Tax candidate scope exceeds 10,000 unique sources; no truncated report is returned.');
        }
        foreach ($candidates as [$kind, $key]) {
            try {
                $local = [];
                $debit = '0.00';
                $credit = '0.00';
                if ($kind === 'expense_finance') {
                    $proof = $this->expenses->verify($id, $key);
                    $plan = $proof['plan'];
                    $p = $plan->getAttribute('snapshot');
                    if ($p['currency'] !== $currency) {
                        $this->fail('Unsupported source currency.');
                    }
                    $date = $p['date'];
                    $number = 'EF-'.$key;
                    $effect = $proof['effect'];
                    foreach ($this->expenseProof->lines($plan) as $line) {
                        if (! in_array($line['role'], ['input_tax', 'output_tax'], true)) {
                            continue;
                        }
                        $local[] = $this->line($effect, $line, (int) $line['account_id'], $kind, $key);
                    }
                } else {
                    $payload = in_array($kind, ['supplier_bill', 'supplier_credit'], true) ? $this->purchase->recognized($id, $kind, $key) : $this->sales->recognized($id, $kind, $key);
                    $p = $payload['projection'];
                    $date = $payload['date'] ?? $p['date'];
                    $number = (string) $p['number'];
                    if (($payload['currency'] ?? $p['currency']) !== $currency) {
                        $this->fail('Unsupported source currency.');
                    }
                    $plans = isset($payload['effects']) ? $payload['effects'] : [$payload['effect_kind'] => ['lines' => $payload['lines']]];
                    foreach ($plans as $effectKind => $plan) {
                        $effect = AccountingSourceEffect::query()->where('company_id', $id)->where('source_kind', $kind)->where('source_id', $key)->where('effect_kind', $effectKind)->sole();
                        if ($effect->getAttribute('journal_entry_id') === null && $effect->getAttribute('outcome') === 'verified_no_journal') {
                            continue;
                        }
                        foreach ($plan['lines'] as $line) {
                            if (! in_array($line['role'], ['input_tax', 'output_tax'], true)) {
                                continue;
                            }
                            if (bccomp($line['debit'], '0', 2) === 0 && bccomp($line['credit'], '0', 2) === 0) {
                                continue;
                            }
                            $map = AccountingAccountMapping::query()->where('company_id', $id)->where('mapping_version_id', $effect->getAttribute('mapping_version_id'))->where('role', $line['role'])->sole();
                            $local[] = $this->line($effect, $line, (int) $map->getAttribute('account_id'), $kind, $key);
                        }
                    }
                }
                foreach ($local as $line) {
                    $debit = bcadd($debit, $line['debit'], 2);
                    $credit = bcadd($credit, $line['credit'], 2);
                }
                $expected = [...$expected, ...$local];
                $sources[] = ['source_kind' => $kind, 'source_id' => $key, 'number' => $number, 'date' => $date, 'tax_debit' => $debit, 'tax_credit' => $credit, 'tax_line_count' => count($local)];
            } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
                $issues[] = ['issue' => 'native_tax_source_or_legacy_coverage_review', 'source_kind' => $kind, 'source_id' => $key];
            }
        }

        return compact('expected', 'sources', 'issues');
    }

    /** @param array<string, mixed> $line @return array<string, mixed> */
    private function line(AccountingSourceEffect $effect, array $line, int $account, string $kind, int $id): array
    {
        if ($effect->getAttribute('journal_entry_id') === null) {
            $this->fail('A positive tax recipe needs an owned posted journal.');
        }

        return ['journal_id' => (int) $effect->getAttribute('journal_entry_id'), 'account_id' => $account, 'date' => $effect->getAttribute('accounting_date')->format('Y-m-d'), 'role' => $line['role'], 'debit' => $line['debit'], 'credit' => $line['credit'], 'source_kind' => $line['source_line_type'] ?? $kind, 'source_id' => $line['source_line_id'] ?? $id];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['tax_report' => $message]);
    }
}

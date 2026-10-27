<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Close;

use App\Services\ERP\Accounting\Source\AccountingReconciliationCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Conservative company-state digest. Hashes raw persisted evidence; never treats DB floats as money. */
final class AccountingPeriodCloseFingerprint
{
    public function __construct(private readonly AccountingReconciliationCatalog $catalog) {}

    public function capture(int $company): string
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Capture requires a retained company lock.');
        }
        $tables = ['accounting_company_settings', 'fiscal_years', 'accounting_periods', 'accounts', 'account_groups', 'accounting_mapping_versions', 'accounting_account_mappings', 'journal_entries', 'journal_entry_lines', 'accounting_source_effects', 'accounting_cutovers', 'accounting_cutover_sources', 'accounting_cash_accounts', 'accounting_bank_statements', 'accounting_bank_statement_lines', 'accounting_bank_reconciliations', 'accounting_bank_reconciliation_operations', 'accounting_bank_finalization_events', 'accounting_expenses', 'accounting_expense_finance_plans', 'accounting_expense_finance_operations', 'inventory_balances', 'stock_movements', 'products', 'warehouses'];
        foreach ($this->catalog->definitions() as $d) {
            $class = $d['model'];
            $model = new $class;
            $tables[] = $model->getTable();
        }
        $tables = array_values(array_unique($tables));
        sort($tables, SORT_STRING);
        $identity = DB::table('companies')->where('id', $company)->first(['id', 'currency_code', 'timezone', 'status']);
        $hash = hash('sha256', 'period-close-fingerprint-v1|'.json_encode($identity, JSON_THROW_ON_ERROR));
        $total = 0;
        foreach ($tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'company_id')) {
                $this->fail('A required company-scoped evidence table is missing: '.$table);
            }
            $rows = DB::table($table)->where('company_id', $company)->orderBy('id')->limit(10001)->get();
            $total += $rows->count();
            if ($rows->count() > 10000 || $total > 100000) {
                $this->fail('Close fingerprint exceeds 10,000 rows per table or 100,000 total. No partial evidence is accepted.');
            }
            $hash = hash('sha256', $hash.'|'.$table.'|'.$rows->count());
            foreach ($rows as $row) {
                $value = (array) $row;
                if ($table === 'accounting_periods') {
                    unset($value['close_evidence_head_id'], $value['close_evidence_hash']);
                } if ($table === 'fiscal_years') {
                    unset($value['year_end_head_id'], $value['year_end_hash']);
                } ksort($value, SORT_STRING);
                $hash = hash('sha256', $hash.'|'.json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            }
        }

        return $hash;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['period_close' => $message]);
    }
}

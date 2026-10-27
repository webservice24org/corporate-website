<?php

declare(strict_types=1);

use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentContract;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// This project-root tool is CLI-only, including when tools/ is under a web document root.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$companyId = null;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help') {
        fwrite(STDOUT, "Usage: php tools/accounting-supplier-payment-audit.php [--company=ID]\nRead-only 6.7A1 preflight; JSON on stdout. Exit 0=clear preflight, 2=blockers, 1=audit failure, 64=usage error.\n");
        exit(0);
    }
    if ($companyId !== null || ! preg_match('/\A--company=([1-9][0-9]*)\z/', $argument, $match) || filter_var($match[1], FILTER_VALIDATE_INT) === false) {
        fwrite(STDERR, "Use only --company=POSITIVE_INTEGER, or --help.\n");
        exit(64);
    }
    $companyId = (int) $match[1];
}

try {
    $root = dirname(__DIR__);
    if (! is_file($root.'/vendor/autoload.php') || ! is_file($root.'/bootstrap/app.php')) {
        throw new RuntimeException('Install this tool under the Laravel project tools directory.');
    }
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $contract = $app->make(AccountingSupplierPaymentContract::class);
    $report = ['audit' => 'supplier-payment-preflight.v1', 'contract' => AccountingSupplierPaymentContract::VERSION, 'read_only' => true, 'can_post' => false, 'scope_company_id' => $companyId, 'database_driver' => DB::connection()->getDriverName(), 'basis' => 'Pre-foundation observations, not financial recognition, posting readiness or a full journal-integrity audit.', 'schema' => [], 'planned_table_collisions' => [], 'planned_file_collisions' => [], 'counts' => [], 'findings' => []];
    $finding = static function (string $severity, string $code, int $count) use (&$report): void {
        if ($count > 0) {
            $report['findings'][] = ['severity' => $severity, 'code' => $code, 'count' => $count];
        }
    };
    $schemaReady = true;
    foreach ($contract->requiredSchema() as $table => $required) {
        $exists = Schema::hasTable($table);
        $missing = $exists ? array_values(array_diff($required, Schema::getColumnListing($table))) : $required;
        $metadata = ['exists' => $exists, 'missing_columns' => $missing, 'indexes' => [], 'foreign_keys' => [], 'has_id_company_unique' => null];
        if ($exists) {
            $builder = DB::connection()->getSchemaBuilder();
            if (method_exists($builder, 'getIndexes') && method_exists($builder, 'getForeignKeys')) {
                $metadata['has_id_company_unique'] = false;
                foreach ($builder->getIndexes($table) as $index) {
                    $metadata['indexes'][] = ['name' => $index['name'], 'columns' => $index['columns'], 'unique' => $index['unique'], 'primary' => $index['primary']];
                    if ($index['columns'] === ['id', 'company_id'] && $index['unique']) {
                        $metadata['has_id_company_unique'] = true;
                    }
                }
                foreach ($builder->getForeignKeys($table) as $key) {
                    $metadata['foreign_keys'][] = ['name' => $key['name'], 'columns' => $key['columns'], 'foreign_table' => $key['foreign_table'], 'foreign_columns' => $key['foreign_columns'], 'on_delete' => $key['on_delete'], 'on_update' => $key['on_update']];
                }
            } else {
                $finding('advisory', 'constraint_introspection_unavailable', 1);
            }
        }
        $report['schema'][$table] = $metadata;
        if (! $exists || $missing !== []) {
            $schemaReady = false;
            $finding('blocker', 'missing_required_schema', 1);
        }
    }
    foreach ($contract->tables() as $table) {
        if (Schema::hasTable($table)) {
            $report['planned_table_collisions'][] = $table;
        }
    }
    $finding('blocker', 'planned_table_collision', count($report['planned_table_collisions']));
    foreach (['app/Models/SupplierPayment.php', 'app/Models/SupplierPaymentTarget.php', 'app/Models/SupplierPaymentAllocation.php', 'app/Models/SupplierPaymentAuditLog.php', 'app/Models/Accounting/AccountingSupplierPaymentOperation.php', 'app/Services/ERP/Accounting/Source/Purchase/AccountingSupplierPaymentService.php', 'app/Services/ERP/Accounting/Source/Purchase/SupplierPaymentNumberService.php', 'database/seeders/AccountingSupplierPaymentPermissionSeeder.php', 'app/Http/Controllers/ERP/Accounting/AccountingSupplierPaymentController.php', 'routes/accounting-supplier-payments.php'] as $file) {
        if (file_exists($root.'/'.$file)) {
            $report['planned_file_collisions'][] = $file;
        }
    }
    $finding('blocker', 'planned_file_collision', count($report['planned_file_collisions']));
    if ($schemaReady) {
        $scope = static function (Builder $query, string $column) use ($companyId): Builder {
            return $companyId === null ? $query : $query->where($column, $companyId);
        };
        $report['counts'] = DB::transaction(static function () use ($scope): array {
            $ap = static function () use ($scope): Builder {
                return $scope(DB::table('supplier_payables as ap')->leftJoin('supplier_bills as b', 'b.id', '=', 'ap.supplier_bill_id')->leftJoin('suppliers as s', 's.id', '=', 'ap.supplier_id')->leftJoin('companies as c', 'c.id', '=', 'ap.company_id'), 'ap.company_id');
            };
            $ownership = $ap()->where(static function (Builder $q): void {
                $q->whereNull('b.id')->orWhereNull('s.id')->orWhereNull('c.id')->orWhereColumn('b.company_id', '<>', 'ap.company_id')->orWhereColumn('b.supplier_id', '<>', 'ap.supplier_id')->orWhereColumn('s.company_id', '<>', 'ap.company_id')->orWhereColumn('b.currency_code', '<>', 'ap.currency_code')->orWhereColumn('c.currency_code', '<>', 'ap.currency_code')->orWhereColumn('b.grand_total', '<>', 'ap.amount')->orWhere('b.status', '<>', 'approved');
            })->count();
            $balances = $ap()->where(static function (Builder $q): void {
                $q->where('ap.amount', '<', 0)->orWhere('ap.paid_amount', '<', 0)->orWhere('ap.credited_amount', '<', 0)->orWhereRaw('ap.paid_amount + ap.credited_amount > ap.amount');
            })->count();
            $credits = $scope(DB::table('accounting_supplier_credits')->where('status', 'issued'), 'company_id')->select('company_id', 'supplier_bill_id')->selectRaw('SUM(amount) as native_credited')->groupBy('company_id', 'supplier_bill_id');
            $creditMismatch = $scope(DB::table('supplier_payables as ap'), 'ap.company_id')->leftJoinSub($credits, 'cr', static function ($join): void {
                $join->on('cr.company_id', '=', 'ap.company_id')->on('cr.supplier_bill_id', '=', 'ap.supplier_bill_id');
            })->whereRaw('COALESCE(cr.native_credited, 0) <> ap.credited_amount')->count();
            $orphanCredit = $scope(DB::table('accounting_supplier_credits as cr')->where('cr.status', 'issued'), 'cr.company_id')->leftJoin('supplier_payables as ap', static function ($join): void {
                $join->on('ap.company_id', '=', 'cr.company_id')->on('ap.supplier_bill_id', '=', 'cr.supplier_bill_id');
            })->where(static function (Builder $q): void {
                $q->whereNull('ap.id')->orWhereNull('cr.issued_at')->orWhereColumn('cr.currency_code', '<>', 'ap.currency_code');
            })->count();
            $missingPayable = $scope(DB::table('supplier_bills as b')->where('b.status', 'approved'), 'b.company_id')->leftJoin('supplier_payables as ap', 'ap.supplier_bill_id', '=', 'b.id')->whereNull('ap.id')->count();
            $unverifiedBill = $scope(DB::table('supplier_bills as b')->where('b.status', 'approved'), 'b.company_id')->whereNotExists(static function (Builder $q): void {
                $q->selectRaw('1')->from('accounting_source_effects as e')->whereColumn('e.company_id', 'b.company_id')->whereColumn('e.source_id', 'b.id')->where('e.source_kind', 'supplier_bill')->where('e.event_kind', 'approved')->where('e.effect_kind', 'commercial')->whereIn('e.outcome', ['posted', 'verified_no_journal']);
            })->count();
            $badCash = $scope(DB::table('accounting_cash_accounts as ca')->leftJoin('accounts as a', 'a.id', '=', 'ca.account_id')->leftJoin('companies as c', 'c.id', '=', 'ca.company_id'), 'ca.company_id')->where(static function (Builder $q): void {
                $q->whereNull('a.id')->orWhereNull('c.id')->orWhereColumn('a.company_id', '<>', 'ca.company_id')->orWhereColumn('ca.currency_code', '<>', 'c.currency_code')->orWhereNotIn('ca.kind', ['cash', 'bank'])->orWhere(static function (Builder $q): void {
                    $q->where('ca.kind', 'cash')->where('ca.role', 'not like', 'cash:%');
                })->orWhere(static function (Builder $q): void {
                    $q->where('ca.kind', 'bank')->where('ca.role', 'not like', 'bank:%');
                });
            })->count();

            return ['reserved_payment_number_namespace_rows' => $scope(DB::table('purchase_document_sequences')->where('document_type', 'supplier_payment'), 'company_id')->count(), 'companies' => $scope(DB::table('companies'), 'id')->count(), 'payables' => $ap()->count(), 'payable_identity_conflicts' => $ownership, 'invalid_payable_balances' => $balances, 'nonzero_paid_summaries_without_new_ledger' => $ap()->where('ap.paid_amount', '<>', 0)->count(), 'native_credit_summary_conflicts' => $creditMismatch, 'issued_credit_identity_conflicts' => $orphanCredit, 'approved_bills_without_payable' => $missingPayable, 'approved_bills_missing_native_approval_identity' => $scope(DB::table('supplier_bills')->where('status', 'approved'), 'company_id')->where(static function (Builder $q): void {
                $q->whereNull('approved_by')->orWhereNull('approved_at');
            })->count(), 'bills_without_original_effect_marker' => $unverifiedBill, 'cash_binding_identity_conflicts' => $badCash, 'active_cash_bindings' => $scope(DB::table('accounting_cash_accounts')->where('status', 'active'), 'company_id')->count()];
        });
        foreach (['reserved_payment_number_namespace_rows', 'payable_identity_conflicts', 'invalid_payable_balances', 'nonzero_paid_summaries_without_new_ledger', 'native_credit_summary_conflicts', 'issued_credit_identity_conflicts', 'approved_bills_without_payable', 'cash_binding_identity_conflicts'] as $code) {
            $finding('blocker', $code, $report['counts'][$code]);
        }
        $finding('advisory', 'approved_bills_missing_native_approval_identity', $report['counts']['approved_bills_missing_native_approval_identity']);
        $finding('advisory', 'bills_require_original_finance_or_legacy_coverage_verification', $report['counts']['bills_without_original_effect_marker']);
        if ($report['counts']['active_cash_bindings'] === 0) {
            $finding('advisory', 'no_active_cash_binding_observed', 1);
        }
        if ($companyId !== null && $report['counts']['companies'] !== 1) {
            $finding('blocker', 'requested_company_not_found', 1);
        }
    }
    if ($report['database_driver'] !== 'mysql') {
        $finding('advisory', 'repeat_deployment_preflight_on_mysql', 1);
    }
    $blocked = false;
    foreach ($report['findings'] as $item) {
        if ($item['severity'] === 'blocker') {
            $blocked = true;
        }
    }
    $report['result'] = $blocked ? 'blocked' : 'clear_for_foundation_design';
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    exit($blocked ? 2 : 0);
} catch (Throwable $e) {
    // Do not disclose SQL, database names, credentials, banking identifiers or private source payloads.
    fwrite(STDERR, 'Supplier-payment audit could not complete ('.get_class($e).').'.PHP_EOL);
    fwrite(STDOUT, "{\"audit\":\"supplier-payment-preflight.v1\",\"read_only\":true,\"can_post\":false,\"result\":\"audit_failed\"}\n");
    exit(1);
}

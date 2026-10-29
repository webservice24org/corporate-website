<?php

declare(strict_types=1);

/*
 * Read-only cutover observations. Run against an isolated rehearsal copy.
 * Usage: php tools/accounting_cutover_preflight.php COMPANY_ID CUTOVER_ID
 * This never certifies coverage, changes configuration or enables accounting.
 */

use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingOpeningWorkspace;
use App\Models\Accounting\JournalEntry;
use App\Services\ERP\Accounting\AccountingCutoverManifest;
use App\Services\ERP\Accounting\AccountingManifestSources;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli' || count($argv) !== 3 || ! preg_match('/^[1-9][0-9]*$/D', $argv[1]) || ! preg_match('/^[1-9][0-9]*$/D', $argv[2])) {
    fwrite(STDERR, "Usage: php tools/accounting_cutover_preflight.php COMPANY_ID CUTOVER_ID\n");
    exit(2);
}

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$companyId = (int) $argv[1];
$cutoverId = (int) $argv[2];
try {
    $report = DB::transaction(static function () use ($companyId, $cutoverId): array {
        $plan = AccountingCutover::query()->where('company_id', $companyId)->findOrFail($cutoverId);
        $setting = DB::table('accounting_company_settings')->where('company_id', $companyId)->first();
        $date = $plan->getAttribute('cutover_date')->format('Y-m-d');
        $workspace = AccountingOpeningWorkspace::query()->where('company_id', $companyId)->where('cutover_id', $cutoverId)->first();
        $opening = $workspace === null ? null : JournalEntry::query()->where('company_id', $companyId)->find($workspace->getAttribute('journal_entry_id'));
        $manifest = 'not_captured';
        if ($plan->getAttribute('manifest_hash') !== null) {
            if ($workspace === null || $opening === null) {
                $manifest = 'invalid_or_missing_opening';
            } else {
                try {
                    app(AccountingCutoverManifest::class)->verify($plan, $workspace, $opening);
                    $manifest = 'prepared_integrity_verified_not_recognized';
                } catch (Throwable $e) {
                    $manifest = 'invalid_or_unverifiable';
                }
            }
        }
        $candidates = null;
        $scan = 'complete';
        try {
            $rows = app(AccountingManifestSources::class)->collect($companyId, $date);
            $candidates = ['sales_invoice' => 0, 'supplier_bill' => 0, 'inventory_balance' => 0];
            foreach ($rows as $row) {
                $candidates[$row['source_kind']]++;
            }
        } catch (Throwable $e) {
            $scan = 'failed_or_exceeded_limit';
        }
        $mapping = DB::table('accounting_mapping_versions')->where('company_id', $companyId)
            ->where('state', 'published')->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')->orderByDesc('version')->first(['id', 'version', 'effective_from', 'payload_hash']);

        return [
            'scope' => 'preparation_observation_only',
            'company_id' => $companyId,
            'cutover_id' => $cutoverId,
            'proposed_cutover_date' => $date,
            'cutover_revision' => (int) $plan->getAttribute('revision'),
            'strategy' => (string) $plan->getAttribute('strategy'),
            'cutover_state' => (string) $plan->getAttribute('status'),
            'accounting_state' => $setting->state ?? null,
            'recorded_activation_date' => $setting->activation_date ?? null,
            'settings_revision' => isset($setting->revision) ? (int) $setting->revision : null,
            'effective_mapping_observation' => $mapping,
            'opening' => $opening === null ? null : [
                'id' => (int) $opening->getKey(), 'state' => (string) $opening->getAttribute('status'),
                'revision' => (int) $opening->getAttribute('revision'), 'payload_hash' => $opening->getAttribute('payload_hash'),
            ],
            'manifest_integrity' => $manifest,
            'candidate_scan' => $scan,
            'candidate_counts' => $candidates,
            'unverified' => [
                'dated_receivables_and_customer_funds', 'dated_payables_and_supplier_advances',
                'inventory_valuation_and_dispatch', 'bank_and_cash', 'opening_control_totals',
                'post_cutover_settlements_returns_and_reversals', 'real_data_rehearsal_and_independent_review',
            ],
            'activation_ready' => false,
            'notice' => 'Observations are not recognized coverage or proof that control balances reconcile.',
        ];
    });
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "Preflight could not inspect the requested company and cutover. Check IDs, migrations, configuration and application logs. No readiness conclusion was produced.\n");
    exit(1);
}

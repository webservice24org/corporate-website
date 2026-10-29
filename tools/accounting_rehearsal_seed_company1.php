<?php

declare(strict_types=1);

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\FiscalYear;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\AccountingCutoverManifest;
use App\Services\ERP\Accounting\AccountingCutoverPreparation;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\AccountingOpeningService;
use App\Services\ERP\Accounting\AccountingStarterService;
use App\Services\ERP\Accounting\AccountingStarterTemplate;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

if (PHP_SAPI !== 'cli' || count($argv) !== 1) {
    fwrite(STDERR, "Run without arguments: php tools/accounting_rehearsal_seed_company1.php\n");
    exit(2);
}
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// This naming check helps avoid mistakes; it cannot prove a database is a disposable copy.
$database = (string) DB::connection()->getDatabaseName();
if ($app->environment('production') || ! preg_match('/(?:rehearsal|sandbox|test)/i', basename($database))) {
    fwrite(STDERR, "Stopped: use a non-production isolated database with rehearsal, sandbox or test in its name.\n");
    exit(2);
}

try {
    $result = DB::transaction(static function (): array {
        $company = 1;
        $actor = User::query()->findOrFail(1);
        $setting = AccountingSetting::query()->where('company_id', $company)->lockForUpdate()->firstOrFail();
        if ($setting->getAttribute('state') !== 'disabled' || $setting->getAttribute('activation_date') !== null || $setting->getAttribute('functional_currency') !== 'BDT') {
            throw new RuntimeException('Company 1 must have disabled BDT accounting with no activation date.');
        }
        $year = FiscalYear::query()->where('company_id', $company)->whereDate('starts_on', '<=', '2026-10-01')
            ->whereDate('ends_on', '>=', '2026-10-01')->lockForUpdate()->get();
        if ($year->count() !== 1 || $year->first()->getAttribute('state') !== 'open') {
            throw new RuntimeException('Company 1 needs exactly one open fiscal year covering 2026-10-01.');
        }
        if (AccountingCutover::query()->where('company_id', $company)->exists()
            || DB::table('journal_entries')->where('company_id', $company)->exists()
            || DB::table('accounting_source_effects')->where('company_id', $company)->exists()
            || DB::table('accounting_mapping_versions')->where('company_id', $company)->exists()) {
            throw new RuntimeException('Existing cutover, journal, source effect or mapping profile detected; use a clean rehearsal copy and review before proceeding.');
        }

        $calendar = app(AccountingCalendarService::class);
        $periods = $calendar->generate($actor, (int) $year->first()->getKey(), [
            'company_id' => $company, 'revision' => (int) $year->first()->getAttribute('revision'),
            'reason' => 'DEMO ONLY: generate rehearsal periods',
        ]);
        $starter = app(AccountingStarterService::class)->apply($actor, [
            'company_id' => $company, 'template_id' => AccountingStarterTemplate::ID,
            'reason' => 'DEMO ONLY: rehearsal chart',
        ]);
        $accounts = Account::query()->where('company_id', $company)->get()->keyBy('code');
        $mapping = [];
        foreach (app(AccountingStarterTemplate::class)->definitions() as $definition) {
            if ($definition['role'] !== null) {
                $account = $accounts->get($definition['code']);
                if ($account === null) {
                    throw new RuntimeException('Starter account is missing.');
                }
                $mapping[$definition['role']] = (int) $account->getKey();
            }
        }
        $version = app(AccountingMappingService::class)->publish($actor, [
            'company_id' => $company, 'base_version_id' => null, 'effective_from' => '2026-10-01',
            'mappings' => $mapping, 'reason' => 'DEMO ONLY: rehearsal profile',
        ]);
        $plan = app(AccountingCutoverPreparation::class)->save($actor, [
            'company_id' => $company, 'cutover_date' => '2026-10-01', 'strategy' => 'snapshot',
            'reason' => 'DEMO ONLY: rehearsal cutover',
        ]);
        $cash = $accounts->get('1010');
        $capital = $accounts->get('3200');
        if ($cash === null || $capital === null) {
            throw new RuntimeException('Rehearsal cash or capital account missing.');
        }
        $opening = app(AccountingOpeningService::class)->save($actor, (int) $plan->getKey(), [
            'cutover_revision' => (int) $plan->getAttribute('revision'),
            'description' => 'DEMO ONLY: illustrative opening',
            'supporting_reference' => 'DEMO-ONLY-REHEARSAL-NOT-REAL-EVIDENCE',
            'reason' => 'DEMO ONLY: balanced sample opening',
            'lines' => [
                ['account_id' => (int) $cash->getKey(), 'debit' => '10000.00', 'credit' => '0.00'],
                ['account_id' => (int) $capital->getKey(), 'debit' => '0.00', 'credit' => '10000.00'],
            ],
        ]);
        $plan = app(AccountingCutoverManifest::class)->capture($actor, (int) $plan->getKey(), [
            'cutover_revision' => (int) $plan->getAttribute('revision'),
            'opening_revision' => (int) $opening->getAttribute('revision'),
            'reason' => 'DEMO ONLY: capture preparation observations',
        ]);

        return [
            'company_id' => $company, 'actor_id' => 1, 'cutover_id' => (int) $plan->getKey(),
            'cutover_date' => '2026-10-01', 'mapping_version_id' => (int) $version->getKey(),
            'opening_journal_id' => (int) $opening->getKey(), 'periods' => $periods,
            'starter_accounts' => $starter, 'opening_debit' => '10000.00', 'opening_credit' => '10000.00',
            'state' => 'draft_and_pending_reconciliation',
        ];
    });
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (ValidationException $e) {
    fwrite(STDERR, 'No changes saved. Validation issues: '.json_encode($e->errors(), JSON_UNESCAPED_SLASHES)."\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'No changes saved. '.$e->getMessage()."\n");
    exit(1);
}

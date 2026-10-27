<?php

declare(strict_types=1);

// Run from the Laravel project root. Default: inspect only. --apply repairs filename/history labels, never schemas.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $directory = $root.'/database/migrations';
    $kinds = [
        'create_bank_statement_import_foundation' => 'accounting_bank_statement_lines',
        'create_bank_reconciliation_matching_foundation' => 'accounting_bank_reconciliations',
        'create_bank_adjustment_posting_foundation' => 'accounting_bank_adjustments',
    ];
    $files = [];
    foreach ($kinds as $suffix => $table) {
        $matches = glob($directory.'/*'.$suffix.'.php');
        if ($matches === false || count($matches) !== 1) {
            throw new RuntimeException('Expected exactly one '.$suffix.' migration; found '.($matches === false ? 0 : count($matches)).'. Restore the missing phase or resolve duplicate files first. No changes made.');
        }
        $file = $matches[0];
        $content = file_get_contents($file);
        if ($content === false || ! preg_match('/Schema::create\(\s*[\'"]'.preg_quote($table, '/').'[\'"]/', $content)) {
            throw new RuntimeException('Unexpected migration contents: '.basename($file).'. No changes made.');
        }
        $files[$suffix] = $file;
    }
    $ordered = array_values($files);
    $sorted = $ordered;
    sort($sorted, SORT_STRING);
    if ($sorted === $ordered) {
        echo "Bank migrations already sort correctly: statement -> reconciliation -> adjustment. No changes needed.\n";
        foreach ($ordered as $file) {
            echo basename($file)."\n";
        }
        exit(0);
    }
    // Keep all three at the latest existing BANK migration timestamp, after earlier prerequisites.
    // Explicit ordinal suffixes make their mutual order independent of renamed local timestamps.
    $prefixes = [];
    foreach ($files as $file) {
        if (! preg_match('/\A(\d{4}_\d{2}_\d{2}_\d{6})_/', basename($file), $match)) {
            throw new RuntimeException('Unsupported migration filename: '.basename($file));
        }
        $prefixes[] = $match[1];
    }
    sort($prefixes, SORT_STRING);
    $prefix = $prefixes[count($prefixes) - 1];
    $connection = DB::connection();
    $repository = config('database.migrations', 'migrations');
    $repository = is_array($repository) ? ($repository['table'] ?? 'migrations') : $repository;
    if (! is_string($repository) || $repository === '') {
        throw new RuntimeException('Cannot resolve migrations repository table.');
    }
    $hasRepository = Schema::hasTable($repository);
    $plan = [];
    $ordinal = 0;
    foreach ($files as $suffix => $from) {
        $to = $directory.'/'.$prefix.'_z'.(++$ordinal).'_'.$suffix.'.php';
        if ($from === $to) {
            continue;
        }
        if (file_exists($to)) {
            throw new RuntimeException('Target exists: '.basename($to).'. No changes made.');
        }
        $old = pathinfo($from, PATHINFO_FILENAME);
        $new = pathinfo($to, PATHINFO_FILENAME);
        $records = $hasRepository ? $connection->table($repository)->where('migration', $old)->get(['id', 'batch'])->all() : [];
        if (count($records) > 1 || ($hasRepository && $connection->table($repository)->where('migration', $new)->exists())) {
            throw new RuntimeException('Ambiguous migration repository labels. No changes made.');
        }
        $plan[] = ['from' => $from, 'to' => $to, 'old' => $old, 'new' => $new, 'sha256' => hash_file('sha256', $from), 'records' => $records];
    }
    foreach ($plan as $step) {
        echo basename($step['from']).' -> '.basename($step['to']).(count($step['records']) === 1 ? ' [applied: preserve batch, update history label]' : ' [pending]')."\n";
    }
    if (! in_array('--apply', $argv, true)) {
        echo "Inspection only. Apply this plan with: php tools/fix-bank-migration-order.php --apply\n";
        exit(0);
    }
    $backupDirectory = $root.'/storage/app/bank-migration-order-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
    if (! mkdir($backupDirectory, 0700, true)) {
        throw new RuntimeException('Cannot create migration backup.');
    }
    foreach ($plan as $step) {
        if (! copy($step['from'], $backupDirectory.'/'.basename($step['from']))) {
            throw new RuntimeException('Cannot back up migration; originals unchanged.');
        }
    }
    if (file_put_contents($backupDirectory.'/plan.json', json_encode($plan, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Cannot save repair plan.');
    }
    $renamed = [];
    try {
        $connection->transaction(function () use ($connection, $repository, $plan, &$renamed): void {
            foreach ($plan as $step) {
                if (hash_file('sha256', $step['from']) !== $step['sha256']) {
                    throw new RuntimeException('Migration changed during repair.');
                }
                if (count($step['records']) === 1) {
                    $record = $step['records'][0];
                    $changed = $connection->table($repository)->where('id', $record->id)->where('migration', $step['old'])->where('batch', $record->batch)->update(['migration' => $step['new']]);
                    if ($changed !== 1) {
                        throw new RuntimeException('Migration history changed during repair.');
                    }
                } elseif ($connection->getSchemaBuilder()->hasTable($repository) && $connection->table($repository)->where('migration', $step['old'])->exists()) {
                    throw new RuntimeException('A migration ran during repair.');
                }
                if (! rename($step['from'], $step['to'])) {
                    throw new RuntimeException('Cannot rename migration. Close any editor/file lock and retry.');
                }
                $renamed[] = $step;
            }
        });
    } catch (Throwable $error) {
        $restoreFailed = [];
        foreach (array_reverse($renamed) as $step) {
            if (! rename($step['to'], $step['from'])) {
                $restoreFailed[] = basename($step['from']);
            }
        }
        if ($restoreFailed !== []) {
            throw new RuntimeException('Restore file names from '.$backupDirectory.': '.implode(', ', $restoreFailed), 0, $error);
        }
        throw $error;
    }
    echo "Migration order repaired. No schema or business records changed. Applied migration batches preserved.\nBackup: ".$backupDirectory."\n";
    echo "Next: php artisan test --filter=AccountingBankAdjustmentPostingTest --stop-on-failure\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}

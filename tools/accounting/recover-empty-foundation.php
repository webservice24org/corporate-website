<?php

declare(strict_types=1);
use App\Services\ERP\Accounting\AccountingFoundationRecovery;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (array_diff(array_slice($argv, 1), ['--apply']) !== []) {
    fwrite(STDERR, "Usage: php tools/accounting/recover-empty-foundation.php [--apply]\n");
    exit(2);
}
$projectRoot = dirname(__DIR__, 2);
if (! is_file($projectRoot.'/vendor/autoload.php') || ! is_file($projectRoot.'/bootstrap/app.php')) {
    fwrite(STDERR, "Run inside the installed Laravel project.\n");
    exit(2);
}
require $projectRoot.'/vendor/autoload.php';

try {
    $app = require $projectRoot.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $paths = glob($projectRoot.'/database/migrations/*_create_accounting_domain_foundation.php');
    if (! is_array($paths) || count($paths) !== 1) {
        throw new RuntimeException('Keep exactly one corrected accounting foundation migration, using its existing filename.');
    }
    $migrationText = file_get_contents($paths[0]);
    if ($migrationText === false || ! str_contains($migrationText, "dateTime('occurred_at')") || ! str_contains($migrationText, 'recover_empty_foundation')) {
        throw new RuntimeException('Replace the migration with the full corrected file before recovery.');
    }
    $verified = $app->make(AccountingFoundationRecovery::class)->inspect();
    fwrite(STDOUT, "Preflight passed. Verified existing EMPTY tables:\n".($verified === [] ? "(none)\n" : implode("\n", $verified)."\n"));
    fwrite(STDOUT, "Existing tables are preserved. Only missing foundation tables will be created.\n");
    if (! in_array('--apply', $argv, true)) {
        fwrite(STDOUT, "Preview only. Run again with --apply to finish the migration.\n");
        exit(0);
    }
    // One-process opt-in; not an .env setting. The migration rechecks all metadata before DDL.
    config(['accounting.recover_empty_foundation' => true]);
    $relativePath = 'database/migrations/'.basename($paths[0]);
    $status = Artisan::call('migrate', ['--path' => [$relativePath], '--force' => true]);
    fwrite(STDOUT, Artisan::output());
    exit($status);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Recovery stopped: '.$exception->getMessage()."\nNo table deletion or truncation is performed by this recovery.\n");
    exit(2);
}

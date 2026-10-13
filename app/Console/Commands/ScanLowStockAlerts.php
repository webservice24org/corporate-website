<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Inventory\LowStockAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ScanLowStockAlerts extends Command
{
    protected $signature = 'inventory:scan-low-stock-alerts {--company= : Scan one active company ID only}';

    protected $description = 'Reconcile low-stock alerts for active ERP companies';

    public function handle(LowStockAlertService $service): int
    {
        $companyOption = $this->option('company');

        if (
            $companyOption !== null
            && (
                ! ctype_digit($companyOption)
                || (int) $companyOption <= 0
            )
        ) {
            $this->error('The --company option must be a positive integer.');

            return self::FAILURE;
        }

        $query = Company::query()->active();

        if ($companyOption !== null) {
            $query->whereKey((int) $companyOption);
        }

        $totals = [
            'companies' => 0,
            'created' => 0,
            'updated' => 0,
            'reopened' => 0,
            'resolved' => 0,
            'unchanged' => 0,
            'failed' => 0,
        ];

        // Company-by-company processing prevents one failure from stopping other companies.
        $query->orderBy('id')->chunkById(100, function ($companies) use ($service, &$totals): void {
            /** @var Company $company */
            foreach ($companies as $company) {
                $companyId = (int) $company->getKey();
                $totals['companies']++;

                try {
                    $result = $service->syncCompany($companyId);

                    foreach (['created', 'updated', 'reopened', 'resolved', 'unchanged'] as $key) {
                        $totals[$key] += $result[$key];
                    }

                    $this->line(sprintf(
                        'Company %d: created=%d updated=%d reopened=%d resolved=%d unchanged=%d',
                        $companyId,
                        $result['created'],
                        $result['updated'],
                        $result['reopened'],
                        $result['resolved'],
                        $result['unchanged']
                    ));
                } catch (Throwable $exception) {
                    $totals['failed']++;
                    Log::error('Low-stock alert scan failed', [
                        'company_id' => $companyId,
                        'exception' => $exception,
                    ]);
                    $this->error(sprintf('Company %d: failed; see Laravel log.', $companyId));
                }
            }
        });

        if ($totals['companies'] === 0) {
            $this->warn('No matching active companies found.');

            return $companyOption !== null ? self::FAILURE : self::SUCCESS;
        }

        $this->info(sprintf(
            'Scanned=%d Created=%d Updated=%d Reopened=%d Resolved=%d Unchanged=%d Failed=%d',
            $totals['companies'],
            $totals['created'],
            $totals['updated'],
            $totals['reopened'],
            $totals['resolved'],
            $totals['unchanged'],
            $totals['failed']
        ));

        return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

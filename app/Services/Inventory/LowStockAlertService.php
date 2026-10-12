<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Company;
use App\Models\InventoryLowStockAlert;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Reconciles a complete, unfiltered low-stock snapshot for one company.
 * Reconciles inventory alerts, then delivers idempotent database notifications.
 * Never modifies inventory quantities.
 */
final class LowStockAlertService
{
    public function __construct(
        private readonly LowStockService $lowStockService,
        private readonly LowStockNotificationService $notificationService,
    )
    {
    }

    /**
     * @return array{created:int,updated:int,reopened:int,resolved:int,unchanged:int}
     */
    public function syncCompany(int $companyId): array
    {
        if ($companyId <= 0) {
            throw new InvalidArgumentException('A valid company ID is required.');
        }

        $counts = DB::transaction(function () use ($companyId): array {
            // Serializes scans for this company across workers using the same DB.
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->first();

            if ($company === null) {
                throw new InvalidArgumentException('Company does not exist.');
            }

            // Never pass user filters: a partial report would incorrectly resolve alerts.
            $report = $this->lowStockService->report($companyId);
            $rows = $report['rows'];
            $now = now();
            $counts = [
                'created' => 0,
                'updated' => 0,
                'reopened' => 0,
                'resolved' => 0,
                'unchanged' => 0,
            ];

            // Only active, eligible non-healthy positions appear in this snapshot.
            $seen = [];

            foreach ($rows as $row) {
                $severity = (string) $row['status'];
                if (
                    !in_array($severity, [
                        LowStockService::STATUS_OUT_OF_STOCK,
                        LowStockService::STATUS_CRITICAL,
                        LowStockService::STATUS_LOW_STOCK,
                    ], true)
                ) {
                    throw new RuntimeException('Unexpected status in low-stock alert snapshot.');
                }

                if ((int) $row['company_id'] !== $companyId) {
                    throw new RuntimeException('Cross-company inventory row encountered.');
                }

                $position = [
                    'company_id' => $companyId,
                    'warehouse_id' => (int) $row['warehouse_id'],
                    'product_id' => (int) $row['product_id'],
                    'variant_key' => (int) $row['variant_key'],
                ];
                $positionKey = $this->positionKey($position);
                if (isset($seen[$positionKey])) {
                    throw new RuntimeException('Duplicate inventory position in snapshot.');
                }
                $seen[$positionKey] = true;

                $snapshot = [
                    'product_variant_id' => $row['product_variant_id'] === null
                        ? null : (int) $row['product_variant_id'],
                    'severity' => $severity,
                    'quantity_available' => (string) $row['quantity_available'],
                    'minimum_stock' => (string) $row['minimum_stock'],
                    'reorder_level' => (string) $row['reorder_level'],
                    'suggested_reorder_quantity' => (string) $row['suggested_reorder_quantity'],
                ];

                $alert = InventoryLowStockAlert::query()
                    ->where($position)
                    ->first();

                if ($alert === null) {
                    InventoryLowStockAlert::query()->create(array_merge(
                        $position,
                        $snapshot,
                        [
                            'status' => InventoryLowStockAlert::STATUS_ACTIVE,
                            'first_detected_at' => $now->toDateTimeString(),
                            'last_detected_at' => $now->toDateTimeString(),
                            'resolved_at' => null,
                            'occurrence_count' => 1,
                        ]
                    ));
                    $counts['created']++;
                    continue;
                }

                if ($alert->status === InventoryLowStockAlert::STATUS_RESOLVED) {
                    $alert->fill(array_merge($snapshot, [
                        'status' => InventoryLowStockAlert::STATUS_ACTIVE,
                        'first_detected_at' => $now->toDateTimeString(),
                        'last_detected_at' => $now->toDateTimeString(),
                        'resolved_at' => null,
                        'occurrence_count' => (int) $alert->occurrence_count + 1,
                    ]));
                    $alert->save();
                    $counts['reopened']++;
                    continue;
                }

                if ($alert->status !== InventoryLowStockAlert::STATUS_ACTIVE) {
                    throw new RuntimeException('Unknown low-stock alert lifecycle status.');
                }

                $alert->fill($snapshot);
                if ($alert->isDirty()) {
                    $alert->last_detected_at = $now->toDateTimeString();
                    $alert->save();
                    $counts['updated']++;
                } else {
                    // last_detected_at denotes last meaningful detection/change,
                    // not every scheduler scan; avoids unnecessary writes.
                    $counts['unchanged']++;
                }
            }

            // Resolve active positions absent from the complete snapshot.
            // Use a query-builder update rather than save() on the chunk callback
            // item, which some static analyzers infer as stdClass.
            InventoryLowStockAlert::query()
                ->where('company_id', $companyId)
                ->where('status', InventoryLowStockAlert::STATUS_ACTIVE)
                ->chunkById(200, function ($alerts) use ($seen, $now, &$counts, $companyId): void {
                    foreach ($alerts as $alert) {
                        $key = $this->positionKey([
                            'company_id' => (int) $alert->company_id,
                            'warehouse_id' => (int) $alert->warehouse_id,
                            'product_id' => (int) $alert->product_id,
                            'variant_key' => (int) $alert->variant_key,
                        ]);

                        if (isset($seen[$key])) {
                            continue;
                        }

                        $affected = InventoryLowStockAlert::query()
                            ->whereKey((int) $alert->id)
                            ->where('company_id', $companyId)
                            ->where('status', InventoryLowStockAlert::STATUS_ACTIVE)
                            ->update([
                                'status' => InventoryLowStockAlert::STATUS_RESOLVED,
                                'resolved_at' => $now->toDateTimeString(),
                                'updated_at' => $now->toDateTimeString(),
                            ]);

                        $counts['resolved'] += $affected;
                    }
                });

            return $counts;
        }, 3);

        // Notification writes must occur after the alert transaction commits.
        // Rechecking all active alerts also retries missed deliveries from a
        // prior failed scan, without duplicating existing notifications.
        // occurrence_count=1 denotes creation; subsequent occurrences reopen.
        foreach (
            InventoryLowStockAlert::query()
                ->where('company_id', $companyId)
                ->where('status', InventoryLowStockAlert::STATUS_ACTIVE)
                ->orderBy('id')
                ->cursor() as $alert
        ) {
            $event = (int) $alert->occurrence_count === 1
                ? 'created'
                : 'reopened';

            $this->notificationService->sendForOccurrence($alert, $event);
        }

        return $counts;
    }

    /** @param array{company_id:int,warehouse_id:int,product_id:int,variant_key:int} $position */
    private function positionKey(array $position): string
    {
        return implode(':', [
            $position['company_id'],
            $position['warehouse_id'],
            $position['product_id'],
            $position['variant_key'],
        ]);
    }
}

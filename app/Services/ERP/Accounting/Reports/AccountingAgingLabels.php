<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Models\User;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/** Current scoped party display names only, including retained soft-deleted rows. No private CRM fields. */
final class AccountingAgingLabels
{
    public function __construct(private readonly PurchaseCompanyAccess $access) {}

    /** @param array<string, mixed> $report @return array<string, mixed> */
    public function decorate(User $actor, int $company, string $kind, array $report, bool $links = false): array
    {
        abort_unless(in_array($kind, ['ar', 'ap'], true), 422);
        $this->access->authorize($actor, $company, 'erp.'.$kind.'-aging.view');
        $ids = array_values(array_unique(array_column($report['rows'], 'party_id')));
        $names = $ids === [] ? [] : DB::table($kind === 'ar' ? 'customers' : 'suppliers')->where('company_id', $company)->whereIntegerInRaw('id', $ids)->pluck('name', 'id')->all();
        $route = $kind === 'ar' ? 'erp.sales.invoices.show' : 'erp.purchase.bills.show';
        $native = $links && $actor->can($kind === 'ar' ? 'erp.sales-invoices.view' : 'erp.supplier-bills.view') && Route::has($route);
        $numbers = [];
        foreach ($report['rows'] as &$row) {
            $row['party_label'] = (string) ($names[$row['party_id']] ?? (($kind === 'ar' ? 'Customer #' : 'Supplier #').$row['party_id']));
            $row['native_url'] = $native ? route($route, $row['document_id'], false) : null;
            $numbers[$row['document_id']] = $row['number'];
        } unset($row);
        foreach ($report['issues'] as &$issue) {
            if (isset($issue['document_id'], $numbers[$issue['document_id']])) {
                $issue['document_number'] = $numbers[$issue['document_id']];
            }
        } unset($issue);

        return $report;
    }
}

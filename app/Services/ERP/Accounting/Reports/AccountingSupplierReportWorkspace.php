<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Models\Company;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;

final class AccountingSupplierReportWorkspace
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingBusinessDate $dates) {}

    /** @return array<string, mixed> */
    public function context(User $actor, ?int $companyId, bool $reconciliation = false): array
    {
        abort_unless($actor->exists && $actor->can('erp.supplier-statements.view'), 403);
        if ($reconciliation) {
            abort_unless($actor->can('erp.accounting-reports.view'), 403);
        }
        $query = Company::query();
        if (! $actor->hasRole('super-admin')) {
            $owned = $actor->employee()->value('company_id');
            abort_if($owned === null, 403);
            $query->whereKey($owned);
        }
        $companies = $query->orderBy('name')->orderBy('id')->get(['id', 'code', 'name', 'currency_code', 'status', 'timezone']);
        if ($companyId === null && $companies->count() === 1) {
            $companyId = (int) $companies->firstOrFail()->getKey();
        }
        if ($companyId !== null) {
            $this->access->authorize($actor, $companyId, 'erp.supplier-statements.view');
            if ($reconciliation) {
                $this->access->authorize($actor, $companyId, 'erp.accounting-reports.view');
            }
        }
        $company = $companyId === null ? null : $companies->firstWhere('id', $companyId);
        $suppliers = $company === null ? [] : Supplier::query()->where('company_id', $companyId)->orderBy('name')->orderBy('id')->get(['id', 'code', 'name', 'status'])->map(static fn (Supplier $row): array => $row->only(['id', 'code', 'name', 'status']))->all();

        return ['companies' => $companies->map(static fn (Company $row): array => $row->only(['id', 'code', 'name', 'currency_code', 'status']))->all(), 'company' => $company === null ? null : $company->only(['id', 'code', 'name', 'currency_code', 'status']), 'suppliers' => $suppliers, 'today' => $company === null ? null : $this->dates->today($company), 'canExport' => $actor->can('erp.supplier-statements.export') && (! $reconciliation || $actor->can('erp.accounting-reports.export')), 'canReconcile' => $actor->can('erp.accounting-reports.view')];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Books;

use App\Models\Accounting\AccountingCashAccount;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;

final class AccountingCashBookWorkspace
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingBusinessDate $dates) {}

    /** @return array<string, mixed> */
    public function context(User $actor, ?int $companyId, string $kind): array
    {
        abort_unless($actor->exists && $actor->can(AccountingCashBookReader::PERMISSION), 403);
        abort_unless(in_array($kind, ['cash', 'bank'], true), 404);
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
            $this->access->authorize($actor, $companyId, AccountingCashBookReader::PERMISSION);
        }
        $company = $companyId === null ? null : $companies->firstWhere('id', $companyId);
        $bindings = $company === null ? [] : AccountingCashAccount::query()->where('company_id', $companyId)
            ->where('kind', $kind)->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'role', 'kind', 'currency_code', 'status', 'account_id'])
            ->map(static fn (AccountingCashAccount $row): array => $row->only(['id', 'name', 'role', 'kind', 'currency_code', 'status', 'account_id']))->all();

        return [
            'companies' => $companies->map(static fn (Company $row): array => $row->only(['id', 'code', 'name', 'currency_code', 'status']))->all(),
            'company' => $company === null ? null : $company->only(['id', 'code', 'name', 'currency_code', 'status']),
            'bindings' => $bindings, 'kind' => $kind, 'today' => $company === null ? null : $this->dates->today($company),
            'canExport' => $actor->can(AccountingCashBookReader::EXPORT_PERMISSION),
            'canReadiness' => $actor->can('erp.cash-accounts.view'),
        ];
    }
}

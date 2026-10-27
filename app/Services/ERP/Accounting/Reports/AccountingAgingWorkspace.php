<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;

final class AccountingAgingWorkspace
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingBusinessDate $dates, private readonly AccountingAgingReader $reader, private readonly AccountingAgingLabels $labels) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function page(User $actor, string $kind, array $input): array
    {
        $data = Validator::make([...$input, 'kind' => $kind], ['kind' => ['required', 'in:ar,ap'], 'company_id' => ['nullable', 'integer', 'min:1'], 'as_of' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'], 'from' => ['prohibited'], 'to' => ['prohibited'], 'party_id' => ['prohibited'], 'account_id' => ['prohibited'], 'branch_id' => ['prohibited'], 'department_id' => ['prohibited']])->validate();
        $permission = 'erp.'.$kind.'-aging.view';
        if (! $actor->can($permission)) {
            throw new AuthorizationException;
        }
        $query = Company::query();
        if (! $actor->hasRole('super-admin')) {
            $query->whereIn('id', $actor->employee()->select('company_id'));
        }
        $companies = [];
        foreach ($query->orderBy('code')->get(['id', 'code', 'name', 'status']) as $company) {
            $companies[] = ['id' => (int) $company->getKey(), 'code' => (string) $company->getAttribute('code'), 'name' => (string) $company->getAttribute('name'), 'status' => (string) $company->getAttribute('status')];
        }
        $id = isset($data['company_id']) ? (int) $data['company_id'] : null;
        if ($id === null && ! $actor->hasRole('super-admin') && count($companies) === 1) {
            $id = $companies[0]['id'];
        }
        $owner = null;
        $report = null;
        $asOf = (string) ($data['as_of'] ?? '');
        if ($id !== null) {
            $this->access->authorize($actor, $id, $permission);
            $owner = Company::query()->findOrFail($id);
            if ($asOf === '') {
                $asOf = $this->dates->today($owner);
            }
            $report = $this->reader->report($actor, $id, $kind, ['as_of' => $asOf]);
            $report = $this->labels->decorate($actor, $id, $kind, $report, true);
        }

        return ['kind' => $kind, 'companies' => $companies, 'company' => $owner === null ? null : ['id' => (int) $owner->getKey(), 'code' => (string) $owner->getAttribute('code'), 'name' => (string) $owner->getAttribute('name')], 'today' => $owner === null ? null : $this->dates->today($owner), 'asOf' => $asOf, 'report' => $report, 'canAr' => $actor->can('erp.ar-aging.view'), 'canAp' => $actor->can('erp.ap-aging.view'), 'canExport' => $actor->can('erp.'.$kind.'-aging.export'), 'canLedger' => $actor->can(AccountingLedgerReader::LEDGER_PERMISSION), 'canTrial' => $actor->can(AccountingLedgerReader::TRIAL_PERMISSION)];
    }
}

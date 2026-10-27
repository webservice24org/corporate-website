<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCompanyAccess;
use App\Services\ERP\Accounting\Source\AccountingReconciliationCatalog;
use App\Services\ERP\Accounting\Source\AccountingSourceReconciliation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AccountingSourceReconciliationController extends Controller
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingSourceReconciliation $reader, private readonly AccountingReconciliationCatalog $catalog) {}

    public function index(Request $request): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1']]);
        $companies = $this->access->companies($actor, 'erp.accounting-reports.view')->orderBy('name')->get(['id', 'name', 'currency_code']);
        $companyId = empty($data['company_id']) ? null : (int) $data['company_id'];
        if ($companyId === null && $companies->count() === 1) {
            $companyId = (int) $companies->firstOrFail()->getKey();
        }
        $report = $companyId === null ? null : $this->reader->page($actor, $companyId, $request->only(['kind', 'after_kind', 'after_id', 'limit']));

        return Inertia::render('ERP/Accounting/SourceReconciliation/Index', [
            'companies' => $companies->map(static fn (Company $company): array => ['id' => (int) $company->getKey(), 'name' => (string) $company->getAttribute('name'), 'currency_code' => (string) $company->getAttribute('currency_code')])->all(),
            'companyId' => $companyId, 'report' => $report,
            'kinds' => array_map(static fn (string $kind): array => ['value' => $kind, 'label' => ucwords(str_replace('_', ' ', $kind))], array_keys($this->catalog->definitions())),
            'canViewCutover' => $actor->can('erp.accounting-openings.view'),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\AccountingSetting;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingAccountService;
use App\Services\ERP\Accounting\AccountingCompanyAccess;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\AccountingStarterService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AccountingStarterController extends Controller
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingStarterService $starter, private readonly AccountingMappingService $mappings, private readonly AccountingAccountService $accounts) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'date' => ['nullable', 'date_format:Y-m-d']]);
        $actor = $this->actor($request);
        $companies = $this->access->companies($actor, 'erp.accounts.view')->orderBy('name')->orderBy('id')->get(['id', 'name', 'code', 'currency_code', 'timezone', 'status']);
        $id = isset($data['company_id']) ? (int) $data['company_id'] : null;
        if ($id !== null) {
            $this->access->authorize($actor, $id, 'erp.accounts.view');
        }
        if ($id === null && $companies->count() === 1) {
            $single = $companies->first();
            $id = $single === null ? null : (int) $single->getKey();
        }
        $company = $id === null ? null : $companies->firstWhere('id', $id);
        if ($id !== null && $company === null) {
            abort(404);
        }
        $preview = $company === null ? null : $this->starter->preview($actor, (int) $company->getKey());
        $date = isset($data['date']) ? (string) $data['date'] : CarbonImmutable::now($company === null ? 'UTC' : (string) $company->getAttribute('timezone'))->toDateString();
        $summary = ['groups' => 0, 'accounts' => 0, 'active_postable' => 0, 'hierarchy_issues' => []];
        if ($company !== null) {
            try {
                $chart = $this->accounts->read($actor, (int) $company->getKey());
                $summary['groups'] = count($chart['groups']);
                $summary['accounts'] = count($chart['accounts']);
                $summary['active_postable'] = count(array_filter($chart['accounts'], static fn (array $row): bool => $row['status'] === 'active' && $row['is_postable']));
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $messages) {
                    foreach ($messages as $message) {
                        $summary['hierarchy_issues'][] = $message;
                    }
                }
            }
        }
        $mapping = $company !== null && $actor->can('erp.accounting-mappings.view') ? $this->mappings->readiness($actor, (int) $company->getKey(), $date) : null;
        $setting = $company === null ? null : AccountingSetting::query()->where('company_id', $company->getKey())->first();

        return Inertia::render('ERP/Accounting/Starter/Index', ['companies' => $companies->map(static fn (Company $row): array => $row->only(['id', 'name', 'code', 'currency_code', 'timezone', 'status']))->all(), 'company' => $company === null ? null : $company->only(['id', 'name', 'code', 'currency_code', 'timezone', 'status']), 'preview' => $preview, 'summary' => $summary, 'mapping' => $mapping, 'date' => $date, 'setupState' => $setting === null ? null : (string) $setting->getAttribute('state'), 'canApply' => $preview !== null && $preview['issues'] === [] && $preview['counts']['conflict'] === 0 && $summary['hierarchy_issues'] === [] && $actor->can('erp.accounts.create'), 'canViewSetup' => $actor->can('erp.accounting-settings.view'), 'canViewMappings' => $actor->can('erp.accounting-mappings.view')]);
    }

    public function apply(Request $request): RedirectResponse
    {
        $result = $this->starter->apply($this->actor($request), $request->all());

        return redirect()->route('erp.accounting.starter.index', ['company_id' => (int) $request->input('company_id')])->with('success', 'Starter applied: '.$result['created'].' accounts created; '.$result['reused'].' compatible accounts preserved. Review Posting Profiles next.');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\AccountingAuditEvent;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingCalendarDates;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\AccountingCompanyAccess;
use App\Services\ERP\Accounting\AccountingConfigurationGuard;
use App\Services\ERP\Accounting\AccountingPeriodGuard;
use App\Services\ERP\Accounting\AccountingPeriodStateService;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AccountingCalendarController extends Controller
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingConfigurationGuard $configuration, private readonly AccountingCalendarDates $dates, private readonly AccountingCalendarService $service, private readonly AccountingPeriodStateService $states, private readonly AccountingPeriodGuard $guard, private readonly AccountingBusinessDate $businessDate) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1']]);
        $actor = $this->actor($request);
        $context = $this->context($actor, isset($data['company_id']) ? (int) $data['company_id'] : null);
        $years = $context['company'] === null ? [] : FiscalYear::query()->where('company_id', $context['company']['id'])->orderByDesc('starts_on')->get()->map(fn (FiscalYear $row): array => $this->year($row))->all();

        return Inertia::render('ERP/Accounting/Calendar/Index', [...$context, 'years' => $years, 'canManageYears' => $actor->can('erp.fiscal-years.manage'), 'canViewPeriods' => $actor->can('erp.accounting-periods.view')]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1']]);
        $context = $this->context($this->actor($request), isset($data['company_id']) ? (int) $data['company_id'] : null);
        if ($context['company'] === null || $context['issues'] !== []) {
            return redirect()->route('erp.accounting.calendar.index', ['company_id' => $context['company']['id'] ?? null])->with('error', 'Choose an initialized active company before creating a fiscal year.');
        }

        return Inertia::render('ERP/Accounting/Calendar/YearForm', [...$context, 'year' => null, 'canSave' => true]);
    }

    public function edit(Request $request, int $year): Response
    {
        $actor = $this->actor($request);
        $row = $this->find($actor, $year);
        $context = $this->context($actor, (int) $row->getAttribute('company_id'));

        return Inertia::render('ERP/Accounting/Calendar/YearForm', [...$context, 'year' => $this->year($row), 'canSave' => $context['issues'] === [] && $row->getAttribute('state') === 'open']);
    }

    public function show(Request $request, int $year): Response
    {
        $data = $request->validate(['accounting_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:9999-12-31']]);
        $actor = $this->actor($request);
        $row = $this->find($actor, $year);
        $companyId = (int) $row->getAttribute('company_id');
        $this->access->authorize($actor, $companyId, 'erp.accounting-periods.view');
        $context = $this->context($actor, $companyId);
        $periods = AccountingPeriod::query()->where('company_id', $companyId)->where('fiscal_year_id', $year)->orderBy('starts_on')->get()->map(function (AccountingPeriod $period) use ($actor, $companyId, $context, $row): array {
            $state = (string) $period->getAttribute('state');
            $closer = $period->getAttribute('closed_by');
            $closedAt = $period->getAttribute('closed_at');
            $writable = $context['issues'] === [] && $row->getAttribute('state') === 'open';
            $independent = $closer !== null && (int) $closer !== (int) $actor->getKey();

            return ['id' => (int) $period->getKey(), ...$period->only(['code', 'state', 'revision']), 'starts_on' => $period->getAttribute('starts_on')->format('Y-m-d'), 'ends_on' => $period->getAttribute('ends_on')->format('Y-m-d'), 'semantic_locked' => JournalEntry::query()->where('company_id', $companyId)->where('accounting_period_id', $period->getKey())->exists(), 'last_closed_at' => $closedAt instanceof CarbonInterface ? $closedAt->toIso8601String() : null, 'can_close' => $writable && in_array($state, ['open', 'soft_closed'], true) && $actor->can('erp.accounting-periods.close'), 'can_reopen' => $writable && in_array($state, ['soft_closed', 'hard_closed'], true) && $independent && $actor->can('erp.accounting-periods.reopen'), 'reopen_requires_independent' => $state !== 'open' && ! $independent];
        })->all();
        $canManage = $context['issues'] === [] && $row->getAttribute('state') === 'open' && $actor->can('erp.accounting-periods.manage');
        $monthlyPlan = [];
        $generationIssues = [];
        try {
            $monthlyPlan = $this->dates->monthlyRanges($row->getAttribute('starts_on')->format('Y-m-d'), $row->getAttribute('ends_on')->format('Y-m-d'));
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $generationIssues[] = $message;
                }
            }
        }
        $company = $this->access->authorize($actor, $companyId, 'erp.accounting-periods.view');
        $accountingDate = isset($data['accounting_date']) ? (string) $data['accounting_date'] : $this->businessDate->today($company);

        return Inertia::render('ERP/Accounting/Calendar/Show', [...$context, 'year' => $this->year($row), 'periods' => $periods, 'dateCheck' => $this->guard->inspect($actor, $companyId, $accountingDate), 'monthlyPlan' => $monthlyPlan, 'generationIssues' => $generationIssues, 'canManagePeriods' => $canManage, 'canManageYears' => $actor->can('erp.fiscal-years.manage'), 'audit' => $this->audit($actor, $companyId, $year), 'canViewAudit' => $actor->can('erp.accounting-audit.view')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $year = $this->service->saveYear($this->actor($request), $request->all());

        return redirect()->route('erp.accounting.calendar.index', ['company_id' => $year->getAttribute('company_id')])->with('success', 'Fiscal year created. Configure its periods next.');
    }

    public function update(Request $request, int $year): RedirectResponse
    {
        $this->find($this->actor($request), $year);
        $saved = $this->service->saveYear($this->actor($request), $request->all(), $year);

        return redirect()->route('erp.accounting.calendar.index', ['company_id' => $saved->getAttribute('company_id')])->with('success', 'Fiscal year updated.');
    }

    public function storePeriod(Request $request, int $year): RedirectResponse
    {
        $this->find($this->actor($request), $year);
        $this->service->savePeriod($this->actor($request), $year, $request->all());

        return redirect()->route('erp.accounting.calendar.show', $year)->with('success', 'Accounting period created.');
    }

    public function updatePeriod(Request $request, int $year, int $period): RedirectResponse
    {
        $this->find($this->actor($request), $year);
        $this->service->savePeriod($this->actor($request), $year, $request->all(), $period);

        return redirect()->route('erp.accounting.calendar.show', $year)->with('success', 'Accounting period updated.');
    }

    public function generate(Request $request, int $year): RedirectResponse
    {
        $this->find($this->actor($request), $year);
        $result = $this->service->generate($this->actor($request), $year, $request->all());

        return redirect()->route('erp.accounting.calendar.show', $year)->with('success', 'Monthly calendar: '.$result['created'].' periods created; '.$result['reused'].' existing periods preserved.');
    }

    public function closePeriod(Request $request, int $year, int $period): RedirectResponse
    {
        $actor = $this->actor($request);
        $found = $this->find($actor, $year);
        $this->states->close($actor, (int) $found->getAttribute('company_id'), $year, $period, $request->all());

        return redirect()->route('erp.accounting.calendar.show', $year)->with('success', 'Period closure recorded. Posting must recheck its current state.');
    }

    public function reopenPeriod(Request $request, int $year, int $period): RedirectResponse
    {
        $actor = $this->actor($request);
        $found = $this->find($actor, $year);
        $this->states->reopen($actor, (int) $found->getAttribute('company_id'), $year, $period, $request->all());

        return redirect()->route('erp.accounting.calendar.show', $year)->with('success', 'Period independently reopened.');
    }

    /** @return array<string, mixed> */
    private function context(User $actor, ?int $id): array
    {
        $companies = $this->access->companies($actor, 'erp.fiscal-years.view')->orderBy('name')->orderBy('id')->get(['id', 'name', 'code', 'currency_code', 'timezone', 'status']);
        if ($id !== null) {
            $this->access->authorize($actor, $id, 'erp.fiscal-years.view');
        }
        if ($id === null && $companies->count() === 1) {
            $single = $companies->first();
            $id = $single === null ? null : (int) $single->getKey();
        }
        $company = $id === null ? null : $companies->firstWhere('id', $id);
        if ($id !== null && $company === null) {
            abort(404);
        }

        return ['companies' => $companies->map(static fn (Company $row): array => $row->only(['id', 'name', 'code', 'currency_code', 'timezone', 'status']))->all(), 'company' => $company === null ? null : $company->only(['id', 'name', 'code', 'currency_code', 'timezone', 'status']), 'issues' => $company === null ? [] : $this->configuration->issues($company, AccountingSetting::query()->where('company_id', $id)->first()), 'canViewSetup' => $actor->can('erp.accounting-settings.view')];
    }

    private function find(User $actor, int $id): FiscalYear
    {
        return FiscalYear::query()->whereIn('company_id', $this->access->companies($actor, 'erp.fiscal-years.view')->select('id'))->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function year(FiscalYear $row): array
    {
        $periodIds = AccountingPeriod::query()->where('company_id', $row->getAttribute('company_id'))->where('fiscal_year_id', $row->getKey())->select('id');

        return ['id' => (int) $row->getKey(), ...$row->only(['code', 'name', 'state', 'revision']), 'starts_on' => $row->getAttribute('starts_on')->format('Y-m-d'), 'ends_on' => $row->getAttribute('ends_on')->format('Y-m-d'), 'semantic_locked' => JournalEntry::query()->where('company_id', $row->getAttribute('company_id'))->whereIn('accounting_period_id', $periodIds)->exists()];
    }

    /** @return list<array<string, mixed>> */
    private function audit(User $actor, int $companyId, int $yearId): array
    {
        if (! $actor->can('erp.accounting-audit.view')) {
            return [];
        }
        $periodIds = AccountingPeriod::query()->where('company_id', $companyId)->where('fiscal_year_id', $yearId)->pluck('id')->all();

        return AccountingAuditEvent::query()->where('company_id', $companyId)->where(fn ($query) => $query->where(fn ($inner) => $inner->where('entity_kind', 'fiscal_year')->where('entity_id', $yearId))->orWhere(fn ($inner) => $inner->where('entity_kind', 'period')->whereIn('entity_id', $periodIds)))->orderByDesc('id')->limit(50)->get()->map(static function (AccountingAuditEvent $event): array {
            $time = $event->getAttribute('occurred_at');
            $actor = $event->getAttribute('actor_snapshot') ?? [];

            return ['id' => (int) $event->getKey(), 'action' => (string) $event->getAttribute('action'), 'kind' => (string) $event->getAttribute('entity_kind'), 'actor' => (string) ($actor['name'] ?? 'Unknown actor'), 'reason' => (string) $event->getAttribute('reason'), 'occurred_at' => $time instanceof CarbonInterface ? $time->toIso8601String() : null];
        })->all();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}

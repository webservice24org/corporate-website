<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingAuditEvent;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingCutoverSource;
use App\Models\Accounting\AccountingOpeningWorkspace;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCompanyAccess;
use App\Services\ERP\Accounting\AccountingOpeningEvidence;
use App\Services\ERP\Accounting\AccountingOpeningService;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AccountingOpeningController extends Controller
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingOpeningService $service, private readonly AccountingOpeningEvidence $evidence) {}

    public function index(Request $request): Response
    {
        return $this->page($request);
    }

    public function save(Request $request, int $cutover): RedirectResponse
    {
        $this->find($this->actor($request), $cutover);
        $this->service->save($this->actor($request), $cutover, $request->except(['_token', '_method']));

        return $this->back($cutover, 'Opening saved as draft; previous approval cleared.');
    }

    public function dryRun(Request $request, int $cutover): Response
    {
        $this->find($this->actor($request), $cutover);

        return $this->page($request, $cutover, $this->service->dryRun($this->actor($request), $cutover, $request->except(['_token', '_method'])));
    }

    public function submit(Request $request, int $cutover): RedirectResponse
    {
        return $this->action($request, $cutover, 'submit');
    }

    public function approve(Request $request, int $cutover): RedirectResponse
    {
        return $this->action($request, $cutover, 'approve');
    }

    public function reject(Request $request, int $cutover): RedirectResponse
    {
        return $this->action($request, $cutover, 'reject');
    }

    private function action(Request $request, int $cutover, string $action): RedirectResponse
    {
        $this->find($this->actor($request), $cutover);
        $this->service->transition($this->actor($request), $cutover, $action, $request->except(['_token', '_method']));

        return $this->back($cutover, 'Opening workflow updated. This does not approve the cutover or activate accounting.');
    }

    private function back(int $cutover, string $message): RedirectResponse
    {
        return redirect()->route('erp.accounting.openings.index', ['cutover_id' => $cutover])->with('success', $message);
    }

    /** @param array<string, mixed>|null $preview */
    private function page(Request $request, ?int $forced = null, ?array $preview = null): Response
    {
        $actor = $this->actor($request);
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'cutover_id' => ['nullable', 'integer', 'min:1']]);
        $companies = $this->access->companies($actor, 'erp.accounting-openings.view')->orderBy('name')->get(['id', 'code', 'name', 'currency_code', 'timezone', 'status']);
        $companyId = isset($data['company_id']) ? (int) $data['company_id'] : null;
        if ($companyId !== null) {
            $this->access->authorize($actor, $companyId, 'erp.accounting-openings.view');
        }
        $plan = $forced !== null || isset($data['cutover_id']) ? $this->find($actor, $forced ?? (int) $data['cutover_id']) : null;
        if ($plan !== null) {
            if ($companyId !== null && $companyId !== (int) $plan->getAttribute('company_id')) {
                abort(403);
            } $companyId = (int) $plan->getAttribute('company_id');
        }
        if ($companyId === null && $companies->count() === 1) {
            $companyId = (int) $companies->firstOrFail()->getKey();
        }
        $company = $companyId === null ? null : $companies->firstWhere('id', $companyId);
        if ($plan === null && $company !== null) {
            $plan = AccountingCutover::query()->where('company_id', $companyId)->where('status', '!=', 'aborted')->orderByDesc('id')->first();
        }
        $workspace = $plan === null ? null : AccountingOpeningWorkspace::query()->where('company_id', $companyId)->where('cutover_id', $plan->getKey())->first();
        $journal = $workspace === null ? null : JournalEntry::query()->where('company_id', $companyId)->findOrFail($workspace->getAttribute('journal_entry_id'));
        $opening = null;
        $lines = [];
        $exceptions = [];
        $totals = null;
        if ($plan !== null && $workspace !== null && $journal !== null) {
            $checked = $this->evidence->verify($plan, $workspace, $journal);
            $lines = $checked['lines'];
            $totals = ['total_debit' => $checked['total_debit'], 'total_credit' => $checked['total_credit']];
            try {
                $exceptions = $this->evidence->exceptions((int) $companyId, $plan->getAttribute('cutover_date')->format('Y-m-d'), $lines);
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $messages) {
                    foreach ($messages as $message) {
                        $exceptions[] = $message;
                    }
                }
            }
            $opening = ['id' => (int) $journal->getKey(), ...$journal->only(['number', 'status', 'revision', 'description', 'created_by', 'updated_by', 'submitted_by', 'approved_by', 'payload_hash']), 'supporting_reference' => (string) $workspace->getAttribute('supporting_reference'), 'submitted_at' => $journal->getAttribute('submitted_at') instanceof CarbonInterface ? $journal->getAttribute('submitted_at')->toIso8601String() : null, 'approved_at' => $journal->getAttribute('approved_at') instanceof CarbonInterface ? $journal->getAttribute('approved_at')->toIso8601String() : null];
        }
        $accounts = $company === null ? [] : Account::query()->where('company_id', $companyId)->whereIn('account_group_id', AccountGroup::query()->where('company_id', $companyId)->whereIn('category', ['asset', 'liability', 'equity'])->select('id'))->orderBy('code')->get(['id', 'code', 'name', 'status', 'is_postable', 'control_role', 'cash_flow_role'])->map(static fn (Account $row): array => ['id' => (int) $row->getKey(), 'label' => $row->getAttribute('code').' — '.$row->getAttribute('name'), 'selectable' => $row->getAttribute('status') === 'active' && (bool) $row->getAttribute('is_postable')])->all();
        $audit = [];
        if ($journal !== null && $actor->can('erp.accounting-audit.view')) {
            $audit = AccountingAuditEvent::query()->where('company_id', $companyId)->where('entity_kind', 'opening')->where('entity_id', $journal->getKey())->orderByDesc('id')->limit(50)->get()->map(static function (AccountingAuditEvent $row): array {
                $snapshot = $row->getAttribute('actor_snapshot') ?? [];
                $time = $row->getAttribute('occurred_at');

                return ['id' => (int) $row->getKey(), 'action' => (string) $row->getAttribute('action'), 'actor' => (string) ($snapshot['name'] ?? 'Unknown actor'), 'reason' => (string) $row->getAttribute('reason'), 'occurred_at' => $time instanceof CarbonInterface ? $time->toIso8601String() : null];
            })->all();
        }
        $manifestFrozen = $plan !== null && ($plan->getAttribute('manifest_hash') !== null || $plan->getAttribute('reconciliation_hash') !== null || AccountingCutoverSource::query()->where('company_id', $companyId)->where('cutover_id', $plan->getKey())->exists());
        $base = ! $manifestFrozen && $plan !== null && $plan->getAttribute('status') === 'draft' && $plan->getAttribute('strategy') === 'snapshot' && $company !== null && $company->getAttribute('status') === 'active';
        $independent = $journal !== null && (int) $actor->getKey() !== (int) $journal->getAttribute('created_by') && (int) $actor->getKey() !== (int) $journal->getAttribute('updated_by');

        return Inertia::render('ERP/Accounting/Openings/Index', ['companies' => $companies->map(static fn (Company $row): array => $row->only(['id', 'code', 'name', 'currency_code', 'timezone', 'status']))->all(), 'company' => $company === null ? null : $company->only(['id', 'code', 'name', 'currency_code', 'timezone', 'status']), 'plan' => $plan === null ? null : ['id' => (int) $plan->getKey(), 'revision' => (int) $plan->getAttribute('revision'), 'status' => (string) $plan->getAttribute('status'), 'cutover_date' => $plan->getAttribute('cutover_date')->format('Y-m-d')], 'opening' => $opening, 'lines' => $lines, 'accounts' => $accounts, 'exceptions' => $exceptions, 'manifestFrozen' => $manifestFrozen, 'totals' => $totals, 'preview' => $preview, 'canEdit' => $base && ($journal === null || in_array($journal->getAttribute('status'), ['draft', 'approved'], true)) && $actor->can('erp.accounting-openings.manage'), 'canSubmit' => $base && $journal !== null && $journal->getAttribute('status') === 'draft' && $actor->can('erp.accounting-openings.manage'), 'canApprove' => $base && $journal !== null && $journal->getAttribute('status') === 'submitted' && $independent && $exceptions === [] && $actor->can('erp.accounting-openings.approve'), 'canReject' => $base && $journal !== null && $journal->getAttribute('status') === 'submitted' && $actor->can('erp.accounting-openings.manage'), 'audit' => $audit, 'canViewAudit' => $actor->can('erp.accounting-audit.view')]);
    }

    private function find(User $actor, int $id): AccountingCutover
    {
        return AccountingCutover::query()->whereIn('company_id', $this->access->companies($actor, 'erp.accounting-openings.view')->select('id'))->findOrFail($id);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}

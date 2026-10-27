<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\AccountingAuditEvent;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingCutoverSource;
use App\Models\Accounting\AccountingSetting;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCompanyAccess;
use App\Services\ERP\Accounting\AccountingCutoverPreparation;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AccountingCutoverController extends Controller
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingCutoverPreparation $preparation) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1']]);
        $actor = $this->actor($request);
        $companies = $this->access->companies($actor, 'erp.accounting-openings.view')->orderBy('name')->get(['id', 'name', 'code', 'currency_code', 'timezone', 'status']);
        $id = isset($data['company_id']) ? (int) $data['company_id'] : null;
        if ($id !== null) {
            $this->access->authorize($actor, $id, 'erp.accounting-openings.view');
        }
        if ($id === null && $companies->count() === 1) {
            $id = (int) $companies->firstOrFail()->getKey();
        }
        $company = $id === null ? null : $companies->firstWhere('id', $id);
        $setting = $company === null ? null : AccountingSetting::query()->where('company_id', $id)->first();
        $issues = $company === null ? [] : $this->preparation->issues($company, $setting);
        $current = $company === null ? null : AccountingCutover::query()->where('company_id', $id)->where('status', '!=', 'aborted')->orderByDesc('id')->first();
        if ($company !== null && AccountingCutover::query()->where('company_id', $id)->where('status', '!=', 'aborted')->count() > 1) {
            $issues[] = 'Multiple current cutovers require review before preparation can continue.';
        }
        $history = $company === null ? [] : AccountingCutover::query()->where('company_id', $id)->orderByDesc('id')->limit(50)->get()->map(fn (AccountingCutover $row): array => $this->plan($row))->all();
        $audit = [];
        if ($company !== null && $actor->can('erp.accounting-audit.view')) {
            $audit = AccountingAuditEvent::query()->where('company_id', $id)->where('entity_kind', 'cutover')->orderByDesc('id')->limit(50)->get()->map(static function (AccountingAuditEvent $row): array {
                $time = $row->getAttribute('occurred_at');
                $snapshot = $row->getAttribute('actor_snapshot') ?? [];

                return ['id' => (int) $row->getKey(), 'action' => (string) $row->getAttribute('action'), 'actor' => (string) ($snapshot['name'] ?? 'Unknown actor'), 'reason' => (string) $row->getAttribute('reason'), 'occurred_at' => $time instanceof CarbonInterface ? $time->toIso8601String() : null];
            })->all();
        }
        $editable = $current === null || ($current->getAttribute('status') === 'draft' && $current->getAttribute('approved_by') === null && $current->getAttribute('approved_at') === null && $current->getAttribute('activated_by') === null && $current->getAttribute('activated_at') === null && $current->getAttribute('manifest_hash') === null && $current->getAttribute('reconciliation_hash') === null && ! AccountingCutoverSource::query()->where('company_id', $id)->where('cutover_id', $current->getKey())->exists());

        return Inertia::render('ERP/Accounting/Cutover/Index', ['companies' => $companies->map(static fn (Company $row): array => $row->only(['id', 'name', 'code', 'currency_code', 'timezone', 'status']))->all(), 'company' => $company === null ? null : $company->only(['id', 'name', 'code', 'currency_code', 'timezone', 'status']), 'issues' => $issues, 'plan' => $current === null ? null : $this->plan($current), 'history' => $history, 'canManage' => $company !== null && $issues === [] && $editable && $actor->can('erp.accounting-openings.manage'), 'settingsState' => $setting === null ? 'uninitialized' : (string) $setting->getAttribute('state'), 'audit' => $audit, 'canViewAudit' => $actor->can('erp.accounting-audit.view')]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->redirect($this->preparation->save($this->actor($request), $request->except(['_token', '_method'])), 'Draft cutover plan saved. No opening balances or activation were created.');
    }

    public function update(Request $request, int $cutover): RedirectResponse
    {
        $row = $this->find($this->actor($request), $cutover);
        $input = $request->except(['_token', '_method']);
        abort_unless((int) ($input['company_id'] ?? 0) === (int) $row->getAttribute('company_id'), 403);

        return $this->redirect($this->preparation->save($this->actor($request), $input, $cutover), 'Draft cutover plan updated.');
    }

    public function abort(Request $request, int $cutover): RedirectResponse
    {
        $row = $this->find($this->actor($request), $cutover);

        return $this->redirect($this->preparation->abort($this->actor($request), (int) $row->getAttribute('company_id'), $cutover, $request->except(['_token', '_method'])), 'Cutover draft aborted; history and its date reservation were retained.');
    }

    private function find(User $actor, int $id): AccountingCutover
    {
        return AccountingCutover::query()->whereIn('company_id', $this->access->companies($actor, 'erp.accounting-openings.view')->select('id'))->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function plan(AccountingCutover $row): array
    {
        return ['id' => (int) $row->getKey(), ...$this->preparation->snapshot($row), 'created_by' => (int) $row->getAttribute('created_by')];
    }

    private function redirect(AccountingCutover $row, string $message): RedirectResponse
    {
        return redirect()->route('erp.accounting.cutover.index', ['company_id' => $row->getAttribute('company_id')])->with('success', $message);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}

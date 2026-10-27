<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingManifestVersion;
use App\Models\Accounting\AccountingOpeningWorkspace;
use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingCompanyAccess;
use App\Services\ERP\Accounting\AccountingConfigurationGuard;
use App\Services\ERP\Accounting\AccountingCutoverManifest;
use App\Services\ERP\Accounting\AccountingManifestSources;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AccountingManifestController extends Controller
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingConfigurationGuard $configuration, private readonly AccountingCutoverManifest $manifest, private readonly AccountingManifestSources $sources, private readonly AccountingCanonicalPayload $canonical) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'cutover_id' => ['nullable', 'integer', 'min:1']]);
        $actor = $this->actor($request);
        $companies = $this->access->companies($actor, 'erp.accounting-openings.view')->orderBy('name')->get(['id', 'code', 'name', 'currency_code', 'status']);
        $id = isset($data['company_id']) ? (int) $data['company_id'] : null;
        if ($id !== null) {
            $this->access->authorize($actor, $id, 'erp.accounting-openings.view');
        }
        $plan = isset($data['cutover_id']) ? $this->find($actor, (int) $data['cutover_id']) : null;
        if ($plan !== null) {
            if ($id !== null && $id !== (int) $plan->getAttribute('company_id')) {
                abort(403);
            } $id = (int) $plan->getAttribute('company_id');
        }
        if ($id === null && $companies->count() === 1) {
            $id = (int) $companies->firstOrFail()->getKey();
        }
        $company = $id === null ? null : $companies->firstWhere('id', $id);
        if ($plan === null && $company !== null) {
            $plan = AccountingCutover::query()->where('company_id', $id)->where('status', '!=', 'aborted')->orderByDesc('id')->first();
        }
        $issues = $company === null ? [] : $this->configuration->issues($company, AccountingSetting::query()->where('company_id', $id)->first());
        $workspace = $plan === null ? null : AccountingOpeningWorkspace::query()->where('company_id', $id)->where('cutover_id', $plan->getKey())->first();
        $journal = $workspace === null ? null : JournalEntry::query()->where('company_id', $id)->findOrFail($workspace->getAttribute('journal_entry_id'));
        $rows = [];
        $sourceIssues = [];
        $stale = null;
        $currentCount = null;
        $integrity = true;
        if ($plan !== null && $workspace !== null && $journal !== null) {
            try {
                if ($plan->getAttribute('manifest_hash') !== null) {
                    $this->manifest->verify($plan, $workspace, $journal);
                    $rows = $this->manifest->stored($plan, $journal);
                }
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $messages) {
                    foreach ($messages as $message) {
                        $issues[] = $message;
                    }
                } $integrity = false;
            } catch (ModelNotFoundException) {
                $issues[] = 'Manifest history or linked evidence is missing; review integrity before continuing.';
                $integrity = false;
            }
            try {
                $current = $this->sources->collect((int) $id, $plan->getAttribute('cutover_date')->format('Y-m-d'));
                $currentCount = count($current);
                if ($integrity && $plan->getAttribute('manifest_hash') !== null) {
                    $stale = ! hash_equals($this->canonical->hash($rows), $this->canonical->hash($current));
                }
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $messages) {
                    foreach ($messages as $message) {
                        $sourceIssues[] = $message;
                    }
                }
            }
        }
        $history = $plan === null ? [] : AccountingManifestVersion::query()->where('company_id', $id)->where('cutover_id', $plan->getKey())->orderByDesc('id')->limit(20)->get(['id', 'cutover_revision', 'payload_hash', 'created_by'])->map(static fn (AccountingManifestVersion $row): array => $row->only(['id', 'cutover_revision', 'payload_hash', 'created_by']))->all();
        $canManage = $company !== null && $issues === [] && $integrity && $plan !== null && $plan->getAttribute('status') === 'draft' && $plan->getAttribute('strategy') === 'snapshot' && $journal !== null && in_array($journal->getAttribute('status'), ['draft', 'approved'], true) && $actor->can('erp.accounting-openings.manage');
        $hasManifest = $plan !== null && $plan->getAttribute('manifest_hash') !== null;

        return Inertia::render('ERP/Accounting/Manifest/Index', ['companies' => $companies->map(static fn (Company $row): array => $row->only(['id', 'code', 'name', 'currency_code', 'status']))->all(), 'company' => $company === null ? null : $company->only(['id', 'code', 'name', 'currency_code', 'status']), 'plan' => $plan === null ? null : ['id' => (int) $plan->getKey(), 'revision' => (int) $plan->getAttribute('revision'), 'cutover_date' => $plan->getAttribute('cutover_date')->format('Y-m-d'), 'status' => (string) $plan->getAttribute('status'), 'manifest_hash' => $plan->getAttribute('manifest_hash')], 'opening' => $journal === null ? null : ['id' => (int) $journal->getKey(), ...$journal->only(['number', 'status', 'revision'])], 'rows' => $rows, 'history' => $history, 'issues' => [...$issues, ...$sourceIssues], 'stale' => $stale, 'candidateCount' => $currentCount, 'canCapture' => $canManage && ! $hasManifest && $sourceIssues === [], 'canInvalidate' => $canManage && $hasManifest]);
    }

    public function capture(Request $request, int $cutover): RedirectResponse
    {
        $this->find($this->actor($request), $cutover);
        $this->manifest->capture($this->actor($request), $cutover, $request->except(['_token', '_method']));

        return $this->back($cutover, 'Manifest captured as pending reconciliation. Opening review was reset.');
    }

    public function invalidate(Request $request, int $cutover): RedirectResponse
    {
        $this->find($this->actor($request), $cutover);
        $this->manifest->invalidate($this->actor($request), $cutover, $request->except(['_token', '_method']));

        return $this->back($cutover, 'Current manifest invalidated; immutable capture history was retained.');
    }

    private function back(int $cutover, string $message): RedirectResponse
    {
        return redirect()->route('erp.accounting.manifest.index', ['cutover_id' => $cutover])->with('success', $message);
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

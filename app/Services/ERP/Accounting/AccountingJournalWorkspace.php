<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingAuditEvent;
use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\JournalEntry;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read projections only. No controller/UI capability substitutes for a writer's checks. */
final class AccountingJournalWorkspace
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingConfigurationGuard $configuration, private readonly AccountingJournalDraftService $drafts, private readonly AccountingPostingReadiness $readiness, private readonly AccountingPeriodGuard $periods) {}

    /** @return array<string, mixed> */
    public function context(User $actor, ?int $id): array
    {
        $companies = $this->access->companies($actor, 'erp.journal-entries.view')->orderBy('name')->orderBy('id')->get(['id', 'name', 'code', 'currency_code', 'timezone', 'status']);
        if ($id !== null) {
            $this->access->authorize($actor, $id, 'erp.journal-entries.view');
        }
        if ($id === null && $companies->count() === 1) {
            $id = (int) $companies->firstOrFail()->getKey();
        }
        $company = $id === null ? null : $companies->firstWhere('id', $id);
        $setting = $company === null ? null : AccountingSetting::query()->where('company_id', $id)->first();

        return ['companies' => $companies->map(static fn (Company $row): array => $row->only(['id', 'name', 'code', 'currency_code', 'timezone', 'status']))->all(), 'company' => $company === null ? null : $company->only(['id', 'name', 'code', 'currency_code', 'timezone', 'status']), 'issues' => $company === null ? [] : $this->configuration->issues($company, $setting), 'canCreate' => $actor->can('erp.journal-entries.create')];
    }

    public function find(User $actor, int $id): JournalEntry
    {
        return JournalEntry::query()->whereIn('company_id', $this->access->companies($actor, 'erp.journal-entries.view')->select('id'))->findOrFail($id);
    }

    /** @return array<string, mixed> */
    public function options(int $companyId): array
    {
        // Include historical selections but mark unusable accounts, rather than silently changing them.
        $accounts = Account::query()->where('company_id', $companyId)->orderBy('code')->get(['id', 'code', 'name', 'status', 'is_postable', 'parent_id', 'control_role', 'cash_flow_role']);
        $parents = $accounts->pluck('parent_id')->filter()->map(static fn ($id): int => (int) $id)->all();

        return ['accounts' => $accounts->map(static fn (Account $row): array => ['id' => (int) $row->getKey(), 'label' => $row->getAttribute('code').' — '.$row->getAttribute('name'), 'selectable' => $row->getAttribute('status') === 'active' && (bool) $row->getAttribute('is_postable') && ! in_array((int) $row->getKey(), $parents, true) && $row->getAttribute('control_role') === null && $row->getAttribute('cash_flow_role') !== 'cash'])->all(), 'branches' => Branch::query()->where('company_id', $companyId)->orderBy('name')->get(['id', 'name', 'status'])->map(static fn (Branch $row): array => ['id' => (int) $row->getKey(), 'label' => (string) $row->getAttribute('name'), 'selectable' => $row->getAttribute('status') === 'active'])->all(), 'departments' => Department::query()->where('company_id', $companyId)->orderBy('name')->get(['id', 'name', 'status', 'branch_id'])->map(static fn (Department $row): array => ['id' => (int) $row->getKey(), 'label' => (string) $row->getAttribute('name'), 'selectable' => $row->getAttribute('status') === 'active', 'branch_id' => $row->getAttribute('branch_id') === null ? null : (int) $row->getAttribute('branch_id')])->all()];
    }

    /** @return array<string, mixed> */
    public function detail(User $actor, JournalEntry $row): array
    {
        $companyId = (int) $row->getAttribute('company_id');
        $context = $this->context($actor, $companyId);
        $read = $this->drafts->read($actor, (int) $row->getKey());
        $options = $this->options($companyId);
        $writable = $context['issues'] === [] && in_array($row->getAttribute('status'), ['draft', 'submitted', 'approved'], true) && $row->getAttribute('posted_at') === null && $row->getAttribute('posted_by') === null;
        $ownContent = (int) $actor->getKey() === (int) $row->getAttribute('created_by') || (int) $actor->getKey() === (int) $row->getAttribute('updated_by');
        $capabilities = ['edit' => $writable && $row->getAttribute('entry_kind') === 'manual' && in_array($row->getAttribute('status'), ['draft', 'approved'], true) && $actor->can('erp.journal-entries.edit'), 'submit' => $writable && $row->getAttribute('status') === 'draft' && $actor->can('erp.journal-entries.submit'), 'approve' => $writable && $row->getAttribute('status') === 'submitted' && ! $ownContent && $actor->can('erp.journal-entries.approve'), 'reject' => $writable && $row->getAttribute('status') === 'submitted' && $actor->can('erp.journal-entries.approve'), 'cancel' => $writable && $actor->can('erp.journal-entries.edit'), 'post' => $writable && $row->getAttribute('status') === 'approved' && $actor->can('erp.journal-entries.post'), 'reverse' => $context['issues'] === [] && $row->getAttribute('entry_kind') === 'manual' && $row->getAttribute('status') === 'posted' && $actor->can('erp.journal-entries.reverse')];
        $postingIssues = [];
        if ($capabilities['post']) {
            // Readiness needs write locks; this short transaction is only an advisory preview.
            try {
                DB::transaction(function () use ($actor, $companyId, $row): void {
                    $company = $this->access->authorize($actor, $companyId, 'erp.journal-entries.post', true);
                    $setting = $this->configuration->lockForSetup($company);
                    $this->readiness->check($company, $setting, $row->getAttribute('accounting_date')->format('Y-m-d'));
                    $this->periods->lockForJournal($actor, $companyId, (int) $row->getKey());
                });
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $messages) {
                    foreach ($messages as $message) {
                        $postingIssues[] = $message;
                    }
                }
            } catch (AuthorizationException $exception) {
                $postingIssues[] = $exception->getMessage();
            }
            $capabilities['post'] = $postingIssues === [];
        }
        $linked = JournalEntry::query()->where('company_id', $companyId)->where('reverses_journal_id', $row->getKey())->first();
        $audit = [];
        if ($actor->can('erp.accounting-audit.view')) {
            $audit = AccountingAuditEvent::query()->where('company_id', $companyId)->where('entity_kind', 'journal')->where('entity_id', $row->getKey())->orderByDesc('id')->limit(50)->get()->map(static function (AccountingAuditEvent $event): array {
                $snapshot = $event->getAttribute('actor_snapshot') ?? [];
                $time = $event->getAttribute('occurred_at');

                return ['id' => (int) $event->getKey(), 'action' => (string) $event->getAttribute('action'), 'actor' => (string) ($snapshot['name'] ?? 'Unknown actor'), 'reason' => (string) $event->getAttribute('reason'), 'occurred_at' => $time instanceof CarbonInterface ? $time->toIso8601String() : null];
            })->all();
        }

        return [...$context, ...$read, ...$options, 'capabilities' => $capabilities, 'postingIssues' => $postingIssues, 'linkedReversal' => $linked === null ? null : ['id' => (int) $linked->getKey(), ...$linked->only(['number', 'status', 'revision'])], 'audit' => $audit, 'canViewAudit' => $actor->can('erp.accounting-audit.view')];
    }
}

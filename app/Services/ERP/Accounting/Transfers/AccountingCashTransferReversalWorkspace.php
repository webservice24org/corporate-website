<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Transfers;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingCashTransfer;
use App\Models\Accounting\AccountingCashTransferReversalAuditEvent;
use App\Models\Accounting\AccountingCashTransferReversalOperation;
use App\Models\Accounting\AccountingCashTransferReversalRequest;
use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingConfigurationGuard;
use App\Services\ERP\Accounting\AccountingPostingReadiness;
use App\Services\ERP\Accounting\Source\AccountingSourcePeriod;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Read-only authorized projections. Native services recheck every command under company/root locks. */
final class AccountingCashTransferReversalWorkspace
{
    public function __construct(
        private readonly PurchaseCompanyAccess $access,
        private readonly AccountingCashTransferReversalWorkflow $workflow,
        private readonly AccountingCashTransferReversalOriginal $original,
        private readonly AccountingCashTransferReversalEvidence $evidence,
        private readonly AccountingConfigurationGuard $configuration,
        private readonly AccountingBusinessDate $dates,
        private readonly AccountingPostingReadiness $readiness,
        private readonly AccountingSourcePeriod $periods,
    ) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function index(User $actor, ?int $companyId, array $filters): array
    {
        return DB::transaction(function () use ($actor, $companyId, $filters): array {
            $context = $this->context($actor, $companyId);
            $query = AccountingCashTransferReversalRequest::query()->where('company_id', (int) ($context['company']['id'] ?? 0));
            if (! empty($filters['transfer_id'])) {
                $query->where('original_transfer_id', (int) $filters['transfer_id']);
            }
            if (! empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            if (! empty($filters['from'])) {
                $query->whereDate('document_date', '>=', $filters['from']);
            }
            if (! empty($filters['to'])) {
                $query->whereDate('document_date', '<=', $filters['to']);
            }
            $rows = $query->orderByDesc('document_date')->orderByDesc('id')->paginate(25)->withQueryString();
            $rows->through(fn (AccountingCashTransferReversalRequest $row): array => $this->summary($row));

            return [...$context, 'reversals' => $rows, 'filters' => ['transfer_id' => isset($filters['transfer_id']) ? (string) $filters['transfer_id'] : '', 'status' => $filters['status'] ?? '', 'from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? '']];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function create(User $actor, int $transferId, ?int $requestedCompany = null): array
    {
        abort_unless($actor->can('erp.cash-transfer-reversals.create'), 403);
        $query = AccountingCashTransfer::query();
        if (! $actor->hasRole('super-admin')) {
            $query->whereIn('company_id', $actor->employee()->select('company_id'));
        }
        $found = $query->findOrFail($transferId);
        $companyId = (int) $found->getAttribute('company_id');
        if ($requestedCompany !== null && $requestedCompany !== $companyId) {
            throw new AuthorizationException('Company does not own this transfer.');
        }

        return DB::transaction(function () use ($actor, $transferId, $companyId): array {
            $context = $this->context($actor, $companyId);
            abort_unless($context['canCreate'], 403);
            $this->evidence->verifyForOriginal($companyId, $transferId);
            $proof = $this->original->capture($companyId, $transferId);
            if (AccountingCashTransferReversalRequest::query()->where('company_id', $companyId)->where('original_transfer_id', $transferId)->where(function ($q): void {
                $q->where('status', '<>', 'cancelled')->orWhereNotNull('active_transfer_id');
            })->exists()) {
                throw ValidationException::withMessages(['reversal' => 'An active reversal request already exists. Open it in the reversal register.']);
            }

            return [...$context, 'reversal' => null, 'original' => $this->originalSummary($actor, $companyId, $proof), 'requestKey' => (string) Str::uuid()];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function detail(User $actor, int $id, bool $editing = false, ?int $requestedCompany = null): array
    {
        $found = $this->workflow->find($actor, $id);
        $companyId = (int) $found->getAttribute('company_id');
        if ($requestedCompany !== null && $requestedCompany !== $companyId) {
            throw new AuthorizationException('Company does not own this reversal.');
        }

        return DB::transaction(function () use ($actor, $id, $companyId, $editing): array {
            $context = $this->context($actor, $companyId);
            $row = $this->workflow->find($actor, $id);
            $company = Company::query()->findOrFail($companyId);
            $state = (string) $row->getAttribute('status');
            $issues = $context['issues'];
            $preview = null;
            $valid = false;
            $dated = false;
            $postingReady = false;
            try {
                $preview = $this->workflow->preview($actor, $id);
                $valid = true;
            } catch (ValidationException|ModelNotFoundException|\LogicException) {
                $issues[] = 'Original or reversal evidence needs review. No replacement operation is created by this screen.';
            }
            if ($valid && $context['writable'] && in_array($state, ['draft', 'submitted', 'approved'], true)) {
                try {
                    $date = $row->getAttribute('document_date')->format('Y-m-d');
                    if ($date > $this->dates->today($company)) {
                        throw ValidationException::withMessages(['date' => 'Future date.']);
                    }
                    $this->periods->lock($actor, $company, $date);
                    $dated = true;
                    if ($state === 'approved' && $context['postingEnabled'] && $actor->can('erp.cash-transfer-reversals.post')) {
                        $setting = AccountingSetting::query()->where('company_id', $companyId)->firstOrFail();
                        $this->readiness->check($company, $setting, $date);
                        $snapshot = $row->getAttribute('original_snapshot');
                        if (! is_array($snapshot)) {
                            throw new \InvalidArgumentException('Missing original.');
                        }
                        foreach (['source', 'destination'] as $endpoint) {
                            $account = Account::query()->where('company_id', $companyId)->findOrFail((int) $snapshot[$endpoint]['account_id']);
                            if ($account->getAttribute('status') !== 'active' || ! (bool) $account->getAttribute('is_postable') || Account::query()->where('company_id', $companyId)->where('parent_id', $account->getKey())->exists()) {
                                throw ValidationException::withMessages(['accounts' => 'Original GL account must remain an active posting leaf.']);
                            }
                        }
                        $postingReady = true;
                    }
                } catch (ValidationException|ModelNotFoundException|AuthorizationException|\InvalidArgumentException) {
                    $issues[] = 'Review/posting requires an eligible date and open period. Posting also requires activation and eligible original GL accounts.';
                }
            }
            $independent = ! in_array((int) $actor->getKey(), [(int) $row->getAttribute('created_by'), (int) $row->getAttribute('last_edited_by'), (int) $row->getAttribute('submitted_by')], true);
            $capabilities = [
                'edit' => $valid && $context['writable'] && $state === 'draft' && $actor->can('erp.cash-transfer-reversals.edit'),
                'submit' => $dated && $state === 'draft' && $actor->can('erp.cash-transfer-reversals.submit'),
                'approve' => $dated && $state === 'submitted' && $independent && $actor->can('erp.cash-transfer-reversals.approve'),
                'reject' => $context['writable'] && $state === 'submitted' && $actor->can('erp.cash-transfer-reversals.approve'),
                'cancel' => $context['writable'] && in_array($state, ['draft', 'submitted', 'approved'], true) && $actor->can('erp.cash-transfer-reversals.cancel'),
                'post' => $postingReady,
            ];
            $hasFinance = AccountingCashTransferReversalOperation::query()->where('company_id', $companyId)->where('reversal_request_id', $id)->exists()
                || AccountingSourceEffect::query()->where('company_id', $companyId)->where('source_kind', 'cash_transfer_reversal')->where('source_id', $id)->exists();
            $finance = ['state' => 'unposted', 'message' => 'Proposal only. No reversal money has been recorded.', 'journal_id' => null, 'journal_url' => null];
            if ($state === 'posted' || $row->getAttribute('posted_at') !== null || $hasFinance) {
                try {
                    $proof = $this->evidence->verify($companyId, $id);
                    $journalId = (int) $proof['journal']->getKey();
                    $finance = ['state' => 'verified', 'message' => 'The complete reversal and both compensating journal legs verify. The original remains posted.', 'journal_id' => $journalId, 'journal_url' => $actor->can('erp.journal-entries.view') ? '/erp/accounting/journals/'.$journalId : null];
                } catch (ValidationException|ModelNotFoundException|\LogicException) {
                    $finance = ['state' => 'review', 'message' => 'Financial evidence needs review. Retry cannot rebuild missing evidence.', 'journal_id' => null, 'journal_url' => null];
                }
            }
            if ($finance['state'] !== 'unposted' || $state === 'cancelled') {
                $capabilities = array_fill_keys(array_keys($capabilities), false);
            }
            if ($editing) {
                abort_unless($capabilities['edit'], 403);
            }
            $snapshot = $row->getAttribute('original_snapshot');
            $original = $valid && is_array($snapshot) ? $this->originalSummary($actor, $companyId, $snapshot) : null;
            $events = AccountingCashTransferReversalAuditEvent::query()->where('company_id', $companyId)->where('reversal_request_id', $id)->orderByDesc('id')->limit(101)->get();
            $history = $events->take(100)->map(static fn (AccountingCashTransferReversalAuditEvent $event): array => [
                'id' => (int) $event->getKey(), 'action' => (string) $event->getAttribute('action'), 'actor_id' => (int) $event->getAttribute('actor_id'), 'reason' => $event->getAttribute('reason'), 'occurred_at' => self::time($event->getAttribute('occurred_at')),
            ])->values()->all();
            $workflow = [];
            foreach (['created_by', 'last_edited_by', 'submitted_by', 'approved_by', 'posted_by', 'cancelled_by'] as $field) {
                $value = $row->getAttribute($field);
                $workflow[$field] = $value === null ? null : (int) $value;
            }
            foreach (['created_at', 'submitted_at', 'approved_at', 'posted_at', 'cancelled_at'] as $field) {
                $workflow[$field] = self::time($row->getAttribute($field));
            }

            return [...$context, 'issues' => array_values(array_unique($issues)), 'reversal' => [...$this->summary($row), 'reason' => (string) $row->getAttribute('reason'), 'cancellation_reason' => $row->getAttribute('cancellation_reason')],
                'original' => $original, 'preview' => $valid ? $this->safePreview($preview) : null, 'capabilities' => $capabilities, 'finance' => $finance,
                'workflow' => $workflow, 'history' => $history, 'historyTruncated' => $events->count() > 100, 'requestKey' => (string) Str::uuid(), 'bookLinks' => $this->bookLinks($actor, $row)];
        }, 3);
    }

    /** @return array<string, mixed> */
    private function summary(AccountingCashTransferReversalRequest $row): array
    {
        return ['id' => (int) $row->getKey(), 'company_id' => (int) $row->getAttribute('company_id'), 'number' => 'CTR-'.$row->getKey(),
            'original_transfer_id' => (int) $row->getAttribute('original_transfer_id'), 'status' => (string) $row->getAttribute('status'), 'revision' => (int) $row->getAttribute('revision'),
            'document_date' => $row->getAttribute('document_date')->format('Y-m-d'), 'amount' => (string) $row->getAttribute('amount'), 'currency_code' => (string) $row->getAttribute('currency_code'),
            'source' => $this->binding((int) $row->getAttribute('company_id'), (int) $row->getAttribute('source_cash_account_id')),
            'destination' => $this->binding((int) $row->getAttribute('company_id'), (int) $row->getAttribute('destination_cash_account_id'))];
    }

    /** @param array<string, mixed> $proof @return array<string, mixed> */
    private function originalSummary(User $actor, int $companyId, array $proof): array
    {
        // Fixed IDs are projected separately from current human-friendly account names.
        return ['id' => (int) ($proof['transfer_id'] ?? 0), 'number' => (string) ($proof['number'] ?? ''), 'document_date' => (string) ($proof['document_date'] ?? ''), 'amount' => (string) ($proof['amount'] ?? ''), 'currency_code' => (string) ($proof['currency_code'] ?? ''),
            'source' => $this->binding($companyId, (int) ($proof['source']['id'] ?? 0)), 'destination' => $this->binding($companyId, (int) ($proof['destination']['id'] ?? 0)),
            'source_account_id' => (int) ($proof['source']['account_id'] ?? 0), 'destination_account_id' => (int) ($proof['destination']['account_id'] ?? 0),
            'url' => $actor->can('erp.cash-transfers.view') ? '/erp/accounting/cash-transfers/'.(int) ($proof['transfer_id'] ?? 0) : null,
            'journal_url' => $actor->can('erp.journal-entries.view') ? '/erp/accounting/journals/'.(int) ($proof['journal_id'] ?? 0) : null];
    }

    /** @return array<string, mixed>|null */
    private function binding(int $companyId, int $id): ?array
    {
        $row = AccountingCashAccount::query()->where('company_id', $companyId)->find($id);

        return $row === null ? null : ['id' => $id, 'name' => (string) $row->getAttribute('name'), 'role' => (string) $row->getAttribute('role'), 'kind' => (string) $row->getAttribute('kind'), 'currency_code' => (string) $row->getAttribute('currency_code'), 'status' => (string) $row->getAttribute('status')];
    }

    /** @param array<string, mixed>|null $preview @return list<array<string, mixed>> */
    private function safePreview(?array $preview): array
    {
        $rows = [];
        foreach ($preview['lines'] ?? [] as $line) {
            $rows[] = ['account_id' => (int) $line['account_id'], 'cash_account_id' => (int) $line['cash_account_id'], 'debit' => (string) $line['debit'], 'credit' => (string) $line['credit']];
        }

        return $rows;
    }

    /** @return list<array{label: string, url: string}> */
    private function bookLinks(User $actor, AccountingCashTransferReversalRequest $row): array
    {
        if (! $actor->can('erp.cash-books.view')) {
            return [];
        }
        $links = [];
        foreach (['source_cash_account_id' => 'Original source book', 'destination_cash_account_id' => 'Original destination book'] as $field => $label) {
            $binding = AccountingCashAccount::query()->where('company_id', $row->getAttribute('company_id'))->find((int) $row->getAttribute($field));
            if ($binding === null || ! in_array($binding->getAttribute('kind'), ['cash', 'bank'], true)) {
                continue;
            }
            $links[] = ['label' => $label, 'url' => '/erp/accounting/'.($binding->getAttribute('kind') === 'cash' ? 'cashbook' : 'bankbook').'?'.http_build_query(['company_id' => (int) $row->getAttribute('company_id'), 'binding_id' => (int) $binding->getKey(), 'from' => $row->getAttribute('document_date')->format('Y-m-d'), 'to' => $row->getAttribute('document_date')->format('Y-m-d')])];
        }

        return $links;
    }

    /** Screen methods own a transaction; this acquires the selected company lock. @return array<string, mixed> */
    private function context(User $actor, ?int $companyId): array
    {
        abort_unless($actor->exists && $actor->can('erp.cash-transfer-reversals.view'), 403);
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
        $company = null;
        if ($companyId !== null) {
            $this->access->authorize($actor, $companyId, 'erp.cash-transfer-reversals.view');
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $companyId, 'erp.cash-transfer-reversals.view');
        }
        $setting = $company === null ? null : AccountingSetting::query()->where('company_id', $company->getKey())->first();
        $configurationIssues = $company === null ? [] : $this->configuration->issues($company, $setting);
        $writable = $company !== null && $configurationIssues === [];
        $issues = $configurationIssues === [] ? [] : ['This company is read only until its accounting setup, active status or cutover lock is resolved.'];
        if ($company !== null && ($setting === null || $setting->getAttribute('state') !== 'enabled')) {
            $issues[] = 'Accounting is not enabled. Prepare and review drafts here; ledger posting remains unavailable.';
        }

        return ['companies' => $companies->map(static fn (Company $item): array => $item->only(['id', 'code', 'name', 'currency_code', 'status']))->all(),
            'company' => $company === null ? null : $company->only(['id', 'code', 'name', 'currency_code', 'status']), 'actorId' => (int) $actor->getKey(),
            'writable' => $writable, 'postingEnabled' => $writable && $setting !== null && $setting->getAttribute('state') === 'enabled',
            'canCreate' => $writable && $actor->can('erp.cash-transfer-reversals.create'), 'today' => $company === null ? null : $this->dates->today($company), 'issues' => $issues];
    }

    private static function time(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Transfers;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingCashTransfer;
use App\Models\Accounting\AccountingCashTransferAuditEvent;
use App\Models\Accounting\AccountingCashTransferOperation;
use App\Models\Accounting\AccountingCashTransferReversalRequest;
use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingConfigurationGuard;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\AccountingPostingReadiness;
use App\Services\ERP\Accounting\Source\AccountingSourcePeriod;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Authorized, whitelisted screen projections. All commands remain in the installed native services. */
final class AccountingCashTransferWorkspace
{
    public function __construct(
        private readonly PurchaseCompanyAccess $access,
        private readonly AccountingCashTransferWorkflow $workflow,
        private readonly AccountingConfigurationGuard $configuration,
        private readonly AccountingBusinessDate $dates,
        private readonly AccountingCashTransferIntent $intent,
        private readonly AccountingMappingService $mappings,
        private readonly AccountingPostingReadiness $readiness,
        private readonly AccountingSourcePeriod $periods,
        private readonly AccountingCashTransferEvidence $evidence,
    ) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function index(User $actor, ?int $companyId, array $filters): array
    {
        return DB::transaction(function () use ($actor, $companyId, $filters): array {
            $context = $this->context($actor, $companyId);
            $selected = (int) ($context['company']['id'] ?? 0);
            $bindingId = empty($filters['binding_id']) ? null : (int) $filters['binding_id'];
            if ($bindingId !== null) {
                AccountingCashAccount::query()->where('company_id', $selected)->findOrFail($bindingId);
            }
            $query = AccountingCashTransfer::query()->where('company_id', $selected);
            if (! empty($filters['q'])) {
                $query->where('number', 'like', '%'.$filters['q'].'%');
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
            if ($bindingId !== null) {
                $query->where(function ($endpoints) use ($bindingId): void {
                    $endpoints->where('source_cash_account_id', $bindingId)->orWhere('destination_cash_account_id', $bindingId);
                });
            }
            $rows = $query->orderByDesc('document_date')->orderByDesc('id')->paginate(25)->withQueryString();
            $rows->through(fn (AccountingCashTransfer $row): array => $this->summary($row));

            return [...$context, 'transfers' => $rows, 'bindings' => $this->bindingOptions($selected),
                'filters' => ['q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? '', 'binding_id' => isset($filters['binding_id']) ? (string) $filters['binding_id'] : '', 'from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? '']];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function create(User $actor, ?int $companyId): array
    {
        return DB::transaction(function () use ($actor, $companyId): array {
            $context = $this->context($actor, $companyId);
            abort_unless($context['canCreate'], 403);

            return [...$context, 'bindings' => $this->bindingOptions((int) $context['company']['id']), 'transfer' => null, 'requestKey' => (string) Str::uuid()];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function detail(User $actor, int $id, bool $editing = false, ?int $requestedCompany = null): array
    {
        $found = $this->workflow->find($actor, $id);
        $companyId = (int) $found->getAttribute('company_id');
        if ($requestedCompany !== null && $requestedCompany !== $companyId) {
            throw new AuthorizationException('The requested company does not own this transfer.');
        }

        return DB::transaction(function () use ($actor, $id, $companyId, $editing): array {
            $context = $this->context($actor, $companyId);
            $row = $this->workflow->find($actor, $id);
            $company = Company::query()->findOrFail($companyId);
            $state = (string) $row->getAttribute('status');
            $writable = (bool) $context['writable'];
            $issues = $context['issues'];
            $reviewReady = false;
            $postingReady = false;
            if ($writable && in_array($state, ['draft', 'submitted', 'approved'], true)) {
                try {
                    $this->reviewIdentity($company, $row);
                    $reviewReady = true;
                } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
                    $issues[] = 'Transfer details or account setup changed. Return it for review before approval or posting.';
                }
            }
            if ($reviewReady && $state === 'approved' && $actor->can('erp.cash-transfers.post') && $context['postingEnabled']) {
                try {
                    $setting = AccountingSetting::query()->where('company_id', $companyId)->firstOrFail();
                    $this->readiness->check($company, $setting, $row->getAttribute('document_date')->format('Y-m-d'));
                    $this->periods->lock($actor, $company, $row->getAttribute('document_date')->format('Y-m-d'));
                    $postingReady = true;
                } catch (ValidationException|ModelNotFoundException|AuthorizationException) {
                    $issues[] = 'Posting requires valid activation evidence, an open period and any required backdate permission.';
                }
            }
            $independent = ! in_array((int) $actor->getKey(), [(int) $row->getAttribute('created_by'), (int) $row->getAttribute('last_edited_by'), (int) $row->getAttribute('submitted_by')], true);
            $capabilities = [
                'edit' => $writable && $state === 'draft' && $actor->can('erp.cash-transfers.edit'),
                'submit' => $reviewReady && $state === 'draft' && $actor->can('erp.cash-transfers.submit'),
                'approve' => $reviewReady && $state === 'submitted' && $independent && $actor->can('erp.cash-transfers.approve'),
                'reject' => $writable && $state === 'submitted' && $actor->can('erp.cash-transfers.approve'),
                'cancel' => $writable && in_array($state, ['draft', 'submitted', 'approved'], true) && $actor->can('erp.cash-transfers.cancel'),
                'post' => $postingReady,
            ];
            $finance = ['state' => 'unposted', 'message' => 'This transfer has not moved money in the ledger.', 'journal_id' => null, 'journal_url' => null];
            $hasFinance = AccountingCashTransferOperation::query()->where('company_id', $companyId)->where('cash_transfer_id', $id)->exists() || AccountingSourceEffect::query()->where('company_id', $companyId)->where('source_kind', 'cash_transfer')->where('source_id', $id)->exists();
            if ($state === 'posted' || $row->getAttribute('posted_at') !== null || $hasFinance) {
                try {
                    $verified = $this->evidence->verify($companyId, $id);
                    app(AccountingCashTransferReversalEvidence::class)->verifyForOriginal($companyId, $id);
                    $journalId = (int) $verified['journal']->getKey();
                    $finance = ['state' => 'verified', 'message' => 'The complete transfer operation and both journal legs verify.', 'journal_id' => $journalId, 'journal_url' => $actor->can('erp.journal-entries.view') ? '/erp/accounting/journals/'.$journalId : null];
                } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
                    $finance = ['state' => 'review', 'message' => 'Posted evidence needs review. Do not create another transfer to repair this operation.', 'journal_id' => null, 'journal_url' => null];
                }
            }
            if ($finance['state'] !== 'unposted' || $state === 'cancelled') {
                $capabilities = array_fill_keys(array_keys($capabilities), false);
            }
            if ($editing) {
                abort_unless($capabilities['edit'], 403);
            }
            $events = AccountingCashTransferAuditEvent::query()->where('company_id', $companyId)->where('cash_transfer_id', $id)->orderByDesc('id')->limit(101)->get();
            $history = $events->take(100)->map(static fn (AccountingCashTransferAuditEvent $event): array => [
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

            return [...$context, 'issues' => array_values(array_unique($issues)), 'transfer' => [...$this->summary($row), 'purpose' => (string) $row->getAttribute('purpose'), 'notes' => $row->getAttribute('notes'), 'cancellation_reason' => $row->getAttribute('cancellation_reason')],
                'bindings' => $editing ? $this->bindingOptions($companyId) : [], 'capabilities' => $capabilities, 'finance' => $finance,
                'workflow' => $workflow, 'history' => $history, 'historyTruncated' => $events->count() > 100,
                'reversalLinks' => $this->reversalLinks($actor, $row, $finance['state'] === 'verified', $writable),
                'requestKey' => (string) Str::uuid(), 'bookLinks' => $this->bookLinks($actor, $row)];
        }, 3);
    }

    /** Screen methods own a transaction; this acquires the selected company lock. @return array<string, mixed> */
    private function context(User $actor, ?int $companyId): array
    {
        abort_unless($actor->exists && $actor->can('erp.cash-transfers.view'), 403);
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
            $this->access->authorize($actor, $companyId, 'erp.cash-transfers.view');
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $companyId, 'erp.cash-transfers.view');
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
            'canCreate' => $writable && $actor->can('erp.cash-transfers.create'), 'today' => $company === null ? null : $this->dates->today($company), 'issues' => $issues];
    }

    /** @return list<array<string, mixed>> */
    private function bindingOptions(int $companyId): array
    {
        $company = Company::query()->find($companyId);
        if ($company === null) {
            return [];
        }

        return AccountingCashAccount::query()->where('company_id', $companyId)->orderBy('name')->orderBy('id')->get()
            ->map(function (AccountingCashAccount $binding) use ($company): array {
                $account = Account::query()->where('company_id', $company->getKey())->find((int) $binding->getAttribute('account_id'));

                return [...$this->binding($binding), 'selectable' => $binding->getAttribute('status') === 'active' && $binding->getAttribute('currency_code') === $company->getAttribute('currency_code') && $account !== null && $account->getAttribute('status') === 'active' && (bool) $account->getAttribute('is_postable')];
            })->all();
    }

    /** @return array<string, mixed> */
    private function summary(AccountingCashTransfer $row): array
    {
        $source = AccountingCashAccount::query()->where('company_id', $row->getAttribute('company_id'))->find((int) $row->getAttribute('source_cash_account_id'));
        $destination = AccountingCashAccount::query()->where('company_id', $row->getAttribute('company_id'))->find((int) $row->getAttribute('destination_cash_account_id'));

        return ['id' => (int) $row->getKey(), 'company_id' => (int) $row->getAttribute('company_id'), 'number' => (string) $row->getAttribute('number'), 'status' => (string) $row->getAttribute('status'),
            'revision' => (int) $row->getAttribute('revision'), 'document_date' => $row->getAttribute('document_date')->format('Y-m-d'), 'currency_code' => (string) $row->getAttribute('currency_code'), 'amount' => (string) $row->getAttribute('amount'),
            'source_cash_account_id' => (int) $row->getAttribute('source_cash_account_id'), 'destination_cash_account_id' => (int) $row->getAttribute('destination_cash_account_id'),
            'source' => $source === null ? null : $this->binding($source), 'destination' => $destination === null ? null : $this->binding($destination)];
    }

    /** @return array<string, mixed> */
    private function binding(AccountingCashAccount $binding): array
    {
        return ['id' => (int) $binding->getKey(), 'name' => (string) $binding->getAttribute('name'), 'role' => (string) $binding->getAttribute('role'), 'kind' => (string) $binding->getAttribute('kind'), 'status' => (string) $binding->getAttribute('status'), 'currency_code' => (string) $binding->getAttribute('currency_code')];
    }

    private function reviewIdentity(Company $company, AccountingCashTransfer $row): void
    {
        if ($row->getAttribute('document_date')->format('Y-m-d') > $this->dates->today($company)) {
            $this->fail();
        }
        $hash = $this->intent->hash($row);
        $roles = [];
        foreach ([$row->getAttribute('source_snapshot'), $row->getAttribute('destination_snapshot')] as $snapshot) {
            if (! is_array($snapshot)) {
                $this->fail();
            }
            $binding = AccountingCashAccount::query()->where('company_id', $company->getKey())->findOrFail($snapshot['id']);
            if ($binding->getAttribute('status') !== 'active') {
                $this->fail();
            } $roles[] = $snapshot['role'];
        }
        $mapping = $this->mappings->resolveForSource($company, $row->getAttribute('document_date')->format('Y-m-d'), $roles);
        if ($mapping['version_id'] !== (int) $row->getAttribute('mapping_version_id') || ! hash_equals($mapping['payload_hash'], (string) $row->getAttribute('mapping_hash'))) {
            $this->fail();
        }
        if (in_array($row->getAttribute('status'), ['submitted', 'approved'], true) && ! hash_equals($hash, (string) $row->getAttribute('submitted_payload_hash'))) {
            $this->fail();
        }
        if ($row->getAttribute('status') === 'approved' && ! hash_equals($hash, (string) $row->getAttribute('approved_payload_hash'))) {
            $this->fail();
        }
    }

    /** @return list<array{label: string, url: string}> */
    private function bookLinks(User $actor, AccountingCashTransfer $row): array
    {
        if (! $actor->can('erp.cash-books.view')) {
            return [];
        }
        $links = [];
        foreach (['source_cash_account_id' => 'Source book', 'destination_cash_account_id' => 'Destination book'] as $field => $label) {
            $binding = AccountingCashAccount::query()->where('company_id', $row->getAttribute('company_id'))->find((int) $row->getAttribute($field));
            if ($binding === null || ! in_array($binding->getAttribute('kind'), ['cash', 'bank'], true)) {
                continue;
            }
            $links[] = ['label' => $label, 'url' => '/erp/accounting/'.($binding->getAttribute('kind') === 'cash' ? 'cashbook' : 'bankbook').'?'.http_build_query(['company_id' => (int) $row->getAttribute('company_id'), 'binding_id' => (int) $binding->getKey(), 'from' => $row->getAttribute('document_date')->format('Y-m-d'), 'to' => $row->getAttribute('document_date')->format('Y-m-d')])];
        }

        return $links;
    }

    /** @return array<string, mixed> */
    private function reversalLinks(User $actor, AccountingCashTransfer $row, bool $verified, bool $writable): array
    {
        if (! $actor->can('erp.cash-transfer-reversals.view')) {
            return ['create_url' => null, 'register_url' => null, 'requests' => []];
        }
        $companyId = (int) $row->getAttribute('company_id');
        $id = (int) $row->getKey();
        $requests = AccountingCashTransferReversalRequest::query()->where('company_id', $companyId)->where('original_transfer_id', $id)->orderByDesc('id')->limit(100)->get();
        $hasActive = AccountingCashTransferReversalRequest::query()->where('company_id', $companyId)->where('original_transfer_id', $id)->where(function ($q): void {
            $q->where('status', '<>', 'cancelled')->orWhereNotNull('active_transfer_id');
        })->exists();

        return ['create_url' => $verified && $writable && ! $hasActive && $actor->can('erp.cash-transfer-reversals.create') ? '/erp/accounting/cash-transfer-reversals/create?'.http_build_query(['company_id' => $companyId, 'transfer_id' => $id]) : null,
            'register_url' => '/erp/accounting/cash-transfer-reversals?'.http_build_query(['company_id' => $companyId, 'transfer_id' => $id]),
            'requests' => $requests->map(static fn (AccountingCashTransferReversalRequest $request): array => ['id' => (int) $request->getKey(), 'status' => (string) $request->getAttribute('status'), 'url' => '/erp/accounting/cash-transfer-reversals/'.$request->getKey()])->all()];
    }

    private static function time(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : null;
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['transfer' => 'Transfer review identity changed.']);
    }
}

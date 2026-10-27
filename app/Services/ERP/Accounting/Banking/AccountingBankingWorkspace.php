<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Banking;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingBankAdjustment;
use App\Models\Accounting\AccountingBankAdjustmentAuditEvent;
use App\Models\Accounting\AccountingBankAdjustmentOperation;
use App\Models\Accounting\AccountingBankReconciliation;
use App\Models\Accounting\AccountingBankReconciliationLeg;
use App\Models\Accounting\AccountingBankStatement;
use App\Models\Accounting\AccountingBankStatementLine;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntryLine;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingConfigurationGuard;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\AccountingPostingReadiness;
use App\Services\ERP\Accounting\Adjustments\AccountingBankAdjustmentContract;
use App\Services\ERP\Accounting\Adjustments\AccountingBankAdjustmentEvidence;
use App\Services\ERP\Accounting\Adjustments\AccountingBankAdjustmentProof;
use App\Services\ERP\Accounting\Adjustments\AccountingBankAdjustmentWorkflow;
use App\Services\ERP\Accounting\Reconciliation\AccountingBankFinalizationEvidence;
use App\Services\ERP\Accounting\Reconciliation\AccountingBankFinalizationWorkflow;
use App\Services\ERP\Accounting\Reconciliation\AccountingBankReconciliationExportService;
use App\Services\ERP\Accounting\Reconciliation\AccountingBankReconciliationLedger;
use App\Services\ERP\Accounting\Reconciliation\AccountingBankReconciliationService;
use App\Services\ERP\Accounting\Source\AccountingSourcePeriod;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Read-only presentation boundary. All mutations remain in the native command services. */
final class AccountingBankingWorkspace
{
    public function __construct(
        private readonly PurchaseCompanyAccess $access,
        private readonly AccountingBusinessDate $dates,
        private readonly AccountingBankAdjustmentWorkflow $adjustments,
        private readonly AccountingBankAdjustmentContract $contract,
        private readonly AccountingBankAdjustmentProof $proof,
        private readonly AccountingBankAdjustmentEvidence $evidence,
        private readonly AccountingBankReconciliationService $matching,
        private readonly AccountingBankReconciliationLedger $ledger,
    ) {}

    public static function mayRead(User $actor, string $module): bool
    {
        return $actor->exists && $actor->can('erp.bank-statements.view') && $actor->can('erp.cash-books.view')
            && $actor->can('erp.bank-reconciliations.view') && ($module === 'reconciliations' || $actor->can('erp.bank-adjustments.view'));
    }

    /** Caller owns a company transaction. @return array<string, mixed> */
    private function context(User $actor, string $module, ?int $companyId): array
    {
        abort_unless(self::mayRead($actor, $module), 403);
        $query = Company::query();
        if (! $actor->hasRole('super-admin')) {
            $owned = $actor->employee()->value('company_id');
            abort_if($owned === null, 403);
            $query->whereKey($owned);
        }
        $companies = $query->orderBy('name')->orderBy('id')->get(['id', 'code', 'name', 'status', 'currency_code']);
        if ($companyId === null && $companies->count() === 1) {
            $companyId = (int) $companies->firstOrFail()->getKey();
        }
        $company = null;
        if ($companyId !== null) {
            $permission = 'erp.bank-'.$module.'.view';
            $this->access->authorize($actor, $companyId, $permission);
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $companyId, $permission);
        }
        $banks = $company === null ? [] : AccountingCashAccount::query()->where('company_id', $companyId)->where('kind', 'bank')->orderBy('name')->get()
            ->map(static fn (AccountingCashAccount $row): array => $row->only(['id', 'name', 'currency_code', 'status']))->all();

        return ['module' => $module, 'actorId' => (int) $actor->getKey(), 'companies' => $companies->map(static fn (Company $row): array => $row->only(['id', 'code', 'name', 'status', 'currency_code']))->all(),
            'company' => $company?->only(['id', 'code', 'name', 'status', 'currency_code']), 'banks' => $banks,
            'writable' => $company !== null && $company->getAttribute('status') === 'active', 'today' => $company === null ? null : $this->dates->today($company),
            'canCreate' => $company !== null && $company->getAttribute('status') === 'active' && $actor->can('erp.bank-'.$module.'.create'),
            'canAdjust' => self::mayRead($actor, 'adjustments'), 'canReconcile' => self::mayRead($actor, 'reconciliations')];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function index(User $actor, string $module, array $filters): array
    {
        return DB::transaction(function () use ($actor, $module, $filters): array {
            $context = $this->context($actor, $module, isset($filters['company_id']) ? (int) $filters['company_id'] : null);
            $company = (int) ($context['company']['id'] ?? 0);
            $query = $module === 'adjustments' ? AccountingBankAdjustment::query() : AccountingBankReconciliation::query();
            $query->where('company_id', $company);
            if (! empty($filters['cash_account_id'])) {
                AccountingCashAccount::query()->where('company_id', $company)->where('kind', 'bank')->findOrFail((int) $filters['cash_account_id']);
                $query->where('cash_account_id', (int) $filters['cash_account_id']);
            }
            if ($module === 'adjustments' && ! empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            $page = $query->orderByDesc('id')->paginate(25)->withQueryString();
            $page->through(function ($row) use ($company): array {
                if ($row instanceof AccountingBankAdjustment) {
                    return $this->adjustment($row);
                }
                $statement = AccountingBankStatement::query()->where('company_id', $company)->findOrFail((int) $row->getAttribute('statement_id'));
                $finalStates = app(AccountingBankFinalizationEvidence::class)->bank($company, (int) $row->getAttribute('cash_account_id'));

                return ['id' => (int) $row->getKey(), 'cash_account_id' => (int) $row->getAttribute('cash_account_id'), 'revision' => (int) $row->getAttribute('revision'), 'status' => $finalStates[(int) $row->getKey()]['state'] ?? 'working', 'statement_reference' => (string) $statement->getAttribute('statement_reference'), 'starts_on' => $statement->getAttribute('starts_on')->format('Y-m-d'), 'ends_on' => $statement->getAttribute('ends_on')->format('Y-m-d'), 'currency_code' => (string) $row->getAttribute('currency_code')];
            });

            return [...$context, 'records' => $page, 'filters' => ['cash_account_id' => (string) ($filters['cash_account_id'] ?? ''), 'status' => (string) ($filters['status'] ?? '')]];
        }, 3);
    }

    /** Staged selectors use verified statement rows, never arbitrary source/account IDs. @param array<string, mixed> $input @return array<string, mixed> */
    public function create(User $actor, string $module, array $input, ?int $originalId = null): array
    {
        return DB::transaction(function () use ($actor, $module, $input, $originalId): array {
            $original = $originalId === null ? null : $this->adjustments->find($actor, $originalId, 'erp.bank-adjustments.reverse');
            if ($original !== null) {
                abort_if(isset($input['company_id']) && (int) $input['company_id'] !== (int) $original->getAttribute('company_id'), 403);
                $input['company_id'] = (int) $original->getAttribute('company_id');
                $input['cash_account_id'] = (int) $original->getAttribute('cash_account_id');
            }
            $context = $this->context($actor, $module, isset($input['company_id']) ? (int) $input['company_id'] : null);
            abort_unless($actor->can('erp.bank-'.$module.'.'.($originalId === null ? 'create' : 'reverse')), 403);
            $company = (int) ($context['company']['id'] ?? 0);
            $bank = (int) ($input['cash_account_id'] ?? 0);
            $statementId = (int) ($input['statement_id'] ?? 0);
            $statement = null;
            if ($bank > 0) {
                AccountingCashAccount::query()->where('company_id', $company)->where('kind', 'bank')->findOrFail($bank);
            }
            if ($statementId > 0) {
                $statement = AccountingBankStatement::query()->where('company_id', $company)->findOrFail($statementId);
                abort_if($bank > 0 && $bank !== (int) $statement->getAttribute('cash_account_id'), 404);
                $bank = (int) $statement->getAttribute('cash_account_id');
            }
            $statements = $bank === 0 ? [] : AccountingBankStatement::query()->where('company_id', $company)->where('cash_account_id', $bank)->orderByDesc('ends_on')->orderByDesc('id')->limit(100)->get()->all();
            if ($statement !== null && ! in_array($statementId, array_map(static fn (AccountingBankStatement $row): int => (int) $row->getKey(), $statements), true)) {
                $statements[] = $statement;
            }
            $choices = array_map(static fn (AccountingBankStatement $row): array => ['id' => (int) $row->getKey(), 'label' => (string) $row->getAttribute('statement_reference').' · '.$row->getAttribute('starts_on')->format('Y-m-d').' – '.$row->getAttribute('ends_on')->format('Y-m-d')], $statements);
            $issues = [];
            $sources = [];
            $existingUrl = null;
            if ($statementId > 0) {
                try {
                    if ($original !== null) {
                        $this->evidence->family($company, (int) $original->getKey());
                        if ($original->getAttribute('reverses_id') !== null || AccountingBankAdjustment::query()->where('company_id', $company)->where('active_reversal_of_id', $originalId)->exists()) {
                            throw new \LogicException('Refund already reserved.');
                        }
                    }
                    $state = $this->ledger->read($actor, $company, $bank, $statementId);
                    $claimed = AccountingBankAdjustment::query()->where('company_id', $company)->whereNotNull('active_statement_line_id')->pluck('active_statement_line_id')->all();
                    foreach ($state['statements'][$statementId]['rows'] as $id => $row) {
                        $available = ! in_array($id, array_map('intval', $claimed), true) && bccomp($state['used_statement'][$id] ?? '0.00', '0', 2) === 0;
                        if ($original !== null) {
                            $available = $available && $row['amount'] === (string) $original->getAttribute('amount') && $row['direction'] === ($original->getAttribute('kind') === 'charge' ? 'in' : 'out');
                        }
                        $labelLine = AccountingBankStatementLine::query()->where('company_id', $company)->where('statement_id', $statementId)->findOrFail($id);
                        $sources[] = ['reference' => (string) $labelLine->getAttribute('reference'), 'description' => (string) $labelLine->getAttribute('description'), 'id' => $id, 'date' => $row['date'], 'direction' => $row['direction'], 'amount' => $row['amount'], 'selectable' => $available, 'ends_on' => $state['statements'][$statementId]['identity']['ends_on']];
                    }
                    $existing = AccountingBankReconciliation::query()->where('company_id', $company)->where('statement_id', $statementId)->first();
                    if ($existing !== null) {
                        $existingUrl = '/erp/accounting/bank-reconciliations/'.$existing->getKey();
                    }
                } catch (ValidationException|ModelNotFoundException|\LogicException|\JsonException) {
                    $issues[] = 'Source or matching evidence requires review. No new request can be prepared from these records.';
                }
            }
            $counters = [];
            if ($module === 'adjustments' && $original === null && $company > 0) {
                foreach (Account::query()->where('company_id', $company)->where('status', 'active')->where('is_postable', true)->orderBy('code')->get() as $account) {
                    foreach (['charge', 'interest'] as $kind) {
                        try {
                            $this->contract->counter($company, (int) $account->getKey(), $kind, true);
                            $counters[] = ['id' => (int) $account->getKey(), 'code' => (string) $account->getAttribute('code'), 'name' => (string) $account->getAttribute('name'), 'kind' => $kind];
                        } catch (ValidationException|ModelNotFoundException) { /* Ineligible counters are excluded. */
                        }
                    }
                }
            }

            return [...$context, 'mode' => $originalId === null ? 'create' : 'refund', 'record' => null, 'original' => $original === null ? null : $this->adjustment($original), 'selection' => ['cash_account_id' => (string) $bank, 'statement_id' => (string) $statementId], 'statements' => $choices, 'sources' => $sources, 'counters' => $counters, 'issues' => $issues, 'existingUrl' => $existingUrl, 'requestKey' => (string) Str::uuid()];
        }, 3);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function detail(User $actor, string $module, int $id, array $input, bool $edit = false): array
    {
        $found = $module === 'adjustments' ? $this->adjustments->find($actor, $id) : $this->matching->find($actor, $id);
        $company = (int) $found->getAttribute('company_id');
        abort_if(isset($input['company_id']) && (int) $input['company_id'] !== $company, 403);

        return DB::transaction(function () use ($actor, $module, $id, $input, $edit, $company): array {
            $context = $this->context($actor, $module, $company);
            if ($module === 'adjustments') {
                return $this->adjustmentDetail($actor, $id, $context, $edit);
            }
            $root = $this->matching->find($actor, $id);
            $report = null;
            $issues = [];
            try {
                $report = $this->matching->review($actor, $id);
            } catch (ValidationException|ModelNotFoundException|\LogicException|\JsonException) {
                $issues[] = 'Reconciliation evidence is incomplete or changed. Matching and undo are blocked; this screen does not repair history.';
            }
            $pages = [];
            if ($report !== null) {
                foreach (['statement_rows' => 'statement_page', 'book_rows' => 'book_page', 'history' => 'history_page'] as $field => $parameter) {
                    $rows = $report[$field];
                    if ($field !== 'history') {
                        $rows = array_values(array_filter($rows, static function (array $row) use ($input, $field): bool {
                            if (($input['direction'] ?? '') !== '' && $row['direction'] !== $input['direction']) {
                                return false;
                            }

                            return empty($input['outstanding_only']) || bccomp($row[$field === 'statement_rows' ? 'remaining' : 'outstanding_through_cutoff'], '0', 2) > 0;
                        }));
                    } else {
                        $rows = array_reverse($rows);
                    }
                    $size = $field === 'history' ? 25 : 50;
                    $last = max(1, (int) ceil(count($rows) / $size));
                    $page = min(max(1, (int) ($input[$parameter] ?? 1)), $last);
                    $report[$field] = array_slice($rows, ($page - 1) * $size, $size);
                    $pages[$field] = ['current' => $page, 'last' => $last, 'total' => count($rows), 'parameter' => $parameter];
                }
                foreach ($report['statement_rows'] as &$movement) {
                    $nativeLine = AccountingBankStatementLine::query()->where('company_id', $company)->where('statement_id', $root->getAttribute('statement_id'))->findOrFail($movement['id']);
                    $movement['reference'] = (string) $nativeLine->getAttribute('reference');
                    $movement['description'] = (string) $nativeLine->getAttribute('description');
                } unset($movement);
                foreach ($report['book_rows'] as &$movement) {
                    $nativeLine = JournalEntryLine::query()->where('company_id', $company)->where('cash_account_id', $root->getAttribute('cash_account_id'))->findOrFail($movement['id']);
                    $journalId = (int) $nativeLine->getAttribute('journal_entry_id');
                    $effect = AccountingSourceEffect::query()->where('company_id', $company)->where('journal_entry_id', $journalId)->firstOrFail();
                    $kind = (string) $effect->getAttribute('source_kind');
                    $sourceId = (int) $effect->getAttribute('source_id');
                    $movement['reference'] = str_replace('_', ' ', $kind).' #'.$sourceId;
                    $movement['journal_url'] = $actor->can('erp.journal-entries.view') ? '/erp/accounting/journals/'.$journalId : null;
                    $movement['source_url'] = match ($kind) {
                        'bank_adjustment' => self::mayRead($actor, 'adjustments') ? '/erp/accounting/bank-adjustments/'.$sourceId : null,
                        'cash_transfer' => $actor->can('erp.cash-transfers.view') ? '/erp/accounting/cash-transfers/'.$sourceId : null,
                        'cash_transfer_reversal' => $actor->can('erp.cash-transfer-reversals.view') ? '/erp/accounting/cash-transfer-reversals/'.$sourceId : null,
                        default => null,
                    };
                } unset($movement);
                foreach ($report['history'] as &$operation) {
                    $operation['legs'] = AccountingBankReconciliationLeg::query()->where('company_id', $company)->where('operation_id', $operation['id'])->orderBy('id')->get()->map(static fn (AccountingBankReconciliationLeg $leg): array => ['side' => (string) $leg->getAttribute('side'), 'id' => (int) ($leg->getAttribute('statement_line_id') ?? $leg->getAttribute('journal_line_id')), 'amount' => (string) $leg->getAttribute('amount')])->all();
                } unset($operation);
            }
            $finalization = app(AccountingBankFinalizationWorkflow::class)->review($actor, $id);

            return [...$context, 'record' => ['id' => $id, 'revision' => (int) $root->getAttribute('revision'), 'statement_id' => (int) $root->getAttribute('statement_id'), 'cash_account_id' => (int) $root->getAttribute('cash_account_id')], 'exportLinks' => app(AccountingBankReconciliationExportService::class)->links($actor, $id, $finalization['state'] !== 'review'), 'report' => $report, 'finalization' => ['state' => $finalization['state'], 'frozen' => $finalization['frozen']], 'pages' => $pages, 'issues' => $issues,
                'filters' => ['direction' => (string) ($input['direction'] ?? ''), 'outstanding_only' => ! empty($input['outstanding_only'])], 'requestKey' => (string) Str::uuid(),
                'capabilities' => ['match' => ! $finalization['frozen'] && $report !== null && $context['writable'] && $actor->can('erp.bank-reconciliations.match'), 'unmatch' => ! $finalization['frozen'] && $report !== null && $context['writable'] && $actor->can('erp.bank-reconciliations.unmatch')],
                'statementUrl' => '/erp/accounting/bank-statements/'.$root->getAttribute('statement_id'), 'bookUrl' => '/erp/accounting/bankbook?'.http_build_query(['company_id' => $company, 'binding_id' => (int) $root->getAttribute('cash_account_id')])];
        }, 3);
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function adjustmentDetail(User $actor, int $id, array $context, bool $edit): array
    {
        $row = $this->adjustments->find($actor, $id);
        $company = (int) $row->getAttribute('company_id');
        $issues = [];
        $valid = true;
        $preview = [];
        $journalUrl = null;
        $state = (string) $row->getAttribute('status');
        try {
            $this->proof->verify($row);
            $preview = $this->proof->lines($row);
            if ($state === 'posted') {
                $verified = $this->evidence->verify($company, $id);
                $this->evidence->family($company, $id);
                if ($actor->can('erp.journal-entries.view')) {
                    $journalUrl = '/erp/accounting/journals/'.$verified['journal']->getKey();
                }
            } elseif (AccountingBankAdjustmentOperation::query()->where('company_id', $company)->where('adjustment_id', $id)->exists() || AccountingSourceEffect::query()->where('company_id', $company)->where('source_kind', 'bank_adjustment')->where('source_id', $id)->exists() || JournalEntryLine::query()->where('company_id', $company)->where('source_line_type', 'bank_adjustment')->where('source_line_id', $id)->exists()) {
                throw new \LogicException('Unexpected financial evidence.');
            }
        } catch (ValidationException|ModelNotFoundException|\LogicException|\JsonException) {
            $valid = false;
            $preview = [];
            $issues[] = 'Adjustment evidence requires review. Actions are blocked; no missing history is reconstructed.';
        }
        $capabilities = [];
        foreach (['edit', 'submit', 'approve', 'reject', 'cancel', 'post'] as $action) {
            $allowedState = match ($action) {
                'edit', 'submit' => $state === 'draft', 'approve', 'reject' => $state === 'submitted', 'post' => $state === 'approved', default => in_array($state, ['draft', 'submitted', 'approved'], true)
            };
            $capabilities[$action] = $valid && $context['writable'] && $allowedState && $actor->can('erp.bank-adjustments.'.($action === 'reject' ? 'approve' : $action));
        }
        if (in_array((int) $actor->getKey(), [(int) $row->getAttribute('created_by'), (int) $row->getAttribute('last_edited_by'), (int) $row->getAttribute('submitted_by')], true)) {
            $capabilities['approve'] = false;
        }
        if ($valid && in_array($state, ['draft', 'submitted', 'approved'], true)) {
            try {
                $owner = Company::query()->findOrFail($company);
                $setting = app(AccountingConfigurationGuard::class)->lockForSetup($owner);
                $sourceSnapshot = $row->getAttribute('source_snapshot');
                $date = $row->getAttribute('document_date')->format('Y-m-d');
                if ($date < $sourceSnapshot['date'] || $date > $sourceSnapshot['ends_on'] || $date > $this->dates->today($owner)) {
                    throw new \LogicException('Date requires review.');
                }
                app(AccountingSourcePeriod::class)->lock($actor, $owner, $date);
                $this->contract->counter($company, (int) $row->getAttribute('counter_account_id'), (string) $row->getAttribute('kind'), true);
                $matching = $this->ledger->read($actor, $company, (int) $row->getAttribute('cash_account_id'), (int) $sourceSnapshot['statement_id']);
                if (bccomp($matching['used_statement'][(int) $row->getAttribute('statement_line_id')] ?? '0.00', '0', 2) !== 0) {
                    throw new \LogicException('Movement is already matched.');
                }
                if ($row->getAttribute('reverses_id') === null) {
                    $binding = AccountingCashAccount::query()->where('company_id', $company)->findOrFail((int) $row->getAttribute('cash_account_id'));
                    $snapshot = $row->getAttribute('binding_snapshot');
                    $profile = app(AccountingMappingService::class)->resolveForSource($owner, $date, [$snapshot['role']]);
                    if ($binding->getAttribute('status') !== 'active' || $profile['version_id'] !== (int) $row->getAttribute('mapping_version_id') || $profile['payload_hash'] !== $row->getAttribute('mapping_hash') || ($profile['accounts'][$snapshot['role']] ?? null) !== $snapshot['account_id']) {
                        throw new \LogicException('Bank or profile changed.');
                    }
                }
                if ($setting->getAttribute('state') !== 'enabled') {
                    $capabilities['post'] = false;
                } elseif ($capabilities['post']) {
                    try {
                        app(AccountingPostingReadiness::class)->check($owner, $setting, $date);
                    } catch (ValidationException) {
                        $capabilities['post'] = false;
                        $issues[] = 'Approved cutover and activation evidence is required before posting.';
                    }
                }
            } catch (ValidationException|ModelNotFoundException|AuthorizationException|\LogicException|\JsonException) {
                foreach (['submit', 'approve', 'post'] as $action) {
                    $capabilities[$action] = false;
                }
                $issues[] = 'Submission, approval or posting is currently unavailable. Review the bank binding, source availability, profile and open posting period.';
            }
        }
        if ($edit) {
            abort_unless($capabilities['edit'], 403);
        }
        $history = AccountingBankAdjustmentAuditEvent::query()->where('company_id', $company)->where('adjustment_id', $id)->orderByDesc('id')->limit(101)->get();
        $refunds = AccountingBankAdjustment::query()->where('company_id', $company)->where('reverses_id', $id)->orderBy('id')->get()->map(fn (AccountingBankAdjustment $item): array => $this->adjustment($item))->all();
        $source = AccountingBankStatementLine::query()->where('company_id', $company)->find((int) $row->getAttribute('statement_line_id'));
        $refund = $valid && $state === 'posted' && $row->getAttribute('reverses_id') === null && $context['writable'] && $actor->can('erp.bank-adjustments.reverse') && ! AccountingBankAdjustment::query()->where('company_id', $company)->where('active_reversal_of_id', $id)->exists();

        return [...$context, 'mode' => 'edit', 'record' => [...$this->adjustment($row), 'reason' => (string) $row->getAttribute('reason'), 'cancellation_reason' => $row->getAttribute('cancellation_reason'), 'created_by' => (int) $row->getAttribute('created_by'), 'submitted_by' => $row->getAttribute('submitted_by'), 'approved_by' => $row->getAttribute('approved_by')],
            'capabilities' => $capabilities, 'issues' => $issues, 'financeState' => ! $valid ? 'review' : ($state === 'posted' ? 'verified' : 'unposted'), 'preview' => $preview, 'journalUrl' => $journalUrl,
            'statementUrl' => $source === null ? null : '/erp/accounting/bank-statements/'.$source->getAttribute('statement_id'), 'refundUrl' => $refund ? '/erp/accounting/bank-adjustments/'.$id.'/refund' : null, 'refunds' => $refunds,
            'history' => $history->take(100)->map(static fn (AccountingBankAdjustmentAuditEvent $event): array => ['id' => (int) $event->getKey(), 'action' => (string) $event->getAttribute('action'), 'actor_id' => (int) $event->getAttribute('actor_id'), 'reason' => $event->getAttribute('reason'), 'occurred_at' => $event->getAttribute('occurred_at')->format(DATE_ATOM)])->all(), 'historyTruncated' => $history->count() > 100, 'requestKey' => (string) Str::uuid()];
    }

    /** @return array<string, mixed> */
    private function adjustment(AccountingBankAdjustment $row): array
    {
        return ['id' => (int) $row->getKey(), 'cash_account_id' => (int) $row->getAttribute('cash_account_id'), 'statement_line_id' => (int) $row->getAttribute('statement_line_id'), 'counter_account_id' => (int) $row->getAttribute('counter_account_id'), 'kind' => (string) $row->getAttribute('kind'), 'status' => (string) $row->getAttribute('status'), 'revision' => (int) $row->getAttribute('revision'), 'amount' => (string) $row->getAttribute('amount'), 'currency_code' => (string) $row->getAttribute('currency_code'), 'document_date' => $row->getAttribute('document_date')->format('Y-m-d'), 'reverses_id' => $row->getAttribute('reverses_id')];
    }
}

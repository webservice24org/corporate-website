<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reconciliation;

use App\Models\Accounting\AccountingBankAdjustment;
use App\Models\Accounting\AccountingBankStatement;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\Books\AccountingCashBookReader;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Scoped read-only snapshot for the later finalization writer. Captures no approval or financial effect. */
final class AccountingBankFinalizationReadiness
{
    public function __construct(
        private readonly AccountingBankReconciliationService $matching,
        private readonly AccountingBankReconciliationLedger $ledger,
        private readonly AccountingBankReconciliationSources $sources,
        private readonly AccountingBankReconciliationReport $reports,
        private readonly AccountingCashBookReader $books,
        private readonly AccountingBankFinalizationContract $contract,
        private readonly AccountingCanonicalPayload $canonical,
        private readonly AccountingBusinessDate $dates,
        private readonly PurchaseCompanyAccess $access,
    ) {}

    /** Safe, bounded presentation DTO: no source body, hash, key, model or unbounded movement list. @return array<string, mixed> */
    public function review(User $actor, int $id, ?int $requestedCompany = null, int $page = 1): array
    {
        $found = $this->matching->find($actor, $id);
        $company = (int) $found->getAttribute('company_id');
        abort_if($requestedCompany !== null && $requestedCompany !== $company, 403);

        return DB::transaction(function () use ($actor, $id, $requestedCompany, $page, $company): array {
            Company::query()->whereKey($company)->lockForUpdate()->firstOrFail();
            $bundle = $this->capture($actor, $id, $requestedCompany);
            $finalization = app(AccountingBankFinalizationWorkflow::class)->review($actor, $id, $requestedCompany);
            $bundle['review']['finalized'] = $finalization['state'] === 'finalized';
            $bundle['review']['approval_required'] = $finalization['state'] !== 'finalized';
            if ($finalization['state'] === 'review') {
                $bundle['review']['technical_ready'] = false;
                $bundle['review']['evidence_state'] = 'review';
                $bundle['review']['bridge'] = null;
            }
            if ($finalization['state'] === 'finalized') {
                $bundle['review']['message'] = 'This cutoff has verified independent approval. This page only reviews coverage; use the finalization page for preserved evidence and authorized reopening.';
            }
            $exceptions = $bundle['exceptions'];
            $last = max(1, (int) ceil(count($exceptions) / 25));
            $current = min(max(1, $page), $last);

            return ['exportLinks' => app(AccountingBankReconciliationExportService::class)->links($actor, $id, $finalization['state'] !== 'review'), 'review' => $bundle['review'], 'exceptions' => array_slice($exceptions, ($current - 1) * 25, 25),
                'exceptionPage' => ['current' => $current, 'last' => $last, 'total' => count($exceptions)],
                'finalizationUrl' => '/erp/accounting/bank-reconciliations/'.$id.'/finalization', 'workspaceUrl' => '/erp/accounting/bank-reconciliations/'.$id, 'statementUrl' => '/erp/accounting/bank-statements/'.$bundle['review']['statement_id']];
        }, 3);
    }

    /** Internal authorized contract. Never send this bundle's snapshot/hash to Inertia. @return array<string, mixed> */
    public function capture(User $actor, int $id, ?int $requestedCompany = null): array
    {
        $found = $this->matching->find($actor, $id);
        $companyId = (int) $found->getAttribute('company_id');
        abort_if($requestedCompany !== null && $requestedCompany !== $companyId, 403);

        return DB::transaction(function () use ($actor, $id, $companyId): array {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            foreach (['erp.bank-reconciliations.view', 'erp.bank-statements.view', 'erp.cash-books.view'] as $permission) {
                $this->access->authorize($actor, $companyId, $permission);
            }
            $root = $this->matching->find($actor, $id);
            $bankId = (int) $root->getAttribute('cash_account_id');
            $statementId = (int) $root->getAttribute('statement_id');
            $bank = AccountingCashAccount::query()->where('company_id', $companyId)->where('kind', 'bank')->findOrFail($bankId);
            $statement = AccountingBankStatement::query()->where('company_id', $companyId)->where('cash_account_id', $bankId)->findOrFail($statementId);
            $cutoff = $statement->getAttribute('ends_on')->format('Y-m-d');
            $today = $this->dates->today($company);
            $exceptions = [];
            $proofs = [];
            $report = null;
            $facts = ['evidence_verified' => false, 'date_valid' => $cutoff <= $today,
                'currency_agrees' => $company->getAttribute('currency_code') === $bank->getAttribute('currency_code') && $bank->getAttribute('currency_code') === $statement->getAttribute('currency_code') && app(AccountingSetupService::class)->supportsCurrency((string) $company->getAttribute('currency_code')),
                'statement_coverage_complete' => false, 'workspaces_complete' => false, 'unmatched_statement_rows' => null, 'prior_unmatched_statement_rows' => null,
                'pending_adjustments' => null, 'book_complete' => false, 'unsupported_cash_lines' => null, 'bridge' => []];
            $counts = ['statements' => 0, 'prior_statement_rows_unmatched' => null, 'statement_rows_unmatched' => null, 'outstanding_book_rows' => null, 'pending_adjustments' => null, 'unsupported_cash_lines' => null];
            try {
                $state = $this->ledger->read($actor, $companyId, $bankId);
                $report = $this->reports->project($id, $state);
                $book = $this->books->reconciliationBook($actor, $companyId, $bankId, ['from' => '1900-01-01', 'to' => $cutoff]);
                $facts['book_complete'] = (bool) $book['complete'] && (bool) $book['posted_totals_complete'];
                foreach ($book['exceptions'] as $exception) {
                    $exceptions[] = $this->exception((string) $exception['code'], null, null);
                }
                $unsupported = DB::table('journal_entry_lines as l')->join('journal_entries as j', function ($join): void {
                    $join->on('j.id', '=', 'l.journal_entry_id')->on('j.company_id', '=', 'l.company_id');
                })
                    ->where('j.company_id', $companyId)->whereDate('j.accounting_date', '<=', $cutoff)
                    ->where(function ($query) use ($bankId, $bank): void {
                        $query->where('l.cash_account_id', $bankId)->orWhere('l.account_id', $bank->getAttribute('account_id'));
                    })
                    ->where(function ($query): void {
                        $query->where('j.status', 'posted')->orWhereNotNull('j.posted_at')->orWhereNotNull('j.posted_by');
                    })
                    ->where(function ($query) use ($bankId): void {
                        $query->whereIn('j.entry_kind', ['opening', 'manual', 'reversal', 'period_adjustment'])->orWhereNull('l.cash_account_id')->orWhere('l.cash_account_id', '<>', $bankId);
                    })->count();
                $facts['unsupported_cash_lines'] = $unsupported;
                $chain = AccountingBankStatement::query()->where('company_id', $companyId)->where('cash_account_id', $bankId)->whereDate('starts_on', '<=', $cutoff)->orderBy('starts_on')->orderBy('ends_on')->orderBy('id')->get();
                $previousEnd = null;
                $previousClosing = null;
                $coverage = true;
                $workspaces = true;
                $priorUnmatched = 0;
                $rootByStatement = [];
                foreach ($state['roots'] as $rootId => $rootState) {
                    $rootByStatement[$rootState['statement_id']] = $rootId;
                }
                foreach ($chain as $item) {
                    $sourceId = (int) $item->getKey();
                    $source = $this->sources->statement($companyId, $bankId, $sourceId);
                    $identity = $source['identity'];
                    $proofs[] = ['identity' => $identity, 'opening' => $source['opening_balance'], 'closing' => $source['closing_balance'], 'rows' => $source['rows']];
                    if ($identity['ends_on'] > $cutoff) {
                        $coverage = false;
                        $exceptions[] = $this->exception('statement_crosses_cutoff', $sourceId, null);

                        continue;
                    }
                    if ($previousEnd === null) {
                        if (bccomp($source['opening_balance'], '0', 2) !== 0) {
                            $coverage = false;
                            $exceptions[] = $this->exception('opening_requires_verified_cutover_contract', $sourceId, null);
                        }
                    } else {
                        $expected = CarbonImmutable::parse($previousEnd, 'UTC')->addDay()->format('Y-m-d');
                        if ($identity['starts_on'] !== $expected) {
                            $coverage = false;
                            $exceptions[] = $this->exception($identity['starts_on'] <= $previousEnd ? 'statement_period_overlap' : 'statement_period_gap', $sourceId, null);
                        }
                        if (bccomp($source['opening_balance'], (string) $previousClosing, 2) !== 0) {
                            $coverage = false;
                            $exceptions[] = $this->exception('statement_balance_carry_mismatch', $sourceId, null);
                        }
                    }
                    $previousEnd = $identity['ends_on'];
                    $previousClosing = $source['closing_balance'];
                    if (! isset($rootByStatement[$sourceId])) {
                        $workspaces = false;
                        $exceptions[] = $this->exception('statement_workspace_missing', $sourceId, null);
                    }
                    foreach ($source['rows'] as $lineId => $line) {
                        $remaining = bcsub($line['amount'], $state['used_statement'][$lineId] ?? '0.00', 2);
                        if (bccomp($remaining, '0', 2) !== 0 && $sourceId !== $statementId) {
                            $priorUnmatched++;
                            $exceptions[] = $this->exception('prior_statement_movement_unmatched', $sourceId, $lineId);
                        }
                    }
                }
                $unmatched = 0;
                $outstanding = 0;
                foreach ($report['statement_rows'] as $line) {
                    if (bccomp($line['remaining'], '0', 2) !== 0) {
                        $unmatched++;
                        $exceptions[] = $this->exception('statement_movement_unmatched', $statementId, $line['id']);
                    }
                }
                foreach ($report['book_rows'] as $line) {
                    if (bccomp($line['outstanding_through_cutoff'], '0', 2) > 0) {
                        $outstanding++;
                    }
                }
                $pending = AccountingBankAdjustment::query()->where('company_id', $companyId)->where('cash_account_id', $bankId)->whereDate('document_date', '<=', $cutoff)->whereNotIn('status', ['posted', 'cancelled'])->orderBy('id')->get(['id', 'status']);
                foreach ($pending as $item) {
                    $exceptions[] = $this->exception('bank_adjustment_request_unresolved', null, (int) $item->getKey());
                }
                $facts = [...$facts, 'evidence_verified' => true, 'statement_coverage_complete' => $coverage && $previousEnd === $cutoff, 'workspaces_complete' => $workspaces, 'unmatched_statement_rows' => $unmatched,
                    'prior_unmatched_statement_rows' => $priorUnmatched, 'pending_adjustments' => $pending->count(), 'bridge' => $report['bridge']];
                $counts = ['statements' => $chain->count(), 'prior_statement_rows_unmatched' => $priorUnmatched, 'statement_rows_unmatched' => $unmatched, 'outstanding_book_rows' => $outstanding, 'pending_adjustments' => $pending->count(), 'unsupported_cash_lines' => $unsupported];
                $heads = [];
                foreach ($state['roots'] as $rootId => $rootState) {
                    if ($state['statements'][$rootState['statement_id']]['identity']['ends_on'] > $cutoff) {
                        continue;
                    } $heads[] = ['id' => $rootId, 'statement_id' => $rootState['statement_id'], 'revision' => $rootState['revision'], 'head' => $rootState['head']];
                }
                $bookProofs = [];
                foreach ($state['book'] as $line) {
                    if ($line['date'] <= $cutoff) {
                        $bookProofs[] = $line;
                    }
                }
                $snapshot = ['contract' => AccountingBankFinalizationContract::VERSION, 'company_id' => $companyId, 'cash_account_id' => $bankId, 'reconciliation_id' => $id, 'statement_id' => $statementId,
                    'revision' => $report['revision'], 'cutoff' => $cutoff, 'currency_code' => $report['currency_code'], 'heads' => $heads, 'statements' => $proofs, 'book' => $bookProofs, 'bridge' => $report['bridge'], 'facts' => $facts];
            } catch (ValidationException|ModelNotFoundException|\LogicException|\JsonException) {
                $report = null;
                $snapshot = null;
                $facts['evidence_verified'] = false;
                $facts['bridge'] = [];
                $exceptions[] = $this->exception('bank_source_or_matching_evidence_requires_review', null, null);
            }
            $checks = $this->contract->checks($facts);
            $ready = $this->contract->ready($checks);

            return ['snapshot' => $snapshot, 'snapshot_hash' => $snapshot === null ? null : $this->canonical->hash($snapshot), 'exceptions' => $exceptions,
                'review' => ['contract' => AccountingBankFinalizationContract::VERSION, 'company_id' => $companyId, 'company_name' => (string) $company->getAttribute('name'), 'reconciliation_id' => $id,
                    'statement_id' => $statementId, 'statement_reference' => (string) $statement->getAttribute('statement_reference'), 'cash_account_id' => $bankId, 'bank_name' => (string) $bank->getAttribute('name'), 'currency_code' => (string) $bank->getAttribute('currency_code'),
                    'cutoff' => $cutoff, 'revision' => (int) $root->getAttribute('revision'), 'observed_at' => CarbonImmutable::now('UTC')->format(DATE_ATOM), 'technical_ready' => $ready,
                    'evidence_state' => $report === null ? 'review' : 'verified', 'checks' => $checks, 'counts' => $counts, 'bridge' => $report === null ? null : $report['bridge'],
                    'finalized' => false, 'can_finalize' => false, 'approval_required' => true,
                    'message' => $ready ? 'Technical coverage checks pass at this revision. Independent approval and finalization locking are still required; this is not a finalized reconciliation.' : 'Resolve the blocked checks before finalization submission. No approval, journal or matching change is recorded by this review.']];
        }, 3);
    }

    /** @return array{code: string, statement_id: int|null, row_id: int|null} */
    private function exception(string $code, ?int $statement, ?int $row): array
    {
        return ['code' => $code, 'statement_id' => $statement, 'row_id' => $row];
    }
}

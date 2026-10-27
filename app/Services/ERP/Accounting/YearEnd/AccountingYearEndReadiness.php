<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\YearEnd;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\Close\AccountingPeriodCloseEvidence;
use App\Services\ERP\Accounting\Close\AccountingPeriodCloseFingerprint;
use App\Services\ERP\Accounting\Close\AccountingPeriodCloseReadiness;
use App\Services\ERP\Accounting\Reports\AccountingLedgerReader;
use App\Services\ERP\Accounting\Reports\AccountingProfitLossWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountingYearEndReadiness
{
    public function __construct(private readonly AccountingYearEndEvidence $evidence, private readonly AccountingYearEndContract $contract, private readonly AccountingPeriodCloseEvidence $periodEvidence, private readonly AccountingPeriodCloseReadiness $periodReadiness, private readonly AccountingPeriodCloseFingerprint $fingerprint, private readonly AccountingLedgerReader $ledger, private readonly AccountingProfitLossWorkspace $profit, private readonly AccountingMappingService $mappings, private readonly AccountingBusinessDate $dates, private readonly AccountingCanonicalPayload $canonical) {}

    /** Caller retains company/year/period locks. @return array<string, mixed> */
    public function capture(User $actor, FiscalYear $year, string $mode): array
    {
        if (DB::transactionLevel() < 1 || ! in_array($mode, ['close', 'reopen'], true)) {
            throw new \LogicException('Use a locked supported year-end scope.');
        }
        $id = (int) $year->getAttribute('company_id');
        $owner = Company::query()->findOrFail($id);
        $read = $this->evidence->read($year);
        $from = $year->getAttribute('starts_on')->format('Y-m-d');
        $to = $year->getAttribute('ends_on')->format('Y-m-d');
        $checks = [];
        $periodProofs = [];
        $plan = null;
        $mapping = null;
        $labels = [];
        $lastPeriod = null;
        $setting = DB::table('accounting_company_settings')->where('company_id', $id)->first();
        $activation = $setting === null ? null : $setting->activation_date;
        $activeFrom = is_string($activation) && $activation > $from ? $activation : $from;
        $checks[] = $this->check('mode', ($mode === 'close' && $year->getAttribute('state') === 'open' && $read['active'] === null) || ($mode === 'reopen' && $year->getAttribute('state') === 'closed' && $read['active'] !== null), 'Close an open year, or compensate its active retained close before reopening.');
        $checks[] = $this->check('ended', $to < $this->dates->today($owner), 'The fiscal year must have ended in company time.');
        $checks[] = $this->check('enabled', $setting !== null && $setting->state === 'enabled' && is_string($activation) && $activeFrom <= $to && $owner->getAttribute('status') === 'active', 'Use active, enabled accounting with a known activation boundary; this workflow does not activate or repair cutover.');
        $prior = DB::table('fiscal_years')->where('company_id', $id)->whereDate('ends_on', '<', $from)->whereDate('ends_on', '>=', $activation ?? '1900-01-01')->where('state', '<>', 'closed')->count();
        $checks[] = $this->check('prior_active_years', $prior === 0, 'Close earlier active fiscal years first. Pre-activation years are outside this closing horizon.');
        $later = DB::table('fiscal_years')->where('company_id', $id)->whereDate('starts_on', '>', $to)->where('state', 'closed')->exists() || DB::table('accounting_periods')->where('company_id', $id)->whereDate('starts_on', '>', $to)->where('state', 'hard_closed')->exists();
        $checks[] = $this->check('later_closures', ! $later, 'Later closed years and hard-closed periods must be reopened in reverse order first.');
        $opening = DB::table('journal_entries')->where('company_id', $id)->where('entry_kind', 'opening')->whereDate('accounting_date', '>', $to)->where(function ($q): void {
            $q->where('status', 'posted')->orWhereNotNull('posted_at');
        })->exists();
        $checks[] = $this->check('subsequent_openings', ! $opening, 'Subsequent explicit opening journals need cutover review before close/reopen. No next-year opening journal is generated.');
        $periods = AccountingPeriod::query()->where('company_id', $id)->where('fiscal_year_id', $year->getKey())->whereDate('ends_on', '>=', $activeFrom)->orderBy('starts_on')->orderBy('id')->limit(501)->lockForUpdate()->get();
        $next = $activeFrom;
        $covered = $periods->isNotEmpty() && $periods->count() <= 500;
        if ($periods->count() <= 500) {
            foreach ($periods as $period) {
                $start = $period->getAttribute('starts_on')->format('Y-m-d');
                $end = $period->getAttribute('ends_on')->format('Y-m-d');
                if ($start > $next || $end < $next || ($lastPeriod !== null && $start !== $next) || $start < $from || $end > $to || $period->getAttribute('state') !== 'hard_closed') {
                    $covered = false;
                }
                try {
                    $history = $this->periodEvidence->read($period);
                    $last = $history === [] ? null : $history[array_key_last($history)];
                    if ($last === null || $last->getAttribute('event_kind') !== 'hard_close' || (int) $last->getAttribute('actor_id') !== (int) $period->getAttribute('closed_by')) {
                        $covered = false;
                    }
                    $periodProofs[] = ['id' => (int) $period->getKey(), 'revision' => (int) $period->getAttribute('revision'), 'head' => $period->getAttribute('close_evidence_hash')];
                } catch (ValidationException|\LogicException) {
                    $covered = false;
                }
                $next = CarbonImmutable::parse($end, 'UTC')->addDay()->format('Y-m-d');
                $lastPeriod = $period;
            }
        }
        $covered = $covered && $lastPeriod !== null && $lastPeriod->getAttribute('ends_on')->format('Y-m-d') === $to;
        $checks[] = $this->check('period_coverage', $covered, 'All periods in the active fiscal horizon must form a continuous calendar and have anchored independent hard-close evidence.');
        $canRead = $actor->can('erp.trial-balance.view') && $actor->can('erp.profit-loss.view');
        $checks[] = $this->check('report_authority', $canRead, 'Separate Trial Balance and Profit and Loss view permissions are required to inspect and approve amounts.');
        if ($canRead) {
            try {
                $source = $this->ledger->trial($actor, $id, ['from' => $from, 'to' => $to]);
                $checks[] = $this->check('balanced_gl', $source['balanced'] === true, 'Complete posted GL proof and exact trial balance must verify.');
                if ($mode === 'close') {
                    $p = $this->profit->page($actor, ['company_id' => $id, 'from' => $from, 'to' => $to])['report'];
                    $checks[] = $this->check('performance_classification', $p['classification_complete'] === true, 'Resolve nominal classifications and opening/prior-year issues before closing.');
                    $company = Company::query()->findOrFail($id);
                    $resolved = $this->mappings->resolveForSource($company, $to, ['retained_earnings']);
                    $mapping = ['version_id' => $resolved['version_id'], 'payload_hash' => $resolved['payload_hash']];
                    // Nominal opening amounts are separately rejected by the P&L reader and the contract's opening balances.
                    $plan = $this->contract->plan($source['accounts'], [], $resolved['accounts']['retained_earnings']);
                    if (DB::table('accounts')->where('company_id', $id)->whereIn('parent_id', array_column($plan['lines'], 'account_id'))->exists()) {
                        throw ValidationException::withMessages(['year_end' => 'Closing balances require postable leaf accounts.']);
                    }
                    if ($plan['result'] !== $p['provisional_result']) {
                        throw ValidationException::withMessages(['year_end' => 'Residual nominal balances differ from the preserved economic year result.']);
                    }
                    foreach ($source['accounts'] as $a) {
                        $labels[$a['id']] = ['code' => $a['code'], 'name' => $a['name']];
                    }
                    $labels = array_intersect_key($labels, array_flip(array_column($plan['lines'], 'account_id')));
                    if ($covered) {
                        $current = $this->periodReadiness->capture($actor, $lastPeriod);
                        $checks[] = $this->check('current_close_checks', $current['snapshot']['automated_ready'] === true, 'Rerun current source/report/cash/bank checks at the final cutoff; a historical period certificate alone is not enough.');
                        $history = $this->periodEvidence->read($lastPeriod);
                        $manual = null;
                        foreach (array_reverse($history) as $item) {
                            if ($item->getAttribute('event_kind') === 'approve') {
                                $manual = $item->getAttribute('payload');
                                break;
                            }
                        }
                        $checks[] = $this->check('inventory_review_matches', $manual !== null && $manual['inventory_amount'] === $current['snapshot']['inventory_gl']['inventory'] && $manual['dispatched_amount'] === $current['snapshot']['inventory_gl']['dispatched_inventory'], 'Final-period independently reviewed stock/dispatched balances must still match current cutoff GL. Reopen and review the affected period if they changed.');
                    } else {
                        $checks[] = $this->check('current_close_checks', false, 'Complete and independently close every active period first.');
                    }
                } elseif ($read['active'] !== null) {
                    $close = $read['active'];
                    $prep = null;
                    foreach ($read['events'] as $event) {
                        if ((int) $event->getKey() === $close->getAttribute('payload')['preparation_id']) {
                            $prep = $event;
                        }
                    }
                    if ($prep === null) {
                        throw ValidationException::withMessages(['year_end' => 'The active close has no frozen original plan.']);
                    }
                    $original = $prep->getAttribute('payload')['snapshot'];
                    $plan = $this->contract->reverse($original['plan']);
                    $mapping = $original['mapping'];
                    $labels = $original['labels'];
                }
            } catch (ValidationException|ModelNotFoundException|\LogicException|\JsonException) {
                $checks[] = $this->check('financial_evidence', false, 'Financial, mapping or recorded closing evidence requires review. No amount or repair is guessed.');
            }
        }
        try {
            $fingerprint = $this->fingerprint->capture($id);
        } catch (ValidationException|\JsonException) {
            $fingerprint = null;
            $checks[] = $this->check('fingerprint', false, 'Complete current evidence exceeds its supported scope or is missing.');
        }
        $ready = $plan !== null && $mapping !== null && $fingerprint !== null && count(array_filter($checks, static fn (array $c): bool => ! $c['passed'])) === 0;
        $snapshot = ['contract' => 'year-end-v1', 'company_id' => $id, 'year_id' => (int) $year->getKey(), 'year_revision' => (int) $year->getAttribute('revision'), 'year_code' => (string) $year->getAttribute('code'), 'mode' => $mode, 'from' => $from, 'active_from' => $activeFrom, 'date' => $to, 'currency' => (string) $owner->getAttribute('currency_code'), 'period_id' => $lastPeriod === null ? null : (int) $lastPeriod->getKey(), 'period_proofs' => $periodProofs, 'plan' => $plan, 'labels' => $labels, 'mapping' => $mapping, 'original_close_id' => $read['active'] === null ? null : (int) $read['active']->getKey(), 'fingerprint' => $fingerprint, 'checks' => $checks, 'ready' => $ready];

        return ['snapshot' => $snapshot, 'snapshot_hash' => $this->canonical->hash($snapshot)];
    }

    /** @return array{code: string, passed: bool, message: string} */
    private function check(string $code, bool $passed, string $message): array
    {
        return compact('code', 'passed', 'message');
    }
}

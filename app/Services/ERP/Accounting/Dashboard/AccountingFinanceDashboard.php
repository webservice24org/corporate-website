<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Dashboard;

use App\Models\Accounting\AccountingCashAccount;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\Banking\AccountingBankingWorkspace;
use App\Services\ERP\Accounting\Books\AccountingCashBookReader;
use App\Services\ERP\Accounting\Reconciliation\AccountingBankReconciliationService;
use App\Services\ERP\Accounting\Reports\AccountingAgingReader;
use App\Services\ERP\Accounting\Reports\AccountingProfitLossWorkspace;
use App\Services\ERP\Accounting\Source\AccountingSourceReconciliation;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AccountingFinanceDashboard
{
    public const PERMISSION = 'erp.finance-dashboard.view';

    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingBusinessDate $dates, private readonly AccountingDashboardContract $contract, private readonly AccountingProfitLossWorkspace $profit, private readonly AccountingAgingReader $aging, private readonly AccountingCashBookReader $books, private readonly AccountingBankReconciliationService $banks, private readonly AccountingSourceReconciliation $sources) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function page(User $actor, array $input): array
    {
        if (! $actor->can(self::PERMISSION)) {
            throw new AuthorizationException;
        }
        $data = Validator::make($input, ['company_id' => ['nullable', 'integer', 'min:1'], 'from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'], 'to' => ['nullable', 'date_format:Y-m-d'], 'account_id' => ['prohibited'], 'branch_id' => ['prohibited'], 'department_id' => ['prohibited'], 'party_id' => ['prohibited']])->validate();
        $query = Company::query();
        if (! $actor->hasRole('super-admin')) {
            $query->whereIn('id', $actor->employee()->select('company_id'));
        }
        $companies = [];
        foreach ($query->orderBy('code')->get(['id', 'code', 'name', 'status']) as $row) {
            $companies[] = ['id' => (int) $row->getKey(), 'code' => (string) $row->getAttribute('code'), 'name' => (string) $row->getAttribute('name'), 'status' => (string) $row->getAttribute('status')];
        }
        $id = isset($data['company_id']) ? (int) $data['company_id'] : null;
        if ($id === null && ! $actor->hasRole('super-admin') && count($companies) === 1) {
            $id = $companies[0]['id'];
        }
        $owner = null;
        $today = null;
        $dashboard = null;
        $filters = ['from' => (string) ($data['from'] ?? ''), 'to' => (string) ($data['to'] ?? '')];
        if ($id !== null) {
            $this->access->authorize($actor, $id, self::PERMISSION);
            $owner = Company::query()->findOrFail($id);
            $today = $this->dates->today($owner);
            if ($filters['from'] === '') {
                $filters['from'] = substr($today, 0, 7).'-01';
            } if ($filters['to'] === '') {
                $filters['to'] = $today;
            }
            Validator::make($filters, ['from' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.$today]])->validate();
            $dashboard = DB::transaction(function () use ($actor, $id, $filters): array {
                $company = Company::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                $this->access->authorize($actor, $id, self::PERMISSION);

                return $this->read($actor, $company, $filters);
            });
        }

        return ['companies' => $companies, 'company' => $owner === null ? null : ['id' => (int) $owner->getKey(), 'code' => (string) $owner->getAttribute('code'), 'name' => (string) $owner->getAttribute('name')], 'today' => $today, 'filters' => $filters, 'dashboard' => $dashboard];
    }

    /** @param array{from: string, to: string} $filters @return array<string, mixed> */
    private function read(User $actor, Company $company, array $filters): array
    {
        $id = (int) $company->getKey();
        $cards = [];
        $links = [];
        $bankRows = [];
        $sourceScan = null;
        $period = ['company_id' => $id, ...$filters];
        $cutoff = ['company_id' => $id, 'as_of' => $filters['to']];
        $url = fn (string $path, array $q): string => '/erp/accounting/'.$path.'?'.http_build_query($q);
        $run = function (string $key, string $label, string $href, callable $action) use (&$cards): void {
            try {
                $cards = [...$cards, ...$action()];
            } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
                $cards[] = $this->contract->card($key, $label, null, 'review', 'review', $href, 'Evidence or report scope needs review. Open the source report; no zero has been substituted.');
            }
        };
        if ($actor->can('erp.profit-loss.view')) {
            $href = $url('profit-loss', $period);
            $run('profit_review', 'Profit and Loss', $href, fn (): array => $this->contract->profit($this->profit->page($actor, $period)['report'], $href));
        }
        foreach (['ar', 'ap'] as $kind) {
            if (! $actor->can('erp.'.$kind.'-aging.view')) {
                continue;
            }
            $href = $url($kind.'-aging', $cutoff);
            $run($kind.'_review', strtoupper($kind).' aging', $href, fn (): array => $this->contract->aging($this->aging->report($actor, $id, $kind, ['as_of' => $filters['to']]), $kind, $href));
        }
        if ($actor->can(AccountingCashBookReader::PERMISSION)) {
            $href = $url('cashbook', $period);
            $run('cash_review', 'Cash and bank balances', $href, function () use ($actor, $id, $filters, $url): array {
                $bindings = AccountingCashAccount::query()->where('company_id', $id)->orderBy('id')->limit(51)->get();
                if ($bindings->count() > 50) {
                    throw ValidationException::withMessages(['dashboard' => 'Review cash books individually; dashboard is limited to 50 bindings.']);
                }
                $out = [];
                foreach ($bindings as $binding) {
                    $book = $this->books->book($actor, $id, (int) $binding->getKey(), $filters);
                    $b = $book['binding'];
                    $out[] = $this->contract->card('cash_'.$b['id'], $b['name'], $book['posted_totals_complete'] ? $book['posted_gl']['closing'] : null, 'cutoff', $book['complete'] && $book['posted_totals_complete'] ? 'supported_scope' : 'review', $url($b['kind'] === 'bank' ? 'bankbook' : 'cashbook', ['company_id' => $id, 'binding_id' => $b['id'], ...$filters]), 'Individual '.$b['kind'].' binding. Posted GL closing when all posted lines are available; otherwise unknown. Review native coverage in its book.');
                }

                return $out;
            });
        }
        if ($actor->can('erp.journal-entries.view')) {
            foreach (['draft', 'submitted', 'approved'] as $state) {
                $count = DB::table('journal_entries')->where('company_id', $id)->whereIn('entry_kind', ['manual', 'reversal'])->where('status', $state)->whereDate('accounting_date', '<=', $filters['to'])->count();
                $cards[] = $this->contract->card('journals_'.$state, ucfirst($state).' journals', (string) $count, 'current', 'current', $url('journals', ['company_id' => $id, 'status' => $state, 'to' => $filters['to']]), 'Current register state for accounting dates through '.$filters['to'].'. Manual/reversal register with the same state and date cutoff; this is not historical workflow reconstruction.', 'count');
            }
        }
        if (AccountingBankingWorkspace::mayRead($actor, 'reconciliations')) {
            // One latest statement cutoff per bank through the selected date. No cross-cutoff summation.
            $roots = DB::table('accounting_bank_reconciliations as r')->join('accounting_bank_statements as s', function ($j): void {
                $j->on('s.id', '=', 'r.statement_id')->on('s.company_id', '=', 'r.company_id');
            })->where('r.company_id', $id)->whereDate('s.ends_on', '<=', $filters['to'])->orderByDesc('s.ends_on')->orderByDesc('r.id')->limit(1001)->get(['r.id', 'r.cash_account_id', 's.ends_on']);
            if ($roots->count() > 1000) {
                $bankRows[] = ['id' => null, 'bank_id' => null, 'cutoff' => $filters['to'], 'difference' => null, 'status' => 'scope_limit', 'url' => $url('bank-reconciliations', ['company_id' => $id])];
                $roots = collect();
            }
            $seen = [];
            foreach ($roots as $root) {
                $bank = (int) $root->cash_account_id;
                if (isset($seen[$bank])) {
                    continue;
                } $seen[$bank] = true;
                if (count($seen) > 20) {
                    $bankRows[] = ['id' => null, 'bank_id' => null, 'cutoff' => $filters['to'], 'difference' => null, 'status' => 'scope_limit', 'url' => $url('bank-reconciliations', ['company_id' => $id])];
                    break;
                }
                $row = ['id' => (int) $root->id, 'bank_id' => $bank, 'cutoff' => substr((string) $root->ends_on, 0, 10), 'difference' => null, 'status' => 'review', 'url' => '/erp/accounting/bank-reconciliations/'.(int) $root->id];
                try {
                    $report = $this->banks->review($actor, (int) $root->id);
                    if ($report['currency_code'] !== $company->getAttribute('currency_code')) {
                        throw ValidationException::withMessages(['dashboard' => 'Bank projection currency differs from company currency.']);
                    } $row['difference'] = $report['bridge']['statement_minus_book'];
                    $row['status'] = 'working_projection';
                } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) { /* Retain an unknown difference. */
                }
                $bankRows[] = $row;
            }
        }
        if ($actor->can('erp.accounting-reports.view')) {
            try {
                $scan = $this->sources->page($actor, $id, ['limit' => 50]);
                $counts = $scan['page_counts'];
                $verified = (int) ($counts['verified_live'] ?? 0) + (int) ($counts['verified_opening_ar'] ?? 0);
                $sourceScan = ['total_candidates' => $scan['total_candidates'], 'inspected' => count($scan['rows']), 'needs_review' => count($scan['rows']) - $verified, 'has_more' => $scan['has_more'], 'counts' => $counts, 'status' => 'current_scan', 'url' => $url('source-reconciliation', ['company_id' => $id])];
            } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
                $sourceScan = ['status' => 'review', 'url' => $url('source-reconciliation', ['company_id' => $id])];
            }
        }
        foreach (['balance-sheet' => 'Balance Sheet', 'cash-flow' => 'Cash Flow', 'tax-reconciliation' => 'Tax Reconciliation', 'general-ledger' => 'General Ledger', 'trial-balance' => 'Trial Balance', 'profit-loss' => 'Profit and Loss', 'ar-aging' => 'AR Aging', 'ap-aging' => 'AP Aging'] as $path => $label) {
            if (! $actor->can('erp.'.$path.'.view')) {
                continue;
            }
            $links[] = ['label' => $label, 'url' => $url($path, in_array($path, ['balance-sheet', 'ar-aging', 'ap-aging'], true) ? $cutoff : $period)];
        }

        return ['currency' => (string) $company->getAttribute('currency_code'), 'cards' => $cards, 'bank_rows' => $bankRows, 'source_scan' => $sourceScan, 'links' => $links, 'bank_visible' => AccountingBankingWorkspace::mayRead($actor, 'reconciliations'), 'cash_visible' => $actor->can(AccountingCashBookReader::PERMISSION), 'coverage' => 'Each metric retains its source report coverage. This dashboard does not certify native completeness or period close readiness.'];
    }
}

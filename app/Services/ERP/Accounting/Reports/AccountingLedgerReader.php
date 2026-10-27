<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reports;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingOpeningWorkspace;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingJournalEvidence;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\AccountingOpeningEvidence;
use App\Services\ERP\Accounting\Source\AccountingAutomaticEvidence;
use App\Services\ERP\Accounting\YearEnd\AccountingYearEndJournalEvidence;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Read-only posted-GL projection. Native manifest reconciliation remains a separate coverage review. */
final class AccountingLedgerReader
{
    public const LEDGER_PERMISSION = 'erp.general-ledger.view';

    public const TRIAL_PERMISSION = 'erp.trial-balance.view';

    public function __construct(
        private readonly PurchaseCompanyAccess $access,
        private readonly AccountingBusinessDate $dates,
        private readonly AccountingLedgerContract $contract,
        private readonly AccountingJournalEvidence $manual,
        private readonly AccountingOpeningEvidence $opening,
        private readonly AccountingAutomaticEvidence $automatic,
        private readonly AccountingCanonicalPayload $canonical,
        private readonly AccountingMappingService $mappings,
    ) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function ledger(User $actor, int $companyId, int $accountId, array $input): array
    {
        return $this->ledgerProjection($actor, $companyId, $accountId, $input, false);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function ledgerExport(User $actor, int $companyId, int $accountId, array $input): array
    {
        $this->access->authorize($actor, $companyId, 'erp.general-ledger.export');

        return $this->ledgerProjection($actor, $companyId, $accountId, $input, true);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function ledgerProjection(User $actor, int $companyId, int $accountId, array $input, bool $allRows): array
    {
        $this->access->authorize($actor, $companyId, self::LEDGER_PERMISSION);

        return DB::transaction(function () use ($actor, $companyId, $accountId, $input, $allRows): array {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $companyId, self::LEDGER_PERMISSION);
            if ($allRows) {
                $this->access->authorize($actor, $companyId, 'erp.general-ledger.export');
            }
            $account = Account::query()->where('company_id', $companyId)->findOrFail($accountId);
            $filters = $this->filters($company, $input);
            $lines = array_values(array_filter($this->lines($company, $filters['to']), static fn (array $line): bool => $line['account_id'] === $accountId));

            return [...$this->context($company, $filters), 'account' => $this->account($account), ...$this->contract->ledger($lines, $filters['from'], $filters['to'], $filters['page'], $filters['per_page'], $allRows)];
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function trial(User $actor, int $companyId, array $input): array
    {
        return $this->trialProjection($actor, $companyId, $input, self::TRIAL_PERMISSION);
    }

    /** Restricted statement source; Profit and Loss authority does not imply Trial Balance authority.
     * @param  array<string, mixed>  $input  @return array<string, mixed>
     */
    public function profitSource(User $actor, int $companyId, array $input): array
    {
        return $this->trialProjection($actor, $companyId, $input, 'erp.profit-loss.view', false, null, false, true);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function balanceSource(User $actor, int $companyId, array $input): array
    {
        return $this->trialProjection($actor, $companyId, $input, 'erp.balance-sheet.view');
    }

    /** Complete verified period input for the separately authorized indirect cash-flow projection.
     * @param  array<string, mixed>  $input  @return array<string, mixed>
     */
    public function cashFlowSource(User $actor, int $companyId, array $input): array
    {
        return $this->trialProjection($actor, $companyId, $input, 'erp.cash-flow.view', true);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function agingSource(User $actor, int $companyId, array $input, string $kind): array
    {
        if (! in_array($kind, ['ar', 'ap'], true)) {
            $this->fail('Choose AR or AP aging.');
        }

        return $this->trialProjection($actor, $companyId, $input, 'erp.'.$kind.'-aging.view', false, $kind);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function taxSource(User $actor, int $companyId, array $input): array
    {
        return $this->trialProjection($actor, $companyId, $input, 'erp.tax-reconciliation.view', false, null, true);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function trialProjection(User $actor, int $companyId, array $input, string $permission, bool $cashFlow = false, ?string $agingKind = null, bool $tax = false, bool $performance = false): array
    {
        $this->access->authorize($actor, $companyId, $permission);

        return DB::transaction(function () use ($actor, $companyId, $input, $permission, $cashFlow, $agingKind, $tax, $performance): array {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $companyId, $permission);
            $filters = $this->filters($company, $input);
            $groups = [];
            foreach (AccountGroup::query()->where('company_id', $companyId)->get() as $group) {
                $groups[(int) $group->getKey()] = (string) $group->getAttribute('category');
            }
            $accounts = [];
            foreach (Account::query()->where('company_id', $companyId)->orderBy('code')->orderBy('id')->limit(10001)->get() as $account) {
                $accounts[] = [...$this->account($account), 'category' => $groups[(int) $account->getAttribute('account_group_id')] ?? ''];
            }
            if (count($accounts) > 10000) {
                $this->fail('The account count exceeds this interactive reader limit; a reviewed batch reader is required.');
            }
            $lines = $this->lines($company, $filters['to'], $agingKind !== null || $tax);
            $openingMovements = [];
            foreach ($lines as $line) {
                if ($line['entry_kind'] !== 'opening' || $line['date'] < $filters['from']) {
                    continue;
                }
                $id = $line['account_id'];
                $previous = $openingMovements[$id] ?? ['debit' => '0.00', 'credit' => '0.00'];
                $openingMovements[$id] = ['debit' => bcadd($previous['debit'], $line['debit'], 2), 'credit' => bcadd($previous['credit'], $line['credit'], 2)];
            }
            $extra = [];
            if ($cashFlow) {
                $bindings = [];
                foreach (AccountingCashAccount::query()->where('company_id', $companyId)->orderBy('id')->limit(10001)->get() as $binding) {
                    $bindings[] = ['id' => (int) $binding->getKey(), 'account_id' => (int) $binding->getAttribute('account_id'), 'role' => (string) $binding->getAttribute('role'), 'kind' => (string) $binding->getAttribute('kind'), 'currency' => (string) $binding->getAttribute('currency_code'), 'status' => (string) $binding->getAttribute('status')];
                }
                if (count($bindings) > 10000) {
                    $this->fail('The cash binding count exceeds 10,000; no partial cash-flow report is returned.');
                }
                $extra = ['cash_flow_lines' => array_values(array_filter($lines, static fn (array $line): bool => $line['date'] >= $filters['from'] && ! in_array($line['entry_kind'], ['closing', 'closing_reversal'], true))), 'cash_bindings' => $bindings];
            }
            if ($agingKind !== null) {
                $wanted = $agingKind === 'ar' ? ['trade_ar', 'unapplied_customer_funds'] : ['trade_ap', 'supplier_advances'];
                $roleAccounts = [];
                foreach ($accounts as $account) {
                    if (in_array($account['control_role'], $wanted, true)) {
                        $roleAccounts[$account['id']][$account['control_role']] = true;
                    }
                }
                $maps = DB::table('accounting_account_mappings as m')->join('accounting_mapping_versions as v', function ($join): void {
                    $join->on('v.id', '=', 'm.mapping_version_id')->on('v.company_id', '=', 'm.company_id');
                })->where('m.company_id', $companyId)->whereIn('v.state', ['published', 'retired'])->whereIn('m.role', $wanted)->limit(100001)->get(['m.account_id', 'm.role']);
                if ($maps->count() > 100000) {
                    $this->fail('Control mapping history exceeds the interactive limit.');
                }
                foreach ($maps as $map) {
                    $roleAccounts[(int) $map->account_id][(string) $map->role] = true;
                }
                $extra = [...$extra, 'aging_lines' => $lines, 'control_role_accounts' => $roleAccounts];
            }
            if ($tax) {
                $roleAccounts = [];
                $maps = DB::table('accounting_account_mappings as m')->join('accounting_mapping_versions as v', function ($join): void {
                    $join->on('v.id', '=', 'm.mapping_version_id')->on('v.company_id', '=', 'm.company_id');
                })->where('m.company_id', $companyId)->whereIn('v.state', ['published', 'retired'])->whereIn('m.role', ['input_tax', 'output_tax'])->limit(100001)->get(['m.account_id', 'm.role']);
                if ($maps->count() > 100000) {
                    $this->fail('Tax mapping history exceeds the interactive limit.');
                }
                foreach ($maps as $map) {
                    $roleAccounts[(int) $map->account_id][(string) $map->role] = true;
                }
                $extra = [...$extra, 'tax_lines' => $lines, 'tax_role_accounts' => $roleAccounts];
            }
            $trial = $this->contract->trial($accounts, $lines, $filters['from'], $filters['to']);
            if ($performance || $cashFlow) {
                $excluded = [];
                foreach ($lines as $line) {
                    if ($line['date'] < $filters['from'] || ! in_array($line['entry_kind'], ['closing', 'closing_reversal'], true)) {
                        continue;
                    }
                    $id = $line['account_id'];
                    $previous = $excluded[$id] ?? ['debit' => '0.00', 'credit' => '0.00'];
                    $excluded[$id] = ['debit' => bcadd($previous['debit'], $line['debit'], 2), 'credit' => bcadd($previous['credit'], $line['credit'], 2)];
                }
                foreach ($trial['accounts'] as &$account) {
                    if (! in_array($account['category'], ['revenue', 'expense'], true)) {
                        continue;
                    }
                    $movement = $excluded[$account['id']] ?? ['debit' => '0.00', 'credit' => '0.00'];
                    $account['period_debit'] = bcsub($account['period_debit'], $movement['debit'], 2);
                    $account['period_credit'] = bcsub($account['period_credit'], $movement['credit'], 2);
                } unset($account);
            }

            return [...$this->context($company, $filters), ...$trial, 'period_opening_movements' => $openingMovements, ...$extra];
        });
    }

    /** @return list<array<string, mixed>> */
    private function lines(Company $company, string $to, bool $aging = false): array
    {
        $companyId = (int) $company->getKey();
        $result = [];
        $journals = JournalEntry::query()->where('company_id', $companyId)
            ->where(function ($query): void {
                $query->where('status', 'posted')->orWhereNotNull('posted_at')->orWhereNotNull('posted_by');
            })
            ->where(function ($query) use ($to): void {
                $query->whereDate('accounting_date', '<=', $to)->orWhereNull('accounting_date');
            })->orderBy('accounting_date')->orderBy('id')->limit(10001)->get();
        if ($journals->count() > 10000) {
            $this->fail('The history exceeds 10,000 journals; no truncated balance is returned. A reviewed batch reader is required.');
        }
        $accountIds = Account::query()->where('company_id', $companyId)->limit(10001)->pluck('id')->all();
        if (count($accountIds) > 10000) {
            $this->fail('The account count exceeds 10,000; no partial balance is returned.');
        }
        foreach ($journals as $journal) {
            if (! $journal->getAttribute('accounting_date') instanceof \DateTimeInterface || $journal->getAttribute('status') !== 'posted' || $journal->getAttribute('posted_at') === null || $journal->getAttribute('posted_by') === null || $journal->getAttribute('currency_code') !== $company->getAttribute('currency_code')) {
                $this->fail('Posted journal markers or functional currency require review.');
            }
            $kind = (string) $journal->getAttribute('entry_kind');
            if (in_array($kind, ['manual', 'reversal'], true)) {
                $checked = $this->manual->verify($journal);
                $lines = $checked['lines'];
            } elseif (in_array($kind, ['closing', 'closing_reversal'], true)) {
                $lines = app(AccountingYearEndJournalEvidence::class)->verify($journal);
            } elseif ($kind === 'opening') {
                $work = AccountingOpeningWorkspace::query()->where('company_id', $companyId)->where('journal_entry_id', $journal->getKey())->sole();
                $cutover = AccountingCutover::query()->where('company_id', $companyId)->findOrFail($work->getAttribute('cutover_id'));
                $checked = $this->opening->verify($cutover, $work, $journal);
                $lines = $checked['lines'];
            } elseif ($kind === 'automatic') {
                $effect = AccountingSourceEffect::query()->where('company_id', $companyId)->where('journal_entry_id', $journal->getKey())->sole();
                $snapshot = $effect->getAttribute('source_snapshot');
                if (! is_array($snapshot) || ! is_array($snapshot['evidence'] ?? null)) {
                    $this->fail('Automatic source evidence is missing.');
                }
                $body = $snapshot['evidence'];
                $sourceHash = (string) $effect->getAttribute('source_payload_hash');
                if (($snapshot['evidence_hash'] ?? null) !== $this->canonical->hash($body) || ! is_array($body['source'] ?? null) || $this->canonical->hash($body['source']) !== $sourceHash || ! is_string($body['mapping_hash'] ?? null) || ($body['journal_hash'] ?? null) !== $journal->getAttribute('payload_hash') || $effect->getAttribute('outcome') !== 'posted' || $effect->getAttribute('no_journal_reason') !== null || (int) $effect->getAttribute('mapping_version_id') !== (int) $journal->getAttribute('mapping_version_id') || $effect->getAttribute('contract_version') !== $journal->getAttribute('contract_version') || $effect->getAttribute('accounting_date')->format('Y-m-d') !== $journal->getAttribute('accounting_date')->format('Y-m-d')) {
                    $this->fail('Automatic journal and source membership evidence disagree.');
                }
                $source = $body['source'];
                $identity = ['company_id' => $companyId, 'source_kind' => $effect->getAttribute('source_kind'), 'source_id' => (int) $effect->getAttribute('source_id'), 'event_kind' => $effect->getAttribute('event_kind'), 'event_token' => $effect->getAttribute('event_token'), 'currency' => $company->getAttribute('currency_code'), 'accounting_date' => $journal->getAttribute('accounting_date')->format('Y-m-d')];
                foreach ($identity as $key => $value) {
                    if (($source[$key] ?? null) !== $value) {
                        $this->fail('Recorded source identity differs from its immutable envelope.');
                    }
                }
                if (($body['effect_kind'] ?? null) !== $effect->getAttribute('effect_kind') || ($body['outcome'] ?? null) !== 'posted' || ($body['no_journal_reason'] ?? null) !== null) {
                    $this->fail('Recorded source effect outcome differs.');
                }
                $this->mappings->verifyRecordedProfile($companyId, (int) $journal->getAttribute('mapping_version_id'), (string) $company->getAttribute('currency_code'), (string) $journal->getAttribute('contract_version'), $body['mapping_hash']);
                $this->automatic->verify($journal, $sourceHash, (string) $effect->getAttribute('effect_kind'), $body['mapping_hash']);
                $lines = $this->automatic->lines($journal);
            } else {
                $this->fail('An unsupported journal kind cannot be omitted from financial balances.');
            }
            foreach ($lines as $line) {
                $accountId = (int) $line['account_id'];
                if (! in_array($accountId, $accountIds)) {
                    $this->fail('A posted journal account is outside the company scope.');
                }
                $result[] = ['account_id' => $accountId, 'journal_id' => (int) $journal->getKey(), 'number' => (string) $journal->getAttribute('number'), 'line_number' => (int) $line['line_number'], 'date' => $journal->getAttribute('accounting_date')->format('Y-m-d'), 'entry_kind' => $kind, 'debit' => (string) $line['debit'], 'credit' => (string) $line['credit'], ...($aging ? ['party_type' => $line['party_type'] ?? null, 'party_id' => isset($line['party_id']) ? (int) $line['party_id'] : null, 'source_kind' => $line['source_line_type'] ?? null, 'source_id' => isset($line['source_line_id']) ? (int) $line['source_line_id'] : null] : [])];
                if (count($result) > 100000) {
                    $this->fail('The history exceeds 100,000 lines; no truncated balance is returned.');
                }
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $input @return array{from: string, to: string, page: int, per_page: int} */
    private function filters(Company $company, array $input): array
    {
        $data = Validator::make($input, ['from' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.$this->dates->today($company)], 'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']])->validate();

        return ['from' => (string) $data['from'], 'to' => (string) $data['to'], 'page' => (int) ($data['page'] ?? 1), 'per_page' => (int) ($data['per_page'] ?? 25)];
    }

    /** @return array<string, mixed> */
    private function account(Account $account): array
    {
        return ['id' => (int) $account->getKey(), 'code' => (string) $account->getAttribute('code'), 'name' => (string) $account->getAttribute('name'), 'normal_side' => (string) $account->getAttribute('normal_side'), 'status' => (string) $account->getAttribute('status'), 'is_postable' => (bool) $account->getAttribute('is_postable'), 'statement_role' => (string) $account->getAttribute('statement_role'), 'cash_flow_role' => $account->getAttribute('cash_flow_role'), 'control_role' => $account->getAttribute('control_role')];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function context(Company $company, array $filters): array
    {
        return ['company' => ['id' => (int) $company->getKey(), 'code' => (string) $company->getAttribute('code'), 'name' => (string) $company->getAttribute('name')], 'currency' => (string) $company->getAttribute('currency_code'), 'filters' => $filters, 'basis' => 'posted_journal_accounting_date', 'journal_evidence_checked' => true, 'native_source_coverage_certified' => false, 'coverage_note' => 'Journal evidence is checked. Native operation manifests, source completeness and cutover coverage require their separate reconciliation review.'];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['financial_reports' => $message]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingAccountMapping;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingMappingVersion;
use App\Models\Accounting\AccountingSetting;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Read-only audit of the installed registry. No balances, identifiers, setup writes or source repair. */
final class AccountingCashBankReadinessService
{
    public const PERMISSION = 'erp.cash-accounts.view';

    public function __construct(
        private readonly PurchaseCompanyAccess $access,
        private readonly AccountingBusinessDate $dates,
        private readonly AccountingMappingService $mappings,
        private readonly AccountingMappingCatalog $catalog,
    ) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function page(User $actor, ?int $companyId, array $filters = []): array
    {
        abort_unless($actor->exists && $actor->can(self::PERMISSION), 403);
        if (array_diff(array_keys($filters), ['q', 'kind', 'status', 'page', 'per_page']) !== []) {
            throw ValidationException::withMessages(['filters' => 'Unsupported readiness filter.']);
        }
        $filters = Validator::make($filters, [
            'q' => ['nullable', 'string', 'max:100'], 'kind' => ['nullable', 'in:cash,bank'],
            'status' => ['nullable', 'in:active,inactive'], 'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ])->validate();
        $query = Company::query();
        if (! $actor->hasRole('super-admin')) {
            $owned = $actor->employee()->value('company_id');
            abort_if($owned === null, 403);
            $query->whereKey($owned);
        }
        $companies = $query->orderBy('name')->orderBy('id')->get(['id', 'code', 'name', 'currency_code', 'status']);
        if ($companyId === null && $companies->count() === 1) {
            $companyId = (int) $companies->firstOrFail()->getKey();
        }
        $context = [
            'companies' => $companies->map(static fn (Company $row): array => $row->only(['id', 'code', 'name', 'currency_code', 'status']))->all(),
            'company' => null,
            'report' => null,
            'canOpenSetup' => $actor->can('erp.accounting-mappings.view'),
            'filters' => $filters,
        ];
        if ($companyId === null) {
            return $context;
        }
        $this->access->authorize($actor, $companyId, self::PERMISSION);

        return DB::transaction(function () use ($actor, $companyId, $context, $filters): array {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $companyId, self::PERMISSION);

            return [...$context, 'company' => $company->only(['id', 'code', 'name', 'currency_code', 'status']), 'report' => $this->audit($company, $filters)];
        }, 3);
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function audit(Company $company, array $filters): array
    {
        $id = (int) $company->getKey();
        $issues = [];
        $today = null;
        try {
            $today = $this->dates->today($company);
        } catch (ValidationException) {
            $issues[] = 'company_timezone_review';
        }
        $setting = AccountingSetting::query()->where('company_id', $id)->first();
        if ($company->getAttribute('status') !== 'active') {
            $issues[] = 'company_inactive';
        }
        if ($setting === null) {
            $issues[] = 'accounting_setup_missing';
        } else {
            if ($setting->getAttribute('state') !== 'enabled') {
                $issues[] = 'accounting_not_enabled';
            }
            if ($setting->getAttribute('cutover_locked_at') !== null) {
                $issues[] = 'cutover_locked';
            }
            $activation = $setting->getAttribute('activation_date');
            if ($activation === null || $today === null || $activation->format('Y-m-d') > $today || $setting->getAttribute('activated_at') === null || $setting->getAttribute('activated_by') === null) {
                $issues[] = 'activation_requires_review';
            }
        }

        $version = $today === null ? null : AccountingMappingVersion::query()
            ->where('company_id', $id)->where('state', 'published')->whereDate('effective_from', '<=', $today)
            ->orderByDesc('effective_from')->orderByDesc('version')->first();
        $current = [];
        $profileVerified = false;
        if ($version === null) {
            $issues[] = 'effective_posting_profile_missing';
        } else {
            foreach (AccountingAccountMapping::query()->where('company_id', $id)->where('mapping_version_id', $version->getKey())->get() as $mapping) {
                $role = (string) $mapping->getAttribute('role');
                if ($this->catalog->cashRole($role)) {
                    $current[$role] = (int) $mapping->getAttribute('account_id');
                }
            }
            try {
                // This verifier only reads/locks; it never initializes a setting, profile or binding.
                $this->mappings->resolveForSource($company, (string) $today, array_keys($current));
                $profileVerified = $current !== [];
                if (! $profileVerified) {
                    $issues[] = 'cash_roles_missing';
                }
            } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
                $issues[] = 'effective_posting_profile_review';
            }
        }

        $accounts = Account::query()->where('company_id', $id)->get()->keyBy('id');
        $parents = [];
        $cashGlIds = [];
        foreach ($accounts as $account) {
            if ($account->getAttribute('cash_flow_role') === 'cash') {
                $cashGlIds[] = (int) $account->getKey();
            }
            if ($account->getAttribute('parent_id') !== null) {
                $parents[(int) $account->getAttribute('parent_id')] = true;
            }
        }
        $receipts = $this->counts('customer_receipts', $id);
        $payments = $this->counts('supplier_payments', $id);
        $journalCounts = DB::table('journal_entry_lines as l')
            ->join('journal_entries as j', function ($join): void {
                $join->on('j.id', '=', 'l.journal_entry_id')->on('j.company_id', '=', 'l.company_id');
            })->where('j.company_id', $id)->where('j.status', 'posted')->whereNotNull('j.posted_at')
            ->whereNotNull('l.cash_account_id')->select('l.cash_account_id')->selectRaw('COUNT(*) as row_count')
            ->groupBy('l.cash_account_id')->get()->keyBy('cash_account_id');

        $rows = [];
        $boundRoles = [];
        $summary = ['total_bindings' => 0, 'cash_bindings' => 0, 'bank_bindings' => 0, 'inactive_bindings' => 0, 'usable_bindings' => 0, 'review_bindings' => 0];
        foreach (AccountingCashAccount::query()->where('company_id', $id)->orderBy('role')->orderBy('id')->get() as $binding) {
            $bindingId = (int) $binding->getKey();
            $role = (string) $binding->getAttribute('role');
            $accountId = (int) $binding->getAttribute('account_id');
            $account = $accounts->get($accountId);
            $problems = [];
            $boundRoles[$role] = true;
            if (! $this->catalog->cashRole($role)) {
                $problems[] = 'invalid_binding_role';
            }
            $kind = str_starts_with($role, 'cash:') ? 'cash' : 'bank';
            if ($binding->getAttribute('kind') !== $kind) {
                $problems[] = 'binding_kind_mismatch';
            }
            if ($binding->getAttribute('currency_code') !== $company->getAttribute('currency_code')) {
                $problems[] = 'binding_currency_mismatch';
            }
            if (! in_array($binding->getAttribute('status'), ['active', 'inactive'], true)) {
                $problems[] = 'invalid_binding_status';
            }
            if ($account === null) {
                $problems[] = 'owned_gl_account_missing';
            } else {
                if ($account->getAttribute('status') !== 'active' || ! $account->getAttribute('is_postable') || isset($parents[$accountId])) {
                    $problems[] = 'gl_not_active_postable_leaf';
                }
                if ($account->getAttribute('cash_flow_role') !== 'cash' || $account->getAttribute('normal_side') !== 'debit') {
                    $problems[] = 'gl_cash_classification_review';
                }
            }
            if (! isset($current[$role])) {
                $problems[] = 'role_not_in_effective_profile';
            } elseif ($current[$role] !== $accountId) {
                $problems[] = 'mapping_transition_required';
            }
            $ready = $problems === [] && $profileVerified && $binding->getAttribute('status') === 'active';
            $state = $problems !== [] || ! $profileVerified ? 'review' : ($binding->getAttribute('status') === 'inactive' ? 'inactive' : 'ready_for_source_checks');
            $summary['total_bindings']++;
            if ($binding->getAttribute('kind') === 'cash') {
                $summary['cash_bindings']++;
            }
            if ($binding->getAttribute('kind') === 'bank') {
                $summary['bank_bindings']++;
            }
            if ($binding->getAttribute('status') === 'inactive') {
                $summary['inactive_bindings']++;
            }
            if ($ready) {
                $summary['usable_bindings']++;
            }
            if ($state === 'review') {
                $summary['review_bindings']++;
            }
            $rows[] = [
                'id' => $bindingId, ...$binding->only(['name', 'role', 'kind', 'currency_code', 'status']),
                'revision' => (int) $binding->getAttribute('revision'), 'account_id' => $accountId,
                'gl' => $account === null ? null : $account->only(['id', 'code', 'name', 'status']),
                'setup_status' => $state, 'issues' => $problems,
                'usage' => ['receipt_documents' => $receipts[$bindingId] ?? 0, 'supplier_payment_documents' => $payments[$bindingId] ?? 0, 'posted_journal_lines' => isset($journalCounts[$bindingId]) ? (int) $journalCounts[$bindingId]->row_count : 0],
            ];
        }
        $missing = [];
        foreach ($current as $role => $accountId) {
            if (! isset($boundRoles[$role])) {
                $missing[] = ['role' => $role, 'account_id' => $accountId];
            }
        }
        if ($missing !== []) {
            $issues[] = 'roles_without_initialized_binding';
        }
        if ($summary['review_bindings'] > 0) {
            $issues[] = 'binding_setup_review';
        }
        if ($summary['usable_bindings'] === 0) {
            $issues[] = 'no_usable_binding';
        }

        $cashGlIds = array_values(array_unique([...$cashGlIds, ...array_values($current), ...array_column($rows, 'account_id')]));
        $unboundLines = DB::table('journal_entry_lines as l')->join('journal_entries as j', function ($join): void {
            $join->on('j.id', '=', 'l.journal_entry_id')->on('j.company_id', '=', 'l.company_id');
        })->where('j.company_id', $id)->where('j.status', 'posted')->whereNotNull('j.posted_at')->whereIn('l.account_id', $cashGlIds)->whereNull('l.cash_account_id')->count();
        if ($unboundLines > 0) {
            $issues[] = 'unbound_posted_cash_lines';
        }
        $filtered = array_values(array_filter($rows, static function (array $row) use ($filters): bool {
            if (! empty($filters['kind']) && $row['kind'] !== $filters['kind']) {
                return false;
            }
            if (! empty($filters['status']) && $row['status'] !== $filters['status']) {
                return false;
            }
            $search = mb_strtolower((string) ($filters['q'] ?? ''));

            return $search === '' || str_contains(mb_strtolower($row['name'].' '.$row['role'].' '.($row['gl']['code'] ?? '')), $search);
        }));
        $page = (int) ($filters['page'] ?? 1);
        $size = (int) ($filters['per_page'] ?? 25);

        return [
            'as_of' => $today, 'currency' => (string) $company->getAttribute('currency_code'),
            'status' => $issues === [] ? 'ready_for_source_checks' : 'review_required',
            'summary' => $summary, 'issues' => array_values(array_unique($issues)),
            'profile' => $version === null ? null : ['id' => (int) $version->getKey(), 'version' => (int) $version->getAttribute('version'), 'effective_from' => $version->getAttribute('effective_from')->format('Y-m-d'), 'verified' => $profileVerified],
            'accounting_state' => $setting === null ? 'not_initialized' : (string) $setting->getAttribute('state'),
            'missing_bindings' => $missing, 'unbound_posted_cash_lines' => $unboundLines,
            'rows' => array_slice($filtered, ($page - 1) * $size, $size),
            'pagination' => ['current_page' => $page, 'per_page' => $size, 'total' => count($filtered), 'last_page' => max(1, (int) ceil(count($filtered) / $size))],
            'bank_identifier_policy' => 'not_collected_in_binding_schema',
            'source_verification' => 'usage_counts_only_not_financial_recognition_or_balances',
        ];
    }

    /** @return array<int, int> */
    private function counts(string $table, int $company): array
    {
        $result = [];
        foreach (DB::table($table)->where('company_id', $company)->whereNotNull('cash_account_id')
            ->select('cash_account_id')->selectRaw('COUNT(*) as row_count')->groupBy('cash_account_id')->get() as $row) {
            $result[(int) $row->cash_account_id] = (int) $row->row_count;
        }

        return $result;
    }
}

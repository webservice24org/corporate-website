<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Statements;

use App\Models\Accounting\AccountingBankReconciliation;
use App\Models\Accounting\AccountingBankStatement;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\Banking\AccountingBankingWorkspace;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AccountingBankStatementWorkspace
{
    public function __construct(private readonly AccountingBankStatementService $service, private readonly PurchaseCompanyAccess $access, private readonly AccountingBusinessDate $dates) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function index(User $actor, ?int $companyId, array $filters): array
    {
        return DB::transaction(function () use ($actor, $companyId, $filters): array {
            $context = $this->context($actor, $companyId);
            $selected = (int) ($context['company']['id'] ?? 0);
            $query = AccountingBankStatement::query()->where('company_id', $selected);
            if (! empty($filters['cash_account_id'])) {
                AccountingCashAccount::query()->where('company_id', $selected)->where('kind', 'bank')->findOrFail((int) $filters['cash_account_id']);
                $query->where('cash_account_id', (int) $filters['cash_account_id']);
            }
            if (! empty($filters['from'])) {
                $query->whereDate('ends_on', '>=', $filters['from']);
            }
            if (! empty($filters['to'])) {
                $query->whereDate('starts_on', '<=', $filters['to']);
            }
            $rows = $query->orderByDesc('ends_on')->orderByDesc('id')->paginate(25)->withQueryString();
            $rows->through(fn (AccountingBankStatement $row): array => $this->summary($row));

            return [...$context, 'statements' => $rows, 'filters' => ['cash_account_id' => isset($filters['cash_account_id']) ? (string) $filters['cash_account_id'] : '', 'from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? '']];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function create(User $actor, ?int $companyId): array
    {
        return DB::transaction(function () use ($actor, $companyId): array {
            $context = $this->context($actor, $companyId);
            abort_unless($context['company'] !== null && ($context['canImport'] || $context['canCreate']), 403);

            return [...$context, 'requestKey' => (string) Str::uuid()];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function detail(User $actor, int $id, int $page = 1, ?int $requestedCompany = null): array
    {
        $found = $this->service->find($actor, $id);
        $companyId = (int) $found->getAttribute('company_id');
        if ($requestedCompany !== null && $requestedCompany !== $companyId) {
            throw new AuthorizationException('Company does not own this statement.');
        }

        return DB::transaction(function () use ($actor, $id, $companyId, $page): array {
            $context = $this->context($actor, $companyId);
            $row = $this->service->find($actor, $id);
            $payload = null;
            $state = 'verified';
            try {
                $payload = $this->service->verifyOwned($companyId, $id);
            } catch (ValidationException|ModelNotFoundException|\LogicException|\JsonException) {
                $state = 'review';
            }
            $all = $payload === null ? [] : $payload['lines'];
            $last = max(1, (int) ceil(count($all) / 50));
            $current = min($page, $last);
            $rows = [];
            foreach (array_slice($all, ($current - 1) * 50, 50) as $line) {
                $rows[] = array_diff_key($line, ['fingerprint' => true, 'transaction_hash' => true]);
            }
            $bankId = (int) $row->getAttribute('cash_account_id');
            $workflowLinks = [];
            if ($state === 'verified' && AccountingBankingWorkspace::mayRead($actor, 'reconciliations')) {
                $existing = AccountingBankReconciliation::query()->where('company_id', $companyId)->where('statement_id', $id)->first();
                if ($existing !== null) {
                    $workflowLinks[] = ['label' => 'Open reconciliation', 'url' => '/erp/accounting/bank-reconciliations/'.$existing->getKey()];
                } elseif ($context['company']['status'] === 'active' && $actor->can('erp.bank-reconciliations.create')) {
                    $workflowLinks[] = ['label' => 'Prepare reconciliation workspace', 'url' => '/erp/accounting/bank-reconciliations/create?'.http_build_query(['company_id' => $companyId, 'statement_id' => $id])];
                }
                if (AccountingBankingWorkspace::mayRead($actor, 'adjustments') && $context['company']['status'] === 'active' && $actor->can('erp.bank-adjustments.create')) {
                    $workflowLinks[] = ['label' => 'Prepare charge or interest', 'url' => '/erp/accounting/bank-adjustments/create?'.http_build_query(['company_id' => $companyId, 'statement_id' => $id])];
                }
            }

            return [...$context, 'statement' => [...$this->summary($row), 'created_by' => (int) $row->getAttribute('created_by'), 'imported_at' => $row->getAttribute('imported_at')->format(DATE_ATOM)],
                'workflowLinks' => $workflowLinks, 'evidenceState' => $state, 'rows' => $rows, 'page' => ['current' => $current, 'last' => $last, 'total' => count($all)],
                'exportUrl' => $context['canExport'] && $state === 'verified' ? '/erp/accounting/bank-statements/'.$id.'/csv' : null,
                'bookUrl' => $actor->can('erp.cash-books.view') ? '/erp/accounting/bankbook?'.http_build_query(['company_id' => $companyId, 'binding_id' => $bankId, 'from' => $row->getAttribute('starts_on')->format('Y-m-d'), 'to' => $row->getAttribute('ends_on')->format('Y-m-d')]) : null];
        }, 3);
    }

    /** Export checks the same company snapshot before returning any private transaction text. @return array<string, mixed> */
    public function export(User $actor, int $id): array
    {
        abort_unless($actor->can('erp.bank-statements.export'), 403);
        $found = $this->service->find($actor, $id);
        $companyId = (int) $found->getAttribute('company_id');

        return DB::transaction(function () use ($actor, $companyId, $id): array {
            $this->context($actor, $companyId);

            return $this->service->verifyOwned($companyId, $id);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function context(User $actor, ?int $companyId): array
    {
        abort_unless($actor->exists && $actor->can('erp.bank-statements.view'), 403);
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
        $company = null;
        if ($companyId !== null) {
            $this->access->authorize($actor, $companyId, 'erp.bank-statements.view');
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $companyId, 'erp.bank-statements.view');
        }
        $banks = $company === null ? [] : AccountingCashAccount::query()->where('company_id', $companyId)->where('kind', 'bank')->orderBy('name')->orderBy('id')->get()->map(static fn (AccountingCashAccount $bank): array => ['id' => (int) $bank->getKey(), 'name' => (string) $bank->getAttribute('name'), 'role' => (string) $bank->getAttribute('role'), 'status' => (string) $bank->getAttribute('status'), 'currency_code' => (string) $bank->getAttribute('currency_code')])->all();
        $writable = $company !== null && $company->getAttribute('status') === 'active';

        return ['companies' => $companies->map(static fn (Company $item): array => $item->only(['id', 'code', 'name', 'status', 'currency_code']))->all(),
            'company' => $company === null ? null : $company->only(['id', 'code', 'name', 'status', 'currency_code']), 'banks' => $banks,
            'actorId' => (int) $actor->getKey(), 'canImport' => $writable && $actor->can('erp.bank-statements.import'), 'canCreate' => $writable && $actor->can('erp.bank-statements.create'),
            'canExport' => $actor->can('erp.bank-statements.export'), 'today' => $company === null ? null : $this->dates->today($company)];
    }

    /** @return array<string, mixed> */
    private function summary(AccountingBankStatement $row): array
    {
        $bank = AccountingCashAccount::query()->where('company_id', $row->getAttribute('company_id'))->find((int) $row->getAttribute('cash_account_id'));

        return ['id' => (int) $row->getKey(), 'company_id' => (int) $row->getAttribute('company_id'), 'cash_account_id' => (int) $row->getAttribute('cash_account_id'), 'bank_name' => $bank?->getAttribute('name'),
            'statement_reference' => (string) $row->getAttribute('statement_reference'), 'currency_code' => (string) $row->getAttribute('currency_code'), 'source_mode' => (string) $row->getAttribute('source_mode'),
            'starts_on' => $row->getAttribute('starts_on')->format('Y-m-d'), 'ends_on' => $row->getAttribute('ends_on')->format('Y-m-d'), 'opening_balance' => (string) $row->getAttribute('opening_balance'), 'closing_balance' => (string) $row->getAttribute('closing_balance'),
            'money_in' => (string) $row->getAttribute('money_in'), 'money_out' => (string) $row->getAttribute('money_out'), 'row_count' => (int) $row->getAttribute('row_count')];
    }
}

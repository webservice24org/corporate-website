<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Expenses;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingExpense;
use App\Models\Accounting\AccountingExpenseCategory;
use App\Models\Accounting\AccountingExpenseFinanceEvent;
use App\Models\Accounting\AccountingExpenseFinancePlan;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AccountingExpenseWorkspace
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingExpenseWorkflow $native, private readonly AccountingExpenseReadService $read, private readonly AccountingExpenseFinanceService $finance, private readonly AccountingExpenseFinanceProof $proof, private readonly AccountingExpenseHistory $history, private readonly AccountingExpenseAttachmentService $files, private readonly AccountingExpenseContract $contract, private readonly AccountingExpenseFinanceContract $money, private readonly AccountingBusinessDate $dates) {}

    /** @return array<string, bool> */
    private function permissions(User $actor): array
    {
        $out = [];
        foreach ([...AccountingExpenseContract::PERMISSIONS, ...AccountingExpenseAttachmentLedger::PERMISSIONS, ...AccountingExpenseFinanceContract::PERMISSIONS] as $permission) {
            $out[$permission] = $actor->can($permission);
        }

return $out;
    }

    /** @return array<string, mixed> */
    private function context(User $actor, ?int $company, string $permission): array
    {
        if (! $actor->can($permission)) {
            throw new AuthorizationException;
        }
        $q = Company::query();
        if (! $actor->hasRole('super-admin')) {
            $q->whereIn('id', $actor->employee()->select('company_id'));
        }
        $companies = [];
        foreach ($q->orderBy('code')->get(['id', 'code', 'name', 'currency_code', 'status']) as $row) {
            $companies[] = ['id' => (int) $row->getKey(), 'code' => (string) $row->getAttribute('code'), 'name' => (string) $row->getAttribute('name'), 'status' => (string) $row->getAttribute('status'), 'currency_code' => (string) $row->getAttribute('currency_code')];
        }
        if ($company === null && ! $actor->hasRole('super-admin') && count($companies) === 1) {
            $company = $companies[0]['id'];
        }
        $owner = null;
        if ($company !== null) {
            $this->access->authorize($actor, $company, $permission);
            $owner = Company::query()->findOrFail($company);
        }

        return ['actor_id' => (int) $actor->getKey(), 'companies' => $companies, 'company' => $owner === null ? null : ['id' => (int) $owner->getKey(), 'code' => (string) $owner->getAttribute('code'), 'name' => (string) $owner->getAttribute('name'), 'currency_code' => (string) $owner->getAttribute('currency_code'), 'status' => (string) $owner->getAttribute('status')], 'today' => $owner === null ? null : $this->dates->today($owner), 'can' => $this->permissions($actor)];
    }

    /** @param array<string, mixed> $input */
    public function index(User $actor, array $input, string $module): Response
    {
        $validated = Validator::make($input, ['company_id' => ['nullable', 'integer', 'min:1'], 'page' => ['nullable', 'integer', 'min:1'], 'status' => ['nullable', 'string', 'max:16']])->validate();
        $permission = match ($module) {
            'categories' => 'erp.expense-categories.view', 'finance' => 'erp.expense-finance.view', default => 'erp.expenses.view'
        };
        if ($module === 'finance' && ! $actor->can('erp.expenses.view')) {
            throw new AuthorizationException;
        }
        $context = $this->context($actor, isset($validated['company_id']) ? (int) $validated['company_id'] : null, $permission);
        $result = null;
        $filters = ['status' => (string) ($validated['status'] ?? '')];
        if ($context['company'] !== null) {
            $company = $context['company']['id'];
            $page = (int) ($validated['page'] ?? 1);
            $result = DB::transaction(function () use ($actor, $module, $company, $page, $filters): array {
                Company::query()->whereKey($company)->lockForUpdate()->firstOrFail();
                if ($module !== 'finance') {
                    $query = ['company_id' => $company, 'page' => $page, 'per_page' => 25];
                    if ($filters['status'] !== '') {
                        $query['status'] = $filters['status'];
                    }

return $this->read->listing($actor, $query, $module === 'categories');
                }
                if ($filters['status'] !== '' && ! in_array($filters['status'], ['submitted', 'approved', 'posted', 'cancelled'], true)) {
                    throw ValidationException::withMessages(['status' => 'Choose a finance instruction status.']);
                }
                $query = AccountingExpenseFinancePlan::query()->where('company_id', $company);
                if ($filters['status'] !== '') {
                    $query->where('status', $filters['status']);
                }
                $pagination = $query->orderByDesc('id')->paginate(25, ['*'], 'page', $page);
                $rows = [];
                foreach ($pagination->items() as $plan) {
                    $this->proof->verify($plan);
                    if ($plan->getAttribute('status') === 'posted') {
                        app(AccountingExpenseFinanceEvidence::class)->verify($company, (int) $plan->getKey());
                    }
                    $s = $plan->getAttribute('snapshot');
                    $rows[] = ['id' => (int) $plan->getKey(), 'expense_id' => (int) $plan->getAttribute('expense_id'), 'kind' => (string) $plan->getAttribute('kind'), 'status' => (string) $plan->getAttribute('status'), 'revision' => (int) $plan->getAttribute('revision'), 'date' => $plan->getAttribute('document_date')->format('Y-m-d'), 'amount' => (string) $plan->getAttribute('amount'), 'currency_code' => (string) $plan->getAttribute('currency_code'), 'original_plan_id' => $s['reverses_plan'] ?? null];
                }

                return ['data' => $rows, 'page' => ['current_page' => $pagination->currentPage(), 'last_page' => $pagination->lastPage(), 'per_page' => $pagination->perPage(), 'total' => $pagination->total()]];
            });
        }

        return Inertia::render('ERP/Accounting/Expenses/Index', ['context' => $context, 'module' => $module, 'result' => $result, 'filters' => $filters]);
    }

    /** @param array<string, mixed> $input */
    public function form(User $actor, array $input, ?int $id, bool $category = false): Response
    {
        $action = $category ? 'erp.expense-categories.manage' : 'erp.expenses.'.($id === null ? 'create' : 'edit');
        $row = $id === null ? null : ($category ? $this->native->category($actor, $id) : $this->native->find($actor, $id));
        if ($row !== null && ! $category && $row->getAttribute('status') !== 'draft') {
            abort(422, 'Edit only a draft expense; retained reviewed documents cannot be changed.');
        }
        $company = $row === null ? $this->companyInput($input) : (int) $row->getAttribute('company_id');
        $context = $this->context($actor, $company, $action);
        if ($context['company'] === null) {
            abort(422, 'Select a company on the register first.');
        }
        if ($category && ! $actor->can('erp.expense-categories.view')) {
            throw new AuthorizationException;
        }
        if (! $category && ! $actor->can('erp.expenses.view')) {
            throw new AuthorizationException;
        }
        $data = $row === null ? null : $this->read->detail($actor, (int) $row->getKey(), $category)['data'];

        return Inertia::render('ERP/Accounting/Expenses/Form', ['context' => $context, 'categoryMode' => $category, 'record' => $data, 'options' => $this->options($actor, $context['company']['id'], $category ? 'category' : 'native', '', $row)]);
    }

    public function show(User $actor, int $id): Response
    {
        $row = $this->native->find($actor, $id);
        $context = $this->context($actor, (int) $row->getAttribute('company_id'), 'erp.expenses.view');

        return Inertia::render('ERP/Accounting/Expenses/Show', ['context' => $context, 'expense' => $this->read->detail($actor, $id), 'attachments' => $actor->can('erp.expense-attachments.view') ? $this->files->listing($actor, $id) : null, 'finance' => $actor->can('erp.expense-finance.view') ? $this->finance->payable($actor, $id) : null]);
    }

    /** @param array<string, mixed> $input */
    public function financeForm(User $actor, array $input): Response
    {
        $data = Validator::make($input, ['expense_id' => ['required', 'integer', 'min:1'], 'kind' => ['required', 'in:recognition,settlement,reversal'], 'original_plan_id' => ['nullable', 'integer', 'min:1']])->validate();
        $row = $this->native->find($actor, (int) $data['expense_id']);
        $company = (int) $row->getAttribute('company_id');
        $context = $this->context($actor, $company, 'erp.expense-finance.create');
        if (! $actor->can('erp.expense-finance.view') || ($data['kind'] === 'reversal' && ! $actor->can('erp.expense-finance.reverse'))) {
            throw new AuthorizationException;
        }
        $original = null;
        if ($data['kind'] === 'reversal') {
            $original = $this->finance->detail($actor, (int) ($data['original_plan_id'] ?? 0))['data'];
            if ($original['expense_id'] !== (int) $row->getKey() || $original['status'] !== 'posted' || $original['kind'] === 'reversal') {
                abort(422, 'Choose a posted original operation on this expense.');
            }
        }

        return Inertia::render('ERP/Accounting/Expenses/FinanceForm', ['context' => $context, 'expense' => $this->read->detail($actor, (int) $row->getKey())['data'], 'position' => $this->finance->payable($actor, (int) $row->getKey()), 'kind' => $data['kind'], 'original' => $original, 'options' => $this->options($actor, $company, 'finance')]);
    }

    public function financeShow(User $actor, int $id): Response
    {
        $row = $this->finance->find($actor, $id);
        $company = (int) $row->getAttribute('company_id');
        $s = $row->getAttribute('snapshot');
        $context = $this->context($actor, $company, 'erp.expense-finance.view');
        $original = $this->native->find($actor, $s['expense_id']);
        $excluded = [(int) $row->getAttribute('created_by'), (int) $original->getAttribute('created_by'), (int) $original->getAttribute('last_edited_by'), (int) $original->getAttribute('submitted_by')];
        $events = [];
        foreach (AccountingExpenseFinanceEvent::query()->where('company_id', $company)->where('plan_id', $id)->orderBy('revision')->get() as $event) {
            $events[] = ['action' => (string) $event->getAttribute('action'), 'actor_id' => (int) $event->getAttribute('actor_id'), 'reason' => (string) $event->getAttribute('reason'), 'occurred_at' => $event->getAttribute('occurred_at')->format(DATE_ATOM)];
        }
        $linked = AccountingExpenseFinancePlan::query()->where('company_id', $company)->where('active_reversal_id', $id)->first();
        $accountLabel = static function (?int $id) use ($company): string {
            if ($id === null) {
                return 'None';
            } $row = Account::query()->where('company_id', $company)->findOrFail($id);

            return $row->getAttribute('code').' — '.$row->getAttribute('name').' (#'.$id.')';
        };
        $labels = ['native_number' => (string) $original->getAttribute('number'), 'payee' => (string) $original->getAttribute('payee_snapshot')['label'], 'expense_gl' => $accountLabel($s['expense_account']['id']), 'tax_gl' => $accountLabel($s['tax_account']['id'] ?? null), 'liability' => $accountLabel($s['liability']['id'] ?? null), 'cash' => 'None'];
        if ($s['cash'] !== null) {
            $binding = AccountingCashAccount::query()->where('company_id', $company)->findOrFail($s['cash']['id']);
            $labels['cash'] = $s['cash']['role'].' — '.$binding->getAttribute('name').' ('.$accountLabel($s['cash']['account_id']).')';
        }
        $journalLines = [];
        foreach ($this->proof->lines($row) as $line) {
            $journalLines[] = ['account' => $accountLabel($line['account_id']), 'debit' => $line['debit'], 'credit' => $line['credit']];
        }

        return Inertia::render('ERP/Accounting/Expenses/FinanceShow', ['context' => $context, 'labels' => $labels, 'journalLines' => $journalLines, 'finance' => $this->finance->detail($actor, $id), 'canApprove' => ! in_array((int) $actor->getKey(), $excluded, true) && $actor->can('erp.expense-finance.approve') && ($row->getAttribute('kind') !== 'reversal' || $actor->can('erp.expense-finance.reverse')), 'history' => $events, 'originalPlanId' => $s['reverses_plan'] ?? null, 'reversalPlanId' => $linked === null ? null : (int) $linked->getKey()]);
    }

    /** @param array<string, mixed> $input */
    private function companyInput(array $input): ?int
    {
        $data = Validator::make($input, ['company_id' => ['nullable', 'integer', 'min:1']])->validate();

        return isset($data['company_id']) ? (int) $data['company_id'] : null;
    }

    /** @return array<string, mixed> */
    public function options(User $actor, int $company, string $mode, string $q = '', ?Model $selected = null): array
    {
        $permission = $mode === 'category' ? 'erp.expense-categories.manage' : ($mode === 'finance' ? 'erp.expense-finance.create' : ($selected === null ? 'erp.expenses.create' : 'erp.expenses.edit'));
        if (! in_array($mode, ['category', 'finance', 'native'], true)) {
            abort(422);
        }
        $this->access->authorize($actor, $company, $permission);
        if (! $actor->can($mode === 'category' ? 'erp.expense-categories.view' : 'erp.expenses.view') || ($mode === 'finance' && ! $actor->can('erp.expense-finance.view'))) {
            throw new AuthorizationException;
        }
        if (mb_strlen($q) > 100) {
            abort(422);
        }
        $out = ['categories' => [], 'suppliers' => [], 'employees' => [], 'branches' => [], 'departments' => [], 'bindings' => [], 'accounts' => [], 'taxAccounts' => [], 'liabilities' => [], 'limit' => 500];
        if ($mode === 'native') {
            foreach (AccountingExpenseCategory::query()->where('company_id', $company)->where('status', 'active')->where(function ($query) use ($q): void {
                $query->where('name', 'like', '%'.$q.'%')->orWhere('code', 'like', '%'.$q.'%');
            })->orderBy('code')->limit(500)->get() as $item) {
                $this->history->verify($item);
                $out['categories'][] = ['id' => (int) $item->getKey(), 'label' => $item->getAttribute('code').' — '.$item->getAttribute('name')];
            }
            foreach (['suppliers' => Supplier::class, 'branches' => Branch::class, 'departments' => Department::class] as $field => $class) {
                foreach ($class::query()->where('company_id', $company)->where('status', 'active')->where(function ($query) use ($q): void {
                    $query->where('name', 'like', '%'.$q.'%')->orWhere('code', 'like', '%'.$q.'%');
                })->orderBy('name')->limit(500)->get() as $item) {
                    $out[$field][] = ['id' => (int) $item->getKey(), 'label' => (string) $item->getAttribute('code').' — '.(string) $item->getAttribute('name'), 'branch_id' => $field === 'departments' && $item->getAttribute('branch_id') !== null ? (int) $item->getAttribute('branch_id') : null];
                }
            }
            foreach (Employee::query()->where('company_id', $company)->where('employment_status', 'active')->where(function ($query) use ($q): void {
                $query->where('first_name', 'like', '%'.$q.'%')->orWhere('last_name', 'like', '%'.$q.'%')->orWhere('employee_number', 'like', '%'.$q.'%');
            })->orderBy('employee_number')->limit(500)->get(['id', 'employee_number', 'first_name', 'last_name']) as $item) {
                $out['employees'][] = ['id' => (int) $item->getKey(), 'label' => $item->getAttribute('employee_number').' — '.$item->getAttribute('first_name').' '.$item->getAttribute('last_name')];
            }
        }
        if ($mode !== 'category') {
            foreach (AccountingCashAccount::query()->where('company_id', $company)->where('status', 'active')->where('name', 'like', '%'.$q.'%')->orderBy('name')->limit(500)->get() as $binding) {
                $out['bindings'][] = ['id' => (int) $binding->getKey(), 'label' => (string) $binding->getAttribute('name').' — '.(string) $binding->getAttribute('currency_code')];
            }
        }
        $query = Account::query()->where('company_id', $company)->where('status', 'active')->where('is_postable', true)->where(function ($query) use ($q): void {
            $query->where('name', 'like', '%'.$q.'%')->orWhere('code', 'like', '%'.$q.'%');
        })->orderBy('code')->limit(500);
        foreach ($query->get() as $account) {
            $id = (int) $account->getKey();
            $option = ['id' => $id, 'label' => $account->getAttribute('code').' — '.$account->getAttribute('name')];
            foreach ($mode === 'category' ? ['accounts'] : ($mode === 'finance' ? ['liabilities'] : ['taxAccounts']) as $field) {
                try {
                    if ($field === 'liabilities') {
                        $this->money->liability($company, $id);
                    } else {
                        $this->contract->account($company, $id, $field === 'taxAccounts');
                    } $out[$field][] = $option;
                } catch (ValidationException) { /* Ineligible accounts are not selectable. */
                }
            }
        }
        // Editing preserves a selected ID even outside the first active/search batch; the command still revalidates it.
        if ($selected !== null && $selected instanceof AccountingExpense) {
            foreach (['category_id' => 'categories', 'supplier_id' => 'suppliers', 'employee_id' => 'employees', 'branch_id' => 'branches', 'department_id' => 'departments', 'cash_account_id' => 'bindings', 'tax_account_id' => 'taxAccounts'] as $field => $target) {
                $id = $selected->getAttribute($field);
                if ($id === null || in_array((int) $id, array_column($out[$target], 'id'), true)) {
                    continue;
                } $out[$target][] = ['id' => (int) $id, 'label' => 'Current selection #'.$id.' (refresh or review eligibility)'];
            }
        }
        if ($selected instanceof AccountingExpenseCategory) {
            $id = (int) $selected->getAttribute('account_id');
            if (! in_array($id, array_column($out['accounts'], 'id'), true)) {
                $out['accounts'][] = ['id' => $id, 'label' => 'Current account #'.$id.' (review eligibility)'];
            }
        }

        return $out;
    }
}

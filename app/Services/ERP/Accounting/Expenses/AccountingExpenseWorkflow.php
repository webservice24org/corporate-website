<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Expenses;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingExpense;
use App\Models\Accounting\AccountingExpenseCategory;
use App\Models\Accounting\AccountingSetting;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingConfigurationGuard;
use App\Services\ERP\Accounting\AccountingDomainRegistry;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\Reconciliation\AccountingBankFinalizationGuard;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** A reviews a standalone expense. It does not register a financial source event or expose posting. */
final class AccountingExpenseWorkflow
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingConfigurationGuard $configuration, private readonly AccountingBusinessDate $dates, private readonly AccountingExpenseContract $contract, private readonly AccountingExpenseGuard $guard, private readonly AccountingExpenseHistory $history, private readonly AccountingCanonicalPayload $canonical) {}

    public function find(User $actor, int $id, string $permission = 'erp.expenses.view'): AccountingExpense
    {
        $this->permission($actor, $permission);
        $query = AccountingExpense::query();
        if (! $actor->hasRole('super-admin')) {
            $query->whereIn('company_id', $actor->employee()->select('company_id'));
        }

        return $query->findOrFail($id);
    }

    public function category(User $actor, int $id): AccountingExpenseCategory
    {
        $this->permission($actor, 'erp.expense-categories.view');
        $query = AccountingExpenseCategory::query();
        if (! $actor->hasRole('super-admin')) {
            $query->whereIn('company_id', $actor->employee()->select('company_id'));
        }

        return $query->findOrFail($id);
    }

    /** @param array<string, mixed> $input */
    public function saveCategory(User $actor, array $input, ?int $id = null): AccountingExpenseCategory
    {
        $this->permission($actor, 'erp.expense-categories.manage');
        $this->keys($input, ['company_id', 'code', 'name', 'account_id', 'status', 'reason', $id === null ? 'key' : 'revision']);

        $data = Validator::make($input, ['company_id' => ['required', 'integer', 'min:1'], 'code' => ['required', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_-]{0,29}\z/'], 'name' => ['required', 'string', 'max:160'], 'account_id' => ['required', 'integer', 'min:1'], 'status' => ['required', 'in:active,inactive'], 'reason' => ['required', 'string', 'max:2000'], $id === null ? 'key' : 'revision' => $id === null ? ['required', 'string'] : ['required', 'integer', 'min:1']])->validate();

        $company = (int) $data['company_id'];
        $this->access->authorize($actor, $company, 'erp.expense-categories.manage');

        $data['company_id'] = $company;
        $data['account_id'] = (int) $data['account_id'];
        $data['code'] = strtoupper($data['code']);
        $data['name'] = $this->contract->text($data['name'], 160);
        $data['reason'] = $this->contract->text($data['reason'], 2000);

        $request = $data;
        unset($request['key'], $request['revision']);
        $requestHash = $this->canonical->hash(['actor_id' => (int) $actor->getKey(), 'input' => $request]);
        $key = $id === null ? $this->contract->key($data['key']) : null;

        return DB::transaction(function () use ($actor, $company, $data, $id, $key, $requestHash): AccountingExpenseCategory {
            $owner = Company::query()->whereKey($company)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $company, 'erp.expense-categories.manage');

            if ($id === null) {
                $existing = AccountingExpenseCategory::query()->where('company_id', $company)->where('creation_key_hash', $key)->first();
                if ($existing !== null) {
                    if ($existing->getAttribute('creation_request_hash') !== $requestHash) {
                        $this->contract->fail('Category request key conflicts with its original intent.');
                    }
                    $this->history->verify($existing);

                    return $existing;
                }
            }

            $this->configuration->lockForSetup($owner);
            $this->contract->account($company, $data['account_id'], false, $data['status'] === 'active');
            $before = null;

            if ($id !== null) {
                $row = $this->category($actor, $id);
                if ((int) $row->getAttribute('company_id') !== $company) {
                    abort(403);
                }
                $this->history->verify($row);
                if ((int) $row->getAttribute('revision') !== (int) $data['revision'] || $row->getAttribute('code') !== $data['code']) {
                    $this->contract->fail('Category revision changed or its immutable code was altered.');
                }
                $before = $this->history->summary($row);
            } else {
                $row = new AccountingExpenseCategory;
            }

            if (AccountingExpenseCategory::query()->where('company_id', $company)->where('code', $data['code'])->when($id !== null, fn ($query) => $query->whereKeyNot($id))->exists()) {
                $this->contract->fail('Category code is already in use.');
            }

            $row->forceFill(['company_id' => $company, 'code' => $data['code'], 'name' => $data['name'], 'account_id' => $data['account_id'], 'status' => $data['status'], 'revision' => $id === null ? 1 : (int) $row->getAttribute('revision') + 1, 'updated_by' => (int) $actor->getKey()]);

            if ($id === null) {
                $row->forceFill(['created_by' => (int) $actor->getKey(), 'creation_key_hash' => $key, 'creation_request_hash' => $requestHash]);
            }

            $row->save();
            $this->history->append($row, $actor, $id === null ? 'create' : 'update', $data['reason'], $before);

            return $row->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function save(User $actor, array $input, ?int $id = null): AccountingExpense
    {
        $permission = 'erp.expenses.'.($id === null ? 'create' : 'edit');
        $this->permission($actor, $permission);

        $data = $this->normalize($input, $id === null);
        $company = $data['company_id'];
        $this->access->authorize($actor, $company, $permission);

        $key = $id === null ? $this->contract->key($data['key']) : null;
        $request = $data;
        unset($request['key'], $request['revision']);
        $requestHash = $this->canonical->hash(['actor_id' => (int) $actor->getKey(), 'input' => $request]);

        return DB::transaction(function () use ($actor, $permission, $company, $data, $id, $key, $requestHash): AccountingExpense {
            $owner = Company::query()->whereKey($company)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $company, $permission);

            if ($id === null) {
                $existing = AccountingExpense::query()->where('company_id', $company)->where('creation_key_hash', $key)->first();
                if ($existing !== null) {
                    if ($existing->getAttribute('creation_request_hash') !== $requestHash) {
                        $this->contract->fail('Creation key belongs to a different actor or document body.');
                    }
                    $this->history->verify($existing);

                    return $existing;
                }
            }

            $this->nativeConfiguration($owner);
            $before = null;

            if ($id !== null) {
                $row = $this->find($actor, $id, $permission);
                if ((int) $row->getAttribute('company_id') !== $company) {
                    abort(403);
                }
                $this->history->verify($row);
                if ($row->getAttribute('status') !== 'draft' || (int) $row->getAttribute('revision') !== $data['revision']) {
                    $this->contract->fail('Edit only the current draft revision.');
                }
                $before = $this->history->summary($row);
            } else {
                $row = new AccountingExpense;
            }

            $prepared = $this->prepare($owner, $data);
            $claim = $this->contract->claim($data);

            if (AccountingExpense::query()->where('company_id', $company)->where('active_claim_hash', $claim)->when($id !== null, fn ($query) => $query->whereKeyNot($id))->exists()) {
                $this->contract->fail('An active expense already reserves this payee/document reference.');
            }

            $fields = $data;
            unset($fields['key'], $fields['revision'], $fields['payee_label']);

            $row->forceFill([...$fields, ...$prepared, 'claim_hash' => $claim, 'active_claim_hash' => $claim, 'revision' => $id === null ? 1 : (int) $row->getAttribute('revision') + 1, 'updated_by' => (int) $actor->getKey(), 'last_edited_by' => (int) $actor->getKey()]);

            if ($id === null) {
                $row->forceFill(['number' => $this->number($company, $this->dates->today($owner)), 'currency_code' => (string) $owner->getAttribute('currency_code'), 'created_by' => (int) $actor->getKey(), 'creation_key_hash' => $key, 'creation_request_hash' => $requestHash]);
            }

            $row->save();
            $this->history->append($row, $actor, $id === null ? 'create' : 'edit', 'Expense draft prepared', $before);

            return $row->refresh();
        }, 3);
    }

    public function change(User $actor, int $id, int $revision, string $action, string $reason): AccountingExpense
    {
        if (! in_array($action, ['submit', 'approve', 'reject', 'cancel'], true)) {
            $this->contract->fail('Financial posting is not exposed by the A foundation.');
        }

        $permission = 'erp.expenses.'.($action === 'reject' ? 'approve' : $action);
        $found = $this->find($actor, $id, $permission);
        $company = (int) $found->getAttribute('company_id');
        $reason = $this->contract->text($reason, 2000);

        return DB::transaction(function () use ($actor, $id, $revision, $action, $permission, $company, $reason): AccountingExpense {
            $owner = Company::query()->whereKey($company)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $company, $permission);
            $this->nativeConfiguration($owner);
            $row = $this->find($actor, $id, $permission);
            $this->history->verify($row);

            $state = $row->getAttribute('status');
            if ($revision !== (int) $row->getAttribute('revision') || in_array($state, ['cancelled', 'posted', 'reversed'], true)) {
                $this->contract->fail('Use the current unposted expense revision.');
            }
            $before = $this->history->summary($row);
            $at = CarbonImmutable::now('UTC');

            if ($action === 'cancel') {
                $row->forceFill(['status' => 'cancelled', 'active_claim_hash' => null, 'cancelled_by' => (int) $actor->getKey(), 'cancelled_at' => $at, 'cancellation_reason' => $reason]);
            } elseif ($action === 'reject') {
                if ($state !== 'submitted') {
                    $this->contract->fail('Only submitted expenses can return to draft.');
                }
                $row->forceFill(['status' => 'draft', 'submitted_by' => null, 'submitted_at' => null, 'submitted_hash' => null, 'approved_by' => null, 'approved_at' => null, 'approved_hash' => null]);
            } else {
                if ($state !== ($action === 'submit' ? 'draft' : 'submitted')) {
                    $this->contract->fail('Expense review state changed.');
                }

                app(AccountingExpenseAttachmentLedger::class)->assertReview($actor, $row);
                $this->live($owner, $row);
                $hash = $this->guard->intent($row);

                if ($action === 'submit') {
                    $row->forceFill(['status' => 'submitted', 'submitted_by' => (int) $actor->getKey(), 'submitted_at' => $at, 'submitted_hash' => $hash]);
                } else {
                    if (in_array((int) $actor->getKey(), [(int) $row->getAttribute('created_by'), (int) $row->getAttribute('last_edited_by'), (int) $row->getAttribute('submitted_by')], true)) {
                        throw new AuthorizationException('Expense approval must be independent of the maker, last editor and submitter.');
                    }
                    $row->forceFill(['status' => 'approved', 'approved_by' => (int) $actor->getKey(), 'approved_at' => $at, 'approved_hash' => $hash]);
                }
            }

            $row->forceFill(['revision' => $revision + 1, 'updated_by' => (int) $actor->getKey()])->save();
            $this->history->append($row, $actor, $action, $reason, $before);

            return $row->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalize(array $input, bool $new): array
    {
        $fields = ['company_id', 'category_id', 'document_date', 'accounting_date', 'document_reference', 'payee_type', 'supplier_id', 'employee_id', 'payee_label', 'branch_id', 'department_id', 'settlement_mode', 'cash_account_id', 'net_amount', 'tax_amount', 'tax_treatment', 'tax_account_id', 'description', $new ? 'key' : 'revision'];
        $this->keys($input, $fields);

        $rules = ['company_id' => ['required', 'integer', 'min:1'], 'category_id' => ['required', 'integer', 'min:1'], 'document_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'], 'accounting_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:document_date'], 'document_reference' => ['required', 'string', 'max:100'], 'payee_type' => ['required', 'in:supplier,employee,other'], 'payee_label' => ['nullable', 'string', 'max:160'], 'settlement_mode' => ['required', 'in:paid_now,unpaid,employee_reimbursement'], 'net_amount' => ['required'], 'tax_amount' => ['required'], 'tax_treatment' => ['required', 'in:none,recoverable,included_in_cost'], 'description' => ['required', 'string', 'max:2000'], $new ? 'key' : 'revision' => $new ? ['required', 'string'] : ['required', 'integer', 'min:1']];

        foreach (['supplier_id', 'employee_id', 'branch_id', 'department_id', 'cash_account_id', 'tax_account_id'] as $field) {
            $rules[$field] = ['nullable', 'integer', 'min:1'];
        }

        $data = Validator::make($input, $rules)->validate();

        foreach (['company_id', 'category_id', 'supplier_id', 'employee_id', 'branch_id', 'department_id', 'cash_account_id', 'tax_account_id'] as $field) {
            $data[$field] = isset($data[$field]) ? (int) $data[$field] : null;
        }

        if (! $new) {
            $data['revision'] = (int) $data['revision'];
        }

        $data['document_reference'] = $this->contract->reference($data['document_reference']);
        $data['description'] = $this->contract->text($data['description'], 2000);
        $data['payee_label'] = $this->contract->text($data['payee_label'] ?? '', 160, $data['payee_type'] === 'other');

        if (($data['payee_type'] === 'supplier' ? $data['supplier_id'] === null || $data['employee_id'] !== null || $data['payee_label'] !== '' : ($data['payee_type'] === 'employee' ? $data['employee_id'] === null || $data['supplier_id'] !== null || $data['payee_label'] !== '' : $data['supplier_id'] !== null || $data['employee_id'] !== null)) || ($data['settlement_mode'] === 'employee_reimbursement' && $data['payee_type'] !== 'employee') || ($data['settlement_mode'] === 'unpaid' && $data['payee_type'] === 'employee') || (($data['settlement_mode'] === 'paid_now') !== ($data['cash_account_id'] !== null))) {
            $this->contract->fail('Use a genuine payee identity and consistent funding/reimbursement intent.');
        }

        $data = [...$data, ...$this->contract->amounts($data['net_amount'], $data['tax_amount'], $data['tax_treatment'])];
        unset($data['total_amount']);

        $taxLeg = $data['tax_treatment'] === 'recoverable' && bccomp($data['tax_amount'], '0', 2) > 0;
        if ($taxLeg !== ($data['tax_account_id'] !== null)) {
            $this->contract->fail('A separate input-tax GL is required only for positive recoverable tax.');
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepare(Company $owner, array $data): array
    {
        $company = (int) $owner->getKey();
        if ($data['accounting_date'] > $this->dates->today($owner)) {
            $this->contract->fail('Expense dates cannot be in the company future.');
        }

        $category = AccountingExpenseCategory::query()->where('company_id', $company)->findOrFail($data['category_id']);
        $this->history->verify($category);
        if ($category->getAttribute('status') !== 'active') {
            $this->contract->fail('Expense category must be active.');
        }

        $account = $this->contract->account($company, (int) $category->getAttribute('account_id'));
        $snapshot = ['id' => (int) $category->getKey(), 'company_id' => $company, 'revision' => (int) $category->getAttribute('revision'), 'account_id' => $account['id'], 'account' => $account];

        if ($data['branch_id'] !== null) {
            $branch = Branch::query()->where('company_id', $company)->findOrFail($data['branch_id']);
            if ($branch->getAttribute('status') !== 'active') {
                $this->contract->fail('Branch must be active.');
            }
        }

        if ($data['department_id'] !== null) {
            $department = Department::query()->where('company_id', $company)->findOrFail($data['department_id']);
            if ($department->getAttribute('status') !== 'active' || ($department->getAttribute('branch_id') !== null && (int) $department->getAttribute('branch_id') !== $data['branch_id'])) {
                $this->contract->fail('Department must be active and agree with the chosen branch.');
            }
        }

        $payee = ['type' => $data['payee_type'], 'id' => null, 'company_id' => $company, 'label' => $data['payee_label']];

        if ($data['payee_type'] === 'supplier') {
            $supplier = Supplier::query()->where('company_id', $company)->findOrFail($data['supplier_id']);
            if ($supplier->getAttribute('status') !== 'active') {
                $this->contract->fail('Supplier must be active.');
            }

            $payee['id'] = (int) $supplier->getKey();
            $payee['label'] = (string) $supplier->getAttribute('code').' — '.(string) $supplier->getAttribute('name');

            foreach (SupplierBill::query()->where('company_id', $company)->where('supplier_id', $supplier->getKey())->select(['id', 'supplier_invoice_number'])->orderBy('id')->cursor() as $bill) {
                if ($this->contract->reference((string) $bill->getAttribute('supplier_invoice_number')) === $data['document_reference']) {
                    $this->contract->fail('This supplier invoice already belongs to a purchase bill. Use its existing accounting/settlement path.');
                }
            }
        } elseif ($data['payee_type'] === 'employee') {
            $employee = Employee::query()->where('company_id', $company)->findOrFail($data['employee_id']);
            if ($employee->getAttribute('employment_status') !== 'active') {
                $this->contract->fail('Employee must be active for a new expense review.');
            }
            $payee['id'] = (int) $employee->getKey();
            $payee['label'] = (string) $employee->getAttribute('employee_number').' — '.trim((string) $employee->getAttribute('first_name').' '.(string) $employee->getAttribute('last_name'));
        }

        $cash = null;

        if ($data['cash_account_id'] !== null) {
            $binding = AccountingCashAccount::query()->where('company_id', $company)->findOrFail($data['cash_account_id']);
            if ($binding->getAttribute('status') !== 'active' || $binding->getAttribute('currency_code') !== $owner->getAttribute('currency_code')) {
                $this->contract->fail('Choose an active owned finance binding in company currency.');
            }
            $gl = Account::query()->where('company_id', $company)->findOrFail((int) $binding->getAttribute('account_id'));
            if (! in_array($binding->getAttribute('kind'), ['cash', 'bank'], true) || ! str_starts_with((string) $binding->getAttribute('role'), (string) $binding->getAttribute('kind').':') || $gl->getAttribute('normal_side') !== 'debit' || $gl->getAttribute('cash_flow_role') !== 'cash' || $gl->getAttribute('control_role') !== null || ! AccountGroup::query()->where('company_id', $company)->whereKey($gl->getAttribute('account_group_id'))->where('category', 'asset')->exists() || $gl->getAttribute('status') !== 'active' || ! (bool) $gl->getAttribute('is_postable') || Account::query()->where('company_id', $company)->where('parent_id', $gl->getKey())->exists()) {
                $this->contract->fail('Finance binding requires an active posting GL leaf.');
            }
            $cash = ['id' => (int) $binding->getKey(), 'company_id' => $company, 'account_id' => (int) $binding->getAttribute('account_id'), 'kind' => (string) $binding->getAttribute('kind'), 'role' => (string) $binding->getAttribute('role'), 'currency_code' => (string) $binding->getAttribute('currency_code')];
        }

        return [...$this->contract->amounts($data['net_amount'], $data['tax_amount'], $data['tax_treatment']), 'category_snapshot' => $snapshot, 'expense_account_id' => $account['id'], 'payee_snapshot' => $payee, 'cash_snapshot' => $cash, 'tax_snapshot' => $data['tax_account_id'] === null ? null : $this->contract->account($company, $data['tax_account_id'], true)];
    }

    public function validateForRecognition(User $actor, AccountingExpense $row): void
    {
        $found = $this->find($actor, (int) $row->getKey());
        $this->history->verify($found);
        app(AccountingExpenseAttachmentLedger::class)->assertReview($actor, $found);
        $this->live(Company::query()->findOrFail((int) $found->getAttribute('company_id')), $found);
    }

    private function live(Company $owner, AccountingExpense $row): void
    {
        $data = $row->only(['company_id', 'category_id', 'document_date', 'accounting_date', 'document_reference', 'payee_type', 'supplier_id', 'employee_id', 'branch_id', 'department_id', 'settlement_mode', 'cash_account_id', 'net_amount', 'tax_amount', 'tax_treatment', 'tax_account_id', 'description']);

        foreach (['company_id', 'category_id', 'supplier_id', 'employee_id', 'branch_id', 'department_id', 'cash_account_id', 'tax_account_id'] as $field) {
            $data[$field] = $data[$field] === null ? null : (int) $data[$field];
        }

        $data['document_date'] = $row->getAttribute('document_date')->format('Y-m-d');
        $data['accounting_date'] = $row->getAttribute('accounting_date')->format('Y-m-d');
        $data['payee_label'] = $row->getAttribute('payee_type') === 'other' ? $row->getAttribute('payee_snapshot')['label'] : '';

        $prepared = $this->prepare($owner, $data);

        foreach (['category_snapshot', 'cash_snapshot', 'tax_snapshot'] as $field) {
            if ($this->canonical->hash($prepared[$field] ?? []) !== $this->canonical->hash($row->getAttribute($field) ?? [])) {
                $this->contract->fail('Reviewed category, GL or finance identity changed; refresh and resubmit a draft.');
            }
        }

        $cash = $row->getAttribute('cash_snapshot');
        if (is_array($cash) && $cash['kind'] === 'bank') {
            app(AccountingBankFinalizationGuard::class)->assertDate((int) $owner->getKey(), (int) $cash['id'], $data['accounting_date']);
        }
    }

    private function nativeConfiguration(Company $company): void
    {
        $setting = AccountingSetting::query()->where('company_id', $company->getKey())->lockForUpdate()->first();

        if ($company->getAttribute('status') !== 'active' || $setting === null || ! in_array($setting->getAttribute('state'), ['disabled', 'ready', 'enabled'], true) || $setting->getAttribute('functional_currency') !== $company->getAttribute('currency_code') || (int) $setting->getAttribute('currency_precision') !== 2 || $setting->getAttribute('contract_version') !== AccountingDomainRegistry::VERSION || $setting->getAttribute('recognition_policy') !== 'invoice_cogs' || ! app(AccountingSetupService::class)->supportsCurrency((string) $company->getAttribute('currency_code'))) {
            $this->contract->fail('Initialize compatible accounting settings for an active company before new expense work.');
        }
    }

    private function number(int $company, string $today): string
    {
        $year = (int) substr($today, 0, 4);
        $query = DB::table('accounting_expense_sequences')->where('company_id', $company)->where('year', $year);
        $row = $query->lockForUpdate()->first();
        $next = $row === null ? 1 : (int) $row->last_number + 1;
        if ($next > 999999) {
            $this->contract->fail('Expense numbering range exhausted.');
        }
        if ($row === null) {
            DB::table('accounting_expense_sequences')->insert(['company_id' => $company, 'year' => $year, 'last_number' => $next]);
        } else {
            $query->update(['last_number' => $next]);
        }

        return 'EX-'.$year.'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    private function permission(User $actor, string $permission): void
    {
        $view = str_starts_with($permission, 'erp.expense-categories.') ? 'erp.expense-categories.view' : 'erp.expenses.view';

        if (! $actor->exists || ! in_array($permission, AccountingExpenseContract::PERMISSIONS, true) || ! $actor->can($permission) || ! $actor->can($view)) {
            throw new AuthorizationException('Native expense permission and view authority are required.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $allowed
     */
    private function keys(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            $this->contract->fail('Unexpected expense input. External source IDs, attachments and posting metadata are not accepted here.');
        }
    }
}

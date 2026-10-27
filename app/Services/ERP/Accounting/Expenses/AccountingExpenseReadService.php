<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Expenses;

use App\Models\Accounting\AccountingExpense;
use App\Models\Accounting\AccountingExpenseCategory;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** JSON foundation only. Full Inertia register/form/detail screens are delivered in 6.9D. */
final class AccountingExpenseReadService
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingExpenseHistory $history, private readonly AccountingExpenseWorkflow $workflow) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function listing(User $actor, array $input, bool $categories = false): array
    {
        $permission = $categories ? 'erp.expense-categories.view' : 'erp.expenses.view';

        if (! $actor->can($permission)) {
            throw new AuthorizationException;
        }

        if (array_diff(array_keys($input), ['company_id', 'page', 'per_page', 'status']) !== []) {
            abort(422, 'Unsupported expense list filter.');
        }

        $data = Validator::make($input, ['company_id' => ['required', 'integer', 'min:1'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'in:10,25,50,100'], 'status' => ['sometimes', 'in:'.($categories ? 'active,inactive' : 'draft,submitted,approved,posted,reversed,cancelled')]])->validate();

        $company = (int) $data['company_id'];
        $this->access->authorize($actor, $company, $permission);

        return DB::transaction(function () use ($actor, $categories, $data, $company, $permission): array {
            Company::query()->whereKey($company)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $company, $permission);

            $query = $categories ? AccountingExpenseCategory::query() : AccountingExpense::query();

            $query->where('company_id', $company);
            if (isset($data['status'])) {
                $query->where('status', $data['status']);
            }

            $page = $query->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 25), ['*'], 'page', (int) ($data['page'] ?? 1));
            $rows = [];

            foreach ($page->items() as $row) {
                $this->history->verify($row);
                $rows[] = $this->safe($row, false);
            }

            return ['company_id' => $company, 'data' => $rows, 'page' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()], 'posting_available' => $actor->can('erp.expense-finance.view') && $actor->can('erp.expense-finance.create'), 'posting_stage' => '6.9C reviewed finance instructions', 'coverage' => 'native expense documents only; no financial balances'];
        });
    }

    /** @return array<string, mixed> */
    public function detail(User $actor, int $id, bool $categories = false): array
    {
        $found = $categories ? $this->workflow->category($actor, $id) : $this->workflow->find($actor, $id);
        $company = (int) $found->getAttribute('company_id');

        $permission = $categories ? 'erp.expense-categories.view' : 'erp.expenses.view';

        return DB::transaction(function () use ($actor, $id, $categories, $company, $permission): array {
            Company::query()->whereKey($company)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $company, $permission);

            $row = $categories ? $this->workflow->category($actor, $id) : $this->workflow->find($actor, $id);
            $this->history->verify($row);

            return ['data' => $this->safe($row, true), 'history' => $this->history->timeline($row), 'history_truncated' => (int) $row->getAttribute('revision') > 100, 'posting_available' => $actor->can('erp.expense-finance.view') && $actor->can('erp.expense-finance.create'), 'posting_stage' => '6.9C reviewed finance instructions'];
        });
    }

    /** @return array<string, mixed> */
    private function safe(Model $row, bool $detail): array
    {
        $out = ['id' => (int) $row->getKey(), 'company_id' => (int) $row->getAttribute('company_id'), 'revision' => (int) $row->getAttribute('revision'), 'status' => (string) $row->getAttribute('status')];

        if ($row instanceof AccountingExpenseCategory) {
            return [...$out, 'code' => (string) $row->getAttribute('code'), 'name' => (string) $row->getAttribute('name'), 'account_id' => (int) $row->getAttribute('account_id')];
        }

        if (! $row instanceof AccountingExpense) {
            throw new \LogicException('Unknown native expense entity.');
        }

        foreach (['number', 'currency_code', 'payee_type', 'settlement_mode', 'net_amount', 'tax_amount', 'total_amount', 'tax_treatment'] as $field) {
            $out[$field] = (string) $row->getAttribute($field);
        }

        foreach (['document_date', 'accounting_date'] as $field) {
            $out[$field] = $row->getAttribute($field)->format('Y-m-d');
        }

        $payee = $row->getAttribute('payee_snapshot');
        $out['payee_label'] = $payee['label'];
        $out['category_id'] = (int) $row->getAttribute('category_id');

        if ($detail) {
            foreach (['supplier_id', 'employee_id', 'branch_id', 'department_id', 'expense_account_id', 'tax_account_id', 'cash_account_id', 'created_by', 'last_edited_by', 'submitted_by', 'approved_by', 'cancelled_by'] as $field) {
                $value = $row->getAttribute($field);
                $out[$field] = $value === null ? null : (int) $value;
            }

            foreach (['document_reference', 'description', 'cancellation_reason'] as $field) {
                $out[$field] = $row->getAttribute($field);
            }

            foreach (['submitted_at', 'approved_at', 'cancelled_at'] as $field) {
                $value = $row->getAttribute($field);
                $out[$field] = $value === null ? null : $value->format(DATE_ATOM);
            }
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Expenses;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCashAccount;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingDecimal;
use Illuminate\Validation\ValidationException;

final class AccountingExpenseContract
{
    public const VERSION = 'expense-native.v1';

    public const PERMISSIONS = ['erp.expense-categories.view', 'erp.expense-categories.manage', 'erp.expenses.view', 'erp.expenses.create', 'erp.expenses.edit', 'erp.expenses.submit', 'erp.expenses.approve', 'erp.expenses.cancel'];

    public function __construct(private readonly AccountingDecimal $decimal, private readonly AccountingCanonicalPayload $canonical) {}

    /** @return array{net_amount: string, tax_amount: string, total_amount: string} */
    public function amounts(mixed $net, mixed $tax, string $treatment): array
    {
        $net = $this->decimal->money($net);
        $tax = $this->decimal->money($tax);

        if (bccomp($net, '0', 2) <= 0 || ! in_array($treatment, ['none', 'recoverable', 'included_in_cost'], true) || ($treatment === 'none' && bccomp($tax, '0', 2) !== 0)) {
            $this->fail('Choose a positive exact net amount and an explicit consistent tax treatment.');
        }

        return ['net_amount' => $net, 'tax_amount' => $tax, 'total_amount' => $this->decimal->money(bcadd($net, $tax, 2))];
    }

    public function text(mixed $value, int $max, bool $required = true): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            $this->fail('Use valid plain UTF-8 text.');
        }

        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
        if (($required && $value === '') || mb_strlen($value) > $max) {
            $this->fail('Text is missing or exceeds its field limit.');
        }

        return $value;
    }

    public function reference(string $value): string
    {
        return $this->text(mb_strtoupper((string) preg_replace('/\s+/u', ' ', $this->text($value, 100))), 100);
    }

    public function key(string $value): string
    {
        if (! preg_match('/\A[A-Za-z0-9_-]{16,64}\z/', $value)) {
            $this->fail('Use an opaque request key of 16 to 64 letters, digits, underscores or hyphens.');
        }

        return hash('sha256', $value);
    }

    /**
     * Explicit ordinary expense or recoverable input-tax classification.
     *
     * @return array<string, mixed>
     */
    public function account(int $company, int $id, bool $tax = false, bool $live = true): array
    {
        $row = Account::query()->where('company_id', $company)->findOrFail($id);
        $group = AccountGroup::query()->where('company_id', $company)->findOrFail((int) $row->getAttribute('account_group_id'));

        $roles = $tax ? ['current_asset'] : ['operating_expense', 'other_expense'];

        if ($group->getAttribute('category') !== ($tax ? 'asset' : 'expense') || $row->getAttribute('normal_side') !== 'debit' || $row->getAttribute('control_role') !== null || ! in_array($row->getAttribute('statement_role'), $roles, true) || ($tax ? $row->getAttribute('cash_flow_role') === 'cash' : ! in_array($row->getAttribute('cash_flow_role'), ['operating', 'investing', 'financing'], true)) || AccountingCashAccount::query()->where('company_id', $company)->where('account_id', $id)->exists()) {
            $this->fail('Select an ordinary classified expense account or a separate non-cash input-tax asset account.');
        }

        if ($live && ($row->getAttribute('status') !== 'active' || ! (bool) $row->getAttribute('is_postable') || Account::query()->where('company_id', $company)->where('parent_id', $id)->exists())) {
            $this->fail('Selected GL account must be an active posting leaf.');
        }

        return ['id' => $id, 'company_id' => $company, 'account_group_id' => (int) $group->getKey(), 'normal_side' => 'debit', 'statement_role' => (string) $row->getAttribute('statement_role'), 'cash_flow_role' => $row->getAttribute('cash_flow_role')];
    }

    /** @param array<string, mixed> $data */
    public function claim(array $data): string
    {
        $identity = match ($data['payee_type']) {
            'supplier' => (string) $data['supplier_id'], 'employee' => (string) $data['employee_id'], default => mb_strtoupper((string) preg_replace('/\s+/u', ' ', $this->text($data['payee_label'], 160)))
        };

        return $this->canonical->hash(['company_id' => (int) $data['company_id'], 'payee_type' => $data['payee_type'], 'payee_identity' => $identity, 'reference' => $data['document_reference']]);
    }

    public function fail(string $message): never
    {
        throw ValidationException::withMessages(['expense' => $message]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Expenses;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingExpense;
use App\Models\Company;
use App\Models\SupplierBill;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingDomainRegistry;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\Reconciliation\AccountingBankFinalizationGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountingExpenseFinanceContract
{
    public const VERSION = 'expense-finance.v1';

    public const PERMISSIONS = ['erp.expense-finance.view', 'erp.expense-finance.create', 'erp.expense-finance.approve', 'erp.expense-finance.cancel', 'erp.expense-finance.post', 'erp.expense-finance.reverse'];

    public function __construct(private readonly AccountingExpenseContract $native, private readonly AccountingExpenseGuard $guard, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingMappingService $mappings, private readonly AccountingBusinessDate $dates) {}

    /** @return array<string, mixed> */
    public function binding(int $company, int $id, bool $live = true): array
    {
        $row = AccountingCashAccount::query()->where('company_id', $company)->findOrFail($id);
        $gl = Account::query()->where('company_id', $company)->findOrFail((int) $row->getAttribute('account_id'));

        if (! in_array($row->getAttribute('kind'), ['cash', 'bank'], true) || $gl->getAttribute('cash_flow_role') !== 'cash' || $gl->getAttribute('normal_side') !== 'debit' || $gl->getAttribute('control_role') !== null || ($live && ($row->getAttribute('status') !== 'active' || $gl->getAttribute('status') !== 'active' || ! $gl->getAttribute('is_postable') || Account::query()->where('company_id', $company)->where('parent_id', $gl->getKey())->exists()))) {
            $this->fail('Finance binding/GL is not eligible.');
        }

        return ['id' => $id, 'company_id' => $company, 'account_id' => (int) $gl->getKey(), 'kind' => (string) $row->getAttribute('kind'), 'role' => (string) $row->getAttribute('role'), 'currency_code' => (string) $row->getAttribute('currency_code')];
    }

    /**
     * Dedicated expense payable cohort: no trade-AP, payroll, cash or other published role reuse.
     *
     * @return array<string, mixed>
     */
    public function liability(int $company, int $id, bool $live = true): array
    {
        $row = Account::query()->where('company_id', $company)->findOrFail($id);
        $group = AccountGroup::query()->where('company_id', $company)->findOrFail((int) $row->getAttribute('account_group_id'));

        if ($group->getAttribute('category') !== 'liability' || $row->getAttribute('normal_side') !== 'credit' || $row->getAttribute('control_role') !== null || $row->getAttribute('statement_role') !== 'current_liability' || $row->getAttribute('cash_flow_role') === 'cash' || DB::table('accounting_account_mappings')->where('company_id', $company)->where('account_id', $id)->exists() || ($live && ($row->getAttribute('status') !== 'active' || ! $row->getAttribute('is_postable') || Account::query()->where('company_id', $company)->where('parent_id', $id)->exists()))) {
            $this->fail('Choose a dedicated ordinary current-liability posting leaf for expense payables; published AP/payroll roles cannot be reused.');
        }

        return ['id' => $id, 'company_id' => $company, 'account_group_id' => (int) $group->getKey(), 'normal_side' => 'credit', 'statement_role' => 'current_liability', 'cash_flow_role' => $row->getAttribute('cash_flow_role')];
    }

    public function billConflict(AccountingExpense $expense): void
    {
        if ($expense->getAttribute('payee_type') !== 'supplier') {
            return;
        }

        foreach (SupplierBill::query()->where('company_id', $expense->getAttribute('company_id'))->where('supplier_id', $expense->getAttribute('supplier_id'))->cursor() as $bill) {
            if ($this->native->reference((string) $bill->getAttribute('supplier_invoice_number')) === $expense->getAttribute('document_reference')) {
                $this->fail('This document is already represented by a supplier bill.');
            }
        }
    }

    /** @param array<string, mixed> $snapshot */
    public function intent(array $snapshot, int $maker): string
    {
        return $this->canonical->hash(['contract' => self::VERSION, 'maker' => $maker, 'snapshot' => $snapshot]);
    }

    /** @return array<string, mixed> */
    public function recognition(Company $owner, AccountingExpense $expense, ?int $liability): array
    {
        if ($expense->getAttribute('status') !== 'approved' || $expense->getAttribute('approved_hash') !== $this->guard->intent($expense)) {
            $this->fail('Recognize an independently approved native expense only.');
        }

        $company = (int) $owner->getKey();
        $paid = $expense->getAttribute('settlement_mode') === 'paid_now';

        if ($paid === ($liability !== null)) {
            $this->fail('Paid-now uses its reviewed binding; unpaid expenses need a separately approved payable account.');
        }

        $cash = $paid ? $this->binding($company, (int) $expense->getAttribute('cash_account_id')) : null;

        if ($cash !== null && $this->canonical->hash($cash) !== $this->canonical->hash($expense->getAttribute('cash_snapshot'))) {
            $this->fail('Reviewed expense funding identity changed.');
        }

        $date = $expense->getAttribute('accounting_date')->format('Y-m-d');
        $roles = [];

        if ($cash !== null) {
            $roles[] = $cash['role'];
        }

        if ($expense->getAttribute('tax_account_id') !== null) {
            $roles[] = 'input_tax';
        }

        $map = $this->mappings->resolveForSource($owner, $date, $roles);

        if ($expense->getAttribute('tax_account_id') !== null && ($map['accounts']['input_tax'] ?? null) !== (int) $expense->getAttribute('tax_account_id')) {
            $this->fail('Reviewed recoverable tax GL must match the effective input-tax profile.');
        }

        if ($cash !== null && ($map['accounts'][$cash['role']] ?? null) !== $cash['account_id']) {
            $this->fail('Reviewed funding binding must match its effective cash profile.');
        }

        $this->billConflict($expense);

        return ['contract' => self::VERSION, 'company_id' => $company, 'expense_id' => (int) $expense->getKey(), 'expense_hash' => (string) $expense->getAttribute('approved_hash'), 'kind' => 'recognition', 'currency' => (string) $expense->getAttribute('currency_code'), 'date' => $date, 'origin_date' => $expense->getAttribute('document_date')->format('Y-m-d'), 'amount' => (string) $expense->getAttribute('total_amount'), 'net' => (string) $expense->getAttribute('net_amount'), 'tax' => (string) $expense->getAttribute('tax_amount'), 'tax_treatment' => (string) $expense->getAttribute('tax_treatment'), 'expense_account' => $this->native->account($company, (int) $expense->getAttribute('expense_account_id')), 'tax_account' => $expense->getAttribute('tax_account_id') === null ? null : $this->native->account($company, (int) $expense->getAttribute('tax_account_id'), true), 'liability' => $liability === null ? null : $this->liability($company, $liability), 'cash' => $cash, 'mapping' => ['version_id' => $map['version_id'], 'payload_hash' => $map['payload_hash']], 'payee_type' => $expense->getAttribute('payee_type'), 'payee_id' => $expense->getAttribute('payee_type') === 'supplier' ? (int) $expense->getAttribute('supplier_id') : ($expense->getAttribute('payee_type') === 'employee' ? (int) $expense->getAttribute('employee_id') : null), 'branch_id' => $expense->getAttribute('branch_id') === null ? null : (int) $expense->getAttribute('branch_id'), 'department_id' => $expense->getAttribute('department_id') === null ? null : (int) $expense->getAttribute('department_id'), 'payment_claim' => null, 'parent_plan' => null, 'parent_hash' => null];
    }

    /** @param array<string, mixed> $snapshot */
    public function live(Company $owner, array $snapshot): void
    {
        $company = (int) $owner->getKey();

        if ($owner->getAttribute('status') !== 'active' || $owner->getAttribute('currency_code') !== $snapshot['currency'] || $snapshot['date'] > $this->dates->today($owner)) {
            $this->fail('Expense posting requires an active company, company currency and a nonfuture date.');
        }

        if ($snapshot['kind'] === 'reversal') {
            $this->mappings->verifyRecordedProfile($company, $snapshot['mapping']['version_id'], $snapshot['currency'], AccountingDomainRegistry::VERSION, $snapshot['mapping']['payload_hash']);
            if ($snapshot['cash'] !== null && $this->canonical->hash($this->binding($company, $snapshot['cash']['id'], false)) !== $this->canonical->hash($snapshot['cash'])) {
                $this->fail('Original reversal cash identity changed.');
            }
            if ($snapshot['cash'] !== null && $snapshot['cash']['kind'] === 'bank') {
                app(AccountingBankFinalizationGuard::class)->assertDate($company, $snapshot['cash']['id'], $snapshot['date']);
            }

            return;
        }
        foreach (['liability', 'cash'] as $field) {
            if ($snapshot[$field] !== null) {
                $actual = $field === 'liability' ? $this->liability($company, $snapshot[$field]['id']) : $this->binding($company, $snapshot[$field]['id']);
                if ($this->canonical->hash($actual) !== $this->canonical->hash($snapshot[$field])) {
                    $this->fail('Reviewed payable/funding identity changed.');
                }
            }
        }

        $roles = $snapshot['cash'] === null ? [] : [$snapshot['cash']['role']];
        if ($snapshot['kind'] === 'recognition' && $snapshot['tax_account'] !== null) {
            $roles[] = 'input_tax';
        }

        $map = $this->mappings->resolveForSource($owner, $snapshot['date'], $roles);

        if ($map['version_id'] !== $snapshot['mapping']['version_id'] || $map['payload_hash'] !== $snapshot['mapping']['payload_hash']) {
            $this->fail('Effective profile changed after finance approval. Cancel and prepare a fresh instruction.');
        }

        if ($snapshot['cash'] !== null && (($map['accounts'][$snapshot['cash']['role']] ?? null) !== $snapshot['cash']['account_id'] || $snapshot['cash']['currency_code'] !== $snapshot['currency'])) {
            $this->fail('Effective cash mapping and binding differ.');
        }

        if ($snapshot['kind'] === 'recognition') {
            foreach (['expense_account' => false, 'tax_account' => true] as $field => $tax) {
                if ($snapshot[$field] !== null && $this->canonical->hash($this->native->account($company, $snapshot[$field]['id'], $tax)) !== $this->canonical->hash($snapshot[$field])) {
                    $this->fail('Expense/tax classification changed.');
                }
            }

            if ($snapshot['tax_account'] !== null && ($map['accounts']['input_tax'] ?? null) !== $snapshot['tax_account']['id']) {
                $this->fail('Input tax mapping changed.');
            }
        }

        if ($snapshot['cash'] !== null && $snapshot['cash']['kind'] === 'bank') {
            app(AccountingBankFinalizationGuard::class)->assertDate($company, $snapshot['cash']['id'], $snapshot['date']);
        }
    }

    public function fail(string $message): never
    {
        throw ValidationException::withMessages(['expense_finance' => $message]);
    }
}

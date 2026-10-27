<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Expenses;

use App\Models\Accounting\AccountingExpense;
use App\Models\Accounting\AccountingExpenseCategory;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use Illuminate\Support\Facades\DB;

final class AccountingExpenseGuard
{
    public function __construct(private readonly AccountingExpenseContract $contract, private readonly AccountingCanonicalPayload $canonical) {}

    public function category(AccountingExpenseCategory $row): void
    {
        $this->transaction();

        if (! preg_match('/\A[A-Z0-9][A-Z0-9_-]{0,29}\z/', (string) $row->getAttribute('code')) || trim((string) $row->getAttribute('name')) === '' || ! in_array($row->getAttribute('status'), ['active', 'inactive'], true)) {
            $this->fail();
        }

        if (! $row->exists) {
            if ((int) $row->getAttribute('revision') !== 1 || $row->getAttribute('history_head_id') !== null || $row->getAttribute('history_hash') !== null) {
                $this->fail();
            }
        } elseif ((int) $row->getAttribute('revision') !== (int) $row->getRawOriginal('revision') + 1 || $row->isDirty(['company_id', 'code', 'created_by', 'creation_key_hash', 'creation_request_hash', 'history_head_id', 'history_hash'])) {
            $this->fail();
        }
    }

    public function expense(AccountingExpense $row): void
    {
        $this->transaction();
        $this->review($row);
        $state = (string) $row->getAttribute('status');

        if (! in_array($state, ['draft', 'submitted', 'approved', 'posted', 'reversed', 'cancelled'], true)) {
            $this->fail();
        }

        if (! $row->exists) {
            if ($state !== 'draft' || (int) $row->getAttribute('revision') !== 1 || $row->getAttribute('history_head_id') !== null || $row->getAttribute('history_hash') !== null) {
                $this->fail();
            }
        } else {
            $old = (string) $row->getRawOriginal('status');

            if (in_array($old, ['cancelled', 'reversed'], true) || (int) $row->getAttribute('revision') !== (int) $row->getRawOriginal('revision') + 1 || $row->isDirty(['company_id', 'number', 'currency_code', 'created_by', 'creation_key_hash', 'creation_request_hash', 'history_head_id', 'history_hash'])) {
                $this->fail();
            }

            $allowed = match ($old) {
                'draft' => ['draft', 'submitted', 'cancelled'], 'submitted' => ['draft', 'approved', 'cancelled'], 'approved' => ['cancelled', 'posted'], 'posted' => ['reversed'], default => []
            };

            if (! in_array($state, $allowed, true)) {
                $this->fail();
            }

            if (! ($old === 'draft' && $state === 'draft')) {
                $fields = ['status', 'revision', 'updated_by', 'updated_at'];

                $fields = [...$fields, ...match ($state) {
                    'submitted' => ['submitted_by', 'submitted_at', 'submitted_hash'],
                    'approved' => ['approved_by', 'approved_at', 'approved_hash'],
                    'posted', 'reversed' => [],
                    'draft' => ['submitted_by', 'submitted_at', 'submitted_hash', 'approved_by', 'approved_at', 'approved_hash'],
                    default => ['cancelled_by', 'cancelled_at', 'cancellation_reason', 'active_claim_hash'],
                }];

                if (array_diff(array_keys($row->getDirty()), $fields) !== []) {
                    $this->fail();
                }
            }
        }
    }

    /** Current evidence only; historical reads never require currently active masters. */
    public function review(AccountingExpense $row): void
    {
        $state = (string) $row->getAttribute('status');

        if (! in_array($state, ['draft', 'submitted', 'approved', 'posted', 'reversed', 'cancelled'], true)) {
            $this->fail();
        }

        $money = $this->contract->amounts($row->getAttribute('net_amount'), $row->getAttribute('tax_amount'), (string) $row->getAttribute('tax_treatment'));

        foreach ($money as $field => $amount) {
            if ($row->getAttribute($field) !== $amount) {
                $this->fail();
            }
        }

        if (! preg_match('/\AEX-[0-9]{4}-[0-9]{6}\z/', (string) $row->getAttribute('number'))) {
            $this->fail();
        }

        $this->identities($row);
        $hash = $this->intent($row);

        if ($state === 'draft' && ($row->getAttribute('submitted_by') !== null || $row->getAttribute('submitted_at') !== null || $row->getAttribute('submitted_hash') !== null || $row->getAttribute('approved_by') !== null || $row->getAttribute('approved_at') !== null || $row->getAttribute('approved_hash') !== null)) {
            $this->fail();
        }

        if (in_array($state, ['submitted', 'approved', 'posted', 'reversed'], true) && ((int) $row->getAttribute('submitted_by') < 1 || $row->getAttribute('submitted_at') === null || $row->getAttribute('submitted_hash') !== $hash)) {
            $this->fail();
        }

        if ($state === 'submitted' && ($row->getAttribute('approved_by') !== null || $row->getAttribute('approved_at') !== null || $row->getAttribute('approved_hash') !== null)) {
            $this->fail();
        }

        if (in_array($state, ['approved', 'posted', 'reversed'], true) && ((int) $row->getAttribute('approved_by') < 1 || $row->getAttribute('approved_at') === null || $row->getAttribute('approved_hash') !== $hash || in_array((int) $row->getAttribute('approved_by'), [(int) $row->getAttribute('created_by'), (int) $row->getAttribute('last_edited_by'), (int) $row->getAttribute('submitted_by')], true))) {
            $this->fail();
        }

        if ($state === 'cancelled') {
            if ($row->getAttribute('active_claim_hash') !== null || (int) $row->getAttribute('cancelled_by') < 1 || $row->getAttribute('cancelled_at') === null || trim((string) $row->getAttribute('cancellation_reason')) === '') {
                $this->fail();
            }
        } elseif ($row->getAttribute('active_claim_hash') !== $row->getAttribute('claim_hash') || $row->getAttribute('cancelled_by') !== null || $row->getAttribute('cancelled_at') !== null || $row->getAttribute('cancellation_reason') !== null) {
            $this->fail();
        }
    }

    private function identities(AccountingExpense $row): void
    {
        $company = (int) $row->getAttribute('company_id');

        foreach (['supplier_id' => 'suppliers', 'employee_id' => 'employees', 'branch_id' => 'branches', 'department_id' => 'departments'] as $field => $table) {
            $id = $row->getAttribute($field);

            if ($id !== null && ! DB::table($table)->where('company_id', $company)->where('id', $id)->exists()) {
                $this->fail();
            }
        }

        $type = $row->getAttribute('payee_type');
        $mode = $row->getAttribute('settlement_mode');
        $payee = $row->getAttribute('payee_snapshot');

        if (! in_array($type, ['supplier', 'employee', 'other'], true) || ! in_array($mode, ['paid_now', 'unpaid', 'employee_reimbursement'], true) || ! is_array($payee) || ($payee['type'] ?? null) !== $type || ($payee['company_id'] ?? null) !== $company || ! is_string($payee['label'] ?? null) || trim($payee['label']) === '') {
            $this->fail();
        }

        $supplier = $row->getAttribute('supplier_id');
        $employee = $row->getAttribute('employee_id');

        $id = match ($type) {
            'supplier' => $supplier, 'employee' => $employee, default => null
        };

        if (($type === 'supplier' && ($supplier === null || $employee !== null)) || ($type === 'employee' && ($employee === null || $supplier !== null)) || ($type === 'other' && ($supplier !== null || $employee !== null)) || ($payee['id'] ?? null) !== ($id === null ? null : (int) $id) || ($mode === 'employee_reimbursement' && $type !== 'employee') || ($mode === 'unpaid' && $type === 'employee') || (($mode === 'paid_now') !== ($row->getAttribute('cash_account_id') !== null))) {
            $this->fail();
        }

        $claim = $this->contract->claim(['company_id' => $company, 'payee_type' => $type, 'supplier_id' => $supplier, 'employee_id' => $employee, 'payee_label' => $payee['label'], 'document_reference' => $row->getAttribute('document_reference')]);

        if ($claim !== $row->getAttribute('claim_hash')) {
            $this->fail();
        }

        $category = $row->getAttribute('category_snapshot');

        if (! is_array($category) || ($category['id'] ?? null) !== (int) $row->getAttribute('category_id') || ($category['company_id'] ?? null) !== $company || ($category['account_id'] ?? null) !== (int) $row->getAttribute('expense_account_id')) {
            $this->fail();
        }

        foreach (['cash' => 'cash_account_id', 'tax' => 'tax_account_id'] as $prefix => $field) {
            $snapshot = $row->getAttribute($prefix.'_snapshot');
            $foreign = $row->getAttribute($field);

            if ($foreign === null) {
                if ($snapshot !== null) {
                    $this->fail();
                }
            } elseif (! is_array($snapshot) || ($snapshot['id'] ?? null) !== (int) $foreign || ($snapshot['company_id'] ?? null) !== $company) {
                $this->fail();
            }
        }

        $taxLeg = $row->getAttribute('tax_treatment') === 'recoverable' && bccomp((string) $row->getAttribute('tax_amount'), '0', 2) > 0;

        if ($taxLeg !== ($row->getAttribute('tax_account_id') !== null)) {
            $this->fail();
        }
    }

    public function intent(AccountingExpense $row): string
    {
        $fields = ['company_id', 'number', 'currency_code', 'document_reference', 'category_id', 'expense_account_id', 'category_snapshot', 'payee_type', 'supplier_id', 'employee_id', 'payee_snapshot', 'branch_id', 'department_id', 'settlement_mode', 'cash_account_id', 'cash_snapshot', 'net_amount', 'tax_amount', 'total_amount', 'tax_treatment', 'tax_account_id', 'tax_snapshot', 'description', 'claim_hash', 'created_by', 'last_edited_by'];
        $data = ['contract' => AccountingExpenseContract::VERSION];

        foreach ($fields as $field) {
            $value = $row->getAttribute($field);
            if (in_array($field, ['company_id', 'category_id', 'expense_account_id', 'supplier_id', 'employee_id', 'branch_id', 'department_id', 'cash_account_id', 'tax_account_id', 'created_by', 'last_edited_by'], true) && $value !== null) {
                $value = (int) $value;
            }
            $data[$field] = $value;
        }

        foreach (['document_date', 'accounting_date'] as $field) {
            $date = $row->getAttribute($field);
            if (! $date instanceof \DateTimeInterface) {
                $this->fail();
            }
            $data[$field] = $date->format('Y-m-d');
        }

        if ((int) $row->getAttribute('attachment_revision') > 0) {
            $data['evidence'] = ['contract' => AccountingExpenseAttachmentLedger::VERSION, 'revision' => (int) $row->getAttribute('attachment_revision'), 'head_id' => (int) $row->getAttribute('attachment_head_id'), 'hash' => $row->getAttribute('attachment_hash')];
        }

        return $this->canonical->hash($data);
    }

    private function transaction(): void
    {
        if (DB::transactionLevel() < 1) {
            $this->fail();
        }
    }

    private function fail(): never
    {
        throw new \LogicException('Expense identity, exact amounts, review stamps and native state transition must agree. Posting requires its later integration contract.');
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Adjustments;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingBankAdjustment;
use App\Models\Accounting\AccountingCashAccount;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingDecimal;
use Illuminate\Validation\ValidationException;

final class AccountingBankAdjustmentContract
{
    public const VERSION = 'bank-adjustment.v1';

    public const COUNTER_ROLE = 'bank_adjustment_counter';

    public const PERMISSIONS = ['erp.bank-adjustments.view', 'erp.bank-adjustments.create', 'erp.bank-adjustments.edit', 'erp.bank-adjustments.submit', 'erp.bank-adjustments.approve', 'erp.bank-adjustments.cancel', 'erp.bank-adjustments.post', 'erp.bank-adjustments.reverse'];

    public function __construct(private readonly AccountingCanonicalPayload $canonical, private readonly AccountingDecimal $decimal) {}

    public function key(string $key): string
    {
        if (! preg_match('/\A[A-Za-z0-9_-]{16,64}\z/', $key)) {
            $this->fail('Use a stable opaque request key of 16 to 64 letters, digits, underscores or hyphens.');
        }

        return hash('sha256', $key);
    }

    public function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000 || ! mb_check_encoding($reason, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)) {
            $this->fail('Provide a plain-text adjustment reason of up to 2,000 characters.');
        }

        return $reason;
    }

    /** @return array<string, mixed> */
    public function binding(AccountingCashAccount $row): array
    {
        if ($row->getAttribute('kind') !== 'bank') {
            $this->fail('Bank adjustments require a bank finance binding.');
        }

        return ['id' => (int) $row->getKey(), 'company_id' => (int) $row->getAttribute('company_id'), 'account_id' => (int) $row->getAttribute('account_id'), 'role' => (string) $row->getAttribute('role'), 'kind' => 'bank', 'currency_code' => (string) $row->getAttribute('currency_code')];
    }

    /** Current classification is independently selected and frozen, without changing published profile requirements. @return array<string, mixed> */
    public function counter(int $company, int $id, string $kind, bool $live): array
    {
        $row = Account::query()->where('company_id', $company)->findOrFail($id);
        $group = AccountGroup::query()->where('company_id', $company)->findOrFail((int) $row->getAttribute('account_group_id'));
        $category = $kind === 'charge' ? 'expense' : 'revenue';
        $roles = $kind === 'charge' ? ['operating_expense', 'other_expense'] : ['other_revenue'];
        $normal = $kind === 'charge' ? 'debit' : 'credit';
        if (! in_array($kind, ['charge', 'interest'], true) || $group->getAttribute('category') !== $category || ! in_array($row->getAttribute('statement_role'), $roles, true) || $row->getAttribute('normal_side') !== $normal || $row->getAttribute('control_role') !== null || ! in_array($row->getAttribute('cash_flow_role'), ['operating', 'investing', 'financing'], true)) {
            $this->fail('Choose an ordinary classified bank expense or other-revenue interest account, with an explicit cash-flow classification. Control, cash and sales-revenue accounts are not bank adjustment counters.');
        }
        if ($live && ($row->getAttribute('status') !== 'active' || ! (bool) $row->getAttribute('is_postable') || Account::query()->where('company_id', $company)->where('parent_id', $id)->exists())) {
            $this->fail('The counter must be an active posting leaf.');
        }

        return ['id' => $id, 'company_id' => $company, 'account_group_id' => (int) $group->getKey(), 'normal_side' => $normal, 'statement_role' => (string) $row->getAttribute('statement_role'), 'cash_flow_role' => (string) $row->getAttribute('cash_flow_role')];
    }

    public function intent(AccountingBankAdjustment $row): string
    {
        $amount = $this->decimal->money((string) $row->getAttribute('amount'));
        if (bccomp($amount, '0', 2) <= 0 || ! in_array($row->getAttribute('kind'), ['charge', 'interest'], true)) {
            $this->fail('Bank adjustments require a positive exact amount and known kind.');
        }

        return $this->canonical->hash(['version' => self::VERSION, 'company_id' => (int) $row->getAttribute('company_id'), 'cash_account_id' => (int) $row->getAttribute('cash_account_id'), 'statement_line_id' => (int) $row->getAttribute('statement_line_id'),
            'kind' => (string) $row->getAttribute('kind'), 'counter_account_id' => (int) $row->getAttribute('counter_account_id'), 'reverses_id' => $row->getAttribute('reverses_id') === null ? null : (int) $row->getAttribute('reverses_id'),
            'original_hash' => $row->getAttribute('original_hash'), 'mapping_version_id' => (int) $row->getAttribute('mapping_version_id'), 'mapping_hash' => (string) $row->getAttribute('mapping_hash'),
            'amount' => $amount, 'currency_code' => (string) $row->getAttribute('currency_code'), 'document_date' => $row->getAttribute('document_date')->format('Y-m-d'), 'reason' => $this->reason((string) $row->getAttribute('reason')),
            'source_snapshot' => $row->getAttribute('source_snapshot'), 'binding_snapshot' => $row->getAttribute('binding_snapshot'), 'counter_snapshot' => $row->getAttribute('counter_snapshot'), 'created_by' => (int) $row->getAttribute('created_by'), 'last_edited_by' => (int) $row->getAttribute('last_edited_by')]);
    }

    public function review(AccountingBankAdjustment $row): void
    {
        $state = $row->getAttribute('status');
        $hash = $this->intent($row);
        $submitted = $row->getAttribute('submitted_by') !== null;
        $approved = $row->getAttribute('approved_by') !== null;
        if (! in_array($state, ['draft', 'submitted', 'approved', 'posted', 'cancelled'], true) || ($state === 'draft' && ($submitted || $approved)) || ($state === 'submitted' && (! $submitted || $approved)) || (in_array($state, ['approved', 'posted'], true) && (! $submitted || ! $approved))) {
            $this->fail('Stored adjustment review state is inconsistent.');
        }
        if ($state === 'cancelled') {
            if ($row->getAttribute('active_statement_line_id') !== null || $row->getAttribute('active_reversal_of_id') !== null || (int) $row->getAttribute('cancelled_by') < 1 || $row->getAttribute('cancelled_at') === null || trim((string) $row->getAttribute('cancellation_reason')) === '') {
                $this->fail('Cancelled adjustment evidence changed.');
            }
        } elseif ((int) $row->getAttribute('active_statement_line_id') !== (int) $row->getAttribute('statement_line_id') || ($row->getAttribute('reverses_id') === null ? $row->getAttribute('active_reversal_of_id') !== null : (int) $row->getAttribute('active_reversal_of_id') !== (int) $row->getAttribute('reverses_id')) || $row->getAttribute('cancelled_at') !== null || $row->getAttribute('cancelled_by') !== null || $row->getAttribute('cancellation_reason') !== null) {
            $this->fail('Adjustment source/reversal claim evidence changed.');
        }
        if ($submitted && ((int) $row->getAttribute('submitted_by') < 1 || $row->getAttribute('submitted_at') === null || $row->getAttribute('submitted_hash') !== $hash)) {
            $this->fail('Submitted adjustment intent changed.');
        }
        if ($approved && ((int) $row->getAttribute('approved_by') < 1 || ! $submitted || $row->getAttribute('approved_at') === null || $row->getAttribute('approved_hash') !== $hash || in_array((int) $row->getAttribute('approved_by'), [(int) $row->getAttribute('created_by'), (int) $row->getAttribute('last_edited_by'), (int) $row->getAttribute('submitted_by')], true))) {
            $this->fail('Approval must match the submitted intent and be independent of maker, editor and submitter.');
        }
        if (! $submitted && ($row->getAttribute('submitted_at') !== null || $row->getAttribute('submitted_hash') !== null)) {
            $this->fail('Unexpected submission metadata.');
        }
        if (! $approved && ($row->getAttribute('approved_at') !== null || $row->getAttribute('approved_hash') !== null)) {
            $this->fail('Unexpected approval metadata.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['bank_adjustment' => $message]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\AccountingExpense;
use App\Models\Accounting\AccountingExpenseFinanceOperation;
use App\Models\Accounting\AccountingExpenseFinancePlan;
use App\Models\Accounting\JournalEntryLine;
use App\Services\ERP\Accounting\Books\AccountingCashBookSourceEvidence;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseFinanceEvidence;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ERP\AccountingExpenseWorkspaceTestCase;

final class AccountingExpenseReversalPostingTest extends AccountingExpenseWorkspaceTestCase
{
    private function reversal(AccountingExpense $expense, AccountingExpenseFinancePlan $original, string $date = '2026-10-26'): AccountingExpenseFinancePlan
    {
        return $this->finance->create($this->maker, ['expense_id' => (int) $expense->getKey(), 'native_revision' => (int) $expense->refresh()->getAttribute('revision'), 'kind' => 'reversal', 'original_plan_id' => (int) $original->getKey(), 'date' => $date, 'key' => (string) Str::uuid(), 'reason' => 'Full expense compensation']);
    }

    public function test_paid_now_reversal_refunds_original_cash_and_preserves_original_operation(): void
    {
        $expense = $this->expense(['settlement_mode' => 'paid_now', 'cash_account_id' => $this->cash->getKey()]);
        $original = $this->recognized($expense);
        $before = $original->getAttributes();
        $reverse = $this->postPlan($this->approved($this->reversal($expense, $original)));
        $this->assertSame('100.27', $this->side((int) $reverse->getKey(), (int) $this->cash->getAttribute('account_id'), 'debit'));
        $this->assertSame('100.27', $this->side((int) $reverse->getKey(), $this->mapping['general_expense'], 'credit'));
        $this->assertSame($before, $original->refresh()->getAttributes());
        $this->assertSame('reversed', $expense->refresh()->getAttribute('status'));
        $this->assertDatabaseCount('accounting_expense_finance_operations', 2);
    }

    public function test_unpaid_recognition_reversal_clears_payable_without_cash(): void
    {
        $expense = $this->expense();
        $original = $this->recognized($expense);
        $reverse = $this->postPlan($this->approved($this->reversal($expense, $original)));
        $this->assertSame('100.27', $this->side((int) $reverse->getKey(), (int) $this->liability->getKey(), 'debit'));
        $this->assertSame('reversed', $this->finance->payable($this->maker, (int) $expense->getKey())['financial_status']);
        $this->assertSame(0, JournalEntryLine::query()->where('source_line_type', 'expense_finance')->where('source_line_id', $reverse->getKey())->whereNotNull('cash_account_id')->count());
    }

    public function test_payment_reversal_restores_payable_without_second_expense(): void
    {
        $expense = $this->expense();
        $this->recognized($expense);
        $payment = $this->postPlan($this->approved($this->settle($expense, '40.13')));
        $reverse = $this->postPlan($this->approved($this->reversal($expense, $payment)));
        $position = $this->finance->payable($this->maker, (int) $expense->getKey());
        $this->assertSame('100.27', $position['outstanding']);
        $this->assertSame('0.00', $position['settled']);
        $this->assertSame('0.00', $this->side((int) $reverse->getKey(), $this->mapping['general_expense'], 'credit'));
        $this->assertSame('posted', $expense->refresh()->getAttribute('status'));
    }

    public function test_recognition_reversal_requires_payment_compensation_first(): void
    {
        $expense = $this->expense();
        $original = $this->recognized($expense);
        $payment = $this->postPlan($this->approved($this->settle($expense)));
        $this->invalid(fn () => $this->reversal($expense, $original));
        $this->postPlan($this->approved($this->reversal($expense, $payment)));
        $reverse = $this->postPlan($this->approved($this->reversal($expense, $original)));
        $this->assertSame('reversal', $reverse->getAttribute('kind'));
        $this->assertSame('reversed', $expense->refresh()->getAttribute('status'));
    }

    public function test_dependency_is_rechecked_if_payment_posts_after_reversal_approval(): void
    {
        $expense = $this->expense();
        $original = $this->recognized($expense);
        $reverse = $this->approved($this->reversal($expense, $original));
        $this->postPlan($this->approved($this->settle($expense)));
        $this->invalid(fn () => $this->postPlan($reverse));
        $this->assertSame('approved', $reverse->refresh()->getAttribute('status'));
        $this->assertSame('posted', $expense->refresh()->getAttribute('status'));
    }

    public function test_inactive_original_binding_is_allowed_only_for_exact_refund(): void
    {
        $expense = $this->expense(['settlement_mode' => 'paid_now', 'cash_account_id' => $this->cash->getKey()]);
        $original = $this->recognized($expense);
        $this->cash->update(['status' => 'inactive']);
        $reverse = $this->postPlan($this->approved($this->reversal($expense, $original)));
        $this->assertSame('100.27', $this->side((int) $reverse->getKey(), (int) $this->cash->getAttribute('account_id'), 'debit'));
    }

    public function test_recoverable_tax_reversal_credits_original_tax_account(): void
    {
        $expense = $this->expense(['tax_amount' => '15.03', 'tax_treatment' => 'recoverable', 'tax_account_id' => $this->mapping['input_tax']]);
        $original = $this->recognized($expense);
        $reverse = $this->postPlan($this->approved($this->reversal($expense, $original)));
        $this->assertSame('15.03', $this->side((int) $reverse->getKey(), $this->mapping['input_tax'], 'credit'));
        $this->assertSame('115.30', $this->side((int) $reverse->getKey(), (int) $this->liability->getKey(), 'debit'));
    }

    public function test_duplicate_active_reversal_request_is_blocked(): void
    {
        $expense = $this->expense();
        $original = $this->recognized($expense);
        $this->reversal($expense, $original);
        $this->invalid(fn () => $this->reversal($expense, $original));
        $this->assertDatabaseCount('accounting_expense_finance_plans', 2);
    }

    public function test_cancelled_reversal_reservation_can_be_replaced(): void
    {
        $expense = $this->expense();
        $original = $this->recognized($expense);
        $request = $this->reversal($expense, $original);
        $this->finance->change($this->maker, (int) $request->getKey(), 1, 'cancel', 'Correct the instruction');
        $next = $this->reversal($expense, $original);
        $this->assertNotSame($request->getKey(), $next->getKey());
    }

    public function test_reversal_of_reversal_is_rejected(): void
    {
        $expense = $this->expense();
        $original = $this->recognized($expense);
        $reverse = $this->postPlan($this->approved($this->reversal($expense, $original)));
        $this->invalid(fn () => $this->reversal($expense, $reverse));
    }

    public function test_reversal_cannot_change_amount_or_funding(): void
    {
        $expense = $this->expense();
        $original = $this->recognized($expense);
        $this->invalid(fn () => $this->finance->create($this->maker, ['expense_id' => $expense->getKey(), 'native_revision' => $expense->refresh()->getAttribute('revision'), 'kind' => 'reversal', 'original_plan_id' => $original->getKey(), 'date' => '2026-10-26', 'amount' => '1.00', 'cash_account_id' => $this->cash->getKey(), 'key' => (string) Str::uuid(), 'reason' => 'Invalid altered compensation']));
    }

    public function test_cross_expense_original_id_is_rejected(): void
    {
        $a = $this->expense();
        $original = $this->recognized($a);
        $b = $this->expense();
        $this->recognized($b);
        $this->invalid(fn () => $this->reversal($b, $original));
    }

    public function test_reversal_maker_cannot_approve(): void
    {
        $expense = $this->expense();
        $original = $this->recognized($expense);
        $reverse = $this->reversal($expense, $original);
        $this->expectException(AuthorizationException::class);
        $this->finance->change($this->maker, (int) $reverse->getKey(), 1, 'approve', 'Own compensation');
    }

    public function test_closed_period_blocks_compensation_and_rolls_back_reversal(): void
    {
        $expense = $this->expense();
        $original = $this->recognized($expense);
        $reverse = $this->approved($this->reversal($expense, $original));
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->update(['state' => 'closed']);
        $this->invalid(fn () => $this->postPlan($reverse));
        $this->assertSame('approved', $reverse->refresh()->getAttribute('status'));
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_new_compensation_cannot_bypass_disabled_accounting(): void
    {
        $expense = $this->expense();
        $original = $this->recognized($expense);
        $reverse = $this->approved($this->reversal($expense, $original));
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['state' => 'disabled']);
        $this->invalid(fn () => $this->postPlan($reverse));
        $this->assertSame('posted', $expense->refresh()->getAttribute('status'));
        $this->assertDatabaseCount('accounting_expense_finance_operations', 1);
    }

    public function test_compensation_replay_and_original_replay_survive_reversal(): void
    {
        $expense = $this->expense();
        $original = $this->approved($this->instruction($expense));
        $originalKey = (string) Str::uuid();
        $original = $this->postPlan($original, $originalKey);
        $reverse = $this->approved($this->reversal($expense, $original));
        $reverseKey = (string) Str::uuid();
        $reverse = $this->postPlan($reverse, $reverseKey);
        DB::table('accounting_periods')->where('company_id', $this->company->getKey())->update(['state' => 'closed']);
        $this->finance->change($this->maker, (int) $original->getKey(), 2, 'post', 'Original retry', $originalKey);
        $this->finance->change($this->maker, (int) $reverse->getKey(), 2, 'post', 'Reversal retry', $reverseKey);
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_missing_compensation_manifest_is_not_repaired(): void
    {
        $expense = $this->expense();
        $original = $this->recognized($expense);
        $reverse = $this->approved($this->reversal($expense, $original));
        $key = (string) Str::uuid();
        $reverse = $this->postPlan($reverse, $key);
        DB::table('accounting_expense_finance_operations')->where('plan_id', $reverse->getKey())->delete();
        try {
            $this->finance->change($this->maker, (int) $reverse->getKey(), 2, 'post', 'Retry damaged compensation', $key);
            $this->fail('Missing manifest must fail.');
        } catch (ModelNotFoundException|ValidationException) {
            $this->assertDatabaseCount('accounting_expense_finance_operations', 1);
        } $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_manifest_failure_rolls_back_compensation_and_native_reversed_state(): void
    {
        $expense = $this->expense();
        $original = $this->recognized($expense);
        $reverse = $this->approved($this->reversal($expense, $original));
        $active = true;
        AccountingExpenseFinanceOperation::creating(static function ($row) use (&$active, $reverse): void {
            if ($active && (int) $row->getAttribute('plan_id') === (int) $reverse->getKey()) {
                throw new \RuntimeException('Injected reverse manifest failure');
            }
        });
        try {
            $this->postPlan($reverse);
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected reverse manifest failure', $e->getMessage());
        } finally {
            $active = false;
        } $this->assertSame('posted', $expense->refresh()->getAttribute('status'));
        $this->assertSame('approved', $reverse->refresh()->getAttribute('status'));
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_reversed_recognition_blocks_later_payment(): void
    {
        $expense = $this->expense();
        $original = $this->recognized($expense);
        $this->postPlan($this->approved($this->reversal($expense, $original)));
        $this->invalid(fn () => $this->settle($expense));
        $this->assertSame('0.00', $this->finance->payable($this->maker, (int) $expense->getKey())['outstanding']);
    }

    public function test_cashbook_uses_refund_debit_from_verified_compensation(): void
    {
        $expense = $this->expense(['settlement_mode' => 'paid_now', 'cash_account_id' => $this->cash->getKey()]);
        $original = $this->recognized($expense);
        $reverse = $this->postPlan($this->approved($this->reversal($expense, $original)));
        $proof = app(AccountingExpenseFinanceEvidence::class)->verify((int) $this->company->getKey(), (int) $reverse->getKey());
        $book = app(AccountingCashBookSourceEvidence::class)->verify($this->maker, $this->cash, $proof['journal']);
        $this->assertSame('100.27', $book['debit']);
        $this->assertSame('0.00', $book['credit']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\JournalEntry;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\AccountingPeriodStateService;
use App\Services\ERP\Accounting\Reports\AccountingBalanceSheetWorkspace;
use App\Services\ERP\Accounting\Reports\AccountingCashFlowReader;
use App\Services\ERP\Accounting\Reports\AccountingLedgerReader;
use App\Services\ERP\Accounting\Reports\AccountingProfitLossWorkspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ERP\AccountingYearEndTestCase;

final class AccountingYearEndWorkflowTest extends AccountingYearEndTestCase
{
    /** @return array<string, mixed> */
    private function profit(): array
    {
        return app(AccountingProfitLossWorkspace::class)->page($this->maker, ['company_id' => $this->company->getKey(), 'from' => '2026-01-01', 'to' => '2026-10-31'])['report'];
    }

    public function test_open_period_blocks_preparation(): void
    {
        $this->assertFalse($this->yearReview()['ready']);
        $this->invalid(fn () => $this->prepareYear());
    }

    public function test_zero_balance_year_records_no_journal_and_closes_atomically(): void
    {
        $this->closeMonth();
        $this->prepareYear();
        $this->approveYear();
        $e = $this->postYear();
        $this->assertNull($e->getAttribute('journal_entry_id'));
        $this->assertSame('zero_nominal_balances', $e->getAttribute('payload')['no_journal_reason']);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertSame('closed', $this->endYear->fresh()->getAttribute('state'));
    }

    public function test_preparer_cannot_approve_their_own_plan(): void
    {
        $this->closeMonth();
        $this->prepareYear();
        $this->expectException(AuthorizationException::class);
        $this->approveYear('close', $this->maker);
    }

    public function test_loss_debits_retained_earnings_and_clears_nominal_balance(): void
    {
        $e = $this->closeExpenseYear();
        $lines = DB::table('journal_entry_lines')->where('journal_entry_id', $e->getAttribute('journal_entry_id'))->get();
        $retained = $lines->firstWhere('account_id', $this->mapping['retained_earnings']);
        $this->assertSame('100.27', bcadd((string) $retained->debit, '0', 2));
        $this->assertSame('0.00', $this->profit()['net_revenue']);
        $this->assertSame('-100.27', $this->profit()['provisional_result']);
    }

    public function test_profit_credits_retained_earnings(): void
    {
        $this->income();
        $this->closeMonth();
        $this->prepareYear();
        $this->approveYear();
        $e = $this->postYear();
        $line = DB::table('journal_entry_lines')->where('journal_entry_id', $e->getAttribute('journal_entry_id'))->where('account_id', $this->mapping['retained_earnings'])->first();
        $this->assertSame('125.01', bcadd((string) $line->credit, '0', 2));
        $this->assertSame('125.01', $this->profit()['provisional_result']);
    }

    public function test_zero_profit_with_offsetting_activity_still_posts_nominal_clearing(): void
    {
        $this->income('100.27');
        $this->recognized($this->expense());
        $this->closeMonth();
        $this->prepareYear();
        $this->approveYear();
        $e = $this->postYear();
        $this->assertNotNull($e->getAttribute('journal_entry_id'));
        $this->assertSame(2, DB::table('journal_entry_lines')->where('journal_entry_id', $e->getAttribute('journal_entry_id'))->count());
    }

    public function test_trial_balance_includes_close_but_profit_loss_keeps_operating_history(): void
    {
        $e = $this->closeExpenseYear();
        $report = app(AccountingLedgerReader::class)->trial($this->maker, (int) $this->company->getKey(), ['from' => '2026-01-01', 'to' => '2026-10-31']);
        $accounts = array_column($report['accounts'], null, 'id');
        $this->assertTrue($report['balanced']);
        $this->assertSame('0.00', $accounts[$this->mapping['general_expense']]['closing_debit']);
        $this->assertSame('100.27', $accounts[$this->mapping['retained_earnings']]['closing_debit']);
        $this->assertSame('-100.27', $this->profit()['provisional_result']);
    }

    public function test_balance_sheet_does_not_double_count_posted_retained_earnings(): void
    {
        $this->closeExpenseYear();
        $r = app(AccountingBalanceSheetWorkspace::class)->page($this->maker, ['company_id' => $this->company->getKey(), 'as_of' => '2026-10-31'])['report'];
        $this->assertTrue($r['equation_balanced']);
        $this->assertSame('0.00', $r['earnings']['residual_total']);
        $this->assertSame('-100.27', $r['totals']['posted_equity']);
    }

    public function test_cash_flow_result_and_flows_are_unchanged_by_year_end_transfer(): void
    {
        $this->recognized($this->expense());
        $reader = app(AccountingCashFlowReader::class);
        $filters = ['from' => '2026-01-01', 'to' => '2026-10-31'];
        $before = $reader->report($this->maker, (int) $this->company->getKey(), $filters);
        $this->closeMonth();
        $this->prepareYear();
        $this->approveYear();
        $this->postYear();
        $after = $reader->report($this->maker, (int) $this->company->getKey(), $filters);
        $this->assertSame($before['provisional_flows'], $after['provisional_flows']);
        $this->assertSame($before['indirect_bridge']['period_result'], $after['indirect_bridge']['period_result']);
    }

    public function test_same_post_request_replays_without_new_journal_or_event(): void
    {
        $this->recognized($this->expense());
        $this->closeMonth();
        $this->prepareYear();
        $this->approveYear();
        $command = $this->yearCommand();
        $one = $this->postYear('close', null, $command);
        $count = DB::table('journal_entries')->count();
        $two = $this->postYear('close', null, $command);
        $this->assertSame($one->getKey(), $two->getKey());
        $this->assertSame($count, DB::table('journal_entries')->count());
        $this->assertDatabaseCount('accounting_year_end_events', 3);
    }

    public function test_financial_replay_survives_disabled_current_settings(): void
    {
        $this->recognized($this->expense());
        $this->closeMonth();
        $this->prepareYear();
        $this->approveYear();
        $command = $this->yearCommand();
        $one = $this->postYear('close', null, $command);
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['state' => 'disabled']);
        $this->assertSame($one->getKey(), $this->postYear('close', null, $command)->getKey());
    }

    public function test_key_reuse_with_changed_command_is_rejected(): void
    {
        $this->closeMonth();
        $command = $this->yearCommand();
        $this->yearWorkflow->change($this->maker, (int) $this->company->getKey(), (int) $this->endYear->getKey(), 'close', 'prepare', $command);
        $this->invalid(fn () => $this->yearWorkflow->change($this->maker, (int) $this->company->getKey(), (int) $this->endYear->getKey(), 'close', 'prepare', [...$command, 'reason' => 'Different']));
    }

    public function test_account_change_after_approval_blocks_posting(): void
    {
        $this->closeMonth();
        $this->prepareYear();
        $this->approveYear();
        DB::table('accounts')->where('id', $this->mapping['general_expense'])->update(['name' => 'Changed after approval']);
        $this->invalid(fn () => $this->postYear());
        $this->assertSame('open', $this->endYear->fresh()->getAttribute('state'));
    }

    public function test_outer_rollback_preserves_approved_state_and_reverts_journal_numbering(): void
    {
        $this->recognized($this->expense());
        $this->closeMonth();
        $this->prepareYear();
        $this->approveYear();
        $before = DB::table('journal_entries')->count();
        try {
            DB::transaction(function (): void {
                $this->postYear();
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('rollback', $e->getMessage());
        } $this->assertSame('open', $this->endYear->fresh()->getAttribute('state'));
        $this->assertDatabaseCount('accounting_year_end_events', 2);
        $this->assertSame($before, DB::table('journal_entries')->count());
    }

    public function test_original_closer_cannot_execute_reopening(): void
    {
        $this->closeExpenseYear();
        $this->prepareYear('reopen');
        $this->approveYear('reopen');
        $this->expectException(AuthorizationException::class);
        $this->postYear('reopen', $this->maker);
    }

    public function test_reopening_exactly_compensates_original_journal_and_keeps_period_closed(): void
    {
        $original = $this->closeExpenseYear();
        $this->prepareYear('reopen');
        $this->approveYear('reopen');
        $reverse = $this->postYear('reopen');
        $header = JournalEntry::query()->findOrFail($reverse->getAttribute('journal_entry_id'));
        $this->assertSame((int) $original->getAttribute('journal_entry_id'), (int) $header->getAttribute('reverses_journal_id'));
        $this->assertSame('open', $this->endYear->fresh()->getAttribute('state'));
        $this->assertSame('hard_closed', $this->closePeriod->fresh()->getAttribute('state'));
        $this->assertSame('-100.27', $this->profit()['provisional_result']);
    }

    public function test_reopening_uses_original_inactive_retained_account(): void
    {
        $original = $this->closeExpenseYear();
        DB::table('accounts')->where('id', $this->mapping['retained_earnings'])->update(['status' => 'inactive']);
        $this->prepareYear('reopen');
        $this->approveYear('reopen');
        $e = $this->postYear('reopen');
        $this->assertTrue(DB::table('journal_entry_lines')->where('journal_entry_id', $e->getAttribute('journal_entry_id'))->where('account_id', $this->mapping['retained_earnings'])->exists());
    }

    public function test_reclose_creates_a_new_exact_transfer_without_duplicate_net_earnings(): void
    {
        $this->closeExpenseYear();
        $this->prepareYear('reopen');
        $this->approveYear('reopen');
        $this->postYear('reopen');
        $this->prepareYear();
        $this->approveYear();
        $this->postYear();
        $r = app(AccountingLedgerReader::class)->trial($this->maker, (int) $this->company->getKey(), ['from' => '2026-01-01', 'to' => '2026-10-31']);
        $accounts = array_column($r['accounts'], null, 'id');
        $this->assertSame('100.27', $accounts[$this->mapping['retained_earnings']]['closing_debit']);
        $this->assertSame(2, JournalEntry::query()->where('entry_kind', 'closing')->count());
        $this->assertSame(1, JournalEntry::query()->where('entry_kind', 'closing_reversal')->count());
    }

    public function test_zero_nominal_close_and_reopen_never_create_zero_value_journals(): void
    {
        $this->closeMonth();
        $this->prepareYear();
        $this->approveYear();
        $this->postYear();
        $this->prepareYear('reopen');
        $this->approveYear('reopen');
        $this->postYear('reopen');
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertSame('open', $this->endYear->fresh()->getAttribute('state'));
    }

    public function test_deleted_history_tail_is_not_rebuilt(): void
    {
        $this->closeMonth();
        $this->prepareYear();
        DB::table('accounting_year_end_events')->delete();
        $this->invalid(fn () => $this->yearReview());
        $this->assertDatabaseCount('accounting_year_end_events', 0);
    }

    public function test_corrupt_closing_journal_blocks_replay_without_reposting(): void
    {
        $this->recognized($this->expense());
        $this->closeMonth();
        $this->prepareYear();
        $this->approveYear();
        $command = $this->yearCommand();
        $e = $this->postYear('close', null, $command);
        DB::table('journal_entries')->where('id', $e->getAttribute('journal_entry_id'))->update(['payload_hash' => str_repeat('0', 64)]);
        $before = DB::table('journal_entries')->count();
        $this->invalid(fn () => $this->postYear('close', null, $command));
        $this->assertSame($before, DB::table('journal_entries')->count());
    }

    public function test_no_next_year_opening_is_generated(): void
    {
        $this->closeExpenseYear();
        $this->assertSame(0, JournalEntry::query()->where('entry_kind', 'opening')->count());
    }

    public function test_reclose_after_independently_reopening_and_correcting_period_uses_new_result(): void
    {
        $this->closeExpenseYear();
        $this->prepareYear('reopen');
        $this->approveYear('reopen');
        $this->postYear('reopen');
        $period = $this->closePeriod->fresh();
        app(AccountingPeriodStateService::class)->reopen($this->reviewer, (int) $this->company->getKey(), (int) $period->getAttribute('fiscal_year_id'), (int) $period->getKey(), ['revision' => (int) $period->getAttribute('revision'), 'reason' => 'Review additional expense']);
        $this->recognized($this->expense());
        $this->closeMonth();
        $this->prepareYear();
        $this->approveYear();
        $this->postYear();
        $r = app(AccountingLedgerReader::class)->trial($this->maker, (int) $this->company->getKey(), ['from' => '2026-01-01', 'to' => '2026-10-31']);
        $a = array_column($r['accounts'], null, 'id');
        $this->assertSame('200.54', $a[$this->mapping['retained_earnings']]['closing_debit']);
        $this->assertSame('-200.54', $this->profit()['provisional_result']);
    }

    public function test_original_post_replay_after_reopening_does_not_close_the_year_again(): void
    {
        $this->recognized($this->expense());
        $this->closeMonth();
        $this->prepareYear();
        $this->approveYear();
        $command = $this->yearCommand();
        $original = $this->postYear('close', null, $command);
        $this->prepareYear('reopen');
        $this->approveYear('reopen');
        $this->postYear('reopen');
        $count = DB::table('journal_entries')->count();
        $this->assertSame($original->getKey(), $this->postYear('close', null, $command)->getKey());
        $this->assertSame('open', $this->endYear->fresh()->getAttribute('state'));
        $this->assertSame($count, DB::table('journal_entries')->count());
    }

    public function test_json_object_key_reordering_preserves_journal_proof_and_replay(): void
    {
        $this->recognized($this->expense());
        $this->closeMonth();
        $prep = $this->prepareYear();
        $this->approveYear();
        $command = $this->yearCommand();
        $original = $this->postYear('close', null, $command);
        $reorder = function (mixed $value) use (&$reorder): mixed {
            if (! is_array($value)) {
                return $value;
            } foreach ($value as $key => $item) {
                $value[$key] = $reorder($item);
            } if (! array_is_list($value)) {
                krsort($value, SORT_STRING);
            }

            return $value;
        };
        DB::table('accounting_year_end_events')->where('id', $prep->getKey())->update(['payload' => json_encode($reorder($prep->getAttribute('payload')), JSON_THROW_ON_ERROR)]);
        $this->assertSame($original->getKey(), $this->postYear('close', null, $command)->getKey());
    }

    public function test_subsequent_explicit_opening_blocks_reopening_instead_of_duplicating_carryforward(): void
    {
        $this->closeExpenseYear();
        $calendar = app(AccountingCalendarService::class);
        $year = $calendar->saveYear($this->maker, ['company_id' => $this->company->getKey(), 'code' => 'NEXT-OB', 'name' => 'Next', 'starts_on' => '2026-11-01', 'ends_on' => '2027-10-31', 'reason' => 'Opening dependency']);
        $period = $calendar->savePeriod($this->maker, (int) $year->getKey(), ['company_id' => $this->company->getKey(), 'code' => 'NOV-OB', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'reason' => 'Opening dependency']);
        DB::table('journal_entries')->insert(['company_id' => $this->company->getKey(), 'accounting_period_id' => $period->getKey(), 'number' => 'SYNTHETIC-OPENING-DEPENDENCY', 'entry_kind' => 'opening', 'status' => 'posted', 'accounting_date' => '2026-11-01', 'currency_code' => 'BDT', 'description' => 'Isolated dependency fixture', 'revision' => 1, 'created_by' => $this->maker->getKey(), 'updated_by' => $this->maker->getKey(), 'posted_by' => $this->maker->getKey(), 'posted_at' => now(), 'contract_version' => '1.0.0']);
        $checks = array_column($this->yearReview()['checks'], null, 'code');
        $this->assertFalse($checks['subsequent_openings']['passed']);
        $this->invalid(fn () => $this->prepareYear('reopen'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\AccountingPeriodCloseEvent;
use App\Services\ERP\Accounting\AccountingPeriodStateService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ERP\AccountingPeriodCloseTestCase;

final class AccountingPeriodCloseWorkflowTest extends AccountingPeriodCloseTestCase
{
    public function test_ended_zero_activity_period_has_automated_readiness_but_still_needs_review(): void
    {
        $r = $this->reviewClose();
        $this->assertTrue($r['automatedReady']);
        $this->assertFalse($r['canClose']);
        $this->assertSame('0.00', $r['inventoryGl']['inventory']);
    }

    public function test_open_current_period_cannot_prepare(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 23:00:00', 'Asia/Dhaka'));
        $this->assertFalse($this->reviewClose()['automatedReady']);
        $this->invalid(fn () => $this->prepareClose());
    }

    public function test_preparer_cannot_approve_their_own_checklist(): void
    {
        $this->prepareClose();
        $this->expectException(AuthorizationException::class);
        $this->approveClose($this->maker);
    }

    public function test_mismatched_inventory_value_does_not_override_gl(): void
    {
        $this->prepareClose();
        $this->invalid(fn () => $this->approveClose(null, ['inventory_amount' => '1.00']));
        $this->assertSame(1, AccountingPeriodCloseEvent::query()->count());
    }

    public function test_dispatched_stock_is_reconciled_separately(): void
    {
        $this->prepareClose();
        $this->invalid(fn () => $this->approveClose(null, ['dispatched_amount' => '1.00']));
    }

    public function test_reference_and_adjustment_confirmation_are_required(): void
    {
        $this->prepareClose();
        $this->invalid(fn () => $this->approveClose(null, ['inventory_reference' => '', 'adjustments_complete' => false]));
    }

    public function test_independent_approval_then_hard_close_records_atomic_evidence_and_state(): void
    {
        $this->prepareClose();
        $this->approveClose();
        $this->assertTrue($this->reviewClose()['canClose']);
        $before = DB::table('journal_entries')->count();
        $this->hardClose();
        $this->assertSame('hard_closed', $this->closePeriod->fresh()->getAttribute('state'));
        $this->assertSame(3, AccountingPeriodCloseEvent::query()->count());
        $this->assertSame('hard_close', AccountingPeriodCloseEvent::query()->orderByDesc('sequence')->firstOrFail()->getAttribute('event_kind'));
        $this->assertSame($before, DB::table('journal_entries')->count());
        $this->assertFalse($this->reviewClose()['canClose']);
    }

    public function test_recognized_expense_can_close_without_recognizing_it_twice(): void
    {
        $this->recognized($this->expense());
        $this->prepareClose();
        $this->approveClose();
        $count = DB::table('journal_entries')->count();
        $this->hardClose();
        $this->assertSame($count, DB::table('journal_entries')->count());
        $this->assertSame('hard_closed', $this->closePeriod->fresh()->getAttribute('state'));
    }

    public function test_account_change_invalidates_approval_without_substituting_a_new_snapshot(): void
    {
        $this->prepareClose();
        $this->approveClose();
        DB::table('accounts')->where('id', $this->mapping['general_expense'])->update(['name' => 'Renamed after approval']);
        $this->assertFalse($this->reviewClose()['fresh']);
        $this->invalid(fn () => $this->hardClose());
        $this->assertSame(2, AccountingPeriodCloseEvent::query()->count());
        $this->assertSame('open', $this->closePeriod->fresh()->getAttribute('state'));
    }

    public function test_new_native_expense_blocks_an_approved_close(): void
    {
        $this->prepareClose();
        $this->approveClose();
        $this->expense();
        $this->assertFalse($this->reviewClose()['automatedReady']);
        $this->invalid(fn () => $this->hardClose());
    }

    public function test_soft_close_is_available_without_checklist_approval(): void
    {
        $this->softClose();
        $this->assertSame('soft_closed', $this->closePeriod->fresh()->getAttribute('state'));
        $this->assertSame(0, AccountingPeriodCloseEvent::query()->count());
    }

    public function test_soft_close_after_review_requires_new_preparation(): void
    {
        $this->prepareClose();
        $this->approveClose();
        $this->softClose();
        $this->assertFalse($this->reviewClose()['fresh']);
        $this->invalid(fn () => $this->hardClose());
        $this->prepareClose();
        $this->approveClose();
        $this->hardClose();
        $this->assertSame('hard_closed', $this->closePeriod->fresh()->getAttribute('state'));
    }

    public function test_original_calendar_close_cannot_bypass_the_new_gate(): void
    {
        $this->invalid(fn () => app(AccountingPeriodStateService::class)->close($this->maker, (int) $this->company->getKey(), (int) $this->closePeriod->getAttribute('fiscal_year_id'), (int) $this->closePeriod->getKey(), ['state' => 'hard_closed', 'revision' => 1, 'reason' => 'Attempt calendar bypass']));
        $this->assertSame('open', $this->closePeriod->fresh()->getAttribute('state'));
    }

    public function test_failed_outer_transaction_rolls_back_close_evidence_and_period_state(): void
    {
        $this->prepareClose();
        $this->approveClose();
        $before = DB::table('accounting_audit_events')->count();
        try {
            DB::transaction(function (): void {
                $this->hardClose();
                throw new \RuntimeException('rollback close');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('rollback close', $e->getMessage());
        }
        $this->assertSame('open', $this->closePeriod->fresh()->getAttribute('state'));
        $this->assertSame(2, AccountingPeriodCloseEvent::query()->count());
        $this->assertSame($before, DB::table('accounting_audit_events')->count());
    }

    public function test_deleted_approval_is_not_recreated(): void
    {
        $this->prepareClose();
        $this->approveClose();
        DB::table('accounting_period_close_events')->where('event_kind', 'approve')->delete();
        $this->invalid(fn () => $this->reviewClose());
        $this->assertSame(1, AccountingPeriodCloseEvent::query()->count());
    }

    public function test_corrupt_payload_hash_blocks_history_without_repair(): void
    {
        $this->prepareClose();
        DB::table('accounting_period_close_events')->update(['payload_hash' => str_repeat('0', 64)]);
        $this->invalid(fn () => $this->approveClose());
    }

    public function test_history_cannot_be_edited_through_model(): void
    {
        $this->prepareClose();
        $row = AccountingPeriodCloseEvent::query()->firstOrFail();
        $this->expectException(\LogicException::class);
        $row->update(['event_kind' => 'approve']);
    }

    public function test_history_cannot_be_deleted_through_model(): void
    {
        $this->prepareClose();
        $this->expectException(\LogicException::class);
        AccountingPeriodCloseEvent::query()->firstOrFail()->delete();
    }

    public function test_reopen_invalidates_old_close_authority(): void
    {
        $this->prepareClose();
        $this->approveClose();
        $this->hardClose();
        $p = $this->closePeriod->fresh();
        app(AccountingPeriodStateService::class)->reopen($this->reviewer, (int) $this->company->getKey(), (int) $p->getAttribute('fiscal_year_id'), (int) $p->getKey(), ['revision' => (int) $p->getAttribute('revision'), 'reason' => 'Independent reopen']);
        $this->assertFalse($this->reviewClose()['canClose']);
        $this->invalid(fn () => $this->hardClose());
    }

    public function test_bank_binding_without_exact_cutoff_finalization_is_blocking(): void
    {
        DB::table('accounting_cash_accounts')->insert($this->bankFixture);
        $r = $this->reviewClose();
        $checks = array_column($r['checks'], null, 'code');
        $this->assertFalse($checks['bank_approval:'.$this->bank->getKey()]['passed']);
        $this->invalid(fn () => $this->prepareClose());
    }

    public function test_disabled_accounting_is_not_a_hard_close_bypass(): void
    {
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['state' => 'disabled']);
        $this->assertFalse($this->reviewClose()['automatedReady']);
        $this->invalid(fn () => $this->prepareClose());
    }

    public function test_stale_sequence_cannot_create_duplicate_preparations(): void
    {
        $command = $this->closeCommand();
        $this->prepareClose();
        $this->invalid(fn () => $this->closeWorkflow->prepare($this->maker, (int) $this->company->getKey(), (int) $this->closePeriod->getKey(), $command));
        $this->assertSame(1, AccountingPeriodCloseEvent::query()->count());
    }

    public function test_actor_cannot_supply_snapshot_or_approval_identity(): void
    {
        $command = [...$this->closeCommand(), 'snapshot_hash' => str_repeat('a', 64)];
        $this->invalid(fn () => $this->closeWorkflow->prepare($this->maker, (int) $this->company->getKey(), (int) $this->closePeriod->getKey(), $command));
    }

    public function test_new_posting_into_hard_closed_period_is_rejected(): void
    {
        $this->prepareClose();
        $this->approveClose();
        $this->hardClose();
        $count = DB::table('journal_entries')->count();
        $this->invalid(fn () => $this->recognized($this->expense()));
        $this->assertSame($count, DB::table('journal_entries')->count());
    }
}

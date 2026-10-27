<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingJournalDraftService;
use App\Services\ERP\Accounting\AccountingJournalEvidence;
use App\Services\ERP\Accounting\AccountingJournalPostingService;
use App\Services\ERP\Accounting\AccountingJournalReversalService;
use App\Services\ERP\Accounting\AccountingJournalWorkflowService;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\AccountingStarterService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AccountingJournalPostingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private FiscalYear $year;

    private AccountingPeriod $period;

    private AccountingJournalDraftService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingPermissionSeeder::class);
        $this->company = $this->newCompany('JV-A');
        $this->actor = User::factory()->create();
        $this->actor->assignRole('super-admin');
        app(AccountingSetupService::class)->initialize($this->actor, (int) $this->company->getKey());
        app(AccountingStarterService::class)->apply($this->actor, ['company_id' => $this->company->getKey(), 'template_id' => 'general_v1', 'reason' => 'Create journal fixture chart']);
        $calendar = app(AccountingCalendarService::class);
        $this->year = $calendar->saveYear($this->actor, ['company_id' => $this->company->getKey(), 'code' => 'FY26.01', 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'reason' => 'Create year']);
        $this->period = $calendar->savePeriod($this->actor, (int) $this->year->getKey(), ['company_id' => $this->company->getKey(), 'code' => 'OCT26', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'reason' => 'Create period']);
        $this->service = app(AccountingJournalDraftService::class);
    }

    private function newCompany(string $code): Company
    {
        return Company::query()->create(['name' => $code, 'code' => $code, 'status' => 'active', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka']);
    }

    private function account(string $code): Account
    {
        return Account::query()->where('company_id', $this->company->getKey())->where('code', $code)->firstOrFail();
    }

    /** @param list<string> $permissions */
    private function staff(array $permissions): User
    {
        $user = User::factory()->create();
        Employee::query()->create(['company_id' => $this->company->getKey(), 'user_id' => $user->getKey(), 'employee_number' => 'JV-'.$user->getKey(), 'first_name' => 'Journal', 'last_name' => 'User']);
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

return $user;
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function data(array $overrides = []): array
    {
        return ['company_id' => (int) $this->company->getKey(), 'accounting_date' => '2026-10-15', 'document_date' => '2026-10-10', 'currency_code' => 'BDT', 'description' => 'Reviewed noncash manual draft', 'reason' => 'Create draft for review', 'lines' => [
            ['account_id' => $this->account('5500')->getKey(), 'debit' => '100.10', 'credit' => '0', 'description' => 'Expense'],
            ['account_id' => $this->account('3200')->getKey(), 'debit' => '0', 'credit' => '100.10', 'description' => 'Noncash capital contribution'],
        ], ...$overrides];
    }

    /** @param array<string, mixed> $overrides */
    private function createDraft(array $overrides = []): JournalEntry
    {
        return $this->service->save($this->actor, $this->data($overrides));
    }

    private function invalid(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected journal validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function reviewer(): User
    {
        $reviewer = User::factory()->create();
        $reviewer->assignRole('super-admin');

        return $reviewer;
    }

    private function act(string $action, JournalEntry $journal, ?User $actor = null, array $input = []): JournalEntry
    {
        return app(AccountingJournalWorkflowService::class)->{$action}($actor ?? $this->actor, (int) $this->company->getKey(), (int) $journal->getKey(), ['revision' => $journal->getAttribute('revision'), 'reason' => 'Workflow review reason', ...$input]);
    }

    private function submitted(): JournalEntry
    {
        return $this->act('submit', $this->createDraft());
    }

    private function approved(): JournalEntry
    {
        return $this->act('approve', $this->submitted(), $this->reviewer());
    }

    /** TEST FIXTURE ONLY: production activation remains a later independently reconciled workflow. */
    private function enablePosting(): void
    {
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['state' => 'enabled', 'activation_date' => '2026-10-01', 'activated_by' => $this->actor->getKey(), 'activated_at' => now()]);
        if (! AccountingCutover::query()->where('company_id', $this->company->getKey())->exists()) {
            AccountingCutover::query()->create(['company_id' => $this->company->getKey(), 'cutover_date' => '2026-10-01', 'strategy' => 'snapshot', 'status' => 'activated', 'created_by' => $this->actor->getKey(), 'approved_by' => $this->reviewer()->getKey(), 'approved_at' => now(), 'activated_by' => $this->actor->getKey(), 'activated_at' => now(), 'manifest_hash' => str_repeat('a', 64), 'reconciliation_hash' => str_repeat('b', 64), 'revision' => 1]);
        }
    }

    private function postJournal(JournalEntry $row, ?User $actor = null, array $input = []): JournalEntry
    {
        return app(AccountingJournalPostingService::class)->post($actor ?? $this->actor, (int) $this->company->getKey(), (int) $row->getKey(), ['revision' => $row->getAttribute('revision'), 'reason' => 'Post verified accounting evidence', ...$input]);
    }

    private function posted(): JournalEntry
    {
        $this->enablePosting();

        return $this->postJournal($this->approved());
    }

    private function reverseJournal(JournalEntry $original, array $input = [], ?User $actor = null): JournalEntry
    {
        return app(AccountingJournalReversalService::class)->prepare($actor ?? $this->actor, (int) $this->company->getKey(), (int) $original->getKey(), ['revision' => $original->getAttribute('revision'), 'accounting_date' => '2026-10-16', 'reason' => 'Correct the posted manual journal', ...$input]);
    }

    private function approvedReversal(JournalEntry $original): JournalEntry
    {
        return $this->act('approve', $this->act('submit', $this->reverseJournal($original)), $this->reviewer());
    }

    public function test_post_records_server_state_and_keeps_approved_financial_evidence(): void
    {
        $this->enablePosting();
        $row = $this->approved();
        $before = $row->getAttributes();
        $ids = JournalEntryLine::query()->where('journal_entry_id', $row->getKey())->pluck('id')->all();
        $posted = $this->postJournal($row);
        $this->assertSame('posted', $posted->getAttribute('status'));
        $this->assertSame(4, $posted->getAttribute('revision'));
        $this->assertNotNull($posted->getAttribute('posted_at'));
        $this->assertSame((int) $this->actor->getKey(), (int) $posted->getAttribute('posted_by'));
        foreach (['payload_hash', 'number', 'approved_by', 'approved_at', 'created_by', 'updated_by', 'accounting_date'] as $key) {
            $this->assertSame($before[$key], $posted->getAttributes()[$key]);
        }
        $this->assertSame($ids, JournalEntryLine::query()->where('journal_entry_id', $row->getKey())->pluck('id')->all());
        $this->assertDatabaseCount('accounting_source_effects', 0);
    }

    public function test_disabled_and_ready_settings_block_live_posting(): void
    {
        $row = $this->approved();
        foreach (['disabled', 'ready'] as $state) {
            DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['state' => $state]);
            $this->invalid(fn () => $this->postJournal($row), 'company_id');
        }
        $this->assertSame('approved', $row->fresh()->getAttribute('status'));
    }

    public function test_enabled_flag_without_activation_proof_cannot_post(): void
    {
        $row = $this->approved();
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['state' => 'enabled']);
        $this->invalid(fn () => $this->postJournal($row), 'company_id');
    }

    public function test_missing_cutover_blocks_posting_even_with_settings_stamps(): void
    {
        $this->enablePosting();
        $row = $this->approved();
        DB::table('accounting_cutovers')->delete();
        $this->invalid(fn () => $this->postJournal($row), 'company_id');
    }

    public function test_unapproved_or_unreconciled_cutover_blocks_posting(): void
    {
        $this->enablePosting();
        $row = $this->approved();
        foreach ([['reconciliation_hash' => null], ['manifest_hash' => 'not-proof'], ['approved_by' => $this->actor->getKey()], ['approved_at' => null]] as $change) {
            $original = (array) DB::table('accounting_cutovers')->first();
            DB::table('accounting_cutovers')->update($change);
            $this->invalid(fn () => $this->postJournal($row), 'company_id');
            DB::table('accounting_cutovers')->update($original);
        }
    }

    public function test_pre_activation_accounting_date_is_rejected(): void
    {
        $this->enablePosting();
        $row = $this->approved();
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['activation_date' => '2026-10-16']);
        $this->invalid(fn () => $this->postJournal($row), 'accounting_date');
    }

    public function test_draft_and_submitted_journals_cannot_post(): void
    {
        $this->enablePosting();
        foreach ([$this->createDraft(), $this->submitted()] as $row) {
            $this->invalid(fn () => $this->postJournal($row), 'approval');
        }
    }

    public function test_self_approval_injected_into_header_cannot_post(): void
    {
        $this->enablePosting();
        $row = $this->approved();
        DB::table('journal_entries')->where('id', $row->getKey())->update(['approved_by' => $this->actor->getKey()]);
        $this->invalid(fn () => $this->postJournal($row->fresh()), 'approval');
    }

    public function test_stale_post_revision_cannot_write_or_audit(): void
    {
        $this->enablePosting();
        $row = $this->approved();
        $count = DB::table('accounting_audit_events')->count();
        $this->invalid(fn () => $this->postJournal($row, null, ['revision' => 2]), 'revision');
        $this->assertSame($count, DB::table('accounting_audit_events')->count());
        $this->assertNull($row->fresh()->getAttribute('posted_at'));
    }

    public function test_matching_retry_returns_existing_post_even_after_period_and_config_close(): void
    {
        $this->enablePosting();
        $row = $this->approved();
        $posted = $this->postJournal($row);
        $count = DB::table('accounting_audit_events')->count();
        DB::table('accounting_periods')->where('id', $this->period->getKey())->update(['state' => 'hard_closed']);
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['state' => 'disabled']);
        $this->account('5500')->update(['status' => 'inactive']);
        $retry = $this->postJournal($row);
        $this->assertSame($posted->getKey(), $retry->getKey());
        $this->assertSame($count, DB::table('accounting_audit_events')->count());
    }

    public function test_different_reason_or_actor_is_not_a_matching_post_retry(): void
    {
        $this->enablePosting();
        $row = $this->approved();
        $this->postJournal($row);
        $this->invalid(fn () => $this->postJournal($row, null, ['reason' => 'Different request']), 'revision');
        $this->invalid(fn () => $this->postJournal($row, $this->reviewer()), 'revision');
    }

    public function test_post_requires_its_own_permission(): void
    {
        $this->enablePosting();
        $row = $this->approved();
        $this->expectException(AuthorizationException::class);
        $this->postJournal($row, $this->staff(['erp.journal-entries.approve']));
    }

    public function test_post_cannot_cross_company_scope(): void
    {
        $row = $this->approved();
        $foreign = $this->newCompany('POST-B');
        $this->expectException(AuthorizationException::class);
        app(AccountingJournalPostingService::class)->post($this->staff(['erp.journal-entries.post']), (int) $foreign->getKey(), (int) $row->getKey(), ['revision' => 3, 'reason' => 'Foreign company']);
    }

    public function test_closed_period_blocks_post_even_after_approval(): void
    {
        $this->enablePosting();
        $row = $this->approved();
        foreach (['soft_closed', 'hard_closed'] as $state) {
            DB::table('accounting_periods')->where('id', $this->period->getKey())->update(['state' => $state]);
            $this->invalid(fn () => $this->postJournal($row), 'accounting_date');
        }
    }

    public function test_inactive_account_blocks_post_even_after_approval(): void
    {
        $this->enablePosting();
        $row = $this->approved();
        $this->account('5500')->update(['status' => 'inactive']);
        $this->invalid(fn () => $this->postJournal($row), 'lines.0.account_id');
    }

    public function test_balanced_financial_tampering_blocks_post(): void
    {
        $this->enablePosting();
        $row = $this->approved();
        DB::table('journal_entry_lines')->where('journal_entry_id', $row->getKey())->where('line_number', 1)->update(['debit' => '200.20']);
        DB::table('journal_entry_lines')->where('journal_entry_id', $row->getKey())->where('line_number', 2)->update(['credit' => '200.20']);
        $this->invalid(fn () => $this->postJournal($row), 'journal');
    }

    public function test_posted_header_cannot_be_edited_or_deleted(): void
    {
        $row = $this->posted();
        try {
            $row->update(['description' => 'Rewrite']);
            $this->fail('Expected immutable header');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
        $this->expectException(\LogicException::class);
        $row->fresh()->delete();
    }

    public function test_posted_lines_cannot_be_changed_removed_or_appended(): void
    {
        $row = $this->posted();
        $line = JournalEntryLine::query()->where('journal_entry_id', $row->getKey())->firstOrFail();
        try {
            $line->update(['description' => 'Rewrite']);
            $this->fail('Expected immutable line');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
        try {
            $line->fresh()->update(['debit' => 1.25]);
            $this->fail('Posted immutability must run before decimal validation');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
        try {
            $line->fresh()->delete();
            $this->fail('Expected immutable line');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
        $this->expectException(\LogicException::class);
        JournalEntryLine::query()->create(['company_id' => $this->company->getKey(), 'journal_entry_id' => $row->getKey(), 'line_number' => 3, 'account_id' => $this->account('5500')->getKey(), 'debit' => '1.00', 'credit' => '0']);
    }

    public function test_posting_rolls_back_with_outer_transaction(): void
    {
        $this->enablePosting();
        $row = $this->approved();
        $count = DB::table('accounting_audit_events')->count();
        DB::beginTransaction();
        try {
            $this->postJournal($row);
        } finally {
            DB::rollBack();
        }
        $this->assertSame('approved', $row->fresh()->getAttribute('status'));
        $this->assertSame($count, DB::table('accounting_audit_events')->count());
    }

    public function test_reverse_prepares_exact_linked_draft_without_touching_original(): void
    {
        $original = $this->posted();
        $before = $original->getAttributes();
        $reverse = $this->reverseJournal($original);
        $this->assertSame('reversal', $reverse->getAttribute('entry_kind'));
        $this->assertSame('draft', $reverse->getAttribute('status'));
        $this->assertStringStartsWith('RV-FY26.01-', $reverse->getAttribute('number'));
        $this->assertSame((int) $original->getKey(), (int) $reverse->getAttribute('reverses_journal_id'));
        $this->assertNull($reverse->getAttribute('approved_by'));
        $read = $this->service->read($this->actor, (int) $reverse->getKey());
        $this->assertSame('0.00', $read['lines'][0]['debit']);
        $this->assertSame('100.10', $read['lines'][0]['credit']);
        $this->assertSame($before, $original->fresh()->getAttributes());
    }

    public function test_reversal_requires_fresh_independent_approval_before_post(): void
    {
        $original = $this->posted();
        $reverse = $this->reverseJournal($original);
        $this->invalid(fn () => $this->postJournal($reverse), 'approval');
        $submitted = $this->act('submit', $reverse);
        try {
            $this->act('approve', $submitted);
            $this->fail('Expected independent review');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $approved = $this->act('approve', $submitted, $this->reviewer());
        $this->assertSame('posted', $this->postJournal($approved)->getAttribute('status'));
    }

    public function test_original_and_posted_reversal_net_to_zero_with_both_in_ledger(): void
    {
        $original = $this->posted();
        $reverse = $this->postJournal($this->approvedReversal($original));
        $this->assertSame('posted', $original->fresh()->getAttribute('status'));
        $this->assertSame('posted', $reverse->getAttribute('status'));
        foreach (['5500', '3200'] as $code) {
            $lines = JournalEntryLine::query()->where('account_id', $this->account($code)->getKey())->get();
            $net = '0.00';
            foreach ($lines as $line) {
                $net = bcadd($net, bcsub((string) $line->getAttribute('debit'), (string) $line->getAttribute('credit'), 2), 2);
            }
            $this->assertSame('0.00', $net);
        }
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_closed_original_period_can_be_reversed_in_later_open_period(): void
    {
        $original = $this->posted();
        app(AccountingCalendarService::class)->savePeriod($this->actor, (int) $this->year->getKey(), ['company_id' => $this->company->getKey(), 'code' => 'NOV26', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'reason' => 'Next period']);
        DB::table('accounting_periods')->where('id', $this->period->getKey())->update(['state' => 'hard_closed']);
        $reverse = $this->reverseJournal($original, ['accounting_date' => '2026-11-01']);
        $reverse = $this->act('approve', $this->act('submit', $reverse), $this->reviewer());
        $this->assertSame('posted', $this->postJournal($reverse)->getAttribute('status'));
        $this->assertSame('2026-10-15', $original->fresh()->getAttribute('accounting_date')->format('Y-m-d'));
    }

    public function test_closed_target_period_blocks_reversal_preparation(): void
    {
        $original = $this->posted();
        DB::table('accounting_periods')->where('id', $this->period->getKey())->update(['state' => 'hard_closed']);
        $this->invalid(fn () => $this->reverseJournal($original), 'accounting_date');
    }

    public function test_reversal_cannot_predate_original(): void
    {
        $original = $this->posted();
        $this->invalid(fn () => $this->reverseJournal($original, ['accounting_date' => '2026-10-14']), 'accounting_date');
    }

    public function test_reverse_requires_dedicated_permission(): void
    {
        $original = $this->posted();
        $this->expectException(AuthorizationException::class);
        $this->reverseJournal($original, [], $this->staff(['erp.journal-entries.post']));
    }

    public function test_only_posted_manual_original_is_supported(): void
    {
        $this->invalid(fn () => $this->reverseJournal($this->approved()), 'journal');
        $original = $this->posted();
        $reverse = $this->postJournal($this->approvedReversal($original));
        $this->invalid(fn () => $this->reverseJournal($reverse), 'journal');
    }

    public function test_second_reversal_after_posting_is_rejected(): void
    {
        $original = $this->posted();
        $this->postJournal($this->approvedReversal($original));
        $this->invalid(fn () => $this->reverseJournal($original), 'journal');
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_existing_unposted_reversal_requires_its_current_revision(): void
    {
        $original = $this->posted();
        $reverse = $this->reverseJournal($original);
        $this->invalid(fn () => $this->reverseJournal($original), 'reversal_revision');
        $this->invalid(fn () => $this->reverseJournal($original, ['reversal_revision' => 99]), 'reversal_revision');
        $revised = $this->reverseJournal($original, ['reversal_revision' => 1]);
        $this->assertSame($reverse->getKey(), $revised->getKey());
        $this->assertSame(2, $revised->getAttribute('revision'));
    }

    public function test_cancelled_reversal_can_be_reprepared_in_same_slot_with_audit_history(): void
    {
        $original = $this->posted();
        $reverse = $this->act('cancel', $this->reverseJournal($original));
        $revised = $this->reverseJournal($original, ['reversal_revision' => $reverse->getAttribute('revision')]);
        $this->assertSame($reverse->getKey(), $revised->getKey());
        $this->assertSame('draft', $revised->getAttribute('status'));
        $this->assertSame($reverse->getAttribute('number'), $revised->getAttribute('number'));
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_repreparing_approved_reversal_withdraws_approval(): void
    {
        $original = $this->posted();
        $reverse = $this->approvedReversal($original);
        $reverse = $this->reverseJournal($original, ['reversal_revision' => $reverse->getAttribute('revision')]);
        $this->assertSame('draft', $reverse->getAttribute('status'));
        $this->assertNull($reverse->getAttribute('approved_at'));
        $this->assertNull($reverse->getAttribute('submitted_at'));
    }

    public function test_normal_draft_editor_cannot_modify_reversal_lines(): void
    {
        $original = $this->posted();
        $reverse = $this->reverseJournal($original);
        $this->invalid(fn () => $this->service->save($this->actor, $this->data(['revision' => 1]), (int) $reverse->getKey()), 'journal');
    }

    public function test_balanced_but_nonmatching_reversal_lines_cannot_be_approved(): void
    {
        $original = $this->posted();
        $reverse = $this->reverseJournal($original);
        DB::table('journal_entry_lines')->where('journal_entry_id', $reverse->getKey())->where('line_number', 1)->update(['credit' => '200.20']);
        DB::table('journal_entry_lines')->where('journal_entry_id', $reverse->getKey())->where('line_number', 2)->update(['debit' => '200.20']);
        // Even recalculating a financial hash cannot make arbitrary lines into a valid compensation.
        $evidence = app(AccountingJournalEvidence::class);
        $canonical = app(AccountingCanonicalPayload::class);
        DB::table('journal_entries')->where('id', $reverse->getKey())->update(['payload_hash' => $canonical->hash($evidence->payload($reverse, $evidence->lines($reverse)))]);
        $this->invalid(fn () => $this->act('submit', $reverse->fresh()), 'journal');
    }

    public function test_inactive_original_account_requires_reactivation_before_reversal(): void
    {
        $original = $this->posted();
        $this->account('5500')->update(['status' => 'inactive']);
        $this->invalid(fn () => $this->reverseJournal($original), 'lines.0.account_id');
    }

    public function test_reversal_preparation_rolls_back_number_lines_and_audit(): void
    {
        $original = $this->posted();
        $count = DB::table('accounting_audit_events')->count();
        DB::beginTransaction();
        try {
            $this->reverseJournal($original);
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('journal_entry_lines', 2);
        $this->assertSame($count, DB::table('accounting_audit_events')->count());
        $this->assertFalse(DB::table('accounting_document_sequences')->where('document_kind', 'reversal')->exists());
    }

    public function test_inputs_cannot_supply_post_state_or_reversal_financial_lines(): void
    {
        $this->enablePosting();
        $row = $this->approved();
        $this->invalid(fn () => $this->postJournal($row, null, ['posted_by' => 123]), 'journal');
        $original = $this->postJournal($row);
        $this->invalid(fn () => $this->reverseJournal($original, ['lines' => []]), 'journal');
    }

    public function test_post_audit_contains_before_after_stamps_and_reversal_link(): void
    {
        $original = $this->posted();
        $reverse = $this->postJournal($this->approvedReversal($original));
        $event = DB::table('accounting_audit_events')->where('entity_kind', 'journal')->where('entity_id', $reverse->getKey())->where('action', 'post')->first();
        $before = json_decode($event->before_payload, true);
        $after = json_decode($event->after_payload, true);
        $this->assertNull($before['posted_at']);
        $this->assertNotNull($after['posted_at']);
        $this->assertSame('posted', $after['status']);
        $this->assertSame((int) $original->getKey(), $after['reverses_journal_id']);
    }

    public function test_backdated_post_requires_period_adjust_permission(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-24 12:00:00', 'Asia/Dhaka'));
        $this->enablePosting();
        $row = $this->approved();
        $actor = $this->staff(['erp.journal-entries.post']);
        $this->expectException(AuthorizationException::class);
        $this->postJournal($row, $actor);
    }

    public function test_missing_submission_evidence_cannot_be_posted(): void
    {
        $this->enablePosting();
        $row = $this->approved();
        DB::table('journal_entries')->where('id', $row->getKey())->update(['submitted_at' => null]);
        $this->invalid(fn () => $this->postJournal($row->fresh()), 'journal');
    }

    public function test_closing_year_after_approval_blocks_post(): void
    {
        $this->enablePosting();
        $row = $this->approved();
        DB::table('fiscal_years')->where('id', $this->year->getKey())->update(['state' => 'closed']);
        $this->invalid(fn () => $this->postJournal($row), 'accounting_date');
    }

    public function test_cutover_lock_and_inactive_company_block_post(): void
    {
        $this->enablePosting();
        $row = $this->approved();
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['cutover_locked_at' => now()]);
        $this->invalid(fn () => $this->postJournal($row), 'company_id');
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['cutover_locked_at' => null]);
        $this->company->update(['status' => 'inactive']);
        $this->invalid(fn () => $this->postJournal($row), 'company_id');
    }

    public function test_reversal_link_is_bound_into_financial_hash(): void
    {
        $original = $this->posted();
        $other = $this->postJournal($this->approved());
        $reverse = $this->reverseJournal($original);
        DB::table('journal_entries')->where('id', $reverse->getKey())->update(['reverses_journal_id' => $other->getKey()]);
        $this->invalid(fn () => $this->act('submit', $reverse->fresh()), 'journal');
    }

    public function test_reversal_preparation_cannot_change_a_submitted_reversal(): void
    {
        $original = $this->posted();
        $reverse = $this->act('submit', $this->reverseJournal($original));
        $this->invalid(fn () => $this->reverseJournal($original, ['reversal_revision' => $reverse->getAttribute('revision')]), 'journal');
    }
}

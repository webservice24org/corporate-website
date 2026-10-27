<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\AccountingJournalDraftService;
use App\Services\ERP\Accounting\AccountingJournalWorkflowService;
use App\Services\ERP\Accounting\AccountingPeriodGuard;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\AccountingStarterService;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AccountingJournalWorkflowTest extends TestCase
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

    public function test_submission_and_independent_approval_preserve_financial_evidence(): void
    {
        $draft = $this->createDraft();
        $hash = $draft->getAttribute('payload_hash');
        $number = $draft->getAttribute('number');
        $ids = JournalEntryLine::query()->where('journal_entry_id', $draft->getKey())->pluck('id')->all();
        $submitted = $this->act('submit', $draft);
        $this->assertSame('submitted', $submitted->getAttribute('status'));
        $this->assertSame(2, $submitted->getAttribute('revision'));
        $this->assertSame((int) $this->actor->getKey(), (int) $submitted->getAttribute('submitted_by'));
        $this->assertNotNull($submitted->getAttribute('submitted_at'));
        $reviewer = $this->reviewer();
        $approved = $this->act('approve', $submitted, $reviewer);
        $this->assertSame('approved', $approved->getAttribute('status'));
        $this->assertSame(3, $approved->getAttribute('revision'));
        $this->assertSame((int) $reviewer->getKey(), (int) $approved->getAttribute('approved_by'));
        $this->assertNotNull($approved->getAttribute('approved_at'));
        $this->assertSame($hash, $approved->getAttribute('payload_hash'));
        $this->assertSame($number, $approved->getAttribute('number'));
        $this->assertSame($ids, JournalEntryLine::query()->where('journal_entry_id', $draft->getKey())->pluck('id')->all());
        $this->assertSame((int) $this->actor->getKey(), (int) $approved->getAttribute('updated_by'));
        $this->assertNull($approved->getAttribute('posted_at'));
        $this->assertSame('100.10', $this->service->read($this->actor, (int) $approved->getKey())['total_debit']);
    }

    public function test_creator_cannot_approve_even_as_super_admin(): void
    {
        $journal = $this->submitted();
        $this->expectException(AuthorizationException::class);
        $this->act('approve', $journal);
    }

    public function test_last_content_editor_cannot_approve(): void
    {
        $journal = $this->createDraft();
        $editor = $this->reviewer();
        $journal = $this->service->save($editor, $this->data(['revision' => 1, 'description' => 'Editor changed this']), (int) $journal->getKey());
        $journal = $this->act('submit', $journal);
        $this->expectException(AuthorizationException::class);
        $this->act('approve', $journal, $editor);
    }

    public function test_separate_submitter_does_not_replace_last_content_editor(): void
    {
        $reviewer = $this->reviewer();
        $journal = $this->act('submit', $this->createDraft(), $reviewer);
        $journal = $this->act('approve', $journal, $reviewer);
        $this->assertSame('approved', $journal->getAttribute('status'));
        $this->assertSame((int) $this->actor->getKey(), (int) $journal->getAttribute('updated_by'));
    }

    public function test_rejection_clears_submission_and_can_be_resubmitted(): void
    {
        $journal = $this->submitted();
        $hash = $journal->getAttribute('payload_hash');
        $journal = $this->act('reject', $journal, $this->reviewer());
        $this->assertSame('draft', $journal->getAttribute('status'));
        $this->assertSame(3, $journal->getAttribute('revision'));
        $this->assertNull($journal->getAttribute('submitted_by'));
        $this->assertNull($journal->getAttribute('submitted_at'));
        $this->assertSame($hash, $journal->getAttribute('payload_hash'));
        $this->assertSame('submitted', $this->act('submit', $journal)->getAttribute('status'));
    }

    public function test_cancel_is_terminal_from_each_unposted_state(): void
    {
        foreach (['draft', 'submitted', 'approved'] as $state) {
            $journal = match ($state) {
                'draft' => $this->createDraft(), 'submitted' => $this->submitted(), 'approved' => $this->approved()
            };
            $hash = $journal->getAttribute('payload_hash');
            $journal = $this->act('cancel', $journal);
            $this->assertSame('cancelled', $journal->getAttribute('status'));
            $this->assertSame($hash, $journal->getAttribute('payload_hash'));
            $this->assertNull($journal->getAttribute('approved_by'));
            $this->assertNull($journal->getAttribute('submitted_by'));
            $this->invalid(fn () => $this->act('submit', $journal), 'journal');
            $this->invalid(fn () => $this->act('cancel', $journal), 'journal');
        }
    }

    public function test_approved_edit_resets_all_authority_and_keeps_number(): void
    {
        $journal = $this->approved();
        $number = $journal->getAttribute('number');
        $editor = $this->reviewer();
        $journal = $this->service->save($editor, $this->data(['revision' => 3, 'description' => 'Corrected approved content']), (int) $journal->getKey());
        $this->assertSame('draft', $journal->getAttribute('status'));
        $this->assertSame(4, $journal->getAttribute('revision'));
        $this->assertSame($number, $journal->getAttribute('number'));
        foreach (['submitted_by', 'submitted_at', 'approved_by', 'approved_at'] as $key) {
            $this->assertNull($journal->getAttribute($key));
        }
        $this->assertSame((int) $editor->getKey(), (int) $journal->getAttribute('updated_by'));
        $this->invalid(fn () => $this->act('approve', $journal, $this->reviewer()), 'journal');
    }

    public function test_even_unchanged_approved_save_requires_fresh_approval(): void
    {
        $journal = $this->approved();
        $hash = $journal->getAttribute('payload_hash');
        $journal = $this->service->save($this->actor, $this->data(['revision' => 3]), (int) $journal->getKey());
        $this->assertSame('draft', $journal->getAttribute('status'));
        $this->assertNull($journal->getAttribute('approved_at'));
        $this->assertSame($hash, $journal->getAttribute('payload_hash'));
    }

    public function test_failed_approved_edit_preserves_authority_and_evidence(): void
    {
        $journal = $this->approved();
        $original = $journal->getAttributes();
        $data = $this->data(['revision' => 3]);
        $data['lines'][0]['debit'] = '999';
        $this->invalid(fn () => $this->service->save($this->actor, $data, (int) $journal->getKey()), 'lines');
        $this->assertSame($original, $journal->fresh()->getAttributes());
    }

    public function test_stale_requests_and_replays_do_not_add_audit_events(): void
    {
        foreach (['submit', 'approve', 'reject', 'cancel'] as $action) {
            $journal = in_array($action, ['approve', 'reject'], true) ? $this->submitted() : $this->createDraft();
            $count = DB::table('accounting_audit_events')->count();
            $this->invalid(fn () => $this->act($action, $journal, $this->reviewer(), ['revision' => 99]), 'revision');
            $this->assertSame($count, DB::table('accounting_audit_events')->count());
        }
        $journal = $this->createDraft();
        $this->act('submit', $journal);
        $count = DB::table('accounting_audit_events')->count();
        $this->invalid(fn () => $this->act('submit', $journal), 'revision');
        $this->assertSame($count, DB::table('accounting_audit_events')->count());
    }

    public function test_illegal_current_revision_transitions_are_rejected(): void
    {
        $draft = $this->createDraft();
        foreach (['approve', 'reject'] as $action) {
            $this->invalid(fn () => $this->act($action, $draft, $this->reviewer()), 'journal');
        }
        foreach ([$this->submitted(), $this->approved()] as $journal) {
            $this->invalid(fn () => $this->act('submit', $journal), 'journal');
        }
    }

    public function test_each_action_enforces_its_permission(): void
    {
        foreach (['submit', 'approve', 'reject', 'cancel'] as $action) {
            $journal = in_array($action, ['approve', 'reject'], true) ? $this->submitted() : $this->createDraft();
            try {
                $this->act($action, $journal, $this->staff(['erp.journal-entries.view']));
                $this->fail('Expected permission denial');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_approve_permission_allows_rejection_but_not_cancellation(): void
    {
        $reviewer = $this->staff(['erp.journal-entries.approve']);
        $journal = $this->act('reject', $this->submitted(), $reviewer);
        $this->assertSame('draft', $journal->getAttribute('status'));
        $this->expectException(AuthorizationException::class);
        $this->act('cancel', $journal, $reviewer);
    }

    public function test_edit_permission_allows_cancellation_but_not_approval(): void
    {
        $editor = $this->staff(['erp.journal-entries.edit']);
        $this->assertSame('cancelled', $this->act('cancel', $this->createDraft(), $editor)->getAttribute('status'));
        $journal = $this->submitted();
        $this->expectException(AuthorizationException::class);
        $this->act('approve', $journal, $editor);
    }

    public function test_foreign_company_is_denied_to_staff(): void
    {
        $journal = $this->createDraft();
        $foreign = $this->newCompany('WF-FOREIGN');
        $actor = $this->staff(['erp.journal-entries.submit']);
        $this->expectException(AuthorizationException::class);
        app(AccountingJournalWorkflowService::class)->submit($actor, (int) $foreign->getKey(), (int) $journal->getKey(), ['revision' => 1, 'reason' => 'Foreign request']);
    }

    public function test_action_rejects_missing_reason_and_server_metadata(): void
    {
        $journal = $this->createDraft();
        foreach (['', '   ', str_repeat('x', 2001)] as $reason) {
            $this->invalid(fn () => $this->act('submit', $journal, null, ['reason' => $reason]), 'reason');
        }
        foreach (['status', 'approved_by', 'submitted_at', 'company_id', 'payload_hash', 'lines'] as $key) {
            $this->invalid(fn () => $this->act('submit', $journal, null, [$key => 'injected']), 'journal');
        }
        $this->assertSame(1, $journal->fresh()->getAttribute('revision'));
    }

    public function test_submit_and_approve_revalidate_account_status(): void
    {
        foreach (['submit', 'approve'] as $action) {
            $account = $this->account('5500');
            $account->update(['status' => 'active']);
            $journal = $action === 'submit' ? $this->createDraft() : $this->submitted();
            $account->update(['status' => 'inactive']);
            $this->invalid(fn () => $this->act($action, $journal, $this->reviewer()), 'lines.0.account_id');
        }
    }

    public function test_submit_and_approve_reject_closed_period(): void
    {
        foreach (['submit', 'approve'] as $action) {
            DB::table('accounting_periods')->where('id', $this->period->getKey())->update(['state' => 'open']);
            $journal = $action === 'submit' ? $this->createDraft() : $this->submitted();
            DB::table('accounting_periods')->where('id', $this->period->getKey())->update(['state' => 'hard_closed']);
            $this->invalid(fn () => $this->act($action, $journal, $this->reviewer()), 'accounting_date');
        }
    }

    public function test_approval_rejects_closed_year(): void
    {
        $journal = $this->submitted();
        DB::table('fiscal_years')->where('id', $this->year->getKey())->update(['state' => 'closed']);
        $this->invalid(fn () => $this->act('approve', $journal, $this->reviewer()), 'accounting_date');
    }

    public function test_reject_and_cancel_can_withdraw_after_period_closure(): void
    {
        $reject = $this->submitted();
        $cancel = $this->approved();
        DB::table('accounting_periods')->where('id', $this->period->getKey())->update(['state' => 'hard_closed']);
        $this->account('5500')->update(['status' => 'inactive']);
        $this->assertSame('draft', $this->act('reject', $reject, $this->reviewer())->getAttribute('status'));
        $this->assertSame('cancelled', $this->act('cancel', $cancel)->getAttribute('status'));
    }

    public function test_hash_tampering_blocks_even_withdrawal(): void
    {
        $journal = $this->submitted();
        DB::table('journal_entries')->where('id', $journal->getKey())->update(['description' => 'Tampered financial content']);
        foreach (['approve', 'reject', 'cancel'] as $action) {
            $this->invalid(fn () => $this->act($action, $journal->fresh(), $this->reviewer()), 'journal');
        }
    }

    public function test_missing_submission_evidence_cannot_be_approved(): void
    {
        $journal = $this->submitted();
        DB::table('journal_entries')->where('id', $journal->getKey())->update(['submitted_by' => null]);
        $this->invalid(fn () => $this->act('approve', $journal->fresh(), $this->reviewer()), 'journal');
    }

    public function test_nonmanual_or_posted_marker_cannot_transition(): void
    {
        foreach ([['entry_kind' => 'opening'], ['posted_by' => $this->actor->getKey()], ['posted_at' => now()]] as $change) {
            $journal = $this->createDraft();
            DB::table('journal_entries')->where('id', $journal->getKey())->update($change);
            $this->invalid(fn () => $this->act('submit', $journal->fresh()), 'journal');
        }
    }

    public function test_outer_transaction_rollback_restores_state_and_audit(): void
    {
        $journal = $this->createDraft();
        $count = DB::table('accounting_audit_events')->count();
        DB::beginTransaction();
        try {
            $this->act('submit', $journal);
        } finally {
            DB::rollBack();
        }
        $this->assertSame('draft', $journal->fresh()->getAttribute('status'));
        $this->assertSame($count, DB::table('accounting_audit_events')->count());
    }

    public function test_workflow_audit_keeps_prior_approval_after_edit_reset(): void
    {
        $journal = $this->approved();
        $reviewerId = (int) $journal->getAttribute('approved_by');
        $this->service->save($this->actor, $this->data(['revision' => 3, 'description' => 'New version']), (int) $journal->getKey());
        $event = DB::table('accounting_audit_events')->where('entity_kind', 'journal')->where('entity_id', $journal->getKey())->orderByDesc('id')->first();
        $before = json_decode($event->before_payload, true);
        $after = json_decode($event->after_payload, true);
        $this->assertSame($reviewerId, $before['approved_by']);
        $this->assertNotNull($before['approved_at']);
        $this->assertNull($after['approved_by']);
        $this->assertSame('draft', $after['status']);
    }

    public function test_posting_period_guard_accepts_real_approval_then_rejects_edited_revision(): void
    {
        $journal = $this->approved();
        $checked = DB::transaction(fn () => app(AccountingPeriodGuard::class)->lockForJournal($this->actor, (int) $this->company->getKey(), (int) $journal->getKey()));
        $this->assertSame((int) $journal->getKey(), $checked['journal_id']);
        $this->service->save($this->actor, $this->data(['revision' => 3]), (int) $journal->getKey());
        $this->invalid(fn () => DB::transaction(fn () => app(AccountingPeriodGuard::class)->lockForJournal($this->actor, (int) $this->company->getKey(), (int) $journal->getKey())), 'approval');
    }

    public function test_approval_revalidates_department_status(): void
    {
        $department = Department::query()->create(['company_id' => $this->company->getKey(), 'code' => 'WF-FIN', 'name' => 'Finance', 'status' => 'active']);
        $data = $this->data();
        $data['lines'][0]['department_id'] = $department->getKey();
        $journal = $this->act('submit', $this->service->save($this->actor, $data));
        $department->update(['status' => 'inactive']);
        $this->invalid(fn () => $this->act('approve', $journal, $this->reviewer()), 'lines.0.department_id');
    }

    public function test_inactive_company_blocks_workflow(): void
    {
        $journal = $this->submitted();
        $this->company->update(['status' => 'inactive']);
        $this->invalid(fn () => $this->act('approve', $journal, $this->reviewer()), 'company_id');
    }

    public function test_foreign_header_is_not_found_even_for_super_admin(): void
    {
        $foreign = $this->newCompany('WF-B');
        app(AccountingSetupService::class)->initialize($this->actor, (int) $foreign->getKey());
        $journal = $this->createDraft();
        $this->expectException(ModelNotFoundException::class);
        app(AccountingJournalWorkflowService::class)->submit($this->actor, (int) $foreign->getKey(), (int) $journal->getKey(), ['revision' => 1, 'reason' => 'Wrong scoped identity']);
    }

    public function test_balanced_line_tampering_is_detected_by_hash(): void
    {
        $journal = $this->submitted();
        DB::table('journal_entry_lines')->where('journal_entry_id', $journal->getKey())->where('line_number', 1)->update(['debit' => '200.20']);
        DB::table('journal_entry_lines')->where('journal_entry_id', $journal->getKey())->where('line_number', 2)->update(['credit' => '200.20']);
        $this->invalid(fn () => $this->act('approve', $journal, $this->reviewer()), 'journal');
    }

    public function test_read_exposes_current_workflow_evidence(): void
    {
        $journal = $this->approved();
        $read = $this->service->read($this->actor, (int) $journal->getKey());
        $this->assertSame('approved', $read['journal']['status']);
        $this->assertNotNull($read['journal']['submitted_at']);
        $this->assertNotNull($read['journal']['approved_at']);
        $this->assertSame((int) $journal->getAttribute('approved_by'), (int) $read['journal']['approved_by']);
        $this->assertArrayNotHasKey('email', $read['journal']);
    }
}

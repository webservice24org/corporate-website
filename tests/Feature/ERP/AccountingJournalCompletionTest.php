<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\AccountingJournalDraftService;
use App\Services\ERP\Accounting\AccountingJournalPostingService;
use App\Services\ERP\Accounting\AccountingJournalReversalService;
use App\Services\ERP\Accounting\AccountingJournalWorkflowService;
use App\Services\ERP\Accounting\AccountingLedgerPrototype;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\AccountingStarterService;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AccountingJournalCompletionTest extends TestCase
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

    private function report(array $input = [], ?User $actor = null): array
    {
        return app(AccountingLedgerPrototype::class)->read($actor ?? $this->actor, (int) $this->company->getKey(), ['account_id' => $this->account('5500')->getKey(), ...$input]);
    }

    public function test_only_posted_lines_affect_ledger(): void
    {
        $this->createDraft();
        $this->submitted();
        $this->approved();
        $this->act('cancel', $this->createDraft());
        $this->assertSame('0.00', $this->report()['closing']);
        $this->posted();
        $result = $this->report();
        $this->assertSame('100.10', $result['closing']);
        $this->assertSame(1, $result['total']);
    }

    public function test_reversal_keeps_both_posted_entries_and_nets_to_zero(): void
    {
        $original = $this->posted();
        $reverse = $this->postJournal($this->approvedReversal($original));
        $result = $this->report();
        $this->assertSame('0.00', $result['closing']);
        $this->assertCount(2, $result['entries']);
        $this->assertSame('100.10', $result['total_debit']);
        $this->assertSame('100.10', $result['total_credit']);
        $this->assertSame((int) $reverse->getKey(), $result['entries'][1]['journal_id']);
    }

    public function test_from_date_derives_carry_and_retains_reversal_activity(): void
    {
        $original = $this->posted();
        $this->postJournal($this->approvedReversal($original));
        $result = $this->report(['from' => '2026-10-16', 'to' => '2026-10-16']);
        $this->assertSame('100.10', $result['opening']);
        $this->assertSame('100.10', $result['pageCarry']);
        $this->assertSame('0.00', $result['closing']);
        $this->assertSame(1, $result['total']);
    }

    public function test_as_of_original_date_excludes_later_reversal(): void
    {
        $original = $this->posted();
        $this->postJournal($this->approvedReversal($original));
        $this->assertSame('100.10', $this->report(['to' => '2026-10-15'])['closing']);
    }

    public function test_credit_side_balance_is_signed_and_exact(): void
    {
        $this->posted();
        $this->assertSame('-100.10', $this->report(['account_id' => $this->account('3200')->getKey()])['closing']);
    }

    public function test_report_permission_is_independent_and_does_not_grant_drilldown(): void
    {
        $this->posted();
        $actor = $this->staff(['erp.accounting-reports.view']);
        $result = $this->report([], $actor);
        $this->assertFalse($result['canViewJournals']);
        $this->assertSame('100.10', $result['closing']);
        $this->expectException(AuthorizationException::class);
        $this->report([], $this->staff(['erp.journal-entries.view']));
    }

    public function test_foreign_account_is_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->report(['account_id' => 999999]);
    }

    public function test_reading_does_not_require_active_account_or_enabled_setup(): void
    {
        $this->posted();
        $this->account('5500')->update(['status' => 'inactive']);
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['state' => 'disabled']);
        $this->assertSame('100.10', $this->report()['closing']);
    }

    public function test_dates_are_validated(): void
    {
        $this->invalid(fn () => $this->report(['from' => '2026-11-01', 'to' => '2026-10-01']), 'to');
    }

    public function test_print_and_ledger_pages_resolve_and_hide_audit_snapshots(): void
    {
        $row = $this->createDraft();
        $this->actingAs($this->actor)->get('/erp/accounting/journals/'.$row->getKey().'/print')->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Journals/Print')->where('journal.status', 'draft')->missing('audit')->where('total_debit', '100.10'));
        $this->get('/erp/accounting/ledger?company_id='.$this->company->getKey().'&account_id='.$this->account('5500')->getKey())->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Ledger/Index')->where('result.closing', '0.00'));
    }

    public function test_print_requires_journal_view_and_ledger_requires_report_view(): void
    {
        $row = $this->createDraft();
        $this->actingAs($this->staff(['erp.journal-entries.view']))->get('/erp/accounting/ledger')->assertForbidden();
        $this->actingAs($this->staff(['erp.accounting-reports.view']))->get('/erp/accounting/journals/'.$row->getKey().'/print')->assertForbidden();
    }

    public function test_foreign_company_filter_is_forbidden(): void
    {
        $other = $this->newCompany('GL-B');
        $this->actingAs($this->staff(['erp.accounting-reports.view']))->get('/erp/accounting/ledger?company_id='.$other->getKey())->assertForbidden();
    }

    public function test_second_page_has_exact_carry_and_whole_selection_totals(): void
    {
        $this->enablePosting();
        $lines = [];
        for ($i = 0; $i < 101; $i++) {
            $lines[] = ['account_id' => $this->account('5500')->getKey(), 'debit' => '1.00', 'credit' => '0.00'];
        }
        $lines[] = ['account_id' => $this->account('3200')->getKey(), 'debit' => '0.00', 'credit' => '101.00'];
        $draft = $this->createDraft(['lines' => $lines]);
        $this->postJournal($this->act('approve', $this->act('submit', $draft), $this->reviewer()));
        $result = $this->report(['page' => 2]);
        $this->assertSame(101, $result['total']);
        $this->assertCount(1, $result['entries']);
        $this->assertSame('100.00', $result['pageCarry']);
        $this->assertSame('101.00', $result['entries'][0]['balance']);
        $this->assertSame('101.00', $result['total_debit']);
        $this->assertSame('101.00', $result['closing']);
    }
}

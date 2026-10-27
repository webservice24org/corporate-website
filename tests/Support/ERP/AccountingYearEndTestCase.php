<?php

declare(strict_types=1);

namespace Tests\Support\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingYearEndEvent;
use App\Models\Accounting\FiscalYear;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\AccountingJournalDraftService;
use App\Services\ERP\Accounting\AccountingJournalPostingService;
use App\Services\ERP\Accounting\AccountingJournalWorkflowService;
use App\Services\ERP\Accounting\YearEnd\AccountingYearEndWorkflow;
use Database\Seeders\AccountingBalanceSheetPermissionSeeder;
use Database\Seeders\AccountingCashFlowPermissionSeeder;
use Database\Seeders\AccountingProfitLossPermissionSeeder;
use Database\Seeders\AccountingYearEndPermissionSeeder;
use Illuminate\Support\Str;

abstract class AccountingYearEndTestCase extends AccountingPeriodCloseTestCase
{
    protected FiscalYear $endYear;

    protected AccountingYearEndWorkflow $yearWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([AccountingYearEndPermissionSeeder::class, AccountingProfitLossPermissionSeeder::class, AccountingBalanceSheetPermissionSeeder::class, AccountingCashFlowPermissionSeeder::class] as $seeder) {
            $this->seed($seeder);
        }
        $year = FiscalYear::query()->whereKey($this->closePeriod->getAttribute('fiscal_year_id'))->firstOrFail();
        $this->endYear = app(AccountingCalendarService::class)->saveYear($this->maker, ['company_id' => $this->company->getKey(), 'code' => $year->getAttribute('code'), 'name' => $year->getAttribute('name'), 'starts_on' => '2026-01-01', 'ends_on' => '2026-10-31', 'revision' => (int) $year->getAttribute('revision'), 'reason' => 'Short first active fiscal-year fixture'], (int) $year->getKey());
        $this->yearWorkflow = app(AccountingYearEndWorkflow::class);
    }

    protected function closeMonth(): void
    {
        $this->prepareClose();
        $this->approveClose();
        $this->hardClose();
    }

    /** @return array<string, mixed> */
    protected function yearReview(?User $actor = null): array
    {
        return $this->yearWorkflow->page($actor ?? $this->maker, (int) $this->company->getKey(), (int) $this->endYear->getKey())['review'];
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    protected function yearCommand(array $extra = []): array
    {
        $r = $this->yearReview();

        return ['revision' => $r['year']['revision'], 'sequence' => $r['sequence'], 'key' => (string) Str::uuid(), 'reason' => 'Reviewed year-end action', ...$extra];
    }

    protected function prepareYear(string $mode = 'close', ?User $actor = null): AccountingYearEndEvent
    {
        return $this->yearWorkflow->change($actor ?? $this->maker, (int) $this->company->getKey(), (int) $this->endYear->getKey(), $mode, 'prepare', $this->yearCommand());
    }

    protected function approveYear(string $mode = 'close', ?User $actor = null): AccountingYearEndEvent
    {
        return $this->yearWorkflow->change($actor ?? $this->reviewer, (int) $this->company->getKey(), (int) $this->endYear->getKey(), $mode, 'approve', $this->yearCommand(['review_reference' => 'YEAR-END-REVIEW-2026', 'period_reviews_confirmed' => true]));
    }

    /** @param array<string, mixed>|null $command */
    protected function postYear(string $mode = 'close', ?User $actor = null, ?array $command = null): AccountingYearEndEvent
    {
        return $this->yearWorkflow->change($actor ?? ($mode === 'close' ? $this->maker : $this->reviewer), (int) $this->company->getKey(), (int) $this->endYear->getKey(), $mode, 'post', $command ?? $this->yearCommand());
    }

    protected function closeExpenseYear(): AccountingYearEndEvent
    {
        $this->recognized($this->expense());
        $this->closeMonth();
        $this->prepareYear();
        $this->approveYear();

        return $this->postYear();
    }

    protected function income(string $amount = '125.01'): void
    {
        $group = AccountGroup::query()->where('company_id', $this->company->getKey())->where('category', 'asset')->firstOrFail();
        $asset = Account::query()->create(['company_id' => $this->company->getKey(), 'account_group_id' => $group->getKey(), 'code' => '9090', 'name' => 'Noncash income fixture asset', 'normal_side' => 'debit', 'is_postable' => true, 'control_role' => null, 'statement_role' => 'current_asset', 'cash_flow_role' => 'operating', 'status' => 'active', 'revision' => 1]);
        $journal = app(AccountingJournalDraftService::class)->save($this->maker, ['company_id' => $this->company->getKey(), 'accounting_date' => '2026-10-26', 'document_date' => '2026-10-26', 'currency_code' => 'BDT', 'description' => 'Noncash income', 'reason' => 'Year-end revenue fixture', 'lines' => [['account_id' => $asset->getKey(), 'debit' => $amount, 'credit' => '0.00'], ['account_id' => $this->mapping['sales_revenue'], 'debit' => '0.00', 'credit' => $amount]]]);
        $workflow = app(AccountingJournalWorkflowService::class);
        $id = (int) $journal->getKey();
        $company = (int) $this->company->getKey();
        $journal = $workflow->submit($this->maker, $company, $id, ['revision' => (int) $journal->getAttribute('revision'), 'reason' => 'Submit income']);
        $journal = $workflow->approve($this->reviewer, $company, $id, ['revision' => (int) $journal->getAttribute('revision'), 'reason' => 'Approve income']);
        app(AccountingJournalPostingService::class)->post($this->maker, $company, $id, ['revision' => (int) $journal->getAttribute('revision'), 'reason' => 'Post income']);
    }
}

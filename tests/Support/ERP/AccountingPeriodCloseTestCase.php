<?php

declare(strict_types=1);

namespace Tests\Support\ERP;

use App\Models\Accounting\AccountingPeriod;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingPeriodStateService;
use App\Services\ERP\Accounting\Close\AccountingPeriodCloseWorkflow;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingAgingPermissionSeeder;
use Database\Seeders\AccountingBankReconciliationPermissionSeeder;
use Database\Seeders\AccountingBankStatementPermissionSeeder;
use Database\Seeders\AccountingCashBookPermissionSeeder;
use Database\Seeders\AccountingFinancialReportPermissionSeeder;
use Database\Seeders\AccountingPeriodClosePermissionSeeder;
use Database\Seeders\AccountingTaxPermissionSeeder;
use Illuminate\Support\Facades\DB;

abstract class AccountingPeriodCloseTestCase extends AccountingExpenseWorkspaceTestCase
{
    protected AccountingPeriod $closePeriod;

    protected AccountingPeriodCloseWorkflow $closeWorkflow;

    /** @var array<string, mixed> */
    protected array $bankFixture;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([AccountingPeriodClosePermissionSeeder::class, AccountingFinancialReportPermissionSeeder::class, AccountingAgingPermissionSeeder::class, AccountingTaxPermissionSeeder::class, AccountingCashBookPermissionSeeder::class, AccountingBankStatementPermissionSeeder::class, AccountingBankReconciliationPermissionSeeder::class] as $seeder) {
            $this->seed($seeder);
        }
        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00', 'Asia/Dhaka'));
        $this->closePeriod = AccountingPeriod::query()->where('company_id', $this->company->getKey())->firstOrFail();
        // A zero-activity fixture with no bank relationship; real bank requirements are tested separately.
        $row = DB::table('accounting_cash_accounts')->where('id', $this->bank->getKey())->first();
        $this->bankFixture = (array) $row;
        DB::table('accounting_cash_accounts')->where('id', $this->bank->getKey())->delete();
        $this->closeWorkflow = app(AccountingPeriodCloseWorkflow::class);
    }

    /** @return array<string, mixed> */
    protected function reviewClose(?User $actor = null): array
    {
        return $this->closeWorkflow->page($actor ?? $this->maker, (int) $this->company->getKey(), (int) $this->closePeriod->getKey())['review'];
    }

    /** @return array<string, mixed> */
    protected function closeCommand(): array
    {
        $r = $this->reviewClose();

        return ['revision' => $r['period']['revision'], 'sequence' => $r['sequence'], 'reason' => 'Review ended month'];
    }

    protected function prepareClose(): void
    {
        $this->closeWorkflow->prepare($this->maker, (int) $this->company->getKey(), (int) $this->closePeriod->getKey(), $this->closeCommand());
    }

    /** @param array<string, mixed> $extra */
    protected function approveClose(?User $actor = null, array $extra = []): void
    {
        $this->closeWorkflow->approve($actor ?? $this->reviewer, (int) $this->company->getKey(), (int) $this->closePeriod->getKey(), [...$this->closeCommand(), 'inventory_amount' => '0.00', 'dispatched_amount' => '0.00', 'inventory_reference' => 'INV-OCT26-zero-stock-reviewed', 'adjustments_reference' => 'ADJ-OCT26-other-payables-reviewed', 'adjustments_complete' => true, ...$extra]);
    }

    protected function hardClose(): void
    {
        $this->closeWorkflow->close($this->maker, (int) $this->company->getKey(), (int) $this->closePeriod->getKey(), $this->closeCommand());
    }

    protected function softClose(): void
    {
        $this->closePeriod->refresh();
        app(AccountingPeriodStateService::class)->close($this->maker, (int) $this->company->getKey(), (int) $this->closePeriod->getAttribute('fiscal_year_id'), (int) $this->closePeriod->getKey(), ['state' => 'soft_closed', 'revision' => (int) $this->closePeriod->getAttribute('revision'), 'reason' => 'Work remains in progress']);
    }
}

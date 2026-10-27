<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Services\ERP\Accounting\Dashboard\AccountingFinanceDashboard;
use App\Services\ERP\Accounting\Reports\AccountingAgingReader;
use Database\Seeders\AccountingAgingPermissionSeeder;
use Database\Seeders\AccountingFinanceDashboardPermissionSeeder;
use Tests\Support\ERP\AccountingApAgingTestCase;

final class AccountingDashboardApTest extends AccountingApAgingTestCase
{
    public function test_dashboard_ap_reproduces_dated_reader_and_exact_cutoff_link(): void
    {
        $this->seed(AccountingFinanceDashboardPermissionSeeder::class);
        $this->seed(AccountingAgingPermissionSeeder::class);
        $this->liveBill();
        $actor = $this->actor;
        $id = (int) $this->company->getKey();
        $report = app(AccountingAgingReader::class)->report($actor, $id, 'ap', ['as_of' => '2026-10-16']);
        $page = app(AccountingFinanceDashboard::class)->page($actor, ['company_id' => $id, 'from' => '2026-10-01', 'to' => '2026-10-16']);
        $cards = array_column($page['dashboard']['cards'], null, 'id');
        $this->assertSame($report['totals']['open_documents'], $cards['ap']['value']);
        $this->assertSame('cutoff', $cards['ap']['basis']);
        $this->assertStringContainsString('as_of=2026-10-16', $cards['ap']['url']);
    }
}

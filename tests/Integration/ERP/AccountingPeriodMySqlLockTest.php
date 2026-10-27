<?php

declare(strict_types=1);

namespace Tests\Integration\ERP;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\AccountingPeriodGuard;
use App\Services\ERP\Accounting\AccountingPeriodStateService;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\Close\AccountingPeriodCloseGuard;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

/** Opt-in, two independent MySQL connections; rebuilds only a dedicated *_test DB.
 * The journal update is a lock-level stand-in, not the later balanced journal engine.
 */
final class AccountingPeriodMySqlLockTest extends TestCase
{
    private string $primaryName = '';

    private bool $configured = false;

    private Company $company;

    private FiscalYear $year;

    private AccountingPeriod $period;

    private JournalEntry $journal;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        // Isolate the pre-existing calendar state/lock contract. The 6.13A suites exercise the real close gate.
        $this->mock(AccountingPeriodCloseGuard::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('assertReady')->andReturnNull();
        });
        if (getenv('ACCOUNTING_MYSQL_LOCK_TESTS') !== '1') {
            $this->markTestSkipped('Opt in with ACCOUNTING_MYSQL_LOCK_TESTS=1 and a dedicated MySQL test database.');
        }
        if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql' || preg_match('/(?:_test|_testing)\z/', DB::connection()->getDatabaseName()) !== 1) {
            $this->markTestSkipped('Requires testing environment and a dedicated MySQL database ending _test or _testing.');
        }
        $this->primaryName = DB::getDefaultConnection();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(AccountingPermissionSeeder::class);
        $this->actor = User::factory()->create();
        $this->actor->assignRole('super-admin');
        $reviewer = User::factory()->create();
        $reviewer->assignRole('super-admin');
        $this->company = Company::query()->create(['name' => 'Lock fixture', 'code' => 'LOCK-A', 'status' => 'active', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka']);
        app(AccountingSetupService::class)->initialize($this->actor, (int) $this->company->getKey());
        $calendar = app(AccountingCalendarService::class);
        $this->year = $calendar->saveYear($this->actor, ['company_id' => $this->company->getKey(), 'code' => 'FY26', 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'reason' => 'Lock fixture']);
        $this->period = $calendar->savePeriod($this->actor, (int) $this->year->getKey(), ['company_id' => $this->company->getKey(), 'code' => 'OCT26', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'reason' => 'Lock fixture']);
        $this->journal = JournalEntry::query()->create(['company_id' => $this->company->getKey(), 'accounting_period_id' => $this->period->getKey(), 'number' => 'LOCK-JV', 'entry_kind' => 'manual', 'status' => 'approved', 'accounting_date' => '2026-10-15', 'currency_code' => 'BDT', 'description' => 'Lock fixture only', 'created_by' => $this->actor->getKey(), 'updated_by' => $this->actor->getKey(), 'approved_by' => $reviewer->getKey(), 'approved_at' => now()]);
        config(['database.connections.accounting_lock_peer' => config('database.connections.'.$this->primaryName)]);
        DB::purge('accounting_lock_peer');
        DB::connection('accounting_lock_peer')->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $this->configured = true;
    }

    protected function tearDown(): void
    {
        if ($this->configured) {
            foreach ([$this->primaryName, 'accounting_lock_peer'] as $name) {
                $connection = DB::connection($name);
                while ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
            }
            DB::setDefaultConnection($this->primaryName);
            DB::purge('accounting_lock_peer');
        }
        parent::tearDown();
    }

    private function peer(callable $action): mixed
    {
        DB::setDefaultConnection('accounting_lock_peer');
        try {
            return $action();
        } finally {
            DB::setDefaultConnection($this->primaryName);
        }
    }

    private function closePeriod(): void
    {
        app(AccountingPeriodStateService::class)->close($this->actor, (int) $this->company->getKey(), (int) $this->year->getKey(), (int) $this->period->getKey(), ['state' => 'hard_closed', 'revision' => 1, 'reason' => 'Verify lock serialization']);
    }

    private function checkJournal(): void
    {
        DB::transaction(fn () => app(AccountingPeriodGuard::class)->lockForJournal($this->actor, (int) $this->company->getKey(), (int) $this->journal->getKey()));
    }

    private function timesOut(callable $action): void
    {
        try {
            $this->peer($action);
            $this->fail('The competing writer should wait on the shared company lock.');
        } catch (QueryException $exception) {
            $this->assertSame(1205, (int) ($exception->errorInfo[1] ?? 0));
        }
    }

    public function test_close_wins_then_waiting_guard_rechecks_and_rejects(): void
    {
        $primary = DB::connection($this->primaryName);
        $primary->beginTransaction();
        $this->closePeriod();
        $this->timesOut(fn () => $this->checkJournal());
        $primary->commit();
        try {
            $this->peer(fn () => $this->checkJournal());
            $this->fail('A guard after committed closure must reject.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('accounting_date', $exception->errors());
        }
        $this->assertSame('approved', $this->journal->fresh()->getAttribute('status'));
    }

    public function test_guard_retains_lock_through_write_before_close_can_proceed(): void
    {
        $primary = DB::connection($this->primaryName);
        $primary->beginTransaction();
        $this->checkJournal();
        $this->journal->update(['status' => 'posted', 'posted_at' => now()]);
        $this->timesOut(fn () => $this->closePeriod());
        $primary->commit();
        $this->peer(fn () => $this->closePeriod());
        $this->assertSame('posted', $this->journal->fresh()->getAttribute('status'));
        $this->assertSame('hard_closed', $this->period->fresh()->getAttribute('state'));
    }
}

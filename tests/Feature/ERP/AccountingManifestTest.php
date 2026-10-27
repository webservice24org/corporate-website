<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountGroup;
use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingCutoverSource;
use App\Models\Accounting\AccountingManifestVersion;
use App\Models\Accounting\AccountingOpeningWorkspace;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCalendarService;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingCoverageResolver;
use App\Services\ERP\Accounting\AccountingCutoverManifest;
use App\Services\ERP\Accounting\AccountingCutoverPreparation;
use App\Services\ERP\Accounting\AccountingJournalDraftService;
use App\Services\ERP\Accounting\AccountingJournalWorkflowService;
use App\Services\ERP\Accounting\AccountingManifestSources;
use App\Services\ERP\Accounting\AccountingOpeningEvidence;
use App\Services\ERP\Accounting\AccountingOpeningService;
use App\Services\ERP\Accounting\AccountingSetupService;
use App\Services\ERP\Accounting\AccountingStarterService;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AccountingManifestTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private FiscalYear $year;

    private AccountingPeriod $period;

    private AccountingJournalDraftService $service;

    private AccountingCutover $plan;

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
        $group = AccountGroup::query()->where('company_id', $this->company->getKey())->where('category', 'asset')->firstOrFail();
        $parent = Account::query()->where('company_id', $this->company->getKey())->where('account_group_id', $group->getKey())->whereNull('parent_id')->firstOrFail();
        Account::query()->create(['company_id' => $this->company->getKey(), 'account_group_id' => $group->getKey(), 'parent_id' => $parent->getKey(), 'code' => '1599', 'name' => 'Noncash opening asset fixture', 'normal_side' => 'debit', 'is_postable' => true, 'status' => 'active', 'control_role' => null, 'statement_role' => 'current_asset', 'cash_flow_role' => 'noncash', 'revision' => 1]);
        $this->plan = $this->savePlan();
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

    private function planData(array $overrides = []): array
    {
        return ['company_id' => (int) $this->company->getKey(), 'cutover_date' => '2026-10-16', 'strategy' => 'snapshot', 'reason' => 'Prepare reviewed company snapshot', ...$overrides];
    }

    private function savePlan(array $overrides = [], ?int $id = null, ?User $actor = null): AccountingCutover
    {
        return app(AccountingCutoverPreparation::class)->save($actor ?? $this->actor, $this->planData($overrides), $id);
    }

    private function abortPlan(AccountingCutover $row, array $input = []): AccountingCutover
    {
        return app(AccountingCutoverPreparation::class)->abort($this->actor, (int) $this->company->getKey(), (int) $row->getKey(), ['revision' => $row->getAttribute('revision'), 'reason' => 'Withdraw untouched draft plan', ...$input]);
    }

    private function openingData(array $overrides = []): array
    {
        return ['cutover_revision' => $this->plan->getAttribute('revision'), 'description' => 'Reviewed opening asset/capital snapshot', 'supporting_reference' => 'Approved working-paper TB-2026-10', 'reason' => 'Prepare financial opening for review', 'lines' => [
            ['account_id' => $this->account('1599')->getKey(), 'debit' => '100.10', 'credit' => '0.00'],
            ['account_id' => $this->account('3200')->getKey(), 'debit' => '0.00', 'credit' => '100.10'],
        ], ...$overrides];
    }

    private function opening(array $overrides = [], ?User $actor = null): JournalEntry
    {
        return app(AccountingOpeningService::class)->save($actor ?? $this->actor, (int) $this->plan->getKey(), $this->openingData($overrides));
    }

    private function openingAction(string $action, JournalEntry $row, ?User $actor = null, array $input = []): JournalEntry
    {
        return app(AccountingOpeningService::class)->transition($actor ?? $this->actor, (int) $this->plan->getKey(), $action, ['revision' => $row->getAttribute('revision'), 'cutover_revision' => $this->plan->getAttribute('revision'), 'reason' => 'Financial review action', ...$input]);
    }

    private function submittedOpening(): JournalEntry
    {
        return $this->openingAction('submit', $this->opening());
    }

    private function approvedOpening(): JournalEntry
    {
        return $this->openingAction('approve', $this->submittedOpening(), $this->reviewer());
    }

    private function captureManifest(JournalEntry $row, array $input = [], ?User $actor = null): AccountingCutover
    {
        return app(AccountingCutoverManifest::class)->capture($actor ?? $this->actor, (int) $this->plan->getKey(), ['cutover_revision' => $this->plan->fresh()->getAttribute('revision'), 'opening_revision' => $row->fresh()->getAttribute('revision'), 'reason' => 'Capture pending source evidence for review', ...$input]);
    }

    private function invalidateManifest(JournalEntry $row, array $input = []): AccountingCutover
    {
        return app(AccountingCutoverManifest::class)->invalidate($this->actor, (int) $this->plan->getKey(), ['cutover_revision' => $this->plan->fresh()->getAttribute('revision'), 'opening_revision' => $row->fresh()->getAttribute('revision'), 'reason' => 'Invalidate current capture before revision', ...$input]);
    }

    private function bill(string $date = '2026-10-15', string $status = 'approved', ?Company $company = null): SupplierBill
    {
        $company ??= $this->company;
        $code = 'MF-'.(Supplier::query()->count() + 1);
        $supplier = Supplier::query()->create(['company_id' => $company->getKey(), 'code' => $code, 'name' => 'Supplier fixture', 'currency_code' => 'BDT', 'status' => 'active', 'notes' => 'PRIVATE-SUPPLIER']);

        return SupplierBill::query()->create(['company_id' => $company->getKey(), 'supplier_id' => $supplier->getKey(), 'number' => 'B-'.$code, 'supplier_invoice_number' => 'SI-'.$code, 'status' => $status, 'invoice_date' => $date, 'subtotal' => '77.25', 'grand_total' => '77.25', 'currency_code' => 'BDT', 'created_by' => $this->actor->getKey(), 'approved_by' => $status === 'approved' ? $this->actor->getKey() : null, 'approved_at' => $status === 'approved' ? now() : null, 'revision' => 1, 'notes' => 'PRIVATE-BILL']);
    }

    private function verifyManifest(JournalEntry $row): array
    {
        return app(AccountingCutoverManifest::class)->verify($this->plan->fresh(), AccountingOpeningWorkspace::query()->where('cutover_id', $this->plan->getKey())->firstOrFail(), $row->fresh());
    }

    public function test_capture_is_pending_and_preserves_balances_and_disabled_settings(): void
    {
        $row = $this->opening();
        $bill = $this->bill();
        $plan = $this->captureManifest($row);
        $payload = $this->verifyManifest($row);
        $this->assertSame('draft', $plan->getAttribute('status'));
        $this->assertSame(2, $plan->getAttribute('revision'));
        $this->assertSame('pending_reconciliation', $payload['recognition_state']);
        $this->assertCount(1, $payload['rows']);
        $this->assertDatabaseHas('accounting_cutover_sources', ['cutover_id' => $plan->getKey(), 'source_id' => $bill->getKey(), 'source_kind' => 'supplier_bill', 'coverage_kind' => 'snapshot', 'covered_through_event' => null]);
        $this->assertNull($plan->getAttribute('reconciliation_hash'));
        $this->assertDatabaseHas('accounting_company_settings', ['company_id' => $this->company->getKey(), 'state' => 'disabled']);
        $this->assertDatabaseCount('accounting_source_effects', 0);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_empty_capture_does_not_certify_reconciliation(): void
    {
        $row = $this->opening();
        $this->captureManifest($row);
        $payload = $this->verifyManifest($row);
        $this->assertSame([], $payload['rows']);
        $this->assertSame('not_reconciled', $payload['coverage_catalog']['dated_allocations_and_credits']);
        $this->assertSame('pending_reconciliation', $payload['recognition_state']);
    }

    public function test_approved_opening_is_reset_by_capture(): void
    {
        $row = $this->approvedOpening();
        $hash = $row->getAttribute('payload_hash');
        $this->captureManifest($row);
        $this->assertSame('draft', $row->fresh()->getAttribute('status'));
        $this->assertSame(4, $row->fresh()->getAttribute('revision'));
        $this->assertNull($row->fresh()->getAttribute('approved_at'));
        $this->assertNull($row->fresh()->getAttribute('submitted_at'));
        $this->assertSame($hash, $row->fresh()->getAttribute('payload_hash'));
    }

    public function test_invalidation_retains_archive_and_allows_opening_edit_again(): void
    {
        $row = $this->opening();
        $this->bill();
        $this->captureManifest($row);
        $oldHash = $this->plan->fresh()->getAttribute('manifest_hash');
        $plan = $this->invalidateManifest($row);
        $this->assertNull($plan->getAttribute('manifest_hash'));
        $this->assertDatabaseCount('accounting_cutover_sources', 0);
        $this->assertDatabaseCount('accounting_manifest_versions', 1);
        $this->assertSame($oldHash, AccountingManifestVersion::query()->firstOrFail()->getAttribute('payload_hash'));
        $this->plan = $plan;
        $edited = $this->opening(['revision' => $row->fresh()->getAttribute('revision')]);
        $this->assertSame('draft', $edited->getAttribute('status'));
    }

    public function test_explicit_invalidation_is_required_before_recapture(): void
    {
        $row = $this->opening();
        $this->captureManifest($row);
        $this->invalid(fn () => $this->captureManifest($row), 'manifest');
        $this->invalidateManifest($row);
        $this->captureManifest($row);
        $this->assertDatabaseCount('accounting_manifest_versions', 2);
    }

    public function test_stale_revisions_cannot_change_capture_or_audit(): void
    {
        $row = $this->opening();
        $count = DB::table('accounting_audit_events')->count();
        $this->invalid(fn () => $this->captureManifest($row, ['cutover_revision' => 99]), 'cutover_revision');
        $this->invalid(fn () => $this->captureManifest($row, ['opening_revision' => 99]), 'opening_revision');
        $this->assertSame($count, DB::table('accounting_audit_events')->count());
        $this->assertDatabaseCount('accounting_manifest_versions', 0);
    }

    public function test_capture_cannot_accept_client_sources_hashes_or_recognized_status(): void
    {
        $row = $this->opening();
        foreach (['rows', 'manifest_hash', 'recognition_state', 'reconciliation_hash', 'covered_through_event'] as $key) {
            $this->invalid(fn () => $this->captureManifest($row, [$key => 'forged']), 'manifest');
        }
    }

    public function test_submitted_opening_must_be_rejected_before_capture(): void
    {
        $row = $this->submittedOpening();
        $this->invalid(fn () => $this->captureManifest($row), 'opening');
    }

    public function test_source_catalog_is_scoped_and_excludes_draft_or_cutover_day_bills(): void
    {
        $included = $this->bill();
        $this->bill('2026-10-16');
        $this->bill('2026-10-14', 'draft');
        $this->bill('2026-10-15', 'approved', $this->newCompany('MF-OTHER'));
        $rows = app(AccountingManifestSources::class)->collect((int) $this->company->getKey(), '2026-10-16');
        $this->assertCount(1, $rows);
        $this->assertSame((int) $included->getKey(), $rows[0]['source_id']);
        $this->assertSame('77.25', $rows[0]['opening_state']['snapshot']['document_total']);
        $this->assertArrayNotHasKey('notes', $rows[0]['opening_state']['snapshot']);
    }

    public function test_source_fingerprint_changes_without_modifying_archived_capture(): void
    {
        $row = $this->opening();
        $bill = $this->bill();
        $this->captureManifest($row);
        $archived = $this->verifyManifest($row);
        $bill->update(['grand_total' => '88.35']);
        $live = app(AccountingManifestSources::class)->collect((int) $this->company->getKey(), '2026-10-16');
        $this->assertNotSame($archived['rows'][0]['source_payload_hash'], $live[0]['source_payload_hash']);
        $this->assertSame($archived, $this->verifyManifest($row));
    }

    public function test_archive_and_current_coverage_tampering_are_detected(): void
    {
        $row = $this->opening();
        $this->bill();
        $this->captureManifest($row);
        DB::table('accounting_cutover_sources')->update(['source_payload_hash' => str_repeat('f', 64)]);
        $this->invalid(fn () => $this->verifyManifest($row), 'manifest');
        $this->invalid(fn () => $this->invalidateManifest($row), 'manifest');
    }

    public function test_capture_versions_are_append_only(): void
    {
        $row = $this->opening();
        $this->captureManifest($row);
        $version = AccountingManifestVersion::query()->firstOrFail();
        $this->expectException(\LogicException::class);
        $version->update(['payload_hash' => str_repeat('a', 64)]);
    }

    public function test_legacy_65b_hash_is_readable_and_upgraded_on_capture(): void
    {
        $row = $this->opening();
        $workspace = AccountingOpeningWorkspace::query()->firstOrFail();
        $evidence = app(AccountingOpeningEvidence::class);
        $payload = $evidence->payload($this->plan, $row, $workspace->getAttribute('supporting_reference'), $evidence->lines($row));
        unset($payload['opening_payload_version']);
        $payload['cutover_revision'] = (int) $this->plan->getAttribute('revision');
        $hash = app(AccountingCanonicalPayload::class)->hash($payload);
        DB::table('journal_entries')->where('id', $row->getKey())->update(['payload_hash' => $hash]);
        $evidence->verify($this->plan, $workspace, $row->fresh());
        $this->assertSame($hash, $row->fresh()->getAttribute('payload_hash'));
        $this->captureManifest($row);
        $this->assertNotSame($hash, $row->fresh()->getAttribute('payload_hash'));
        $this->verifyManifest($row);
    }

    public function test_outer_rollback_preserves_opening_review_and_creates_no_archive(): void
    {
        $row = $this->approvedOpening();
        $before = $row->getAttributes();
        $count = DB::table('accounting_audit_events')->count();
        DB::beginTransaction();
        try {
            $this->captureManifest($row);
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertDatabaseCount('accounting_manifest_versions', 0);
        $this->assertSame($count, DB::table('accounting_audit_events')->count());
    }

    public function test_view_only_actor_cannot_capture(): void
    {
        $row = $this->opening();
        $this->expectException(AuthorizationException::class);
        $this->captureManifest($row, [], $this->staff(['erp.accounting-openings.view']));
    }

    public function test_existing_coverage_freezes_opening_writer_until_invalidation(): void
    {
        $row = $this->opening();
        $this->captureManifest($row);
        $this->invalid(fn () => $this->opening(['revision' => 2, 'cutover_revision' => 2]), 'cutover');
    }

    public function test_prepared_manifest_cannot_claim_activated_original_recognition(): void
    {
        $row = $this->opening();
        $bill = $this->bill();
        $this->captureManifest($row);
        $reviewer = $this->reviewer();
        // Isolated malformed activation fixture proves metadata alone cannot elevate prepared coverage.
        DB::table('accounting_company_settings')->where('company_id', $this->company->getKey())->update(['state' => 'enabled', 'activation_date' => '2026-10-16', 'activated_by' => $this->actor->getKey(), 'activated_at' => now()]);
        DB::table('accounting_cutovers')->where('id', $this->plan->getKey())->update(['status' => 'activated', 'approved_by' => $reviewer->getKey(), 'approved_at' => now(), 'activated_by' => $this->actor->getKey(), 'activated_at' => now(), 'reconciliation_hash' => str_repeat('b', 64)]);
        $hash = AccountingCutoverSource::query()->firstOrFail()->getAttribute('source_payload_hash');
        $this->invalid(fn () => DB::transaction(fn () => app(AccountingCoverageResolver::class)->requireRecognized($this->actor, (int) $this->company->getKey(), 'supplier_bill', (int) $bill->getKey(), $hash, 'original-recognition')), 'coverage');
    }

    public function test_http_routes_capture_invalidate_and_show_stale_observations(): void
    {
        $this->assertTrue(Route::has('erp.accounting.manifest.index'), 'Mount accounting-manifest.php once at top level in web.php.');
        $row = $this->opening();
        $bill = $this->bill();
        $this->get('/erp/accounting/manifest')->assertRedirect('/login');
        $this->actingAs($this->actor)->post('/erp/accounting/manifest/'.$this->plan->getKey().'/capture', ['cutover_revision' => 1, 'opening_revision' => 1, 'reason' => 'Capture through route'])->assertRedirect();
        $url = '/erp/accounting/manifest?cutover_id='.$this->plan->getKey();
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('ERP/Accounting/Manifest/Index')->where('stale', false)->has('rows', 1)->missing('history.0.payload'));
        $bill->update(['grand_total' => '88.35']);
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('stale', true));
        $this->post('/erp/accounting/manifest/'.$this->plan->getKey().'/invalidate', ['cutover_revision' => 2, 'opening_revision' => 2, 'reason' => 'Review changed source'])->assertRedirect();
        $this->assertDatabaseCount('accounting_manifest_versions', 1);
    }

    public function test_capture_limit_never_saves_a_partial_manifest_or_resets_review(): void
    {
        $row = $this->approvedOpening();
        $base = $this->bill()->getAttributes();
        unset($base['id']);
        $records = [];
        for ($i = 1; $i <= 500; $i++) {
            $records[] = [...$base, 'number' => 'LIMIT-'.$i, 'supplier_invoice_number' => 'LIMIT-SI-'.$i];
        }
        foreach (array_chunk($records, 25) as $chunk) {
            DB::table('supplier_bills')->insert($chunk);
        }
        $this->invalid(fn () => $this->captureManifest($row), 'manifest');
        $this->assertSame('approved', $row->fresh()->getAttribute('status'));
        $this->assertDatabaseCount('accounting_cutover_sources', 0);
        $this->assertDatabaseCount('accounting_manifest_versions', 0);
        $this->assertNull($this->plan->fresh()->getAttribute('manifest_hash'));
    }

    public function test_foreign_company_query_is_forbidden_to_staff(): void
    {
        $other = $this->newCompany('MF-HTTP-B');
        $this->actingAs($this->staff(['erp.accounting-openings.view']))->get('/erp/accounting/manifest?company_id='.$other->getKey())->assertForbidden();
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingCutoverSource;
use App\Models\Accounting\AccountingManifestVersion;
use App\Models\Accounting\AccountingOpeningWorkspace;
use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Internal prerequisite for future trusted adapters, inside their transaction. Never an HTTP authority. */
final class AccountingCoverageResolver
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingConfigurationGuard $configuration, private readonly AccountingPostingReadiness $readiness, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingOpeningEvidence $opening) {}

    /** Expected fingerprint/event must come from the trusted original-source adapter, never arbitrary HTTP input.
     * @return array<string, mixed>
     */
    public function requireRecognized(User $actor, int $companyId, string $kind, int $sourceId, string $expectedHash, string $expectedEvent): array
    {
        $this->access->requireTransaction();
        if (! in_array($kind, ['sales_invoice', 'supplier_bill'], true) || $sourceId < 1 || ! preg_match('/\A[a-f0-9]{64}\z/', $expectedHash) || trim($expectedEvent) === '' || mb_strlen($expectedEvent) > 64) {
            $this->fail('Invalid trusted source coverage identity.');
        }
        $company = $this->access->authorize($actor, $companyId, 'erp.journal-entries.post', true);
        $setting = $this->configuration->lockForSetup($company);
        if ($setting->getAttribute('activation_date') === null) {
            $this->fail('Accounting coverage is not activated.');
        }
        $this->readiness->check($company, $setting, $setting->getAttribute('activation_date')->format('Y-m-d'));

        return $this->recorded($company, $kind, $sourceId, $expectedHash, $expectedEvent);
    }

    /** Internal historical prerequisite. Caller must already authorize the source/report operation and own the company transaction.
     * Does not grant posting authority or invoke current-state readiness. New effects still use the source orchestrator gates.
     *
     * @return array<string, mixed>
     */
    public function recorded(Company $company, string $kind, int $sourceId, string $expectedHash, string $expectedEvent): array
    {
        $this->access->requireTransaction();
        $companyId = (int) $company->getKey();
        if (! $company->exists || ! in_array($kind, ['sales_invoice', 'supplier_bill'], true) || $sourceId < 1 || ! preg_match('/\A[a-f0-9]{64}\z/', $expectedHash) || trim($expectedEvent) === '' || mb_strlen($expectedEvent) > 64) {
            $this->fail('Invalid recorded coverage identity.');
        }
        Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
        $settings = AccountingSetting::query()->where('company_id', $companyId)->lockForUpdate()->first();
        if ($settings === null || $settings->getAttribute('activation_date') === null || $settings->getAttribute('functional_currency') !== $company->getAttribute('currency_code')) {
            $this->fail('Recorded coverage has no matching activated functional-currency identity.');
        }
        if ($settings->getAttribute('activated_by') === null || $settings->getAttribute('activated_at') === null || $settings->getAttribute('contract_version') !== AccountingDomainRegistry::VERSION || $settings->getAttribute('recognition_policy') !== 'invoice_cogs') {
            $this->fail('Recorded coverage activation configuration is incomplete.');
        }
        $plans = AccountingCutover::query()->where('company_id', $companyId)->where('status', 'activated')->lockForUpdate()->get();
        if ($plans->count() !== 1) {
            $this->fail('Exactly one activated cutover is required.');
        }
        $plan = $plans->firstOrFail();
        if ($plan->getAttribute('strategy') !== 'snapshot' || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $plan->getAttribute('reconciliation_hash')) || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $plan->getAttribute('manifest_hash')) || $plan->getAttribute('cutover_date')->format('Y-m-d') !== $settings->getAttribute('activation_date')->format('Y-m-d') || $plan->getAttribute('approved_by') === null || $plan->getAttribute('approved_at') === null || $plan->getAttribute('activated_by') === null || $plan->getAttribute('activated_at') === null || (int) $plan->getAttribute('approved_by') === (int) $plan->getAttribute('created_by') || (int) $plan->getAttribute('activated_by') !== (int) $settings->getAttribute('activated_by')) {
            $this->fail('Recorded coverage activation/approval identity differs.');
        }
        $version = AccountingManifestVersion::query()->where('company_id', $companyId)->where('cutover_id', $plan->getKey())->where('cutover_revision', $plan->getAttribute('revision'))->first();
        if ($version === null) {
            $this->fail('Activated coverage has no matching immutable manifest.');
        }
        $payload = $version->getAttribute('payload');
        if (! is_array($payload) || ! is_array($payload['rows'] ?? null) || ! is_array($payload['coverage_catalog'] ?? null) || ! hash_equals((string) $version->getAttribute('payload_hash'), $this->canonical->hash($payload)) || ! hash_equals((string) $plan->getAttribute('manifest_hash'), (string) $version->getAttribute('payload_hash')) || ($payload['contract_version'] ?? null) !== AccountingDomainRegistry::VERSION || ($payload['manifest_version'] ?? null) !== 'reconciled.v1' || ($payload['recognition_state'] ?? null) !== 'recognized' || (int) ($payload['company_id'] ?? 0) !== $companyId || (int) ($payload['cutover_id'] ?? 0) !== (int) $plan->getKey() || (int) ($payload['cutover_revision'] ?? 0) !== (int) $plan->getAttribute('revision') || ($payload['cutover_date'] ?? null) !== $plan->getAttribute('cutover_date')->format('Y-m-d') || ($payload['strategy'] ?? null) !== 'snapshot' || ($payload['reconciliation_hash'] ?? null) !== $plan->getAttribute('reconciliation_hash')) {
            $this->fail('A prepared or inconsistent manifest cannot prove recognized coverage.');
        }
        foreach (['sales_invoices', 'supplier_bills', 'inventory', 'dispatch', 'dated_allocations_and_credits', 'funds_and_advances', 'cash_and_bank'] as $category) {
            if (($payload['coverage_catalog'][$category] ?? null) !== 'reconciled') {
                $this->fail('Activated coverage has unresolved reconciliation categories.');
            }
        }
        $workspace = AccountingOpeningWorkspace::query()->where('company_id', $companyId)->where('cutover_id', $plan->getKey())->firstOrFail();
        $journal = JournalEntry::query()->where('company_id', $companyId)->findOrFail($workspace->getAttribute('journal_entry_id'));
        if ($journal->getAttribute('status') !== 'posted' || $journal->getAttribute('posted_at') === null || $journal->getAttribute('posted_by') === null || $journal->getAttribute('approved_by') === null || $journal->getAttribute('approved_at') === null || (int) $journal->getAttribute('approved_by') === (int) $journal->getAttribute('created_by') || (int) $journal->getAttribute('approved_by') === (int) $journal->getAttribute('updated_by') || (int) $version->getAttribute('opening_journal_entry_id') !== (int) $journal->getKey() || (int) ($payload['opening_journal_entry_id'] ?? 0) !== (int) $journal->getKey() || ($payload['opening_payload_hash'] ?? null) !== $journal->getAttribute('payload_hash')) {
            $this->fail('Recognized coverage requires its immutable posted opening.');
        }
        if ($journal->getAttribute('currency_code') !== $company->getAttribute('currency_code')) {
            $this->fail('Opening currency differs from covered company.');
        }
        $checkedOpening = $this->opening->verify($plan, $workspace, $journal);
        $rows = [];
        foreach (AccountingCutoverSource::query()->where('company_id', $companyId)->where('cutover_id', $plan->getKey())->orderBy('source_kind')->orderBy('source_id')->get() as $row) {
            if ((int) $row->getAttribute('opening_journal_entry_id') !== (int) $journal->getKey()) {
                $this->fail('Coverage opening identity mismatch.');
            }
            $rows[] = [
                'source_kind' => (string) $row->getAttribute('source_kind'),
                'source_id' => (int) $row->getAttribute('source_id'),
                'coverage_kind' => (string) $row->getAttribute('coverage_kind'),
                'covered_through_event' => $row->getAttribute('covered_through_event'),
                'source_payload_hash' => (string) $row->getAttribute('source_payload_hash'),
                'opening_state' => $row->getAttribute('opening_state'),
            ];
        }
        if (! hash_equals($this->canonical->hash($rows), $this->canonical->hash($payload['rows']))) {
            $this->fail('Coverage rows differ from the immutable recognized manifest.');
        }
        foreach ($rows as $row) {
            if ($row['source_kind'] !== $kind || $row['source_id'] !== $sourceId || $row['coverage_kind'] !== 'snapshot') {
                continue;
            }
            $state = $row['opening_state'];
            $snapshot = is_array($state) ? ($state['snapshot'] ?? null) : null;
            if (! is_array($snapshot) || ($state['recognition_state'] ?? null) !== 'recognized' || ! hash_equals($expectedHash, (string) $row['source_payload_hash']) || ! hash_equals((string) $row['source_payload_hash'], $this->canonical->hash($snapshot)) || $row['covered_through_event'] !== $expectedEvent) {
                $this->fail('Original source coverage fingerprint/event is missing or conflicting.');
            }

            return ['cutover_id' => (int) $plan->getKey(), 'opening_journal_entry_id' => (int) $journal->getKey(), 'manifest_hash' => (string) $plan->getAttribute('manifest_hash'), 'source_payload_hash' => $expectedHash, 'covered_through_event' => $expectedEvent, 'snapshot' => $snapshot, 'opening_lines' => $checkedOpening['lines'], 'cutover_date' => $plan->getAttribute('cutover_date')->format('Y-m-d')];
        }
        $this->fail('No recognized snapshot coverage exists for this source.');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['coverage' => $message]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingCutoverSource;
use App\Models\Accounting\AccountingManifestVersion;
use App\Models\Accounting\AccountingOpeningWorkspace;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Preparation manifests are captured evidence, never recognized financial coverage. */
final class AccountingCutoverManifest
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingConfigurationGuard $configuration, private readonly AccountingManifestSources $sources, private readonly AccountingOpeningEvidence $opening, private readonly AccountingOpeningLineGuard $lines, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingAuditService $audit) {}

    /** @param array<string, mixed> $input */
    public function capture(User $actor, int $cutoverId, array $input): AccountingCutover
    {
        return $this->change($actor, $cutoverId, $input, false);
    }

    /** @param array<string, mixed> $input */
    public function invalidate(User $actor, int $cutoverId, array $input): AccountingCutover
    {
        return $this->change($actor, $cutoverId, $input, true);
    }

    /** @param array<string, mixed> $input */
    private function change(User $actor, int $cutoverId, array $input, bool $invalidate): AccountingCutover
    {
        if (array_diff(array_keys($input), ['cutover_revision', 'opening_revision', 'reason']) !== []) {
            $this->fail('manifest', 'Manifest sources, hashes, amounts, recognized status and event coverage are server controlled.');
        }
        $data = Validator::make($input, ['cutover_revision' => ['required', 'integer', 'min:1'], 'opening_revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u']])->validate();

        return DB::transaction(function () use ($actor, $cutoverId, $data, $invalidate): AccountingCutover {
            $seed = AccountingCutover::query()->whereIn('company_id', $this->access->companies($actor, 'erp.accounting-openings.manage')->select('id'))->findOrFail($cutoverId);
            $companyId = (int) $seed->getAttribute('company_id');
            $company = $this->access->authorize($actor, $companyId, 'erp.accounting-openings.manage', true);
            $setting = $this->configuration->lockForSetup($company);
            $plan = AccountingCutover::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($cutoverId);
            if ($plan->getAttribute('status') !== 'draft' || $plan->getAttribute('strategy') !== 'snapshot' || $plan->getAttribute('approved_by') !== null || $plan->getAttribute('approved_at') !== null || $plan->getAttribute('activated_by') !== null || $plan->getAttribute('activated_at') !== null || $plan->getAttribute('reconciliation_hash') !== null || $setting->getAttribute('state') === 'enabled' || $setting->getAttribute('activation_date') !== null || $setting->getAttribute('activated_by') !== null || $setting->getAttribute('activated_at') !== null) {
                $this->fail('cutover', 'Only unapproved, unactivated snapshot preparation may change here.');
            }
            if ((int) $plan->getAttribute('revision') !== (int) $data['cutover_revision']) {
                $this->fail('cutover_revision', 'The cutover plan changed. Reload before continuing.');
            }
            if (AccountingCutover::query()->where('company_id', $companyId)->where('id', '!=', $cutoverId)->where('status', '!=', 'aborted')->exists() || JournalEntry::query()->where('company_id', $companyId)->where(fn ($q) => $q->where('status', 'posted')->orWhereNotNull('posted_at')->orWhereNotNull('posted_by'))->exists() || AccountingSourceEffect::query()->where('company_id', $companyId)->exists()) {
                $this->fail('cutover', 'Conflicting plans or recognized accounting require a controlled reconciliation workflow.');
            }
            $workspace = AccountingOpeningWorkspace::query()->where('company_id', $companyId)->where('cutover_id', $cutoverId)->lockForUpdate()->firstOrFail();
            $journal = JournalEntry::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($workspace->getAttribute('journal_entry_id'));
            if ((int) $journal->getAttribute('revision') !== (int) $data['opening_revision']) {
                $this->fail('opening_revision', 'The opening journal changed.');
            }
            if (! in_array($journal->getAttribute('status'), ['draft', 'approved'], true) || $journal->getAttribute('posted_at') !== null || $journal->getAttribute('posted_by') !== null) {
                $this->fail('opening', 'Return a submitted opening to draft before changing manifest evidence. Posted history cannot change.');
            }
            if ($journal->getAttribute('currency_code') !== $setting->getAttribute('functional_currency')) {
                $this->fail('currency_code', 'Opening currency differs from company setup.');
            }
            $checked = $this->opening->verify($plan, $workspace, $journal);
            $beforeOpening = $this->opening->snapshot($journal, $checked);
            $before = $this->snapshot($plan);
            if ($invalidate) {
                if ($plan->getAttribute('manifest_hash') === null) {
                    $this->fail('manifest', 'There is no current manifest to invalidate.');
                }
                $this->verify($plan, $workspace, $journal);
            } else {
                if ($plan->getAttribute('manifest_hash') !== null || AccountingCutoverSource::query()->where('company_id', $companyId)->where('cutover_id', $cutoverId)->exists()) {
                    $this->fail('manifest', 'Invalidate the current manifest explicitly before a new capture.');
                }
                $this->lines->validate($companyId, $checked['lines']);
            }
            $rows = $invalidate ? [] : $this->sources->collect($companyId, $plan->getAttribute('cutover_date')->format('Y-m-d'));
            $plan->fill(['revision' => (int) $plan->getAttribute('revision') + 1, 'manifest_hash' => null])->save();
            $journal->fill(['status' => 'draft', 'revision' => (int) $journal->getAttribute('revision') + 1, 'updated_by' => $actor->getKey(), 'submitted_by' => null, 'submitted_at' => null, 'approved_by' => null, 'approved_at' => null, 'reason' => trim((string) $data['reason']), 'payload_hash' => $this->canonical->hash($this->opening->payload($plan, $journal, (string) $workspace->getAttribute('supporting_reference'), $checked['lines']))])->save();
            if ($invalidate) {
                foreach (AccountingCutoverSource::query()->where('company_id', $companyId)->where('cutover_id', $cutoverId)->orderBy('id')->lockForUpdate()->get() as $source) {
                    $source->delete();
                }
            } else {
                foreach ($rows as $source) {
                    AccountingCutoverSource::query()->create(['company_id' => $companyId, 'cutover_id' => $cutoverId, 'opening_journal_entry_id' => $journal->getKey(), ...$source]);
                }
                $payload = $this->payload($plan, $journal, $rows);
                $hash = $this->canonical->hash($payload);
                AccountingManifestVersion::query()->create(['company_id' => $companyId, 'cutover_id' => $cutoverId, 'cutover_revision' => $plan->getAttribute('revision'), 'opening_journal_entry_id' => $journal->getKey(), 'payload' => $payload, 'payload_hash' => $hash, 'created_by' => $actor->getKey()]);
                $plan->fill(['manifest_hash' => $hash])->save();
                $this->verify($plan, $workspace, $journal);
            }
            $this->audit->record($actor, $companyId, 'opening', (int) $journal->getKey(), 'edit', $beforeOpening, $this->opening->snapshot($journal, $this->opening->verify($plan, $workspace, $journal)), trim((string) $data['reason']));
            $this->audit->record($actor, $companyId, 'cutover', $cutoverId, 'manage', $before, $this->snapshot($plan), trim((string) $data['reason']));

            return $plan;
        });
    }

    /** Internal integrity check: caller first authorizes scope. @return array<string, mixed> */
    public function verify(AccountingCutover $plan, AccountingOpeningWorkspace $workspace, JournalEntry $journal): array
    {
        $this->opening->verify($plan, $workspace, $journal);
        $version = AccountingManifestVersion::query()->where('company_id', $plan->getAttribute('company_id'))->where('cutover_id', $plan->getKey())->where('cutover_revision', $plan->getAttribute('revision'))->firstOrFail();
        $payload = $version->getAttribute('payload');
        if (! is_array($payload) || ! hash_equals((string) $version->getAttribute('payload_hash'), $this->canonical->hash($payload)) || ! hash_equals((string) $plan->getAttribute('manifest_hash'), (string) $version->getAttribute('payload_hash')) || (int) $version->getAttribute('opening_journal_entry_id') !== (int) $journal->getKey()) {
            $this->fail('manifest', 'Manifest archive integrity is inconsistent.');
        }
        $rows = $this->stored($plan, $journal);
        if (! hash_equals((string) $plan->getAttribute('manifest_hash'), $this->canonical->hash($this->payload($plan, $journal, $rows)))) {
            $this->fail('manifest', 'Current coverage rows do not match the immutable archived manifest.');
        }

        return $payload;
    }

    /** @return list<array<string, mixed>> */
    public function stored(AccountingCutover $plan, JournalEntry $journal): array
    {
        $rows = [];
        foreach (AccountingCutoverSource::query()->where('company_id', $plan->getAttribute('company_id'))->where('cutover_id', $plan->getKey())->orderBy('source_kind')->orderBy('source_id')->get() as $row) {
            if ((int) $row->getAttribute('opening_journal_entry_id') !== (int) $journal->getKey()) {
                $this->fail('manifest', 'Coverage row points to another opening.');
            }
            $state = $row->getAttribute('opening_state');
            $sourceSnapshot = is_array($state) ? ($state['snapshot'] ?? null) : null;
            if (! is_array($sourceSnapshot) || ($state['recognition_state'] ?? null) !== 'pending_reconciliation' || $row->getAttribute('covered_through_event') !== null || ! hash_equals((string) $row->getAttribute('source_payload_hash'), $this->canonical->hash($sourceSnapshot))) {
                $this->fail('manifest', 'Prepared source state or fingerprint is inconsistent.');
            }
            $entry = $row->only(['source_kind', 'source_id', 'coverage_kind', 'covered_through_event', 'source_payload_hash', 'opening_state']);
            $entry['source_id'] = (int) $row->getAttribute('source_id');
            $rows[] = $entry;
        }

        return $rows;
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private function payload(AccountingCutover $plan, JournalEntry $journal, array $rows): array
    {
        return ['manifest_version' => 'prepared.v1', 'contract_version' => AccountingDomainRegistry::VERSION, 'company_id' => (int) $plan->getAttribute('company_id'), 'cutover_id' => (int) $plan->getKey(), 'cutover_revision' => (int) $plan->getAttribute('revision'), 'cutover_date' => $plan->getAttribute('cutover_date')->format('Y-m-d'), 'strategy' => 'snapshot', 'opening_journal_entry_id' => (int) $journal->getKey(), 'opening_payload_hash' => (string) $journal->getAttribute('payload_hash'), 'recognition_state' => 'pending_reconciliation', 'coverage_catalog' => ['sales_invoices' => 'document_observations', 'supplier_bills' => 'document_observations', 'inventory' => 'current_valuation_observations', 'dispatch' => 'not_reconciled', 'dated_allocations_and_credits' => 'not_reconciled', 'funds_and_advances' => 'not_reconciled', 'cash_and_bank' => 'not_reconciled'], 'rows' => $rows];
    }

    /** @return array<string, mixed> */
    private function snapshot(AccountingCutover $plan): array
    {
        return ['cutover_date' => $plan->getAttribute('cutover_date')->format('Y-m-d'), 'strategy' => (string) $plan->getAttribute('strategy'), 'status' => (string) $plan->getAttribute('status'), 'revision' => (int) $plan->getAttribute('revision'), 'payload_hash' => $plan->getAttribute('manifest_hash'), 'line_count' => AccountingCutoverSource::query()->where('company_id', $plan->getAttribute('company_id'))->where('cutover_id', $plan->getKey())->count()];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Prepares/revises the one linked reversal; does not grant approval or post it. */
final class AccountingJournalReversalService
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingConfigurationGuard $configuration, private readonly AccountingDocumentNumberService $numbers, private readonly AccountingManualLineGuard $lineGuard, private readonly AccountingJournalEvidence $evidence, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingAuditService $audit) {}

    /** revision refers to the original; reversal_revision is required to revise an existing unposted reversal.
     * @param  array<string, mixed>  $input
     */
    public function prepare(User $actor, int $companyId, int $originalId, array $input): JournalEntry
    {
        if (array_diff(array_keys($input), ['revision', 'reversal_revision', 'accounting_date', 'reason']) !== []) {
            $this->fail('journal', 'Reversal lines, original link, actors and state are server controlled.');
        }
        $data = Validator::make($input, ['revision' => ['required', 'integer', 'min:1'], 'reversal_revision' => ['sometimes', 'integer', 'min:1'], 'accounting_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:9999-12-31'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u']])->validate();

        return DB::transaction(function () use ($actor, $companyId, $originalId, $data): JournalEntry {
            $company = $this->access->authorize($actor, $companyId, 'erp.journal-entries.reverse', true);
            $setting = $this->configuration->lockForSetup($company);
            $original = JournalEntry::query()->where('company_id', $companyId)->findOrFail($originalId);
            if ($original->getAttribute('entry_kind') !== 'manual' || $original->getAttribute('status') !== 'posted' || $original->getAttribute('posted_by') === null || $original->getAttribute('posted_at') === null) {
                $this->fail('journal', 'Only posted manual journals can be reversed here. Source documents and reversals require their own correction workflow.');
            }
            if ($original->getAttribute('submitted_by') === null || $original->getAttribute('submitted_at') === null || $original->getAttribute('approved_by') === null || $original->getAttribute('approved_at') === null || (int) $original->getAttribute('approved_by') === (int) $original->getAttribute('created_by') || (int) $original->getAttribute('approved_by') === (int) $original->getAttribute('updated_by')) {
                $this->fail('journal', 'The original is missing independent workflow evidence.');
            }
            if ((int) $original->getAttribute('revision') !== (int) $data['revision']) {
                $this->fail('revision', 'The original journal revision does not match.');
            }
            $date = (string) $data['accounting_date'];
            if ($date < $original->getAttribute('accounting_date')->format('Y-m-d')) {
                $this->fail('accounting_date', 'A reversal cannot predate its original journal.');
            }
            if ($original->getAttribute('currency_code') !== $setting->getAttribute('functional_currency')) {
                $this->fail('currency_code', 'The original currency differs from company configuration.');
            }
            $existing = JournalEntry::query()->where('company_id', $companyId)->where('reverses_journal_id', $originalId)->first();
            if ($existing !== null && (! in_array($existing->getAttribute('status'), ['draft', 'approved', 'cancelled'], true) || $existing->getAttribute('posted_at') !== null || $existing->getAttribute('posted_by') !== null)) {
                $this->fail('journal', 'The linked reversal is submitted or already posted. Reuse its workflow; a second reversal is not allowed.');
            }
            if ($existing === null && array_key_exists('reversal_revision', $data)) {
                $this->fail('reversal_revision', 'There is no existing linked reversal to revise.');
            }
            if ($existing !== null && (! array_key_exists('reversal_revision', $data) || (int) $data['reversal_revision'] !== (int) $existing->getAttribute('revision'))) {
                $this->fail('reversal_revision', 'Reload the existing reversal and provide its current revision.');
            }
            $years = FiscalYear::query()->where('company_id', $companyId)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->orderBy('id')->lockForUpdate()->get();
            if ($years->count() !== 1 || $years->firstOrFail()->getAttribute('state') !== 'open') {
                $this->fail('accounting_date', 'Exactly one open fiscal year must cover the reversal date.');
            }
            $year = $years->firstOrFail();
            $periods = AccountingPeriod::query()->where('company_id', $companyId)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->orderBy('id')->lockForUpdate()->get();
            if ($periods->count() !== 1) {
                $this->fail('accounting_date', 'Exactly one accounting period must cover the reversal date.');
            }
            $period = $periods->firstOrFail();
            if ((int) $period->getAttribute('fiscal_year_id') !== (int) $year->getKey() || $period->getAttribute('state') !== 'open' || $period->getAttribute('starts_on')->format('Y-m-d') < $year->getAttribute('starts_on')->format('Y-m-d') || $period->getAttribute('ends_on')->format('Y-m-d') > $year->getAttribute('ends_on')->format('Y-m-d')) {
                $this->fail('accounting_date', 'The reversal period must be open and fully contained in its fiscal year.');
            }
            if ($existing !== null) {
                $oldPeriod = AccountingPeriod::query()->where('company_id', $companyId)->findOrFail($existing->getAttribute('accounting_period_id'));
                if ((int) $oldPeriod->getAttribute('fiscal_year_id') !== (int) $year->getKey()) {
                    $this->fail('accounting_date', 'A reserved reversal number must remain in its allocated fiscal year.');
                }
            }
            $checked = $this->evidence->verify($original);
            $lines = array_map(static fn (array $line): array => [...$line, 'debit' => $line['credit'], 'credit' => $line['debit']], $checked['lines']);
            $this->lineGuard->validate($companyId, $lines);
            // The company lock serializes supported writers; headers follow account/dimension locks.
            JournalEntry::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($originalId);
            $row = $existing === null ? null : JournalEntry::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($existing->getKey());
            $before = $row === null ? [] : $this->evidence->snapshot($row, $this->evidence->verify($row));
            $number = $row === null ? $this->numbers->next($companyId, (int) $year->getKey(), 'reversal', $date) : (string) $row->getAttribute('number');
            if ($row === null && JournalEntry::query()->where('company_id', $companyId)->where('number', $number)->exists()) {
                $this->fail('number', 'Reversal numbering requires reconciliation.');
            }
            $values = ['accounting_period_id' => $period->getKey(), 'entry_kind' => 'reversal', 'status' => 'draft', 'accounting_date' => $date, 'document_date' => $original->getAttribute('accounting_date')->format('Y-m-d'), 'currency_code' => $original->getAttribute('currency_code'), 'description' => 'Reversal of '.$original->getAttribute('number'), 'updated_by' => $actor->getKey(), 'submitted_by' => null, 'submitted_at' => null, 'approved_by' => null, 'approved_at' => null, 'reason' => trim((string) $data['reason'])];
            if ($row === null) {
                $row = JournalEntry::query()->create(['company_id' => $companyId, 'number' => $number, 'created_by' => $actor->getKey(), 'revision' => 1, 'contract_version' => AccountingDomainRegistry::VERSION, 'reverses_journal_id' => $originalId, ...$values]);
                foreach ($lines as $line) {
                    JournalEntryLine::query()->create(['company_id' => $companyId, 'journal_entry_id' => $row->getKey(), ...$line]);
                }
            } else {
                $row->fill([...$values, 'revision' => (int) $row->getAttribute('revision') + 1])->save();
            }
            $expected = $this->canonical->hash($this->evidence->payload($row, $lines));
            $actual = $this->canonical->hash($this->evidence->payload($row, $this->evidence->lines($row)));
            if (! hash_equals($expected, $actual)) {
                $this->fail('lines', 'Reversal values did not round-trip exactly; preparation was rolled back.');
            }
            $row->fill(['payload_hash' => $expected])->save();
            $verified = $this->evidence->verify($row);
            $this->audit->record($actor, $companyId, 'journal', (int) $row->getKey(), 'reverse', $before, $this->evidence->snapshot($row, $verified), trim((string) $data['reason']));

            return $row;
        });
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

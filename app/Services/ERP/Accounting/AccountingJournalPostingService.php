<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Posts persisted, independently approved manual or exact linked-reversal evidence. */
final class AccountingJournalPostingService
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingConfigurationGuard $configuration, private readonly AccountingPostingReadiness $readiness, private readonly AccountingPeriodGuard $periods, private readonly AccountingManualLineGuard $lineGuard, private readonly AccountingJournalEvidence $evidence, private readonly AccountingAuditService $audit) {}

    /** @param array<string, mixed> $input */
    public function post(User $actor, int $companyId, int $journalId, array $input): JournalEntry
    {
        if (array_diff(array_keys($input), ['revision', 'reason']) !== []) {
            $this->fail('journal', 'Posting accepts only revision and reason.');
        }
        $data = Validator::make($input, ['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u']])->validate();

        return DB::transaction(function () use ($actor, $companyId, $journalId, $data): JournalEntry {
            $company = $this->access->authorize($actor, $companyId, 'erp.journal-entries.post', true);
            $header = JournalEntry::query()->where('company_id', $companyId)->findOrFail($journalId);
            if (! in_array($header->getAttribute('entry_kind'), ['manual', 'reversal'], true)) {
                $this->fail('entry_kind', 'Source, opening, closing and adjustment journals require their own trusted posting adapter.');
            }
            // A matching retry returns immutable history before rechecking today's settings/period/account state.
            if ($header->getAttribute('status') === 'posted') {
                if ($header->getAttribute('posted_at') === null || (int) $header->getAttribute('posted_by') !== (int) $actor->getKey() || (int) $header->getAttribute('revision') !== (int) $data['revision'] + 1 || $header->getAttribute('reason') !== trim((string) $data['reason'])) {
                    $this->fail('revision', 'The posted journal does not match this posting request.');
                }
                $this->evidence->verify($header);

                return $header;
            }
            if ((int) $header->getAttribute('revision') !== (int) $data['revision']) {
                $this->fail('revision', 'The journal changed. Reload before posting.');
            }
            if ($header->getAttribute('posted_by') !== null || $header->getAttribute('posted_at') !== null) {
                $this->fail('journal', 'Journal posting metadata is inconsistent.');
            }
            $setting = $this->configuration->lockForSetup($company);
            $this->readiness->check($company, $setting, $header->getAttribute('accounting_date')->format('Y-m-d'));
            $this->periods->lockForJournal($actor, $companyId, $journalId);
            if ($header->getAttribute('submitted_by') === null || $header->getAttribute('submitted_at') === null) {
                $this->fail('journal', 'Submission evidence is missing.');
            }
            if ($header->getAttribute('currency_code') !== $setting->getAttribute('functional_currency')) {
                $this->fail('currency_code', 'Journal currency must match activated company currency.');
            }
            $checked = $this->evidence->verify($header);
            $this->lineGuard->validate($companyId, $checked['lines']);
            $row = JournalEntry::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($journalId);
            JournalEntryLine::query()->where('company_id', $companyId)->where('journal_entry_id', $journalId)->orderBy('id')->lockForUpdate()->get();
            if ((int) $row->getAttribute('revision') !== (int) $data['revision'] || $row->getAttribute('status') !== 'approved') {
                $this->fail('revision', 'The approved journal changed before posting.');
            }
            $checked = $this->evidence->verify($row);
            $before = $this->evidence->snapshot($row, $checked);
            $row->fill(['status' => 'posted', 'posted_by' => $actor->getKey(), 'posted_at' => CarbonImmutable::now('UTC'), 'revision' => (int) $row->getAttribute('revision') + 1, 'reason' => trim((string) $data['reason'])])->save();
            $this->audit->record($actor, $companyId, 'journal', $journalId, 'post', $before, $this->evidence->snapshot($row, $checked), trim((string) $data['reason']));

            return $row;
        });
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

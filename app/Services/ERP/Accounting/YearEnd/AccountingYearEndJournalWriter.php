<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\YearEnd;

use App\Models\Accounting\AccountingYearEndEvent;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingDocumentNumberService;
use Illuminate\Support\Facades\DB;

/** The only year-end exception to hard-closed dates: approved noncash nominal clearing or exact compensation. */
final class AccountingYearEndJournalWriter
{
    public function __construct(private readonly AccountingYearEndJournalEvidence $proof, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingDocumentNumberService $numbers) {}

    public function write(User $actor, FiscalYear $year, AccountingYearEndEvent $prep, AccountingYearEndEvent $approval): ?JournalEntry
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Year-end journals require the lifecycle transaction and company/year/period locks.');
        }
        $s = $prep->getAttribute('payload')['snapshot'];
        if ($s['plan']['lines'] === []) {
            return null;
        } $mode = $s['mode'];
        $company = (int) $year->getAttribute('company_id');
        $number = $mode === 'close' ? $this->numbers->next($company, (int) $year->getKey(), 'closing', $s['date']) : $this->reopenNumber($year);
        $reverse = null;
        if ($mode === 'reopen') {
            $original = AccountingYearEndEvent::query()->where('company_id', $company)->where('fiscal_year_id', $year->getKey())->findOrFail($s['original_close_id']);
            $reverse = (int) $original->getAttribute('journal_entry_id');
        }
        $journal = JournalEntry::query()->create(['company_id' => $company, 'accounting_period_id' => $s['period_id'], 'mapping_version_id' => $s['mapping']['version_id'], 'number' => $number, 'entry_kind' => $mode === 'close' ? 'closing' : 'closing_reversal', 'status' => 'draft', 'accounting_date' => $s['date'], 'document_date' => $s['date'], 'currency_code' => $s['currency'], 'description' => $mode === 'close' ? 'Fiscal year nominal clearing to retained earnings' : 'Exact fiscal year closing compensation', 'revision' => 1, 'created_by' => $prep->getAttribute('actor_id'), 'updated_by' => $prep->getAttribute('actor_id'), 'submitted_by' => $prep->getAttribute('actor_id'), 'submitted_at' => $prep->getAttribute('occurred_at'), 'approved_by' => $approval->getAttribute('actor_id'), 'approved_at' => $approval->getAttribute('occurred_at'), 'reverses_journal_id' => $reverse, 'reason' => $approval->getAttribute('payload')['reason'], 'contract_version' => '1.0.0']);
        foreach ($s['plan']['lines'] as $line) {
            JournalEntryLine::query()->create(['company_id' => $company, 'journal_entry_id' => (int) $journal->getKey(), ...$line]);
        }
        $journal->fill(['payload_hash' => $this->canonical->hash($this->proof->payload($journal, $s['plan']['lines'], $prep)), 'status' => 'posted', 'posted_by' => $actor->getKey(), 'posted_at' => now()->utc(), 'revision' => 2])->save();

        return $journal;
    }

    private function reopenNumber(FiscalYear $year): string
    {
        // This private allocator is used only for an independently approved exact compensation in the still-closed source year.
        $code = (string) $year->getAttribute('code');
        if ($year->getAttribute('state') !== 'closed' || preg_match('/\A[A-Za-z0-9_.-]{1,30}\z/', $code) !== 1) {
            throw new \LogicException('Reopening requires the retained closed source-year scope.');
        }
        $query = DB::table('accounting_document_sequences')->where(['company_id' => (int) $year->getAttribute('company_id'), 'fiscal_year_id' => (int) $year->getKey(), 'document_kind' => 'closing']);
        $row = (clone $query)->lockForUpdate()->first();
        if ($row === null || (int) $row->last_number >= 999999) {
            throw new \LogicException('Original closing sequence is missing or exhausted.');
        }
        $next = (int) $row->last_number + 1;
        $query->update(['last_number' => $next, 'updated_at' => now()]);

        return sprintf('CL-%s-%06d', $code, $next);
    }
}

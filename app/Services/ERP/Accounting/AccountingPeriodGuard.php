<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Internal prerequisite, not a journal writer. No public HTTP posting route. */
final class AccountingPeriodGuard
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingConfigurationGuard $configuration, private readonly AccountingBusinessDate $businessDate) {}

    /** Caller owns the transaction and must retain locks until its actual journal write.
     * Journal identity binds adjustment authority to the same persisted approved header.
     *
     * @return array{journal_id: int, fiscal_year_id: int, period_id: int, accounting_date: string, period_revision: int}
     */
    public function lockForJournal(User $actor, int $companyId, int $journalId): array
    {
        $this->access->requireTransaction();
        $company = $this->access->authorize($actor, $companyId, 'erp.journal-entries.post', true);
        $this->configuration->lockForSetup($company);
        // Under the shared company lock, read the header to locate year/period locks.
        // Recheck under that serialization boundary. The journal writer locks mappings/
        // accounts and then its header later in the global lock order.
        $header = JournalEntry::query()->where('company_id', $companyId)->findOrFail($journalId);
        $date = $header->getAttribute('accounting_date')->format('Y-m-d');
        [$year, $period] = $this->covering($companyId, $date, true);
        $journal = JournalEntry::query()->where('company_id', $companyId)->findOrFail($journalId);
        if ($journal->getAttribute('accounting_date')->format('Y-m-d') !== $date || (int) $journal->getAttribute('accounting_period_id') !== (int) $period->getKey()) {
            $this->fail('accounting_date', 'The journal date/period changed or does not match the fiscal calendar.');
        }
        if ($year->getAttribute('state') !== 'open') {
            $this->fail('accounting_date', 'The fiscal year is closed.');
        }
        $kind = (string) $journal->getAttribute('entry_kind');
        if (! in_array($kind, ['manual', 'opening', 'reversal', 'period_adjustment'], true)) {
            $this->fail('entry_kind', 'This journal kind requires its own trusted posting workflow.');
        }
        $approver = $journal->getAttribute('approved_by');
        if ($journal->getAttribute('status') !== 'approved' || $journal->getAttribute('posted_at') !== null || $approver === null || $journal->getAttribute('approved_at') === null || (int) $approver === (int) $journal->getAttribute('created_by') || (int) $approver === (int) $journal->getAttribute('updated_by')) {
            $this->fail('approval', 'The persisted journal must have a current independent approval before period posting checks.');
        }
        if ($date < $this->businessDate->today($company) && ! $actor->can('erp.accounting-periods.adjust')) {
            throw new AuthorizationException('Backdated journal posting requires period-adjust permission; the accounting date is never silently moved.');
        }
        $state = (string) $period->getAttribute('state');
        if ($state === 'hard_closed') {
            $this->fail('accounting_date', 'Hard-closed periods reject every new journal, including reversals and adjustments.');
        }
        if (! in_array($state, ['open', 'soft_closed'], true)) {
            $this->fail('accounting_date', 'The accounting period state requires review.');
        }
        if ($kind === 'period_adjustment' && ! $actor->can('erp.accounting-periods.adjust')) {
            throw new AuthorizationException('Posting a period-adjustment journal requires period-adjust permission.');
        }
        if ($state === 'soft_closed' && $kind !== 'period_adjustment') {
            $this->fail('accounting_date', 'Soft-closed periods allow only separately approved period-adjustment journals.');
        }

        return ['journal_id' => $journalId, 'fiscal_year_id' => (int) $year->getKey(), 'period_id' => (int) $period->getKey(), 'accounting_date' => $date, 'period_revision' => (int) $period->getAttribute('revision')];
    }

    /** Read-only, company-scoped date lookup; not permission to post or adjust.
     * @return array<string, mixed>
     */
    public function inspect(User $actor, int $companyId, string $date): array
    {
        $company = $this->access->authorize($actor, $companyId, 'erp.accounting-periods.view');
        Validator::make(['accounting_date' => $date], ['accounting_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:9999-12-31']])->validate();
        $backdated = $date < $this->businessDate->today($company);
        $issues = $this->configuration->issues($company, AccountingSetting::query()->where('company_id', $companyId)->first());
        try {
            [$year, $period] = $this->covering($companyId, $date, false);
            if ($year->getAttribute('state') !== 'open') {
                $issues[] = 'The fiscal year is closed.';
            }
            if ($period->getAttribute('state') === 'soft_closed') {
                $issues[] = 'Ordinary postings are blocked. An independently approved period-adjustment workflow and adjust permission are required.';
            } elseif ($period->getAttribute('state') !== 'open') {
                $issues[] = 'This period blocks new journals.';
            }

            return ['date' => $date, 'backdated' => $backdated, 'ordinary_open' => $issues === [], 'year_id' => (int) $year->getKey(), 'year_code' => (string) $year->getAttribute('code'), 'period_code' => (string) $period->getAttribute('code'), 'state' => (string) $period->getAttribute('state'), 'issues' => $issues];
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $issues[] = $message;
                }
            }

            return ['date' => $date, 'backdated' => $backdated, 'ordinary_open' => false, 'year_id' => null, 'year_code' => null, 'period_code' => null, 'state' => null, 'issues' => $issues];
        }
    }

    /** @return array{FiscalYear, AccountingPeriod} */
    private function covering(int $companyId, string $date, bool $locking): array
    {
        $years = FiscalYear::query()->where('company_id', $companyId)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->orderBy('id');
        if ($locking) {
            $years->lockForUpdate();
        }
        $matches = $years->get();
        if ($matches->count() !== 1) {
            $this->fail('accounting_date', 'Exactly one fiscal year must cover the accounting date.');
        }
        $year = $matches->firstOrFail();
        $periods = AccountingPeriod::query()->where('company_id', $companyId)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->orderBy('id');
        if ($locking) {
            $periods->lockForUpdate();
        }
        $matches = $periods->get();
        if ($matches->count() !== 1) {
            $this->fail('accounting_date', 'Exactly one accounting period must cover the accounting date.');
        }
        $period = $matches->firstOrFail();
        if ((int) $period->getAttribute('fiscal_year_id') !== (int) $year->getKey() || $period->getAttribute('starts_on')->format('Y-m-d') < $year->getAttribute('starts_on')->format('Y-m-d') || $period->getAttribute('ends_on')->format('Y-m-d') > $year->getAttribute('ends_on')->format('Y-m-d')) {
            $this->fail('accounting_date', 'Period ownership or containment requires review.');
        }

        return [$year, $period];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

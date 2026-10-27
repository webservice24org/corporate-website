<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Manual and controlled-reversal journal state machine. No posting, reversal preparation, opening or automatic-source authority. */
final class AccountingJournalWorkflowService
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingConfigurationGuard $configuration, private readonly AccountingDomainRegistry $registry, private readonly AccountingJournalEvidence $evidence, private readonly AccountingManualLineGuard $lineGuard, private readonly AccountingAuditService $audit) {}

    /** @param array<string, mixed> $input */
    public function submit(User $actor, int $companyId, int $journalId, array $input): JournalEntry
    {
        return $this->transition($actor, $companyId, $journalId, $input, 'submit');
    }

    /** @param array<string, mixed> $input */
    public function approve(User $actor, int $companyId, int $journalId, array $input): JournalEntry
    {
        return $this->transition($actor, $companyId, $journalId, $input, 'approve');
    }

    /** @param array<string, mixed> $input */
    public function reject(User $actor, int $companyId, int $journalId, array $input): JournalEntry
    {
        return $this->transition($actor, $companyId, $journalId, $input, 'reject');
    }

    /** @param array<string, mixed> $input */
    public function cancel(User $actor, int $companyId, int $journalId, array $input): JournalEntry
    {
        return $this->transition($actor, $companyId, $journalId, $input, 'cancel');
    }

    /** @param array<string, mixed> $input */
    private function transition(User $actor, int $companyId, int $journalId, array $input, string $action): JournalEntry
    {
        if (array_diff(array_keys($input), ['revision', 'reason']) !== []) {
            $this->fail('journal', 'Workflow input permits only revision and reason. Identity, action, actors, timestamps and evidence are server controlled.');
        }
        $data = Validator::make($input, ['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u']])->validate();

        return DB::transaction(function () use ($actor, $companyId, $journalId, $data, $action): JournalEntry {
            $company = $this->access->authorize($actor, $companyId, $this->registry->permission('journal', $action), true);
            $setting = $this->configuration->lockForSetup($company);
            $header = JournalEntry::query()->where('company_id', $companyId)->findOrFail($journalId);
            $this->state($header, $action, (int) $data['revision']);
            $period = AccountingPeriod::query()->where('company_id', $companyId)->findOrFail($header->getAttribute('accounting_period_id'));
            $year = FiscalYear::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($period->getAttribute('fiscal_year_id'));
            $period = AccountingPeriod::query()->where('company_id', $companyId)->where('fiscal_year_id', $year->getKey())->lockForUpdate()->findOrFail($period->getKey());
            $checked = $this->evidence->verify($header);
            if (in_array($action, ['submit', 'approve'], true)) {
                $date = $header->getAttribute('accounting_date')->format('Y-m-d');
                $years = FiscalYear::query()->where('company_id', $companyId)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->get();
                $periods = AccountingPeriod::query()->where('company_id', $companyId)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->get();
                if ($year->getAttribute('state') !== 'open' || $period->getAttribute('state') !== 'open' || $date < $period->getAttribute('starts_on')->format('Y-m-d') || $date > $period->getAttribute('ends_on')->format('Y-m-d') || $period->getAttribute('starts_on')->format('Y-m-d') < $year->getAttribute('starts_on')->format('Y-m-d') || $period->getAttribute('ends_on')->format('Y-m-d') > $year->getAttribute('ends_on')->format('Y-m-d') || $years->count() !== 1 || $periods->count() !== 1) {
                    $this->fail('accounting_date', 'Submission and approval require one open, fully containing fiscal year and period.');
                }
                if ($header->getAttribute('currency_code') !== $setting->getAttribute('functional_currency')) {
                    $this->fail('currency_code', 'Journal currency no longer matches company functional currency.');
                }
                $this->lineGuard->validate($companyId, $checked['lines']);
            }
            $row = JournalEntry::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($journalId);
            $this->state($row, $action, (int) $data['revision']);
            JournalEntryLine::query()->where('company_id', $companyId)->where('journal_entry_id', $journalId)->orderBy('id')->lockForUpdate()->get();
            $checked = $this->evidence->verify($row);
            $before = $this->evidence->snapshot($row, $checked);
            if ($action === 'approve') {
                if ((int) $actor->getKey() === (int) $row->getAttribute('created_by') || (int) $actor->getKey() === (int) $row->getAttribute('updated_by')) {
                    throw new AuthorizationException('Approval requires a different actor from both the creator and the last content editor, including super-admins.');
                }
                if ($row->getAttribute('submitted_by') === null || $row->getAttribute('submitted_at') === null) {
                    $this->fail('journal', 'Submission evidence is missing.');
                }
            }
            $values = match ($action) {
                'submit' => ['status' => 'submitted', 'submitted_by' => $actor->getKey(), 'submitted_at' => CarbonImmutable::now('UTC'), 'approved_by' => null, 'approved_at' => null],
                'approve' => ['status' => 'approved', 'approved_by' => $actor->getKey(), 'approved_at' => CarbonImmutable::now('UTC')],
                'reject' => ['status' => 'draft', 'submitted_by' => null, 'submitted_at' => null, 'approved_by' => null, 'approved_at' => null],
                'cancel' => ['status' => 'cancelled', 'submitted_by' => null, 'submitted_at' => null, 'approved_by' => null, 'approved_at' => null],
                default => throw new \LogicException('Unknown internal journal workflow action.'),
            };
            // updated_by represents the last content editor, not the submitter/reviewer.
            $row->fill([...$values, 'revision' => (int) $row->getAttribute('revision') + 1, 'reason' => trim((string) $data['reason'])])->save();
            $this->audit->record($actor, $companyId, 'journal', $journalId, $action, $before, $this->evidence->snapshot($row, $checked), trim((string) $data['reason']));

            return $row;
        });
    }

    private function state(JournalEntry $row, string $action, int $revision): void
    {
        if (! in_array($row->getAttribute('entry_kind'), ['manual', 'reversal'], true) || $row->getAttribute('posted_at') !== null || $row->getAttribute('posted_by') !== null || $row->getAttribute('status') === 'posted') {
            $this->fail('journal', 'Only unposted manual journals and verified linked reversals support these workflow actions.');
        }
        if ((int) $row->getAttribute('revision') !== $revision) {
            $this->fail('revision', 'The journal changed. Reload before continuing.');
        }
        $allowed = match ($action) {
            'submit' => ['draft'], 'approve', 'reject' => ['submitted'], 'cancel' => ['draft', 'submitted', 'approved'], default => []
        };
        if (! in_array($row->getAttribute('status'), $allowed, true)) {
            $this->fail('journal', 'This workflow action is not allowed from the current state.');
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingCutoverSource;
use App\Models\Accounting\AccountingOpeningWorkspace;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AccountingOpeningService
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingConfigurationGuard $configuration, private readonly AccountingOpeningLineGuard $lineGuard, private readonly AccountingJournalAmounts $amounts, private readonly AccountingOpeningEvidence $evidence, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingDocumentNumberService $numbers, private readonly AccountingAuditService $audit) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function dryRun(User $actor, int $cutoverId, array $input): array
    {
        return $this->write($actor, $cutoverId, $input, false);
    }

    /** @param array<string, mixed> $input */
    public function save(User $actor, int $cutoverId, array $input): JournalEntry
    {
        return $this->write($actor, $cutoverId, $input, true)['journal'];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function write(User $actor, int $cutoverId, array $input, bool $persist): array
    {
        if (array_diff(array_keys($input), ['cutover_revision', 'revision', 'description', 'supporting_reference', 'reason', 'lines']) !== []) {
            $this->fail('opening', 'Company, date, number, kind, state, hash and approval metadata are server controlled.');
        }

        return DB::transaction(function () use ($actor, $cutoverId, $input, $persist): array {
            [$plan, $setting] = $this->lock($actor, $cutoverId, 'erp.accounting-openings.manage');
            $companyId = (int) $plan->getAttribute('company_id');
            $workspace = AccountingOpeningWorkspace::query()->where('company_id', $companyId)->where('cutover_id', $cutoverId)->first();
            $row = $workspace === null ? null : JournalEntry::query()->where('company_id', $companyId)->findOrFail($workspace->getAttribute('journal_entry_id'));
            $data = Validator::make($input, ['cutover_revision' => ['required', 'integer', 'min:1'], 'revision' => [$row === null ? 'prohibited' : 'required', 'integer', 'min:1'], 'description' => ['required', 'string', 'max:500', 'regex:/\S/u'], 'supporting_reference' => ['required', 'string', 'max:500', 'regex:/\S/u'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u'], 'lines' => ['required', 'array', 'min:2', 'max:200'], 'lines.*' => ['required', 'array:account_id,debit,credit,branch_id,department_id,description,party_type,party_id,source_line_type,source_line_id'], 'lines.*.account_id' => ['required', 'integer', 'min:1'], 'lines.*.debit' => ['present'], 'lines.*.credit' => ['present'], 'lines.*.branch_id' => ['nullable', 'integer', 'min:1'], 'lines.*.department_id' => ['nullable', 'integer', 'min:1'], 'lines.*.description' => ['nullable', 'string', 'max:500'], 'lines.*.party_type' => ['nullable', 'in:customer,supplier'], 'lines.*.party_id' => ['nullable', 'integer', 'min:1'], 'lines.*.source_line_type' => ['nullable', 'in:sales_invoice,supplier_bill,inventory_balance'], 'lines.*.source_line_id' => ['nullable', 'integer', 'min:1']])->validate();
            if ((int) $plan->getAttribute('revision') !== (int) $data['cutover_revision']) {
                $this->fail('cutover_revision', 'The cutover plan changed. Reload before continuing.');
            }
            if ($row !== null && ((int) $row->getAttribute('revision') !== (int) $data['revision'] || ! in_array($row->getAttribute('status'), ['draft', 'approved'], true) || $row->getAttribute('posted_at') !== null || $row->getAttribute('posted_by') !== null)) {
                $this->fail('revision', 'Only current unposted draft/approved openings can be saved.');
            }
            [$year, $period] = $this->calendar($companyId, $plan->getAttribute('cutover_date')->format('Y-m-d'));
            $calculated = $this->amounts->balanced($data['lines']);
            $lines = [];
            foreach ($data['lines'] as $index => $line) {
                $nullable = static fn (string $key): ?int => ! isset($line[$key]) || $line[$key] === '' ? null : (int) $line[$key];
                $lines[] = ['line_number' => $index + 1, 'account_id' => (int) $line['account_id'], ...$calculated['amounts'][$index], 'branch_id' => $nullable('branch_id'), 'department_id' => $nullable('department_id'), 'description' => empty($line['description']) ? null : trim((string) $line['description']), 'party_type' => $line['party_type'] ?? null, 'party_id' => $nullable('party_id'), 'source_line_type' => $line['source_line_type'] ?? null, 'source_line_id' => $nullable('source_line_id'), 'cash_account_id' => null];
            }
            $this->lineGuard->validate($companyId, $lines);
            $exceptions = $this->evidence->exceptions($companyId, $plan->getAttribute('cutover_date')->format('Y-m-d'), $lines);
            $preview = ['total_debit' => $calculated['total_debit'], 'total_credit' => $calculated['total_credit'], 'exceptions' => $exceptions, 'eligibleForFinancialApproval' => $exceptions === [], 'cutoverReconciled' => false];
            if (! $persist) {
                return $preview;
            }
            $before = $row === null ? [] : $this->evidence->snapshot($row, $this->evidence->verify($plan, $this->existing($workspace), $row));
            $values = ['entry_kind' => 'opening', 'status' => 'draft', 'accounting_period_id' => $period->getKey(), 'accounting_date' => $plan->getAttribute('cutover_date')->format('Y-m-d'), 'document_date' => $plan->getAttribute('cutover_date')->format('Y-m-d'), 'currency_code' => $setting->getAttribute('functional_currency'), 'description' => trim((string) $data['description']), 'updated_by' => $actor->getKey(), 'submitted_by' => null, 'submitted_at' => null, 'approved_by' => null, 'approved_at' => null, 'reason' => trim((string) $data['reason'])];
            if ($row === null) {
                $number = $this->numbers->next($companyId, (int) $year->getKey(), 'opening', $plan->getAttribute('cutover_date')->format('Y-m-d'));
                if (JournalEntry::query()->where('company_id', $companyId)->where('number', $number)->exists()) {
                    $this->fail('number', 'Opening numbering requires reconciliation.');
                }
                $row = JournalEntry::query()->create(['company_id' => $companyId, 'number' => $number, 'created_by' => $actor->getKey(), 'revision' => 1, 'contract_version' => AccountingDomainRegistry::VERSION, ...$values]);
                $workspace = AccountingOpeningWorkspace::query()->create(['company_id' => $companyId, 'cutover_id' => $plan->getKey(), 'journal_entry_id' => $row->getKey(), 'supporting_reference' => trim((string) $data['supporting_reference'])]);
            } else {
                $workspace = $this->existing($workspace);
                $row->fill([...$values, 'revision' => (int) $row->getAttribute('revision') + 1])->save();
                $workspace->fill(['supporting_reference' => trim((string) $data['supporting_reference'])])->save();
            }
            foreach (JournalEntryLine::query()->where('company_id', $companyId)->where('journal_entry_id', $row->getKey())->orderBy('id')->lockForUpdate()->get() as $line) {
                $line->delete();
            }
            foreach ($lines as $line) {
                JournalEntryLine::query()->create(['company_id' => $companyId, 'journal_entry_id' => $row->getKey(), ...$line]);
            }
            $expected = $this->canonical->hash($this->evidence->payload($plan, $row, (string) $workspace->getAttribute('supporting_reference'), $lines));
            $actual = $this->canonical->hash($this->evidence->payload($plan, $row, (string) $workspace->getAttribute('supporting_reference'), $this->evidence->lines($row)));
            if (! hash_equals($expected, $actual)) {
                $this->fail('lines', 'Opening values did not round-trip exactly. The save was rolled back.');
            }
            $row->fill(['payload_hash' => $expected])->save();
            $checked = $this->evidence->verify($plan, $workspace, $row);
            $this->audit->record($actor, $companyId, 'opening', (int) $row->getKey(), $before === [] ? 'create' : 'edit', $before, $this->evidence->snapshot($row, $checked), trim((string) $data['reason']));

            return [...$preview, 'journal' => $row];
        });
    }

    /** @param array<string, mixed> $input */
    public function transition(User $actor, int $cutoverId, string $action, array $input): JournalEntry
    {
        if (! in_array($action, ['submit', 'approve', 'reject'], true) || array_diff(array_keys($input), ['revision', 'cutover_revision', 'reason']) !== []) {
            $this->fail('opening', 'Unsupported opening action or payload.');
        }
        $data = Validator::make($input, ['revision' => ['required', 'integer', 'min:1'], 'cutover_revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u']])->validate();

        return DB::transaction(function () use ($actor, $cutoverId, $action, $data): JournalEntry {
            [$plan, $setting] = $this->lock($actor, $cutoverId, $action === 'approve' ? 'erp.accounting-openings.approve' : 'erp.accounting-openings.manage');
            $companyId = (int) $plan->getAttribute('company_id');
            if ((int) $plan->getAttribute('revision') !== (int) $data['cutover_revision']) {
                $this->fail('cutover_revision', 'The cutover plan changed.');
            }
            if ($action !== 'reject') {
                $this->calendar($companyId, $plan->getAttribute('cutover_date')->format('Y-m-d'));
            }
            $workspace = AccountingOpeningWorkspace::query()->where('company_id', $companyId)->where('cutover_id', $cutoverId)->lockForUpdate()->firstOrFail();
            $row = JournalEntry::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($workspace->getAttribute('journal_entry_id'));
            if ((int) $row->getAttribute('revision') !== (int) $data['revision']) {
                $this->fail('revision', 'Opening changed. Reload before continuing.');
            }
            if ($row->getAttribute('posted_at') !== null || $row->getAttribute('posted_by') !== null || $row->getAttribute('status') !== ($action === 'submit' ? 'draft' : 'submitted')) {
                $this->fail('opening', 'Action is not allowed from the current opening state.');
            }
            if ($row->getAttribute('currency_code') !== $setting->getAttribute('functional_currency')) {
                $this->fail('currency_code', 'Opening currency differs from company functional currency.');
            }
            $checked = $this->evidence->verify($plan, $workspace, $row);
            $before = $this->evidence->snapshot($row, $checked);
            $exceptions = [];
            if ($action !== 'reject') {
                $this->lineGuard->validate($companyId, $checked['lines']);
                $exceptions = $this->evidence->exceptions($companyId, $plan->getAttribute('cutover_date')->format('Y-m-d'), $checked['lines']);
            }
            if ($action === 'approve') {
                if ((int) $actor->getKey() === (int) $row->getAttribute('created_by') || (int) $actor->getKey() === (int) $row->getAttribute('updated_by')) {
                    throw new AuthorizationException('Opening approval requires a different actor from creator and last content editor, including super-admins.');
                }
                if ($row->getAttribute('submitted_by') === null || $row->getAttribute('submitted_at') === null) {
                    $this->fail('opening', 'Submission evidence is missing.');
                }
                if ($exceptions !== []) {
                    $this->fail('reconciliation', implode(' ', $exceptions));
                }
            }
            $values = match ($action) {
                'submit' => ['status' => 'submitted', 'submitted_by' => $actor->getKey(), 'submitted_at' => CarbonImmutable::now('UTC')], 'approve' => ['status' => 'approved', 'approved_by' => $actor->getKey(), 'approved_at' => CarbonImmutable::now('UTC')], default => ['status' => 'draft', 'submitted_by' => null, 'submitted_at' => null, 'approved_by' => null, 'approved_at' => null]
            };
            $row->fill([...$values, 'revision' => (int) $row->getAttribute('revision') + 1, 'reason' => trim((string) $data['reason'])])->save();
            $this->audit->record($actor, $companyId, 'opening', (int) $row->getKey(), $action, $before, $this->evidence->snapshot($row, $checked), trim((string) $data['reason']));

            return $row;
        });
    }

    /** @return array{AccountingCutover, AccountingSetting} */
    private function lock(User $actor, int $cutoverId, string $permission): array
    {
        $seed = AccountingCutover::query()->whereIn('company_id', $this->access->companies($actor, $permission)->select('id'))->findOrFail($cutoverId);
        $company = $this->access->authorize($actor, (int) $seed->getAttribute('company_id'), $permission, true);
        $setting = $this->configuration->lockForSetup($company);
        $plan = AccountingCutover::query()->where('company_id', $company->getKey())->lockForUpdate()->findOrFail($cutoverId);
        if ($plan->getAttribute('status') !== 'draft' || $plan->getAttribute('strategy') !== 'snapshot' || $plan->getAttribute('approved_by') !== null || $plan->getAttribute('approved_at') !== null || $plan->getAttribute('activated_by') !== null || $plan->getAttribute('activated_at') !== null || $plan->getAttribute('manifest_hash') !== null || $plan->getAttribute('reconciliation_hash') !== null || $setting->getAttribute('state') === 'enabled' || $setting->getAttribute('activation_date') !== null || $setting->getAttribute('activated_by') !== null || $setting->getAttribute('activated_at') !== null || AccountingCutoverSource::query()->where('company_id', $company->getKey())->where('cutover_id', $cutoverId)->exists()) {
            $this->fail('cutover', 'Only an unactivated snapshot draft without manifest evidence accepts opening work here.');
        }
        if (AccountingCutover::query()->where('company_id', $company->getKey())->where('status', '!=', 'aborted')->where('id', '!=', $cutoverId)->exists() || JournalEntry::query()->where('company_id', $company->getKey())->where(fn ($q) => $q->where('status', 'posted')->orWhereNotNull('posted_at')->orWhereNotNull('posted_by'))->exists() || AccountingSourceEffect::query()->where('company_id', $company->getKey())->exists()) {
            $this->fail('cutover', 'Existing recognized activity or conflicting cutover plans require a controlled reconciliation workflow.');
        }

        return [$plan, $setting];
    }

    /** @return array{FiscalYear, AccountingPeriod} */
    private function calendar(int $companyId, string $date): array
    {
        $years = FiscalYear::query()->where('company_id', $companyId)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->lockForUpdate()->get();
        $periods = AccountingPeriod::query()->where('company_id', $companyId)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->lockForUpdate()->get();
        if ($years->count() !== 1 || $periods->count() !== 1) {
            $this->fail('cutover', 'Exactly one fiscal year and period must cover the opening date.');
        }
        $year = $years->firstOrFail();
        $period = $periods->firstOrFail();
        if ($year->getAttribute('state') !== 'open' || $period->getAttribute('state') !== 'open' || (int) $period->getAttribute('fiscal_year_id') !== (int) $year->getKey() || $period->getAttribute('starts_on')->format('Y-m-d') < $year->getAttribute('starts_on')->format('Y-m-d') || $period->getAttribute('ends_on')->format('Y-m-d') > $year->getAttribute('ends_on')->format('Y-m-d')) {
            $this->fail('cutover', 'Opening work requires an open and fully containing fiscal year and period.');
        }

        return [$year, $period];
    }

    private function existing(?AccountingOpeningWorkspace $workspace): AccountingOpeningWorkspace
    {
        if ($workspace === null) {
            throw new \LogicException('Opening workspace is missing.');
        }

        return $workspace;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

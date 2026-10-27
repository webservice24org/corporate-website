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

/** Manual content writer: editing an unposted approved journal always withdraws approval. */
final class AccountingJournalDraftService
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingConfigurationGuard $configuration, private readonly AccountingDocumentNumberService $numbers, private readonly AccountingJournalAmounts $amounts, private readonly AccountingManualLineGuard $lineGuard, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingAuditService $audit, private readonly AccountingJournalEvidence $evidenceService) {}

    /** @param array<string, mixed> $input */
    public function save(User $actor, array $input, ?int $id = null): JournalEntry
    {
        if (array_diff(array_keys($input), ['company_id', 'accounting_date', 'document_date', 'currency_code', 'description', 'revision', 'reason', 'lines']) !== []) {
            $this->fail('journal', 'Journal identity, state, totals, source links, mapping, approvals and posting metadata are server controlled.');
        }
        if (is_string($input['description'] ?? null)) {
            $input['description'] = trim($input['description']);
        }
        $data = Validator::make($input, [
            'company_id' => ['required', 'integer', 'min:1'],
            'accounting_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:9999-12-31'],
            'document_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:9999-12-31'],
            'currency_code' => ['required', 'string', 'size:3'],
            'description' => ['required', 'string', 'max:500', 'regex:/\S/u'],
            'revision' => [$id === null ? 'prohibited' : 'required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u'],
            'lines' => ['required', 'array', 'min:2', 'max:200'],
            'lines.*' => ['required', 'array:account_id,debit,credit,branch_id,department_id,description'],
            'lines.*.account_id' => ['required', 'integer', 'min:1'],
            'lines.*.debit' => ['present'], 'lines.*.credit' => ['present'],
            'lines.*.branch_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.department_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
        ])->validate();
        if (! array_is_list($data['lines'])) {
            $this->fail('lines', 'Journal lines must be an ordered list.');
        }
        $calculated = $this->amounts->balanced($data['lines']);
        $lines = [];
        foreach ($data['lines'] as $index => $line) {
            $description = isset($line['description']) ? trim((string) $line['description']) : '';
            $lines[] = ['line_number' => $index + 1, 'account_id' => (int) $line['account_id'], ...$calculated['amounts'][$index], 'branch_id' => ! isset($line['branch_id']) || $line['branch_id'] === '' ? null : (int) $line['branch_id'], 'department_id' => ! isset($line['department_id']) || $line['department_id'] === '' ? null : (int) $line['department_id'], 'description' => $description === '' ? null : $description];
        }

        return DB::transaction(function () use ($actor, $data, $lines, $id): JournalEntry {
            $companyId = (int) $data['company_id'];
            $company = $this->access->authorize($actor, $companyId, $id === null ? 'erp.journal-entries.create' : 'erp.journal-entries.edit', true);
            $setting = $this->configuration->lockForSetup($company);
            if ($data['currency_code'] !== $setting->getAttribute('functional_currency')) {
                $this->fail('currency_code', 'Journal currency must equal the company functional currency; FX is not enabled.');
            }
            $existing = $id === null ? null : JournalEntry::query()->where('company_id', $companyId)->findOrFail($id);
            if ($existing !== null && ($existing->getAttribute('entry_kind') !== 'manual' || ! in_array($existing->getAttribute('status'), ['draft', 'approved'], true) || $existing->getAttribute('posted_at') !== null || $existing->getAttribute('posted_by') !== null)) {
                $this->fail('journal', 'Only manual drafts or unposted approved journals may be edited. Approved edits return to draft and require a new independent approval.');
            }
            if ($existing !== null && (int) $existing->getAttribute('revision') !== (int) $data['revision']) {
                $this->fail('revision', 'This draft changed. Reload before editing.');
            }
            $date = (string) $data['accounting_date'];
            $years = FiscalYear::query()->where('company_id', $companyId)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->orderBy('id')->lockForUpdate()->get();
            if ($years->count() !== 1) {
                $this->fail('accounting_date', 'Exactly one fiscal year must cover the accounting date.');
            }
            $year = $years->firstOrFail();
            if ($year->getAttribute('state') !== 'open') {
                $this->fail('accounting_date', 'Manual drafts require an open fiscal year.');
            }
            $targets = AccountingPeriod::query()->where('company_id', $companyId)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->orderBy('id')->get();
            if ($targets->count() !== 1) {
                $this->fail('accounting_date', 'Exactly one accounting period must cover the accounting date.');
            }
            $target = $targets->firstOrFail();
            $ids = [(int) $target->getKey()];
            if ($existing !== null) {
                $ids[] = (int) $existing->getAttribute('accounting_period_id');
            }
            $periods = AccountingPeriod::query()->where('company_id', $companyId)->whereIn('id', array_unique($ids))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($ids as $periodId) {
                $period = $periods->get($periodId);
                if ($period === null || (int) $period->getAttribute('fiscal_year_id') !== (int) $year->getKey() || $period->getAttribute('state') !== 'open' || $period->getAttribute('starts_on')->format('Y-m-d') < $year->getAttribute('starts_on')->format('Y-m-d') || $period->getAttribute('ends_on')->format('Y-m-d') > $year->getAttribute('ends_on')->format('Y-m-d')) {
                    $this->fail('accounting_date', 'Draft periods must be open and belong wholly to the same fiscal year. Cross-year moves and closed-period escape are not allowed.');
                }
            }
            $this->lineGuard->validate($companyId, $lines);
            $row = $id === null ? null : JournalEntry::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            $before = [];
            if ($row !== null) {
                $before = $this->evidenceService->snapshot($row, $this->evidenceService->verify($row));
            }
            $number = $row === null ? $this->numbers->next($companyId, (int) $year->getKey(), 'journal', $date) : (string) $row->getAttribute('number');
            if ($row === null && JournalEntry::query()->where('company_id', $companyId)->where('number', $number)->exists()) {
                $this->fail('number', 'Journal numbering requires reconciliation before allocating another draft.');
            }
            $values = ['status' => 'draft', 'submitted_by' => null, 'submitted_at' => null, 'approved_by' => null, 'approved_at' => null, 'accounting_period_id' => (int) $target->getKey(), 'accounting_date' => $date, 'document_date' => ($data['document_date'] ?? null) ?: null, 'currency_code' => $data['currency_code'], 'description' => $data['description'], 'updated_by' => $actor->getKey(), 'reason' => trim((string) $data['reason'])];
            if ($row === null) {
                $row = JournalEntry::query()->create(['company_id' => $companyId, 'number' => $number, 'entry_kind' => 'manual', 'status' => 'draft', 'created_by' => $actor->getKey(), 'revision' => 1, 'contract_version' => AccountingDomainRegistry::VERSION, ...$values]);
            } else {
                $row->fill([...$values, 'revision' => (int) $row->getAttribute('revision') + 1])->save();
            }
            foreach (JournalEntryLine::query()->where('company_id', $companyId)->where('journal_entry_id', $row->getKey())->orderBy('id')->lockForUpdate()->get() as $line) {
                $line->delete();
            }
            foreach ($lines as $line) {
                JournalEntryLine::query()->create(['company_id' => $companyId, 'journal_entry_id' => $row->getKey(), ...$line]);
            }
            $expectedHash = $this->canonical->hash($this->evidenceService->payload($row, $lines));
            $stored = $this->evidenceService->lines($row);
            $actualHash = $this->canonical->hash($this->evidenceService->payload($row, $stored));
            if (! hash_equals($expectedHash, $actualHash)) {
                $this->fail('lines', 'Stored journal values did not round-trip exactly. The entire save was rolled back.');
            }
            $row->fill(['payload_hash' => $expectedHash])->save();
            $checked = $this->evidenceService->verify($row);
            $this->audit->record($actor, $companyId, 'journal', (int) $row->getKey(), $id === null ? 'create' : 'edit', $before, $this->evidenceService->snapshot($row, $checked), trim((string) $data['reason']));

            return $row;
        });
    }

    /** @return array<string, mixed> */
    public function read(User $actor, int $id): array
    {
        $row = JournalEntry::query()->whereIn('company_id', $this->access->companies($actor, 'erp.journal-entries.view')->select('id'))->findOrFail($id);
        if (! in_array($row->getAttribute('entry_kind'), ['manual', 'reversal'], true)) {
            $this->fail('journal', 'This reader supports manual journals and controlled reversals only.');
        }
        $checked = $this->evidenceService->verify($row);

        return ['journal' => ['id' => (int) $row->getKey(), ...$row->only(['company_id', 'number', 'entry_kind', 'status', 'currency_code', 'description', 'revision', 'created_by', 'updated_by', 'payload_hash', 'reason', 'submitted_by', 'approved_by', 'posted_by', 'reverses_journal_id']), 'posted_at' => $this->evidenceService->timestamp($row->getAttribute('posted_at')), 'submitted_at' => $this->evidenceService->timestamp($row->getAttribute('submitted_at')), 'approved_at' => $this->evidenceService->timestamp($row->getAttribute('approved_at')), 'accounting_date' => $row->getAttribute('accounting_date')->format('Y-m-d'), 'document_date' => $row->getAttribute('document_date')?->format('Y-m-d')], 'lines' => $checked['lines'], 'total_debit' => $checked['total_debit'], 'total_credit' => $checked['total_credit']];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

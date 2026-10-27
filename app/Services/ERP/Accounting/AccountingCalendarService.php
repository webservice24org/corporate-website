<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AccountingCalendarService
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingConfigurationGuard $configuration, private readonly AccountingCalendarDates $dates, private readonly AccountingAuditService $audit) {}

    /** @param array<string, mixed> $input */
    public function saveYear(User $actor, array $input, ?int $id = null): FiscalYear
    {
        $this->fields($input, ['company_id', 'code', 'name', 'starts_on', 'ends_on', 'revision', 'reason']);
        $input['code'] = is_string($input['code'] ?? null) ? strtoupper(trim($input['code'])) : ($input['code'] ?? null);
        $input['name'] = is_string($input['name'] ?? null) ? trim($input['name']) : ($input['name'] ?? null);
        $data = Validator::make($input, [...$this->rules($id), 'name' => ['required', 'string', 'max:120', 'regex:/\S/u']])->validate();
        $this->dates->assertYearRange((string) $data['starts_on'], (string) $data['ends_on']);

        return DB::transaction(function () use ($actor, $data, $id): FiscalYear {
            $companyId = (int) $data['company_id'];
            $company = $this->access->authorize($actor, $companyId, 'erp.fiscal-years.manage', true);
            $this->configuration->lockForSetup($company);
            $row = $id === null ? null : FiscalYear::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            if ($row !== null) {
                $this->editable($row->getAttribute('state'), (int) $row->getAttribute('revision'), (int) $data['revision']);
            }
            $start = (string) $data['starts_on'];
            $end = (string) $data['ends_on'];
            $overlap = FiscalYear::query()->where('company_id', $companyId)->whereDate('starts_on', '<=', $end)->whereDate('ends_on', '>=', $start);
            if ($id !== null) {
                $overlap->where('id', '<>', $id);
            }
            if ($overlap->exists()) {
                $this->fail('starts_on', 'Fiscal years cannot overlap, including closed years.');
            }
            $unique = FiscalYear::query()->where('company_id', $companyId)->where('code', $data['code']);
            if ($id !== null) {
                $unique->where('id', '<>', $id);
            }
            if ($unique->exists()) {
                $this->fail('code', 'This company already uses that fiscal-year code.');
            }
            $before = $row === null ? [] : $this->yearSnapshot($row);
            if ($row !== null) {
                $periods = AccountingPeriod::query()->where('company_id', $companyId)->where('fiscal_year_id', $id);
                if ((clone $periods)->where(fn ($query) => $query->whereDate('starts_on', '<', $start)->orWhereDate('ends_on', '>', $end))->exists()) {
                    $this->fail('starts_on', 'Year boundaries must contain every existing period.');
                }
                if (JournalEntry::query()->where('company_id', $companyId)->whereIn('accounting_period_id', (clone $periods)->select('id'))->exists()) {
                    foreach (['code', 'starts_on', 'ends_on'] as $field) {
                        if ($before[$field] !== $data[$field]) {
                            $this->fail($field, 'A year with journal references has fixed code and dates. Its name may still be edited.');
                        }
                    }
                }
            }
            $values = ['code' => $data['code'], 'name' => $data['name'], 'starts_on' => $start, 'ends_on' => $end];
            if ($row === null) {
                $row = FiscalYear::query()->create(['company_id' => $companyId, ...$values, 'state' => 'open', 'revision' => 1]);
            } else {
                $row->fill([...$values, 'revision' => (int) $row->getAttribute('revision') + 1])->save();
            }
            $this->audit->record($actor, $companyId, 'fiscal_year', (int) $row->getKey(), 'manage', $before, $this->yearSnapshot($row), trim((string) $data['reason']));

            return $row;
        });
    }

    /** @param array<string, mixed> $input */
    public function savePeriod(User $actor, int $yearId, array $input, ?int $id = null): AccountingPeriod
    {
        $this->fields($input, ['company_id', 'code', 'starts_on', 'ends_on', 'revision', 'reason']);
        $input['code'] = is_string($input['code'] ?? null) ? strtoupper(trim($input['code'])) : ($input['code'] ?? null);
        $data = Validator::make($input, $this->rules($id))->validate();

        return DB::transaction(function () use ($actor, $yearId, $data, $id): AccountingPeriod {
            $companyId = (int) $data['company_id'];
            $company = $this->access->authorize($actor, $companyId, 'erp.accounting-periods.manage', true);
            $this->configuration->lockForSetup($company);
            $year = FiscalYear::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($yearId);
            if ($year->getAttribute('state') !== 'open') {
                $this->fail('calendar', 'Only an open fiscal year can be configured.');
            }
            $row = $id === null ? null : AccountingPeriod::query()->where('company_id', $companyId)->where('fiscal_year_id', $yearId)->lockForUpdate()->findOrFail($id);
            if ($row !== null) {
                $this->editable($row->getAttribute('state'), (int) $row->getAttribute('revision'), (int) $data['revision']);
            }
            $start = (string) $data['starts_on'];
            $end = (string) $data['ends_on'];
            if ($start < $year->getAttribute('starts_on')->format('Y-m-d') || $end > $year->getAttribute('ends_on')->format('Y-m-d')) {
                $this->fail('starts_on', 'A period must fall wholly within its fiscal year.');
            }
            $overlap = AccountingPeriod::query()->where('company_id', $companyId)->whereDate('starts_on', '<=', $end)->whereDate('ends_on', '>=', $start);
            if ($id !== null) {
                $overlap->where('id', '<>', $id);
            }
            if ($overlap->exists()) {
                $this->fail('starts_on', 'Accounting periods cannot overlap, including closed periods.');
            }
            $unique = AccountingPeriod::query()->where('company_id', $companyId)->where('code', $data['code']);
            if ($id !== null) {
                $unique->where('id', '<>', $id);
            }
            if ($unique->exists()) {
                $this->fail('code', 'This company already uses that period code.');
            }
            $before = $row === null ? [] : $this->periodSnapshot($row);
            if ($row !== null && JournalEntry::query()->where('company_id', $companyId)->where('accounting_period_id', $id)->exists()) {
                foreach (['code', 'starts_on', 'ends_on'] as $field) {
                    if ($before[$field] !== $data[$field]) {
                        $this->fail($field, 'Period codes and dates are fixed once any journal references them.');
                    }
                }
            }
            $values = ['code' => $data['code'], 'starts_on' => $start, 'ends_on' => $end];
            if ($row === null) {
                $row = AccountingPeriod::query()->create(['company_id' => $companyId, 'fiscal_year_id' => $yearId, ...$values, 'state' => 'open', 'revision' => 1]);
            } else {
                $row->fill([...$values, 'revision' => (int) $row->getAttribute('revision') + 1])->save();
            }
            $this->audit->record($actor, $companyId, 'period', (int) $row->getKey(), 'manage', $before, $this->periodSnapshot($row), trim((string) $data['reason']));

            return $row;
        });
    }

    /** @param array<string, mixed> $input @return array{created: int, reused: int} */
    public function generate(User $actor, int $yearId, array $input): array
    {
        $this->fields($input, ['company_id', 'revision', 'reason']);
        $data = Validator::make($input, ['company_id' => ['required', 'integer', 'min:1'], 'revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:1800', 'regex:/\S/u']])->validate();

        return DB::transaction(function () use ($actor, $yearId, $data): array {
            $companyId = (int) $data['company_id'];
            $company = $this->access->authorize($actor, $companyId, 'erp.accounting-periods.manage', true);
            $this->configuration->lockForSetup($company);
            $year = FiscalYear::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($yearId);
            $this->editable($year->getAttribute('state'), (int) $year->getAttribute('revision'), (int) $data['revision']);
            $ranges = $this->dates->monthlyRanges($year->getAttribute('starts_on')->format('Y-m-d'), $year->getAttribute('ends_on')->format('Y-m-d'));
            $existing = AccountingPeriod::query()->where('company_id', $companyId)->orderBy('id')->lockForUpdate()->get();
            $reuse = [];
            foreach ($ranges as $range) {
                foreach ($existing as $row) {
                    $start = $row->getAttribute('starts_on')->format('Y-m-d');
                    $end = $row->getAttribute('ends_on')->format('Y-m-d');
                    $same = (int) $row->getAttribute('fiscal_year_id') === $yearId && $row->getAttribute('code') === $range['code'] && $start === $range['starts_on'] && $end === $range['ends_on'];
                    if ($same) {
                        $reuse[$range['code']] = true;

                        continue;
                    }
                    if ($row->getAttribute('code') === $range['code'] || ($start <= $range['ends_on'] && $end >= $range['starts_on'])) {
                        $this->fail('calendar', 'Existing periods conflict with monthly generation. No periods were changed.');
                    }
                }
            }
            $created = 0;
            foreach ($ranges as $range) {
                if (isset($reuse[$range['code']])) {
                    continue;
                }
                $this->savePeriod($actor, $yearId, ['company_id' => $companyId, ...$range, 'reason' => 'Monthly calendar: '.trim((string) $data['reason'])]);
                $created++;
            }

            return ['created' => $created, 'reused' => count($reuse)];
        });
    }

    /** @return array<string, mixed> */
    private function rules(?int $id): array
    {
        return ['company_id' => ['required', 'integer', 'min:1'], 'code' => ['required', 'string', 'max:30', 'regex:/\A[A-Z0-9][A-Z0-9_.-]*\z/'], 'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:9999-12-31'], 'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on', 'before_or_equal:9999-12-31'], 'revision' => [$id === null ? 'prohibited' : 'required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u']];
    }

    private function editable(mixed $state, int $revision, int $expected): void
    {
        if ($state !== 'open') {
            $this->fail('calendar', 'Closed fiscal years and periods cannot be edited here.');
        }
        if ($revision !== $expected) {
            $this->fail('revision', 'The calendar changed. Reload before continuing.');
        }
    }

    /** @param array<string, mixed> $input @param list<string> $allowed */
    private function fields(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            $this->fail('calendar', 'Unsupported calendar fields. State and closure metadata cannot be assigned here.');
        }
    }

    /** @return array<string, mixed> */
    private function yearSnapshot(FiscalYear $row): array
    {
        return ['code' => (string) $row->getAttribute('code'), 'name' => (string) $row->getAttribute('name'), 'starts_on' => $row->getAttribute('starts_on')->format('Y-m-d'), 'ends_on' => $row->getAttribute('ends_on')->format('Y-m-d'), 'state' => (string) $row->getAttribute('state'), 'revision' => (int) $row->getAttribute('revision')];
    }

    /** @return array<string, mixed> */
    private function periodSnapshot(AccountingPeriod $row): array
    {
        return ['fiscal_year_id' => (int) $row->getAttribute('fiscal_year_id'), 'code' => (string) $row->getAttribute('code'), 'starts_on' => $row->getAttribute('starts_on')->format('Y-m-d'), 'ends_on' => $row->getAttribute('ends_on')->format('Y-m-d'), 'state' => (string) $row->getAttribute('state'), 'revision' => (int) $row->getAttribute('revision')];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingCutoverSource;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Preparation only. No opening values, manifests, approval, replay or activation authority. */
final class AccountingCutoverPreparation
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingConfigurationGuard $configuration, private readonly AccountingBusinessDate $businessDate, private readonly AccountingAuditService $audit) {}

    /** @param array<string, mixed> $input */
    public function save(User $actor, array $input, ?int $id = null): AccountingCutover
    {
        if (array_diff(array_keys($input), ['company_id', 'cutover_date', 'strategy', 'revision', 'reason']) !== []) {
            $this->fail('cutover', 'Status, actors, approval, activation and reconciliation evidence are server controlled.');
        }
        $data = Validator::make($input, ['company_id' => ['required', 'integer', 'min:1'], 'cutover_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:9999-12-31'], 'strategy' => ['required', 'in:snapshot'], 'revision' => [$id === null ? 'prohibited' : 'required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u']])->validate();

        return DB::transaction(function () use ($actor, $data, $id): AccountingCutover {
            $companyId = (int) $data['company_id'];
            $company = $this->access->authorize($actor, $companyId, 'erp.accounting-openings.manage', true);
            $setting = $this->configuration->lockForSetup($company);
            $this->guard($company, $setting);
            $this->businessDate->today($company);
            $row = $id === null ? null : AccountingCutover::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            if ($row !== null) {
                $this->editable($row, (int) $data['revision']);
            }
            if (AccountingCutover::query()->where('company_id', $companyId)->where('status', '!=', 'aborted')->when($id !== null, fn ($query) => $query->where('id', '!=', $id))->exists()) {
                $this->fail('cutover', 'A company may have only one current cutover plan. Review or abort its existing draft first.');
            }
            if (AccountingCutover::query()->where('company_id', $companyId)->whereDate('cutover_date', $data['cutover_date'])->when($id !== null, fn ($query) => $query->where('id', '!=', $id))->exists()) {
                $this->fail('cutover_date', 'This date is already reserved by a retained cutover record, including aborted history.');
            }
            $this->calendar($companyId, (string) $data['cutover_date']);
            $before = $row === null ? [] : $this->snapshot($row);
            if ($row === null) {
                $row = AccountingCutover::query()->create(['company_id' => $companyId, 'cutover_date' => $data['cutover_date'], 'strategy' => 'snapshot', 'status' => 'draft', 'created_by' => $actor->getKey(), 'revision' => 1]);
            } else {
                $row->fill(['cutover_date' => $data['cutover_date'], 'strategy' => 'snapshot', 'revision' => (int) $row->getAttribute('revision') + 1])->save();
            }
            $this->audit->record($actor, $companyId, 'cutover', (int) $row->getKey(), 'manage', $before, $this->snapshot($row), trim((string) $data['reason']));

            return $row;
        });
    }

    /** @param array<string, mixed> $input */
    public function abort(User $actor, int $companyId, int $id, array $input): AccountingCutover
    {
        if (array_diff(array_keys($input), ['revision', 'reason']) !== []) {
            $this->fail('cutover', 'Abort accepts only revision and reason.');
        }
        $data = Validator::make($input, ['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u']])->validate();

        return DB::transaction(function () use ($actor, $companyId, $id, $data): AccountingCutover {
            $company = $this->access->authorize($actor, $companyId, 'erp.accounting-openings.manage', true);
            $setting = $this->configuration->lockForSetup($company);
            $this->guard($company, $setting);
            $row = AccountingCutover::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            $this->editable($row, (int) $data['revision']);
            $before = $this->snapshot($row);
            $row->fill(['status' => 'aborted', 'revision' => (int) $row->getAttribute('revision') + 1])->save();
            $this->audit->record($actor, $companyId, 'cutover', $id, 'manage', $before, $this->snapshot($row), trim((string) $data['reason']));

            return $row;
        });
    }

    /** Advisory read; caller already authorizes company scope. @return list<string> */
    public function issues(Company $company, ?AccountingSetting $setting): array
    {
        $issues = $this->configuration->issues($company, $setting);
        if ($setting !== null && ($setting->getAttribute('state') === 'enabled' || $setting->getAttribute('activation_date') !== null || $setting->getAttribute('activated_by') !== null || $setting->getAttribute('activated_at') !== null)) {
            $issues[] = 'Activated accounting cannot be re-planned through the preparation workspace.';
        }
        if (AccountingCutover::query()->where('company_id', $company->getKey())->where('status', 'activated')->exists()) {
            $issues[] = 'An activated cutover already exists.';
        }
        if (JournalEntry::query()->where('company_id', $company->getKey())->where(fn ($q) => $q->where('status', 'posted')->orWhereNotNull('posted_at')->orWhereNotNull('posted_by'))->exists() || AccountingSourceEffect::query()->where('company_id', $company->getKey())->exists()) {
            $issues[] = 'Existing posted journals or source effects require a separately designed migration/reconciliation workflow.';
        }
        if (JournalEntry::query()->where('company_id', $company->getKey())->where('entry_kind', 'opening')->exists()) {
            $issues[] = 'Opening journal evidence exists; its owning opening workflow must control cutover changes.';
        }

        return array_values(array_unique($issues));
    }

    private function guard(Company $company, AccountingSetting $setting): void
    {
        $issues = $this->issues($company, $setting);
        if ($issues !== []) {
            $this->fail('company_id', $issues[0]);
        }
    }

    private function editable(AccountingCutover $row, int $revision): void
    {
        if ((int) $row->getAttribute('revision') !== $revision) {
            $this->fail('revision', 'The cutover plan changed. Reload before continuing.');
        }
        if ($row->getAttribute('status') !== 'draft' || $row->getAttribute('approved_by') !== null || $row->getAttribute('approved_at') !== null || $row->getAttribute('activated_by') !== null || $row->getAttribute('activated_at') !== null || $row->getAttribute('manifest_hash') !== null || $row->getAttribute('reconciliation_hash') !== null || AccountingCutoverSource::query()->where('company_id', $row->getAttribute('company_id'))->where('cutover_id', $row->getKey())->exists()) {
            $this->fail('cutover', 'Only untouched draft plans can be edited or aborted here. Preserve dependent evidence.');
        }
    }

    private function calendar(int $companyId, string $date): void
    {
        $years = FiscalYear::query()->where('company_id', $companyId)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->orderBy('id')->lockForUpdate()->get();
        $periods = AccountingPeriod::query()->where('company_id', $companyId)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->orderBy('id')->lockForUpdate()->get();
        if ($years->count() !== 1 || $periods->count() !== 1) {
            $this->fail('cutover_date', 'Choose a date covered by exactly one fiscal year and accounting period.');
        }
        $year = $years->firstOrFail();
        $period = $periods->firstOrFail();
        if ($year->getAttribute('state') !== 'open' || $period->getAttribute('state') !== 'open' || (int) $period->getAttribute('fiscal_year_id') !== (int) $year->getKey() || $period->getAttribute('starts_on')->format('Y-m-d') < $year->getAttribute('starts_on')->format('Y-m-d') || $period->getAttribute('ends_on')->format('Y-m-d') > $year->getAttribute('ends_on')->format('Y-m-d')) {
            $this->fail('cutover_date', 'Cutover preparation requires an open fiscal year and an open period wholly contained in it.');
        }
    }

    /** @return array<string, mixed> */
    public function snapshot(AccountingCutover $row): array
    {
        return ['cutover_date' => $row->getAttribute('cutover_date')->format('Y-m-d'), 'strategy' => (string) $row->getAttribute('strategy'), 'status' => (string) $row->getAttribute('status'), 'revision' => (int) $row->getAttribute('revision')];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

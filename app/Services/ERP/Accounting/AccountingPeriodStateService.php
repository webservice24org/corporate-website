<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\FiscalYear;
use App\Models\User;
use App\Services\ERP\Accounting\Close\AccountingPeriodCloseGuard;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AccountingPeriodStateService
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingConfigurationGuard $configuration, private readonly AccountingAuditService $audit) {}

    /** @param array<string, mixed> $input */
    public function close(User $actor, int $companyId, int $yearId, int $periodId, array $input): AccountingPeriod
    {
        $this->fields($input, ['state', 'revision', 'reason']);
        $data = Validator::make($input, ['state' => ['required', 'in:soft_closed,hard_closed'], 'revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u']])->validate();

        return DB::transaction(function () use ($actor, $companyId, $yearId, $periodId, $data): AccountingPeriod {
            $row = $this->locked($actor, $companyId, $yearId, $periodId, 'erp.accounting-periods.close');
            $this->revision($row, (int) $data['revision']);
            $current = (string) $row->getAttribute('state');
            if (! in_array($current, ['open', 'soft_closed', 'hard_closed'], true)) {
                $this->fail('state', 'Period state requires review.');
            }
            if ($current === $data['state']) {
                return $row;
            }
            if ($current === 'hard_closed') {
                $this->fail('state', 'A closed period must be independently reopened before reducing its closure level.');
            }
            if ($data['state'] === 'hard_closed') {
                app(AccountingPeriodCloseGuard::class)->assertReady($actor, $row);
            }
            $before = $this->snapshot($row);
            $row->fill(['state' => $data['state'], 'closed_by' => $actor->getKey(), 'closed_at' => CarbonImmutable::now('UTC'), 'revision' => (int) $row->getAttribute('revision') + 1])->save();
            $this->audit->record($actor, $companyId, 'period', $periodId, 'close', $before, $this->snapshot($row), trim((string) $data['reason']));

            return $row;
        });
    }

    /** @param array<string, mixed> $input */
    public function reopen(User $actor, int $companyId, int $yearId, int $periodId, array $input): AccountingPeriod
    {
        $this->fields($input, ['revision', 'reason']);
        $data = Validator::make($input, ['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u']])->validate();

        return DB::transaction(function () use ($actor, $companyId, $yearId, $periodId, $data): AccountingPeriod {
            $row = $this->locked($actor, $companyId, $yearId, $periodId, 'erp.accounting-periods.reopen');
            $this->revision($row, (int) $data['revision']);
            if ($row->getAttribute('state') === 'open') {
                return $row;
            }
            if (! in_array($row->getAttribute('state'), ['soft_closed', 'hard_closed'], true)) {
                $this->fail('state', 'Period state requires review.');
            }
            $closer = $row->getAttribute('closed_by');
            if ($closer === null || (int) $closer === (int) $actor->getKey()) {
                throw new AuthorizationException('A different authorized user from the last closure actor must reopen this period.');
            }
            $before = $this->snapshot($row);
            // Keep last-closure evidence on the period. Current state determines closure;
            // the audit writer also independently verifies the retained closure actor.
            $row->fill(['state' => 'open', 'revision' => (int) $row->getAttribute('revision') + 1])->save();
            $this->audit->record($actor, $companyId, 'period', $periodId, 'reopen', $before, $this->snapshot($row), trim((string) $data['reason']));

            return $row;
        });
    }

    private function locked(User $actor, int $companyId, int $yearId, int $periodId, string $permission): AccountingPeriod
    {
        $company = $this->access->authorize($actor, $companyId, $permission, true);
        $this->configuration->lockForSetup($company);
        $year = FiscalYear::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($yearId);
        if ($year->getAttribute('state') !== 'open') {
            $this->fail('state', 'Period state changes require an open fiscal year. Year-end reopening is a separate workflow.');
        }

        return AccountingPeriod::query()->where('company_id', $companyId)->where('fiscal_year_id', $yearId)->lockForUpdate()->findOrFail($periodId);
    }

    private function revision(AccountingPeriod $period, int $expected): void
    {
        if ((int) $period->getAttribute('revision') !== $expected) {
            $this->fail('revision', 'The period changed. Reload before changing its state.');
        }
    }

    /** @param array<string, mixed> $input @param list<string> $allowed */
    private function fields(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            $this->fail('state', 'Only the target state, revision and reason may be supplied. Ownership and closure metadata are server controlled.');
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(AccountingPeriod $row): array
    {
        $time = $row->getAttribute('closed_at');

        return ['fiscal_year_id' => (int) $row->getAttribute('fiscal_year_id'), 'code' => (string) $row->getAttribute('code'), 'starts_on' => $row->getAttribute('starts_on')->format('Y-m-d'), 'ends_on' => $row->getAttribute('ends_on')->format('Y-m-d'), 'state' => (string) $row->getAttribute('state'), 'revision' => (int) $row->getAttribute('revision'), 'closed_by' => $row->getAttribute('closed_by') === null ? null : (int) $row->getAttribute('closed_by'), 'closed_at' => $time instanceof CarbonInterface ? $time->toIso8601String() : null];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

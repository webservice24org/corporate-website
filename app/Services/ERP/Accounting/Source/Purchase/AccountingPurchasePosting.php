<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Purchase;

use App\Models\Accounting\AccountingPurchaseEvent;
use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\Source\AccountingSourceOrchestrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Called inside source transactions, before source locks and after final source state, respectively. */
final class AccountingPurchasePosting
{
    public function __construct(private readonly AccountingPurchaseEvidence $evidence, private readonly AccountingSourceOrchestrator $engine) {}

    public function before(int $companyId, bool $enforceCutoverLock = false): bool
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Source posting requires a transaction.');
        }
        Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
        $setting = AccountingSetting::query()->where('company_id', $companyId)->lockForUpdate()->first();
        if ($setting === null) {
            return false;
        }
        if ($enforceCutoverLock && $setting->getAttribute('cutover_locked_at') !== null) {
            $this->fail('Source posting is blocked by the cutover lock.');
        }
        if (! in_array($setting->getAttribute('state'), ['disabled', 'ready', 'enabled'], true)) {
            $this->fail('Unknown accounting state.');
        }

        return $setting->getAttribute('state') === 'enabled';
    }

    public function complete(User $actor, int $companyId, string $kind, int $id, bool $newTransition): void
    {
        $query = AccountingSourceEffect::query()->where('company_id', $companyId)->where('source_kind', $kind)->where('source_id', $id);
        $existing = (clone $query)->where('event_kind', $kind === 'supplier_bill' ? 'approved' : ($kind === 'supplier_credit' ? 'issued' : 'posted'))->where('event_token', 'original')->exists();
        if ($existing) {
            $this->engine->execute($actor, $companyId, $kind, $id, $kind === 'supplier_bill' ? 'approved' : ($kind === 'supplier_credit' ? 'issued' : 'posted'), 'original');

            return;
        }
        if ($query->exists()) {
            $this->fail('A conflicting source-event identity requires reconciliation.');
        }
        $enabled = $this->before($companyId, true);
        if (! $enabled) {
            return;
        }
        if (! $newTransition || AccountingPurchaseEvent::query()->where('company_id', $companyId)->where('source_kind', $kind)->where('source_id', $id)->exists()) {
            $this->fail('An already-posted source without its complete financial effect requires reconciliation; it cannot be backfilled by retry.');
        }
        $this->evidence->capture($actor, $companyId, $kind, $id);
        $result = $this->engine->execute($actor, $companyId, $kind, $id, $kind === 'supplier_bill' ? 'approved' : ($kind === 'supplier_credit' ? 'issued' : 'posted'), 'original');
        if ($result['mode'] !== 'recorded') {
            $this->fail('The complete financial effect was not recorded.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['accounting_source' => $message]);
    }
}

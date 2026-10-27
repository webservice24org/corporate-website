<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Close;

use App\Models\Accounting\AccountingPeriod;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingDecimal;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Shared hard-close boundary. Calendar primitives can isolate this dependency in their tests. */
class AccountingPeriodCloseGuard
{
    public function __construct(private readonly AccountingPeriodCloseReadiness $readiness, private readonly AccountingPeriodCloseEvidence $evidence, private readonly PurchaseCompanyAccess $access, private readonly AccountingDecimal $decimal) {}

    public function assertReady(User $actor, AccountingPeriod $period): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Hard-close checks must share the state-change transaction and company lock.');
        }
        $company = (int) $period->getAttribute('company_id');
        $this->access->authorize($actor, $company, AccountingPeriodCloseWorkflow::VIEW);
        $this->access->authorize($actor, $company, 'erp.accounting-periods.close');
        $history = $this->evidence->read($period);
        $last = $history === [] ? null : $history[array_key_last($history)];
        if ($last === null || $last->getAttribute('event_kind') !== 'approve') {
            $this->fail('Hard close requires a current prepared checklist and independent approval. Open Period Close Checklist.');
        }
        $capture = $this->readiness->capture($actor, $period);
        $approval = $last->getAttribute('payload');
        if (! $capture['snapshot']['automated_ready'] || $approval['snapshot_hash'] !== $capture['snapshot_hash'] || ($approval['adjustments_complete'] ?? null) !== true || trim((string) ($approval['inventory_reference'] ?? '')) === '' || trim((string) ($approval['adjustments_reference'] ?? '')) === '' || $this->decimal->money($approval['inventory_amount']) !== $capture['snapshot']['inventory_gl']['inventory'] || $this->decimal->money($approval['dispatched_amount']) !== $capture['snapshot']['inventory_gl']['dispatched_inventory']) {
            $this->fail('A blocker, changed evidence, period revision or unresolved manual reconciliation prevents hard close. Prepare and independently review the current checklist.');
        }
        $this->evidence->append($actor, $period, 'hard_close', ['approval_id' => (int) $last->getKey(), 'approval_hash' => $last->getAttribute('payload_hash'), 'snapshot_hash' => $capture['snapshot_hash'], 'closed_revision' => (int) $period->getAttribute('revision') + 1]);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['period_close' => $message]);
    }
}

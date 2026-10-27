<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Sales;

use App\Models\Accounting\AccountingSalesEvent;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\User;
use App\Services\ERP\Accounting\Source\AccountingSourceOrchestrator;
use App\Services\ERP\Accounting\Source\Purchase\AccountingPurchasePosting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Source-only writer. The source transaction owns status, counters, stock and financial effects together. */
final class AccountingSalesPosting
{
    public function __construct(private readonly AccountingPurchasePosting $gate, private readonly AccountingSalesEvidence $evidence, private readonly AccountingSourceOrchestrator $engine) {}

    public function before(int $companyId, bool $enforceCutoverLock = false): bool
    {
        return $this->gate->before($companyId, $enforceCutoverLock);
    }

    public function complete(User $actor, int $companyId, string $kind, int $id, bool $newTransition): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Keep source mutation and finance in one transaction.');
        }
        $eventKind = $kind === 'sales_delivery' ? 'posted' : 'issued';
        $query = AccountingSourceEffect::query()->where('company_id', $companyId)->where('source_kind', $kind)->where('source_id', $id);
        if ((clone $query)->where('event_kind', $eventKind)->where('event_token', 'original')->exists()) {
            $this->engine->execute($actor, $companyId, $kind, $id, $eventKind, 'original');

            return;
        }
        if ($query->exists()) {
            $this->fail('Conflicting original financial event identity requires reconciliation.');
        }
        if (! $this->before($companyId, true)) {
            return;
        }
        if (! $newTransition || AccountingSalesEvent::query()->where('company_id', $companyId)->where('source_kind', $kind)->where('source_id', $id)->exists()) {
            $this->fail('An already-final source without its complete financial effect requires reconciliation; retry cannot backfill it.');
        }
        $this->evidence->capture($actor, $companyId, $kind, $id);
        $result = $this->engine->execute($actor, $companyId, $kind, $id, $eventKind, 'original');
        if ($result['mode'] !== 'recorded') {
            $this->fail('The complete Sales financial bundle was not recorded.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['accounting_sales' => $message]);
    }
}

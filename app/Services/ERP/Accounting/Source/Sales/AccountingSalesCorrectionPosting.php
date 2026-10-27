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

/** Every caller invokes this inside the same native transaction as stock, counters and final status. */
final class AccountingSalesCorrectionPosting
{
    public function __construct(private readonly AccountingPurchasePosting $gate, private readonly AccountingSalesEvidence $evidence, private readonly AccountingSourceOrchestrator $engine) {}

    public function complete(User $actor, int $company, string $kind, int $id, string $event, bool $new): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Correction finance requires the native writer transaction.');
        }
        $admitted = AccountingSalesEvent::query()->where('company_id', $company)->where('source_kind', $kind)->where('source_id', $id)->exists();
        $recorded = AccountingSourceEffect::query()->where('company_id', $company)->where('source_kind', $kind)->where('source_id', $id)->exists();
        if ($admitted || $recorded) {
            $this->evidence->recognized($company, $kind, $id);
            $result = $this->engine->execute($actor, $company, $kind, $id, $event, 'original');
            if ($result['mode'] !== 'replayed') {
                $this->fail('Correction retry attempted to repair missing financial evidence.');
            }

            return;
        }
        if (! $this->gate->before($company, true)) {
            return;
        }
        if (! $new) {
            $this->fail('Final correction has no original financial evidence. Reconcile it instead of backfilling by retry.');
        }
        $this->evidence->capture($actor, $company, $kind, $id);
        $result = $this->engine->execute($actor, $company, $kind, $id, $event, 'original');
        if ($result['mode'] !== 'recorded') {
            $this->fail('Correction did not record its full financial effect.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['accounting_sales' => $message]);
    }
}

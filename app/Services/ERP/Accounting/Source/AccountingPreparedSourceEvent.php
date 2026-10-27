<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source;

final readonly class AccountingPreparedSourceEvent
{
    /** Dates and facts derive from persisted source evidence; no arbitrary HTTP accounting payload.
     * @param  array<array-key, mixed>  $facts
     * @param  array<array-key, AccountingSourceEffectPlan>  $effects
     * @param  array<array-key, array{source_kind: string, source_id: int, origin_date: string}>  $references
     */
    public function __construct(public int $companyId, public string $sourceKind, public int $sourceId, public string $eventKind, public string $eventToken, public string $currency, public string $documentDate, public string $accountingDate, public string $originDate, public array $facts, public array $effects, public array $references = []) {}
}

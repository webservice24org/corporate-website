<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source;

final readonly class AccountingSourceEffectPlan
{
    /** @param list<array<string, mixed>> $lines @param array<array-key, mixed> $proof */
    public function __construct(public string $kind, public array $lines, public array $proof, public ?string $noJournalReason = null) {}
}

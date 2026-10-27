<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source;

use App\Models\Company;
use App\Models\User;

/** Trusted code binding only. Source permissions/state and immutable source facts belong to each adapter. */
interface AccountingSourceAdapter
{
    public function authorize(User $actor, Company $company, int $sourceId, string $eventKind, string $eventToken): void;

    public function prepare(User $actor, Company $company, int $sourceId, string $eventKind, string $eventToken): AccountingPreparedSourceEvent;
}

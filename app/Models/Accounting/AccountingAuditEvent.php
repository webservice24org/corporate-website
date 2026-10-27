<?php

declare(strict_types=1);

namespace App\Models\Accounting;

final class AccountingAuditEvent extends AccountingImmutableModel
{
    protected $table = 'accounting_audit_events';

    protected function casts(): array
    {
        return ['actor_snapshot' => 'array', 'before_payload' => 'array', 'after_payload' => 'array', 'occurred_at' => 'datetime'];
    }
}

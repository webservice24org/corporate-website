<?php

declare(strict_types=1);

namespace App\Models\Accounting;

abstract class AccountingImmutableModel extends AccountingModel
{
    protected static function booted(): void
    {
        static::updating(static function (self $row): void {
            throw new \LogicException('Accounting history is append-only.');
        });
        static::deleting(static function (self $row): void {
            throw new \LogicException('Accounting history cannot be deleted.');
        });
    }
}

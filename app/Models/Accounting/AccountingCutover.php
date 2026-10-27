<?php

declare(strict_types=1);

namespace App\Models\Accounting;

final class AccountingCutover extends AccountingModel
{
    protected $table = 'accounting_cutovers';

    protected function casts(): array
    {
        return ['cutover_date' => 'date:Y-m-d', 'approved_at' => 'datetime', 'activated_at' => 'datetime', 'revision' => 'integer'];
    }

    protected static function booted(): void
    {
        foreach (['updating', 'deleting'] as $event) {
            self::$event(static function (self $row): void {
                if (self::query()->whereKey($row->getKey())->value('status') === 'activated') {
                    throw new \LogicException('Activated cutover evidence cannot change.');
                }
            });
        }
    }
}

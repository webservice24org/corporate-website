<?php

declare(strict_types=1);

namespace App\Models\Accounting;

final class AccountingMappingVersion extends AccountingModel
{
    protected $table = 'accounting_mapping_versions';

    protected function casts(): array
    {
        return ['effective_from' => 'date:Y-m-d', 'published_at' => 'datetime', 'version' => 'integer', 'revision' => 'integer'];
    }

    protected static function booted(): void
    {
        self::updating(static function (self $row): void {
            $state = self::query()->whereKey($row->getKey())->value('state');
            if ($state === 'retired' || ($state === 'published' && ($row->getAttribute('state') !== 'retired' || array_diff(array_keys($row->getDirty()), ['state', 'revision', 'updated_at']) !== []))) {
                throw new \LogicException('Published accounting mappings are immutable except controlled retirement.');
            }
        });
        self::deleting(static function (self $row): void {
            if (self::query()->whereKey($row->getKey())->value('state') !== 'draft') {
                throw new \LogicException('Published mapping history cannot be deleted.');
            }
        });
    }
}

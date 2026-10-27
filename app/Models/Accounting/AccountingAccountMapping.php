<?php

declare(strict_types=1);

namespace App\Models\Accounting;

final class AccountingAccountMapping extends AccountingModel
{
    protected $table = 'accounting_account_mappings';

    protected static function booted(): void
    {
        foreach (['creating', 'updating', 'deleting'] as $event) {
            self::$event(static function (self $row): void {
                $ids = array_unique(array_filter([$row->getRawOriginal('mapping_version_id'), $row->getAttribute('mapping_version_id'), $row->exists ? self::query()->whereKey($row->getKey())->value('mapping_version_id') : null]));
                foreach ($ids as $id) {
                    $version = AccountingMappingVersion::query()->findOrFail($id);
                    if ($version->getAttribute('state') !== 'draft') {
                        throw new \LogicException('Published mapping rows cannot change.');
                    }
                }
            });
        }
    }
}

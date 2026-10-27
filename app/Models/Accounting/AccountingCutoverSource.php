<?php

declare(strict_types=1);

namespace App\Models\Accounting;

final class AccountingCutoverSource extends AccountingModel
{
    protected $table = 'accounting_cutover_sources';

    protected function casts(): array
    {
        return ['opening_state' => 'array'];
    }

    protected static function booted(): void
    {
        foreach (['creating', 'updating', 'deleting'] as $event) {
            self::$event(static function (self $row): void {
                $ids = array_unique(array_filter([$row->getRawOriginal('cutover_id'), $row->getAttribute('cutover_id'), $row->exists ? self::query()->whereKey($row->getKey())->value('cutover_id') : null]));
                foreach ($ids as $id) {
                    if (AccountingCutover::query()->findOrFail($id)->getAttribute('status') === 'activated') {
                        throw new \LogicException('Activated cutover sources cannot change.');
                    }
                }
            });
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Models\Accounting;

final class AccountingOpeningWorkspace extends AccountingModel
{
    protected $table = 'accounting_opening_workspaces';

    protected static function booted(): void
    {
        foreach (['updating', 'deleting'] as $event) {
            self::$event(static function (self $row): void {
                foreach (array_unique([$row->getRawOriginal('journal_entry_id'), $row->getAttribute('journal_entry_id')]) as $id) {
                    if ($id !== null && JournalEntry::query()->whereKey($id)->where(fn ($q) => $q->where('status', 'posted')->orWhereNotNull('posted_at'))->exists()) {
                        throw new \LogicException('Posted opening evidence cannot change.');
                    }
                }
            });
        }
    }
}

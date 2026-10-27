<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use App\Services\ERP\Accounting\Reconciliation\AccountingBankFinalizationGuard;

final class JournalEntry extends AccountingModel
{
    protected $table = 'journal_entries';

    protected function casts(): array
    {
        return ['accounting_date' => 'date:Y-m-d', 'document_date' => 'date:Y-m-d', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'revision' => 'integer'];
    }

    protected static function booted(): void
    {
        foreach (['updating', 'deleting'] as $event) {
            self::$event(static function (self $row): void {
                if (self::query()->whereKey($row->getKey())->where(fn ($query) => $query->where('status', 'posted')->orWhereNotNull('posted_at'))->exists()) {
                    throw new \LogicException('Posted journals are immutable; use a linked reversal.');
                }
            });
        }
        $posting = static function (self $row): void {
            if ($row->getAttribute('status') === 'posted' || $row->getAttribute('posted_at') !== null) {
                app(AccountingBankFinalizationGuard::class)->assertJournal($row);
            }
        };
        self::creating($posting);
        self::updating($posting);
    }
}

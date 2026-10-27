<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Accounting\AccountingModel;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentReversalIntent;

/** Native review document. Financial recognition requires the dedicated complete-batch writer. */
final class SupplierPaymentReversalRequest extends AccountingModel
{
    protected $table = 'supplier_payment_reversal_requests';

    protected $attributes = ['status' => 'draft', 'revision' => 1];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'original_revision' => 'integer', 'document_date' => 'date:Y-m-d', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::saving(static function (self $row): void {
            app(AccountingSupplierPaymentReversalIntent::class)->guard($row);
        });
        self::deleting(static function (self $row): void {
            throw new \LogicException('Reversal review documents cannot be deleted; cancel an eligible request.');
        });
    }
}

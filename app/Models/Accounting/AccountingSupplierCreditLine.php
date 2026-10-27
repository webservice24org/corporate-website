<?php

declare(strict_types=1);

namespace App\Models\Accounting;

final class AccountingSupplierCreditLine extends AccountingImmutableModel
{
    protected $table = 'accounting_supplier_credit_lines';

    protected static function booted(): void
    {
        parent::booted();
        self::creating(static function (self $line): void {
            $parent = AccountingSupplierCredit::query()->where('company_id', $line->getAttribute('company_id'))->find((int) $line->getAttribute('supplier_credit_id'));
            if ($parent === null || $parent->getAttribute('status') !== 'draft') {
                throw new \LogicException('Credit lines can only be added to their own company draft.');
            }
        });
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'net' => 'decimal:2', 'tax' => 'decimal:2', 'clearing_amount' => 'decimal:2', 'is_stock_item' => 'boolean'];
    }
}

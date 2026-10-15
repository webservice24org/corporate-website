<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CustomerCreditAllocation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'posted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::updating(static function (self $row): void {
            throw new \LogicException('Credit allocations are append-only.');
        });
        self::deleting(static function (self $row): void {
            throw new \LogicException('Credit allocation history cannot be deleted.');
        });
    }
}

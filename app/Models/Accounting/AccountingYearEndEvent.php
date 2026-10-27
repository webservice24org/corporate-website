<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

final class AccountingYearEndEvent extends Model
{
    protected $table = 'accounting_year_end_events';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'sequence' => 'integer', 'occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new \LogicException('Year-end evidence is append only.');
        });
        self::deleting(static function (): never {
            throw new \LogicException('Year-end evidence cannot be deleted.');
        });
    }
}

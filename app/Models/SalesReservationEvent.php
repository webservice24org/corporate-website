<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SalesReservationEvent extends Model
{
    protected $table = 'sales_reservation_events';

    // Internal foundation model. Later services whitelist request input explicitly.
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(static function (self $event): void {
            throw new \LogicException('Sales reservation events are append-only.');
        });
        self::deleting(static function (self $event): void {
            throw new \LogicException('Sales reservation events cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'occurred_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function sales_stock_reservation(): BelongsTo
    {
        return $this->belongsTo(SalesStockReservation::class, 'sales_stock_reservation_id');
    }

    public function actor_idActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

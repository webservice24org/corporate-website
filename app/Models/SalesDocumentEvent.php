<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class SalesDocumentEvent extends Model
{
    protected $table = 'sales_document_events';

    // Internal event model. SalesAuditService supplies trusted, whitelisted metadata.
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(static function (self $event): void {
            throw new LogicException('Sales document events are append-only.');
        });
        self::deleting(static function (self $event): void {
            throw new LogicException('Sales document events cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'actor_snapshot' => 'array',
            'before_payload' => 'array',
            'after_payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function actor_idActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

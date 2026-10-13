<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PurchaseRequisition extends Model
{
    protected $table = 'purchase_requisitions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'required_at' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function requested_by(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approved_by(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}

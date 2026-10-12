<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDocument extends Model
{
    protected $fillable = [
        'employee_id',
        'uploaded_by',

        'document_type',
        'title',
        'document_number',

        'issue_date',
        'expiry_date',

        'file_path',
        'original_name',
        'mime_type',
        'file_size',

        'notes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date:Y-m-d',

            'expiry_date' => 'date:Y-m-d',

            'file_size' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(
            Employee::class
        );
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'uploaded_by'
        );
    }
}

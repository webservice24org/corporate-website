<?php

declare(strict_types=1);

namespace App\Models\Accounting;

final class AccountingDocumentSequence extends AccountingModel
{
    protected $table = 'accounting_document_sequences';

    protected function casts(): array
    {
        return ['last_number' => 'integer'];
    }
}

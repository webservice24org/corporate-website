<?php

declare(strict_types=1);

namespace App\Models\Accounting;

final class AccountingManifestVersion extends AccountingImmutableModel
{
    protected $table = 'accounting_manifest_versions';

    protected function casts(): array
    {
        return ['payload' => 'array', 'cutover_revision' => 'integer'];
    }
}

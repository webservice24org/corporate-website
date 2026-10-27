<?php

declare(strict_types=1);

return [
    // A local private root, outside public/ and storage/app/public. No URL or signed-public-file serving.
    'private_root' => env('ACCOUNTING_EXPENSE_PRIVATE_ROOT', storage_path('app/private-expense-evidence')),
];

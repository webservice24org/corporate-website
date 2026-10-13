<?php

declare(strict_types=1);

namespace App\Services\ERP\Purchase;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class PurchaseDocumentNumberService
{
    /** Call inside the SAME transaction as document creation; this is an internal allocator. */
    public function next(int $companyId, string $type): string
    {
        $prefix = match ($type) {
            'requisition' => 'PR',
            'order' => 'PO',
            'rfq' => 'RFQ',
            'receipt' => 'GRN',
            'return' => 'PRT',
            'bill' => 'BILL',
            default => throw new InvalidArgumentException('Unsupported purchasing document type.'),
        };

        if (DB::transactionLevel() < 1) {
            throw new LogicException('Purchasing numbers must be allocated inside a document transaction.');
        }

        // Lock the existing company row first: concurrent first allocations cannot race.
        Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
        $year = (int) now()->format('Y');
        $scope = ['company_id' => $companyId, 'document_type' => $type, 'year' => $year];
        $table = DB::table('purchase_document_sequences');
        $sequence = (clone $table)->where($scope)->lockForUpdate()->first();

        if ($sequence === null) {
            $table->insert([...$scope, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $sequence = (clone $table)->where($scope)->lockForUpdate()->first();
        }

        if ($sequence === null) {
            throw new LogicException('Unable to initialize purchasing sequence.');
        }

        $next = (int) $sequence->last_number + 1;
        if ($next > 999999) {
            throw new LogicException('Purchasing sequence exhausted for this year.');
        }

        (clone $table)->where($scope)->update(['last_number' => $next, 'updated_at' => now()]);

        return sprintf('%s-%d-%06d', $prefix, $year, $next);
    }
}

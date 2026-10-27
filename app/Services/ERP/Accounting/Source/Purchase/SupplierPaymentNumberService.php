<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Purchase;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

/** Internal native numbering only. Caller authorizes creation and inserts the payment in the same transaction. */
final class SupplierPaymentNumberService
{
    public function next(int $company, string $date): string
    {
        if (! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date) || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            throw new \InvalidArgumentException('Native payment numbering requires a real YYYY-MM-DD document date.');
        }
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Payment numbering belongs in the authorized native document transaction.');
        }
        Company::query()->whereKey($company)->lockForUpdate()->firstOrFail();
        $scope = ['company_id' => $company, 'document_type' => 'supplier_payment', 'year' => (int) substr($date, 0, 4)];
        $query = DB::table('purchase_document_sequences')->where($scope);
        $row = (clone $query)->lockForUpdate()->first();
        if ($row === null) {
            DB::table('purchase_document_sequences')->insert([...$scope, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $row = (clone $query)->lockForUpdate()->first();
        }
        if ($row === null || (int) $row->last_number < 0 || (int) $row->last_number >= 999999) {
            throw new \LogicException('Native supplier payment sequence is unavailable or exhausted.');
        }
        $next = (int) $row->last_number + 1;
        $query->update(['last_number' => $next, 'updated_at' => now()]);

        return sprintf('SP-%s-%06d', substr($date, 0, 4), $next);
    }
}

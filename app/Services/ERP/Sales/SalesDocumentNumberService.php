<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use App\Enums\ERP\Sales\SalesDocumentType;
use App\Models\Company;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class SalesDocumentNumberService
{
    /** Internal allocator: call inside the SAME transaction as document insertion. */
    public function next(int $companyId, string $type, string $documentDate): string
    {
        $documentType = SalesDocumentType::tryFrom($type);
        if ($documentType === null) {
            throw new InvalidArgumentException('Unsupported Sales document type.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $documentDate);
        if ($date === false || $date->format('Y-m-d') !== $documentDate || (int) $date->format('Y') < 1) {
            throw new InvalidArgumentException('Document date must be a valid YYYY-MM-DD date.');
        }
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Sales numbers must be allocated inside a document transaction.');
        }

        // Serialize the first allocation too, before the sequence row exists.
        Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
        $year = (int) $date->format('Y');
        $scope = ['company_id' => $companyId, 'document_type' => $type, 'year' => $year];
        $table = DB::table('sales_document_sequences');
        $sequence = (clone $table)->where($scope)->lockForUpdate()->first();
        if ($sequence === null) {
            $table->insert([...$scope, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $sequence = (clone $table)->where($scope)->lockForUpdate()->first();
        }
        if ($sequence === null) {
            throw new LogicException('Unable to initialize Sales sequence.');
        }

        $next = (int) $sequence->last_number + 1;
        if ($next > 999999) {
            throw new LogicException('Sales sequence exhausted for this year.');
        }
        (clone $table)->where($scope)->update(['last_number' => $next, 'updated_at' => now()]);

        return sprintf('%s-%d-%06d', $documentType->prefix(), $year, $next);
    }
}

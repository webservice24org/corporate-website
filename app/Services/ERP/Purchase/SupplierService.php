<?php

declare(strict_types=1);

namespace App\Services\ERP\Purchase;

use App\DTOs\Purchase\SupplierData;
use App\Models\Company;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SupplierService
{
    /** @return Builder<Supplier> */
    public function forCompany(int $companyId): Builder
    {
        return Supplier::query()->where('company_id', $companyId);
    }

    public function create(Company $company, SupplierData $data): Supplier
    {
        return DB::transaction(function () use ($company, $data): Supplier {
            $this->assertUniqueCode((int) $company->getKey(), $data->code);

            return Supplier::query()->create([
                ...$data->toArray(),
                'company_id' => $company->getKey(),
            ]);
        });
    }

    public function update(int $companyId, int $supplierId, SupplierData $data): Supplier
    {
        return DB::transaction(function () use ($companyId, $supplierId, $data): Supplier {
            $supplier = $this->forCompany($companyId)->lockForUpdate()->findOrFail($supplierId);
            $this->assertUniqueCode($companyId, $data->code, $supplierId);
            $supplier->fill($data->toArray());
            $supplier->save();

            return $supplier->refresh();
        });
    }

    public function find(int $companyId, int $supplierId): Supplier
    {
        return $this->forCompany($companyId)->findOrFail($supplierId);
    }

    private function assertUniqueCode(int $companyId, string $code, ?int $exceptId = null): void
    {
        if ($this->forCompany($companyId)
            ->where('code', $code)
            ->when($exceptId !== null, fn (Builder $q) => $q->whereKeyNot($exceptId))
            ->exists()) {
            throw ValidationException::withMessages(['code' => 'This supplier code is already used by this company.']);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Purchase;

use App\DTOs\Purchase\SupplierData;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierAddress;
use App\Models\SupplierContact;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SupplierManagementService
{
    public function __construct(
        private readonly PurchaseCompanyAccess $access,
        private readonly SupplierService $suppliers,
    ) {}

    /** @return Builder<Company> */
    public function companies(User $actor, string $permission): Builder
    {
        $query = Company::query();
        if (! $actor->can($permission)) {
            return $query->whereRaw('1 = 0');
        }
        if (! $actor->hasRole('super-admin')) {
            $query->whereIn('id', $actor->employee()->select('company_id'));
        }

        return $query;
    }

    /** @return Builder<Supplier> */
    public function visible(User $actor, string $permission = 'erp.suppliers.view'): Builder
    {
        return Supplier::query()->whereIn('company_id', $this->companies($actor, $permission)->select('id'));
    }

    public function find(User $actor, int $id, string $permission): Supplier
    {
        $supplier = $this->visible($actor, $permission)->findOrFail($id);
        $this->access->authorize($actor, (int) $supplier->company_id, $permission);

        return $supplier;
    }

    public function create(User $actor, int $companyId, SupplierData $data): Supplier
    {
        $this->access->authorize($actor, $companyId, 'erp.suppliers.create');
        try {
            return DB::transaction(function () use ($actor, $companyId, $data): Supplier {
                Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
                $supplier = $this->suppliers->create(Company::query()->findOrFail($companyId), $data);
                $this->audit($actor, $supplier, 'supplier', (int) $supplier->getKey(), 'created', null, $supplier->toArray());

                return $supplier;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'This supplier code is already used by this company.']);
        }
    }

    public function update(User $actor, int $id, SupplierData $data): Supplier
    {
        $supplier = $this->find($actor, $id, 'erp.suppliers.edit');
        try {
            return DB::transaction(function () use ($actor, $supplier, $data): Supplier {
                $supplier = $this->visible($actor, 'erp.suppliers.edit')->lockForUpdate()->findOrFail($supplier->getKey());
                $old = $supplier->toArray();
                $updated = $this->suppliers->update((int) $supplier->company_id, (int) $supplier->getKey(), $data);
                $this->audit($actor, $updated, 'supplier', (int) $updated->getKey(), 'updated', $old, $updated->toArray());

                return $updated;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'This supplier code is already used by this company.']);
        }
    }

    public function delete(User $actor, int $id): void
    {
        $this->find($actor, $id, 'erp.suppliers.delete');
        try {
            DB::transaction(function () use ($actor, $id): void {
                $supplier = $this->visible($actor, 'erp.suppliers.delete')->lockForUpdate()->findOrFail($id);
                foreach (['rfq_suppliers', 'supplier_quotations', 'purchase_orders', 'purchase_returns', 'supplier_bills'] as $table) {
                    if (DB::table($table)->where('supplier_id', $id)->exists()) {
                        throw ValidationException::withMessages(['record' => 'This supplier is used by purchasing documents. Set it inactive instead.']);
                    }
                }
                $this->audit($actor, $supplier, 'supplier', $id, 'deleted', $supplier->toArray(), null);
                foreach (['contact' => SupplierContact::class, 'address' => SupplierAddress::class] as $kind => $model) {
                    foreach ($model::query()->where('supplier_id', $id)->get() as $child) {
                        $this->audit($actor, $supplier, $kind, (int) $child->getKey(), 'deleted', $child->toArray(), null);
                        $child->delete();
                    }
                }
                $supplier->delete();
            }, 3);
        } catch (QueryException $exception) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [1451, 787], true)
                || ($exception->errorInfo[0] ?? '') === '23503') {
                throw ValidationException::withMessages(['record' => 'This supplier is used by another record. Set it inactive instead.']);
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function saveChild(User $actor, int $supplierId, string $kind, array $data, ?int $childId = null): void
    {
        [$model, $flag, $permission] = $this->childDefinition($kind);
        $fields = $kind === 'contact'
            ? ['name', 'job_title', 'email', 'phone', 'is_primary']
            : ['label', 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country', 'is_default'];
        $data = array_intersect_key($data, array_flip($fields));
        $this->find($actor, $supplierId, $permission);
        DB::transaction(function () use ($actor, $supplierId, $kind, $data, $childId, $model, $flag, $permission): void {
            $supplier = $this->visible($actor, $permission)->lockForUpdate()->findOrFail($supplierId);
            $record = $childId === null ? new $model : $model::query()->where('supplier_id', $supplierId)->lockForUpdate()->findOrFail($childId);
            $old = $record->exists ? $record->toArray() : null;
            if ($data[$flag] ?? false) {
                // Supplier lock serializes competing primary/default selections.
                foreach ($model::query()->where('supplier_id', $supplierId)->where($flag, true)->get() as $previous) {
                    if ((int) $previous->getKey() === $childId) {
                        continue;
                    }
                    $before = $previous->toArray();
                    $previous->setAttribute($flag, false);
                    $previous->save();
                    $this->audit($actor, $supplier, $kind, (int) $previous->getKey(), 'updated', $before, $previous->toArray());
                }
            }
            $record->fill($data);
            $record->setAttribute('supplier_id', $supplierId);
            $record->save();
            $this->audit($actor, $supplier, $kind, (int) $record->getKey(), $childId === null ? 'created' : 'updated', $old, $record->toArray());
        }, 3);
    }

    public function deleteChild(User $actor, int $supplierId, string $kind, int $childId): void
    {
        [$model, , $permission] = $this->childDefinition($kind);
        $this->find($actor, $supplierId, $permission);
        DB::transaction(function () use ($actor, $supplierId, $kind, $childId, $model, $permission): void {
            $supplier = $this->visible($actor, $permission)->lockForUpdate()->findOrFail($supplierId);
            $record = $model::query()->where('supplier_id', $supplierId)->lockForUpdate()->findOrFail($childId);
            $this->audit($actor, $supplier, $kind, $childId, 'deleted', $record->toArray(), null);
            $record->delete();
        }, 3);
    }

    /** @return array{class-string<SupplierContact>|class-string<SupplierAddress>, string, string} */
    private function childDefinition(string $kind): array
    {
        return match ($kind) {
            'contact' => [SupplierContact::class, 'is_primary', 'erp.suppliers.contacts.manage'],
            'address' => [SupplierAddress::class, 'is_default', 'erp.suppliers.addresses.manage'],
            default => throw ValidationException::withMessages(['record' => 'Unsupported supplier record.']),
        };
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function audit(User $actor, Supplier $supplier, string $type, int $recordId, string $action, ?array $old, ?array $new): void
    {
        DB::table('supplier_audit_logs')->insert([
            'company_id' => $supplier->company_id, 'supplier_id' => $supplier->getKey(),
            'actor_id' => $actor->getKey(), 'record_type' => $type, 'record_id' => $recordId,
            'action' => $action, 'old_values' => $old === null ? null : json_encode($old, JSON_THROW_ON_ERROR),
            'new_values' => $new === null ? null : json_encode($new, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}

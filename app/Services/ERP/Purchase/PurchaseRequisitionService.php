<?php

declare(strict_types=1);

namespace App\Services\ERP\Purchase;

use App\Models\Company;
use App\Models\Department;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PurchaseRequisitionService
{
    public function __construct(
        private readonly PurchaseCompanyAccess $access,
        private readonly PurchaseWorkflowService $workflow,
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

    /** @return Builder<PurchaseRequisition> */
    public function visible(User $actor, string $permission = 'erp.purchase-requisitions.view'): Builder
    {
        return PurchaseRequisition::query()->whereIn('company_id', $this->companies($actor, $permission)->select('id'));
    }

    public function find(User $actor, int $id, string $permission): PurchaseRequisition
    {
        $document = $this->visible($actor, $permission)->findOrFail($id);
        $this->access->authorize($actor, (int) $document->company_id, $permission);

        return $document;
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): PurchaseRequisition
    {
        $companyId = (int) $data['company_id'];
        $this->access->authorize($actor, $companyId, 'erp.purchase-requisitions.create');

        return DB::transaction(function () use ($actor, $companyId, $data): PurchaseRequisition {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $currency = strtoupper((string) $company->currency_code);
            if (! preg_match('/^[A-Z]{3}$/D', $currency)) {
                throw ValidationException::withMessages(['company_id' => 'Configure a three-letter currency code for this company.']);
            }
            $this->validateReferences($companyId, $data);
            $departmentId = empty($data['department_id']) ? null : (int) $data['department_id'];
            $document = $this->workflow->createRequisitionDraft($actor, $companyId, $departmentId);
            $document->fill(['required_at' => $data['required_at'] ?? null, 'notes' => $data['notes'] ?? null, 'currency_code' => $currency, 'revision' => 1]);
            $document->save();
            $this->replaceItems($document, $data['items']);
            $this->audit($actor, $document, 'created', null);

            return $document->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, int $id, array $data): PurchaseRequisition
    {
        $this->find($actor, $id, 'erp.purchase-requisitions.edit');

        return DB::transaction(function () use ($actor, $id, $data): PurchaseRequisition {
            $document = $this->visible($actor, 'erp.purchase-requisitions.edit')->lockForUpdate()->findOrFail($id);
            $this->assertRevision($document, (int) $data['revision']);
            if ($document->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only draft requisitions can be edited.']);
            }
            $this->validateReferences((int) $document->company_id, $data);
            $old = $this->snapshot($document);
            $document->fill([
                'department_id' => empty($data['department_id']) ? null : (int) $data['department_id'],
                'required_at' => $data['required_at'] ?? null, 'notes' => $data['notes'] ?? null,
                'revision' => (int) $document->getAttribute('revision') + 1,
            ]);
            $document->save();
            $this->replaceItems($document, $data['items']);
            $this->audit($actor, $document, 'updated', $old);

            return $document->refresh();
        }, 3);
    }

    public function transition(User $actor, int $id, string $action, int $revision, ?string $comment): PurchaseRequisition
    {
        if (! in_array($action, ['submit', 'approve', 'reject'], true)) {
            throw ValidationException::withMessages(['action' => 'Unsupported requisition action.']);
        }
        $permission = 'erp.purchase-requisitions.'.$action;
        $this->find($actor, $id, $permission);

        return DB::transaction(function () use ($actor, $id, $action, $revision, $comment, $permission): PurchaseRequisition {
            $document = $this->visible($actor, $permission)->lockForUpdate()->findOrFail($id);
            $this->assertRevision($document, $revision);
            if (in_array($action, ['submit', 'approve'], true)) {
                $items = PurchaseRequisitionItem::query()->where('purchase_requisition_id', $id)->get()->map(fn ($item): array => $item->only(['product_id', 'product_variant_id', 'quantity', 'estimated_unit_price', 'notes']))->all();
                $this->validateReferences((int) $document->company_id, ['department_id' => $document->department_id, 'items' => $items]);
            }
            $old = $this->snapshot($document);
            $changed = $this->workflow->transitionRequisition($actor, (int) $document->company_id, $id, $action, $comment);
            $changed->setAttribute('revision', $revision + 1);
            $changed->save();
            $this->audit($actor, $changed, $action, $old);

            return $changed->refresh();
        }, 3);
    }

    private function assertRevision(PurchaseRequisition $document, int $revision): void
    {
        if ((int) $document->getAttribute('revision') !== $revision) {
            throw ValidationException::withMessages(['revision' => 'This requisition changed in another session. Reload it before continuing.']);
        }
    }

    /** @param array<string, mixed> $data */
    private function validateReferences(int $companyId, array $data): void
    {
        if (! empty($data['department_id']) && ! Department::query()->where('company_id', $companyId)->where('status', 'active')->whereKey($data['department_id'])->exists()) {
            throw ValidationException::withMessages(['department_id' => 'Select an active department in this company.']);
        }
        Validator::make($data, [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000', 'regex:/^[0-9]+(?:\.[0-9]{1,4})?$/D'],
            'items.*.estimated_unit_price' => ['required', 'numeric', 'min:0', 'max:1000000', 'regex:/^[0-9]+(?:\.[0-9]{1,2})?$/D'],
        ])->validate();
        $seen = [];
        foreach ($data['items'] as $index => $item) {
            $product = Product::query()->where('company_id', $companyId)->where('is_active', true)->find($item['product_id']);
            if ($product === null) {
                throw ValidationException::withMessages(['items.'.$index.'.product_id' => 'Select an active product in this company.']);
            }
            $variantId = empty($item['product_variant_id']) ? null : (int) $item['product_variant_id'];
            if ($variantId !== null && ! ProductVariant::query()->where('product_id', $product->getKey())->where('is_active', true)->whereKey($variantId)->exists()) {
                throw ValidationException::withMessages(['items.'.$index.'.product_variant_id' => 'Select an active variant of this product.']);
            }
            if (! $product->has_variants && $variantId !== null) {
                throw ValidationException::withMessages(['items.'.$index.'.product_variant_id' => 'This product no longer uses variants. Select the product without a variant.']);
            }
            if ($product->has_variants && $variantId === null) {
                throw ValidationException::withMessages(['items.'.$index.'.product_variant_id' => 'Select a product variant.']);
            }
            $key = $product->getKey().':'.($variantId ?? 0);
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['items.'.$index.'.product_id' => 'This product/variant is already listed. Combine its quantity into one row.']);
            }
            $seen[$key] = true;
        }
    }

    /** @param array<int, array<string, mixed>> $items */
    private function replaceItems(PurchaseRequisition $document, array $items): void
    {
        PurchaseRequisitionItem::query()->where('purchase_requisition_id', $document->getKey())->delete();
        foreach ($items as $item) {
            PurchaseRequisitionItem::query()->create([
                'purchase_requisition_id' => $document->getKey(), 'product_id' => (int) $item['product_id'],
                'product_variant_id' => empty($item['product_variant_id']) ? null : (int) $item['product_variant_id'],
                'quantity' => $item['quantity'], 'estimated_unit_price' => $item['estimated_unit_price'], 'notes' => $item['notes'] ?? null,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(PurchaseRequisition $document): array
    {
        return [...$document->attributesToArray(), 'items' => PurchaseRequisitionItem::query()->where('purchase_requisition_id', $document->getKey())->orderBy('id')->get()->toArray()];
    }

    /** @param array<string, mixed>|null $old */
    private function audit(User $actor, PurchaseRequisition $document, string $action, ?array $old): void
    {
        DB::table('purchase_requisition_audit_logs')->insert([
            'company_id' => $document->company_id, 'purchase_requisition_id' => $document->getKey(), 'actor_id' => $actor->getKey(),
            'action' => $action, 'old_values' => $old === null ? null : json_encode($old, JSON_THROW_ON_ERROR),
            'new_values' => json_encode($this->snapshot($document), JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}

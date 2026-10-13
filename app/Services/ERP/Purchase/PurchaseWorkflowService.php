<?php

declare(strict_types=1);

namespace App\Services\ERP\Purchase;

use App\Models\Department;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Supplier;
use App\Models\SupplierQuotation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Internal domain API. All future purchasing writers must lock the document header first. */
final class PurchaseWorkflowService
{
    public function __construct(
        private readonly PurchaseCompanyAccess $access,
        private readonly PurchaseDocumentNumberService $numbers,
    ) {}

    public function createRequisitionDraft(User $actor, int $companyId, ?int $departmentId = null): PurchaseRequisition
    {
        $this->access->authorize($actor, $companyId, 'erp.purchase-requisitions.create');

        return DB::transaction(function () use ($actor, $companyId, $departmentId): PurchaseRequisition {
            if ($departmentId !== null) {
                Department::query()->where('company_id', $companyId)->findOrFail($departmentId);
            }
            $document = PurchaseRequisition::query()->create([
                'company_id' => $companyId,
                'department_id' => $departmentId,
                'requested_by' => $actor->getKey(),
                'number' => $this->numbers->next($companyId, 'requisition'),
                'status' => 'draft',
            ]);
            $this->record($actor, $companyId, 'requisition', (int) $document->getKey(), null, 'draft', null);

            return $document;
        }, 3);
    }

    public function createOrderDraft(User $actor, int $companyId, int $supplierId): PurchaseOrder
    {
        $this->access->authorize($actor, $companyId, 'erp.purchase-orders.create');

        return DB::transaction(function () use ($actor, $companyId, $supplierId): PurchaseOrder {
            // Allocate first to maintain the company -> supplier lock order in all purchasing writers.
            $number = $this->numbers->next($companyId, 'order');
            $supplier = Supplier::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($supplierId);
            if ($supplier->status !== 'active') {
                throw ValidationException::withMessages(['supplier_id' => 'Select an active supplier.']);
            }
            $document = PurchaseOrder::query()->create([
                'company_id' => $companyId,
                'supplier_id' => $supplierId,
                'created_by' => $actor->getKey(),
                'number' => $number,
                'status' => 'draft',
                'currency_code' => $supplier->currency_code,
            ]);
            $this->record($actor, $companyId, 'order', (int) $document->getKey(), null, 'draft', null);

            return $document;
        }, 3);
    }

    public function transitionRequisition(User $actor, int $companyId, int $id, string $action, ?string $comment = null): PurchaseRequisition
    {
        $permission = $this->permission('purchase-requisitions', $action, ['submit', 'approve', 'reject']);
        $this->access->authorize($actor, $companyId, $permission);

        return DB::transaction(function () use ($actor, $companyId, $id, $action, $comment): PurchaseRequisition {
            $document = PurchaseRequisition::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            $from = (string) $document->status;
            $to = $this->target($from, $action);
            $comment = $this->validateComment($action, $comment);
            if ($action === 'submit') {
                $this->validateItems($companyId, 'purchase_requisition_items', 'purchase_requisition_id', $id);
                $document->submitted_at = now()->toDateTimeString();
            }
            if ($action === 'approve') {
                $this->assertDifferentApprover((int) $document->requested_by, $actor);
                $document->approved_by = $actor->getKey();
                $document->approved_at = now()->toDateTimeString();
            }
            $document->status = $to;
            $document->save();
            $this->record($actor, $companyId, 'requisition', $id, $from, $to, $comment);

            return $document->refresh();
        }, 3);
    }

    public function transitionOrder(User $actor, int $companyId, int $id, string $action, ?string $comment = null): PurchaseOrder
    {
        $permission = $this->permission('purchase-orders', $action, ['submit', 'approve', 'reject', 'order']);
        $this->access->authorize($actor, $companyId, $permission);

        return DB::transaction(function () use ($actor, $companyId, $id, $action, $comment): PurchaseOrder {
            $document = PurchaseOrder::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            $from = (string) $document->status;
            $to = $this->target($from, $action);
            $comment = $this->validateComment($action, $comment);
            if (in_array($action, ['submit', 'approve', 'order'], true)) {
                $supplier = Supplier::query()->where('company_id', $companyId)->findOrFail($document->supplier_id);
                if ($supplier->status !== 'active' || $supplier->currency_code !== $document->currency_code) {
                    throw ValidationException::withMessages(['supplier_id' => 'Supplier must be active and use the order currency.']);
                }
                $this->validateOrder($document, $companyId);
            }
            if ($action === 'submit') {
                $document->submitted_at = now()->toDateTimeString();
            }
            if ($action === 'approve') {
                $this->assertDifferentApprover((int) $document->created_by, $actor);
                $document->approved_by = $actor->getKey();
                $document->approved_at = now()->toDateTimeString();
            }
            if ($action === 'order') {
                $document->ordered_at = now()->toDateTimeString();
            }
            $document->status = $to;
            $document->save();
            $this->record($actor, $companyId, 'order', $id, $from, $to, $comment);

            return $document->refresh();
        }, 3);
    }

    /** @param list<string> $allowed */
    private function permission(string $module, string $action, array $allowed): string
    {
        if (! in_array($action, $allowed, true)) {
            throw ValidationException::withMessages(['action' => 'Unsupported purchasing action.']);
        }

        return 'erp.'.$module.'.'.$action;
    }

    private function target(string $from, string $action): string
    {
        $to = match (true) {
            $from === 'draft' && $action === 'submit' => 'submitted',
            $from === 'submitted' && $action === 'approve' => 'approved',
            $from === 'submitted' && $action === 'reject' => 'rejected',
            $from === 'approved' && $action === 'order' => 'ordered',
            default => null,
        };
        if ($to === null) {
            throw ValidationException::withMessages(['status' => 'This action is not allowed from the current status.']);
        }

        return $to;
    }

    private function validateComment(string $action, ?string $comment): ?string
    {
        $comment = $comment === null ? null : trim($comment);
        Validator::make(['comment' => $comment], [
            'comment' => [$action === 'reject' ? 'required' : 'nullable', 'string', 'max:2000'],
        ])->validate();

        return $comment;
    }

    private function assertDifferentApprover(int $creatorId, User $actor): void
    {
        if ($creatorId === (int) $actor->getKey()) {
            throw ValidationException::withMessages(['approved_by' => 'A different user must approve this document.']);
        }
    }

    private function validateItems(int $companyId, string $table, string $foreignKey, int $id): void
    {
        $items = DB::table($table)->where($foreignKey, $id)->lockForUpdate()->get();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Add at least one item before submitting.']);
        }
        foreach ($items as $item) {
            Validator::make(['quantity' => $item->quantity, 'price' => $item->estimated_unit_price], [
                'quantity' => ['required', 'numeric', 'gt:0'],
                'price' => ['required', 'numeric', 'min:0'],
            ])->validate();
            $product = Product::query()->where('company_id', $companyId)->where('is_active', true)->findOrFail($item->product_id);
            if ($item->product_variant_id !== null) {
                if (! $product->has_variants) {
                    throw ValidationException::withMessages(['items' => 'This product no longer uses variants. Review its source configuration.']);
                }
                ProductVariant::query()->where('product_id', $product->getKey())->where('is_active', true)->findOrFail($item->product_variant_id);
            } elseif ($product->has_variants) {
                throw ValidationException::withMessages(['items' => 'Select a variant for this product.']);
            }
        }
    }

    private function validateOrder(PurchaseOrder $document, int $companyId): void
    {
        if ($document->purchase_requisition_id !== null) {
            PurchaseRequisition::query()->where('company_id', $companyId)->where('status', 'approved')
                ->findOrFail($document->purchase_requisition_id);
        }
        if ($document->supplier_quotation_id !== null) {
            SupplierQuotation::query()->where('company_id', $companyId)
                ->where('supplier_id', $document->supplier_id)->where('currency_code', $document->currency_code)
                ->findOrFail($document->supplier_quotation_id);
        }
        $items = DB::table('purchase_order_items')->where('purchase_order_id', $document->getKey())->lockForUpdate()->get();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Add at least one item before submitting.']);
        }
        $subtotal = 0;
        $discount = 0;
        $tax = 0;
        $total = 0;
        foreach ($items as $item) {
            $product = Product::query()->where('company_id', $companyId)->where('is_active', true)->findOrFail($item->product_id);
            if ($item->product_variant_id !== null) {
                if (! $product->has_variants) {
                    throw ValidationException::withMessages(['items' => 'This product no longer uses variants. Review its source configuration.']);
                }
                ProductVariant::query()->where('product_id', $product->getKey())->where('is_active', true)->findOrFail($item->product_variant_id);
            } elseif ($product->has_variants) {
                throw ValidationException::withMessages(['items' => 'Select a variant for this product.']);
            }
            $quantity = $this->scaled((string) $item->quantity, 4, 1000000);
            $price = $this->scaled((string) $item->unit_price, 2, 1000000);
            $lineDiscount = $this->scaled((string) $item->discount_amount, 2, 1000000000000);
            $lineTax = $this->scaled((string) $item->tax_amount, 2, 1000000000000);
            $lineTotal = $this->scaled((string) $item->line_total, 2, 1000000000000);
            // Integer cents, round half up after quantity (four decimal places) times unit price.
            $base = intdiv($quantity * $price + 5000, 10000);
            if ($quantity === 0 || $lineDiscount > $base || $lineTotal !== $base - $lineDiscount + $lineTax
                || $this->scaled((string) $item->received_quantity, 4, 1000000) !== 0) {
                throw ValidationException::withMessages(['items' => 'Invalid order quantity, received quantity, discount or line total.']);
            }
            $subtotal += $base;
            $discount += $lineDiscount;
            $tax += $lineTax;
            $total += $lineTotal;
            if (max($subtotal, $discount, $tax, $total) > 100000000000000) {
                throw ValidationException::withMessages(['items' => 'Order totals exceed the supported amount.']);
            }
        }
        foreach (['subtotal' => $subtotal, 'discount_total' => $discount, 'tax_total' => $tax, 'grand_total' => $total] as $field => $expected) {
            if ($this->scaled((string) $document->getAttribute($field), 2, 1000000000000) !== $expected) {
                throw ValidationException::withMessages([$field => 'Order totals must match the item totals.']);
            }
        }
    }

    private function scaled(string $value, int $scale, int $max): int
    {
        if (! preg_match('/^[0-9]+(?:\.[0-9]{1,'.$scale.'})?$/D', $value)) {
            throw ValidationException::withMessages(['items' => 'Use nonnegative decimal values with the supported precision.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        if (strlen($whole) > strlen((string) $max) || (int) $whole > $max
            || ((int) $whole === $max && (int) $fraction > 0)) {
            throw ValidationException::withMessages(['items' => 'Value exceeds the supported amount.']);
        }

        return (int) $whole * (10 ** $scale) + (int) str_pad($fraction, $scale, '0');
    }

    private function record(User $actor, int $companyId, string $type, int $id, ?string $from, string $to, ?string $comment): void
    {
        DB::table('purchase_workflow_events')->insert([
            'company_id' => $companyId, 'actor_id' => $actor->getKey(),
            'document_type' => $type, 'document_id' => $id,
            'from_status' => $from, 'to_status' => $to,
            'comment' => $comment, 'created_at' => now(),
        ]);
    }
}

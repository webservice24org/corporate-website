<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\ERP\Inventory\UpdateInventoryReorderSettingRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryReorderSettingController extends Controller
{
    public function update(
        UpdateInventoryReorderSettingRequest $request
    ): RedirectResponse {
        $data = $request->validated();

        $companyId = (int) $data['company_id'];
        $warehouseId = (int) $data['warehouse_id'];
        $productId = (int) $data['product_id'];

        $variantId = isset($data['product_variant_id'])
            ? (int) $data['product_variant_id']
            : null;

        $user = $request->user();

        abort_unless($user !== null, 403);

        /*
         * Company authorization is separate from
         * the reorder-management permission.
         */
        if (!$user->hasRole('super-admin')) {
            $hasCompanyAccess = $user->employee()
                ->where('company_id', $companyId)
                ->exists();

            if (!$hasCompanyAccess) {
                abort(
                    403,
                    'You cannot manage inventory for this company.'
                );
            }
        }

        /*
         * Validate all related entities inside the
         * authorized company boundary.
         */
        $warehouse = Warehouse::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->find($warehouseId);

        if ($warehouse === null) {
            throw ValidationException::withMessages([
                'warehouse_id' =>
                    'The selected warehouse is not available for this company.',
            ]);
        }

        $product = Product::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('product_type', 'physical')
            ->where('track_inventory', true)
            ->find($productId);

        if ($product === null) {
            throw ValidationException::withMessages([
                'product_id' =>
                    'The selected product is not available for this company.',
            ]);
        }

        /*
         * Variant products require a valid variant.
         * Non-variant products must not receive one.
         */
        if ($product->has_variants) {
            if ($variantId === null) {
                throw ValidationException::withMessages([
                    'product_variant_id' =>
                        'A variant is required for this product.',
                ]);
            }

            $variantExists = ProductVariant::query()
                ->where('product_id', $productId)
                ->where('is_active', true)
                ->whereKey($variantId)
                ->exists();

            if (!$variantExists) {
                throw ValidationException::withMessages([
                    'product_variant_id' =>
                        'The selected variant does not belong to this product.',
                ]);
            }
        } elseif ($variantId !== null) {
            throw ValidationException::withMessages([
                'product_variant_id' =>
                    'This product does not use variants.',
            ]);
        }

        $variantKey = $variantId ?? 0;

        /*
         * Store exact decimal values.
         * Do not convert to PHP floats.
         */
        $reorderLevel = (string) $data['reorder_level'];
        $minimumStock = (string) $data['minimum_stock'];

        /*
         * Atomic upsert uses the existing unique index:
         *
         * warehouse_id + product_id + variant_key
         *
         * This avoids duplicate rows from simultaneous
         * requests. It does not modify inventory balances.
         */
        DB::transaction(
            function () use ($companyId, $warehouseId, $productId, $variantId, $variantKey, $reorderLevel, $minimumStock): void {
                $now = now();

                DB::table('inventory_reorder_settings')
                    ->upsert(
                        [
                            [
                                'company_id' => $companyId,
                                'warehouse_id' => $warehouseId,
                                'product_id' => $productId,
                                'product_variant_id' => $variantId,
                                'variant_key' => $variantKey,
                                'reorder_level' => $reorderLevel,
                                'minimum_stock' => $minimumStock,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ],
                        ],
                        [
                            'warehouse_id',
                            'product_id',
                            'variant_key',
                        ],
                        [
                            'reorder_level',
                            'minimum_stock',
                            'updated_at',
                        ]
                    );
            },
            5
        );

        return back()->with(
            'success',
            'Inventory reorder settings saved successfully.'
        );
    }
}

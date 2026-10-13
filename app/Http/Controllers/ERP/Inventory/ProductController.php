<?php

namespace App\Http\Controllers\ERP\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'product_type' => ['nullable', Rule::in(['physical', 'service'])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'search' => ['nullable', 'string', 'max:150'],
        ]);

        $products = Product::query()
            ->with([
                'company:id,name',
                'category:id,name',
                'brand:id,name',
                'unit:id,name,short_name',
            ])
            ->withCount('variants')
            ->when(
                $filters['company_id'] ?? null,
                fn ($query, $value) => $query->where('company_id', $value)
            )
            ->when(
                $filters['product_type'] ?? null,
                fn ($query, $value) => $query->where('product_type', $value)
            )
            ->when(
                ($filters['status'] ?? null) === 'active',
                fn ($query) => $query->where('is_active', true)
            )
            ->when(
                ($filters['status'] ?? null) === 'inactive',
                fn ($query) => $query->where('is_active', false)
            )
            ->when(
                $filters['search'] ?? null,
                fn ($query, $search) => $query->where(
                    fn ($nested) => $nested
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('product_code', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%")
                )
            )
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('ERP/Inventory/Products/Index', [
            'products' => $products,
            'filters' => $filters,
            'companies' => Company::query()
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render(
            'ERP/Inventory/Products/Create',
            $this->options()
        );
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->data($request);

        $product = DB::transaction(function () use ($data, $request) {
            $variants = $data['variants'] ?? [];
            unset($data['variants']);

            $data = $this->normalize($data);
            $data = $this->handleImages($request, $data);

            $product = Product::create([
                ...$data,
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
            ]);

            $this->syncVariants($product, $variants);

            return $product;
        });

        return redirect()
            ->route('erp.products.show', $product)
            ->with('success', 'Product created successfully.');
    }

    public function show(Product $product): Response
    {
        $product->load([
            'company:id,name',
            'category:id,name',
            'brand:id,name',
            'unit:id,name,short_name,decimal_places',
            'variants',
        ]);

        return Inertia::render('ERP/Inventory/Products/Show', [
            'product' => $product,
        ]);
    }

    public function edit(Product $product): Response
    {
        $product->load('variants');

        return Inertia::render('ERP/Inventory/Products/Edit', [
            'product' => $product,
            ...$this->options(),
        ]);
    }

    public function update(
        Request $request,
        Product $product
    ): RedirectResponse {
        $data = $this->data($request, $product);

        DB::transaction(function () use ($data, $request, $product) {
            $variants = $data['variants'] ?? [];
            unset($data['variants']);

            $data = $this->normalize($data);
            $data = $this->handleImages($request, $data, $product);

            $product->update([
                ...$data,
                'updated_by' => $request->user()?->id,
            ]);

            $this->syncVariants($product, $variants);
        });

        return redirect()
            ->route('erp.products.show', $product)
            ->with('success', 'Product updated successfully.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();

        return redirect()
            ->route('erp.products.index')
            ->with('success', 'Product archived successfully.');
    }

    private function data(
        Request $request,
        ?Product $product = null
    ): array {
        $companyId = $request->integer('company_id');

        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'product_category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'product_type' => ['required', Rule::in(['physical', 'service'])],
            'name' => ['required', 'string', 'max:200'],
            'product_code' => [
                'nullable',
                'string',
                'max:80',
                Rule::unique('products', 'product_code')
                    ->where(fn ($query) => $query->where('company_id', $companyId))
                    ->ignore($product?->id),
            ],
            'sku' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'sku')
                    ->where(fn ($query) => $query->where('company_id', $companyId))
                    ->ignore($product?->id),
            ],
            'barcode' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'barcode')
                    ->where(fn ($query) => $query->where('company_id', $companyId))
                    ->ignore($product?->id),
            ],
            'featured_image' => ['nullable', 'image', 'max:5120'],
            'gallery_images' => ['nullable', 'array', 'max:12'],
            'gallery_images.*' => ['image', 'max:5120'],
            'remove_featured_image' => ['nullable', 'boolean'],
            'remove_gallery_images' => ['nullable', 'array'],
            'remove_gallery_images.*' => ['string', 'max:500'],
            'short_description' => ['nullable', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:20000'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'track_inventory' => ['required', 'boolean'],
            'allow_negative_stock' => ['required', 'boolean'],
            'reorder_level' => ['required', 'numeric', 'min:0'],
            'minimum_stock' => ['required', 'numeric', 'min:0'],
            'has_variants' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'variants' => ['array'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.name' => ['required_if:has_variants,true', 'nullable', 'string', 'max:180'],
            'variants.*.sku' => ['nullable', 'string', 'max:100'],
            'variants.*.barcode' => ['nullable', 'string', 'max:100'],
            'variants.*.attributes' => ['nullable', 'array'],
            'variants.*.purchase_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.selling_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.is_active' => ['required', 'boolean'],
        ]);

        if (! empty($data['product_category_id'])) {
            ProductCategory::whereKey($data['product_category_id'])
                ->where('company_id', $companyId)
                ->firstOrFail();
        }

        if (! empty($data['brand_id'])) {
            Brand::whereKey($data['brand_id'])
                ->where('company_id', $companyId)
                ->firstOrFail();
        }

        Unit::whereKey($data['unit_id'])
            ->where('company_id', $companyId)
            ->firstOrFail();

        return $data;
    }

    private function handleImages(
        Request $request,
        array $data,
        ?Product $product = null
    ): array {
        $existingGallery = $product !== null ? ($product->gallery_images ?? []) : [];
        $removeGallery = $data['remove_gallery_images'] ?? [];

        unset(
            $data['featured_image'],
            $data['gallery_images'],
            $data['remove_featured_image'],
            $data['remove_gallery_images']
        );

        if ($product && $request->boolean('remove_featured_image')) {
            if ($product->featured_image) {
                Storage::disk('public')->delete($product->featured_image);
            }

            $data['featured_image'] = null;
        }

        if ($request->hasFile('featured_image')) {
            if ($product?->featured_image) {
                Storage::disk('public')->delete($product->featured_image);
            }

            $data['featured_image'] = $request
                ->file('featured_image')
                ->store('products/featured', 'public');
        }

        if ($product) {
            foreach ($removeGallery as $path) {
                if (in_array($path, $existingGallery, true)) {
                    Storage::disk('public')->delete($path);
                }
            }

            $existingGallery = array_values(
                array_diff($existingGallery, $removeGallery)
            );
        }

        $newGallery = [];

        foreach ($request->file('gallery_images', []) as $image) {
            $newGallery[] = $image->store('products/gallery', 'public');
        }

        $gallery = [
            ...$existingGallery,
            ...$newGallery,
        ];

        $data['gallery_images'] = $gallery ?: null;

        return $data;
    }

    private function normalize(array $data): array
    {
        if ($data['product_type'] === 'service') {
            $data['track_inventory'] = false;
            $data['allow_negative_stock'] = false;
            $data['reorder_level'] = 0;
            $data['minimum_stock'] = 0;
        }

        return $data;
    }

    private function syncVariants(Product $product, array $rows): void
    {
        if (! $product->has_variants) {
            $product->variants()->delete();

            return;
        }

        $keep = [];

        foreach ($rows as $row) {
            $payload = [
                'name' => trim((string) $row['name']),
                'sku' => $row['sku'] ?? null,
                'barcode' => $row['barcode'] ?? null,
                'attributes' => $row['attributes'] ?? null,
                'purchase_price' => $row['purchase_price'] ?? null,
                'selling_price' => $row['selling_price'] ?? null,
                'is_active' => $row['is_active'],
            ];

            /** @var ProductVariant $variant */
            if (! empty($row['id'])) {
                $variant = $product->variants()
                    ->whereKey($row['id'])
                    ->firstOrFail();

                $variant->update($payload);
            } else {
                $variant = $product->variants()->create($payload);
            }

            $keep[] = $variant->getKey();
        }

        $product->variants()
            ->whereNotIn('id', $keep ?: [0])
            ->delete();
    }

    private function options(): array
    {
        return [
            'companies' => Company::orderBy('name')->get(['id', 'name']),
            'categories' => ProductCategory::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'company_id', 'parent_id', 'name']),
            'brands' => Brand::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'company_id', 'name']),
            'units' => Unit::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'company_id', 'name', 'short_name', 'decimal_places']),
        ];
    }
}

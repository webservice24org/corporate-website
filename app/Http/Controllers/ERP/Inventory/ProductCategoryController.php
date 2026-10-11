<?php

namespace App\Http\Controllers\ERP\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ProductCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductCategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $rows = ProductCategory::query()
            ->with([
                'company:id,name',
                'parent:id,name',
            ])
            ->when(
                $filters['search'] ?? null,
                function ($query, $search) {
                    $query->where(function ($query) use ($search) {
                        $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%");
                    });
                }
            )
            ->when(
                $filters['company_id'] ?? null,
                fn ($query, $companyId) => $query->where('company_id', $companyId)
            )
            ->when(
                ($filters['status'] ?? null) === 'active',
                fn ($query) => $query->where('is_active', true)
            )
            ->when(
                ($filters['status'] ?? null) === 'inactive',
                fn ($query) => $query->where('is_active', false)
            )
            ->orderBy('company_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('ERP/Inventory/ProductCategories/Index', [
            'categories' => $rows,
            'companies' => Company::query()
                ->orderBy('name')
                ->get(['id', 'name']),
            'parents' => ProductCategory::query()
                ->orderBy('name')
                ->get(['id', 'company_id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        ProductCategory::create($this->data($request));

        return back()->with('success', 'Category Created successfully.');
    }

    public function update(Request $request, ProductCategory $productCategory): RedirectResponse
    {
        $data = $this->data($request, $productCategory);

        if (!empty($data['parent_id']) && (int) $data['parent_id'] === $productCategory->id) {
            return back()->withErrors([
                'parent_id' => 'A category cannot be its own parent.',
            ]);
        }

        $productCategory->update($data);

        return back()->with('success', 'Category Updated successfully.');
    }

    public function destroy(ProductCategory $productCategory): RedirectResponse
    {
        if ($productCategory->products()->exists()) {
            return back()->withErrors([
                'record' => 'This category is already used by one or more products.',
            ]);
        }

        if ($productCategory->children()->exists()) {
            return back()->withErrors([
                'record' => 'This category has child categories and cannot be deleted.',
            ]);
        }

        $productCategory->delete();

        return back()->with('success', 'Category Deleted successfully.');
    }

    private function data(Request $request, ?ProductCategory $productCategory = null): array
    {
        $companyId = $request->integer('company_id');

        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('product_categories', 'name')
                    ->where(fn ($query) => $query->where('company_id', $companyId))
                    ->ignore($productCategory?->id),
            ],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('product_categories', 'code')
                    ->where(fn ($query) => $query->where('company_id', $companyId))
                    ->ignore($productCategory?->id),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ]);

        if (!empty($data['parent_id'])) {
            ProductCategory::query()
                ->whereKey($data['parent_id'])
                ->where('company_id', $data['company_id'])
                ->firstOrFail();
        }

        return $data;
    }
}

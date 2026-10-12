<?php

namespace App\Http\Controllers\ERP\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BrandController extends Controller
{
    public function index(Request $r): Response
    {
        $rows = Brand::with('company:id,name')
            ->when($r->filled('search'), function ($query) use ($r) {
                $query->where(function ($q) use ($r) {
                    $q->where('name', 'like', '%'.$r->input('search').'%')
                        ->orWhere('code', 'like', '%'.$r->input('search').'%');
                });
            })
            ->when($r->filled('company_id'), function ($query) use ($r) {
                $query->where('company_id', $r->input('company_id'));
            })
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('ERP/Inventory/Brands/Index', [
            'brands' => $rows,
            'companies' => Company::orderBy('name')->get(['id', 'name']),
            'filters' => $r->only(['search', 'company_id']),
        ]);
    }

    public function store(Request $r): RedirectResponse
    {
        Brand::create($this->data($r));

        return back()->with('success', 'Brand Created successfully.');
    }

    public function update(Request $r, Brand $brand): RedirectResponse
    {
        $brand->update($this->data($r));

        return back()->with('success', 'Brand Updated successfully.');
    }

    public function destroy(Brand $brand): RedirectResponse
    {
        if ($brand->products()->exists()) {
            return back()->withErrors(['record' => 'This record is in use.']);
        }

        $brand->delete();

        return back()->with('success', 'Brand Deleted successfully.');
    }

    private function data(Request $r): array
    {
        return $r->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'name' => ['required', 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}

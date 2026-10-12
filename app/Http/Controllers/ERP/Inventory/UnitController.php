<?php

namespace App\Http\Controllers\ERP\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UnitController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $rows = Unit::query()
            ->with('company:id,name')
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('short_name', 'like', "%{$search}%");
                });
            })
            ->when($filters['company_id'] ?? null, fn ($query, $companyId) => $query->where('company_id', $companyId))
            ->when(($filters['status'] ?? null) === 'active', fn ($query) => $query->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('company_id')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('ERP/Inventory/Units/Index', [
            'units' => $rows,
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Unit::create($this->data($request));

        return back()->with('success', 'Created successfully.');
    }

    public function update(Request $request, Unit $unit): RedirectResponse
    {
        $unit->update($this->data($request, $unit));

        return back()->with('success', 'Updated successfully.');
    }

    public function destroy(Unit $unit): RedirectResponse
    {
        if ($unit->products()->exists()) {
            return back()->withErrors(['record' => 'This unit is already used by one or more products.']);
        }
        $unit->delete();

        return back()->with('success', 'Deleted successfully.');
    }

    private function data(Request $request, ?Unit $unit = null): array
    {
        $companyId = $request->integer('company_id');

        return $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'name' => ['required', 'string', 'max:100', Rule::unique('units', 'name')->where(fn ($q) => $q->where('company_id', $companyId))->ignore($unit?->id)],
            'short_name' => ['required', 'string', 'max:30', Rule::unique('units', 'short_name')->where(fn ($q) => $q->where('company_id', $companyId))->ignore($unit?->id)],
            'decimal_places' => ['required', 'integer', 'min:0', 'max:6'],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}

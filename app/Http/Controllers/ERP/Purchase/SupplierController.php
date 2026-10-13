<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Purchase;

use App\DTOs\Purchase\SupplierData;
use App\Http\Controllers\Controller;
use App\Http\Requests\ERP\Purchase\StoreSupplierRequest;
use App\Http\Requests\ERP\Purchase\UpdateSupplierRequest;
use App\Models\SupplierAddress;
use App\Models\SupplierContact;
use App\Models\User;
use App\Services\ERP\Purchase\SupplierManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SupplierController extends Controller
{
    public function __construct(private readonly SupplierManagementService $service) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'company_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);
        if (! empty($filters['company_id'])) {
            $this->service->companies($actor, 'erp.suppliers.view')->findOrFail((int) $filters['company_id']);
        }
        $query = $this->service->visible($actor)->with('company:id,name');
        if (! empty($filters['company_id'])) {
            $query->where('company_id', $filters['company_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', $search)->orWhere('code', 'like', $search)
                    ->orWhere('email', 'like', $search)->orWhere('phone', 'like', $search);
            });
        }

        return Inertia::render('ERP/Purchase/Suppliers/Index', [
            'suppliers' => $query->orderBy('name')->orderBy('id')->paginate(20)->withQueryString(),
            'companies' => $this->service->companies($actor, 'erp.suppliers.view')->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('ERP/Purchase/Suppliers/Form', [
            'supplier' => null,
            'companies' => $this->service->companies($this->actor($request), 'erp.suppliers.create')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreSupplierRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $supplier = $this->service->create($this->actor($request), (int) $data['company_id'], SupplierData::fromArray($data));

        return ($this->actor($request)->can('erp.suppliers.view')
            ? redirect()->route('erp.purchase.suppliers.show', $supplier->getKey())
            : redirect()->route('erp.purchase.suppliers.create'))->with('success', 'Supplier created successfully.');
    }

    public function show(Request $request, int $supplier): Response
    {
        $record = $this->service->find($this->actor($request), $supplier, 'erp.suppliers.view');

        return Inertia::render('ERP/Purchase/Suppliers/Show', [
            'supplier' => $record->load('company:id,name'),
            'contacts' => SupplierContact::query()->where('supplier_id', $supplier)->orderByDesc('is_primary')->orderBy('name')->get(),
            'addresses' => SupplierAddress::query()->where('supplier_id', $supplier)->orderByDesc('is_default')->orderBy('label')->get(),
        ]);
    }

    public function edit(Request $request, int $supplier): Response
    {
        return Inertia::render('ERP/Purchase/Suppliers/Form', [
            'supplier' => $this->service->find($this->actor($request), $supplier, 'erp.suppliers.edit')->load('company:id,name'),
            'companies' => [],
        ]);
    }

    public function update(UpdateSupplierRequest $request, int $supplier): RedirectResponse
    {
        $this->service->update($this->actor($request), $supplier, SupplierData::fromArray($request->validated()));

        return redirect()->route($this->actor($request)->can('erp.suppliers.view')
            ? 'erp.purchase.suppliers.show' : 'erp.purchase.suppliers.edit', $supplier)->with('success', 'Supplier updated successfully.');
    }

    public function destroy(Request $request, int $supplier): RedirectResponse
    {
        $this->service->delete($this->actor($request), $supplier);

        return redirect()->route('erp.purchase.suppliers.index')->with('success', 'Supplier deleted successfully.');
    }

    public function storeContact(Request $request, int $supplier): RedirectResponse
    {
        return $this->saveChild($request, $supplier, 'contact');
    }

    public function updateContact(Request $request, int $supplier, int $contact): RedirectResponse
    {
        return $this->saveChild($request, $supplier, 'contact', $contact);
    }

    public function destroyContact(Request $request, int $supplier, int $contact): RedirectResponse
    {
        $this->service->deleteChild($this->actor($request), $supplier, 'contact', $contact);

        return back()->with('success', 'Contact deleted successfully.');
    }

    public function storeAddress(Request $request, int $supplier): RedirectResponse
    {
        return $this->saveChild($request, $supplier, 'address');
    }

    public function updateAddress(Request $request, int $supplier, int $address): RedirectResponse
    {
        return $this->saveChild($request, $supplier, 'address', $address);
    }

    public function destroyAddress(Request $request, int $supplier, int $address): RedirectResponse
    {
        $this->service->deleteChild($this->actor($request), $supplier, 'address', $address);

        return back()->with('success', 'Address deleted successfully.');
    }

    private function saveChild(Request $request, int $supplier, string $kind, ?int $id = null): RedirectResponse
    {
        $actor = $this->actor($request);
        $permission = $kind === 'contact' ? 'erp.suppliers.contacts.manage' : 'erp.suppliers.addresses.manage';
        $this->service->find($actor, $supplier, $permission);
        $rules = $kind === 'contact' ? [
            'name' => ['required', 'string', 'max:150'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'is_primary' => ['required', 'boolean'],
        ] : [
            'label' => ['required', 'string', 'max:80'],
            'address_line_1' => ['required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'is_default' => ['required', 'boolean'],
        ];
        $data = $request->validate([...$rules, 'supplier_id' => ['prohibited'], 'company_id' => ['prohibited']]);
        $this->service->saveChild($actor, $supplier, $kind, $data, $id);

        return back()->with('success', ucfirst($kind).' saved successfully.');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(403);
        }

        return $actor;
    }
}

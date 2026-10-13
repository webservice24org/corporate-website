<?php

namespace App\Http\Controllers\ERP\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\ERP\Inventory\StockTransferRequest;
use App\Models\Company;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StockTransferController extends Controller
{
    private function authorizeCompany(int $companyId): void
    {
        $user = auth()->user();
        abort_unless($user && (
            $user->hasRole('super-admin') ||
            $user->employee()->where('company_id', $companyId)->exists()
        ), 403, 'You cannot access stock transfers for this company.');
    }

    /**
     * Get company IDs accessible to the authenticated user.
     *
     * @return array<int, int>
     */
    private function allowedCompanyIds(): array
    {
        /** @var User|null $user */
        $user = auth()->user();

        abort_unless($user !== null, 403);

        if ($user->hasRole('super-admin')) {
            return Company::query()
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        return $user->employee()
            ->pluck('company_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function index(Request $request): Response
    {
        $companyIds = $this->allowedCompanyIds();
        $query = StockTransfer::query()->whereIn('company_id', $companyIds)
            ->with(['company:id,name', 'sourceWarehouse:id,company_id,name,code', 'destinationWarehouse:id,company_id,name,code', 'creator:id,name']);
        if ($request->filled('search')) {
            $query->where('document_number', 'like', '%'.trim((string) $request->input('search')).'%');
        }
        if ($request->filled('company_id')) {
            $query->where('company_id', (int) $request->input('company_id'));
        }
        if (in_array($request->input('status'), StockTransfer::statuses(), true)) {
            $query->where('status', $request->input('status'));
        }

        return Inertia::render('ERP/Inventory/StockTransfers/Index', [
            'stockTransfers' => $query->orderByDesc('transfer_date')->orderByDesc('id')->paginate(15)->withQueryString(),
            'filters' => $request->only(['search', 'company_id', 'status']),
            'companies' => Company::query()->whereIn('id', $companyIds)->orderBy('name')->get(['id', 'name']),
            'statuses' => StockTransfer::statuses(),
        ]);
    }

    private function options(): array
    {
        $companyIds = $this->allowedCompanyIds();

        return [
            'companies' => Company::query()->whereIn('id', $companyIds)->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::query()->whereIn('company_id', $companyIds)->where('is_active', true)->orderBy('name')->get(['id', 'company_id', 'name', 'code']),
            'products' => Product::query()->whereIn('company_id', $companyIds)->with('variants:id,product_id,name,sku')->orderBy('name')->get(['id', 'company_id', 'name', 'sku', 'has_variants', 'track_inventory']),
        ];
    }

    public function create(): Response
    {
        return Inertia::render('ERP/Inventory/StockTransfers/Create', $this->options());
    }

    public function store(StockTransferRequest $request, StockTransferService $service): RedirectResponse
    {
        $data = $request->validated();
        $this->authorizeCompany((int) $data['company_id']);
        $transfer = DB::transaction(function () use ($data, $service) {
            $transfer = StockTransfer::create([
                'company_id' => $data['company_id'],
                'document_number' => 'ST-'.now()->format('Y').'-'.strtoupper((string) Str::ulid()),
                'transfer_date' => $data['transfer_date'],
                'source_warehouse_id' => $data['source_warehouse_id'],
                'destination_warehouse_id' => $data['destination_warehouse_id'],
                'status' => StockTransfer::STATUS_DRAFT,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);
            $this->replaceItems($transfer, $data['items']);
            $service->validateDraft($transfer);

            return $transfer;
        }, 5);

        return redirect()->route('erp.inventory.stock-transfers.show', $transfer)->with('success', 'Stock transfer draft created.');
    }

    public function show(StockTransfer $stockTransfer): Response
    {
        $this->authorizeCompany((int) $stockTransfer->company_id);

        return Inertia::render('ERP/Inventory/StockTransfers/Show', [
            'stockTransfer' => $stockTransfer->load(['company:id,name', 'sourceWarehouse:id,company_id,name,code', 'destinationWarehouse:id,company_id,name,code', 'creator:id,name', 'poster:id,name', 'items.product:id,company_id,name,sku', 'items.variant:id,product_id,name,sku']),
        ]);
    }

    public function edit(StockTransfer $stockTransfer): Response
    {
        $this->authorizeCompany((int) $stockTransfer->company_id);
        $this->ensureDraft($stockTransfer);

        return Inertia::render('ERP/Inventory/StockTransfers/Edit', [
            'stockTransfer' => $stockTransfer->load('items'),
            ...$this->options(),
        ]);
    }

    public function update(StockTransferRequest $request, StockTransfer $stockTransfer, StockTransferService $service): RedirectResponse
    {
        $this->authorizeCompany((int) $stockTransfer->company_id);
        $data = $request->validated();
        $this->authorizeCompany((int) $data['company_id']);
        DB::transaction(function () use ($stockTransfer, $data, $service) {
            $locked = StockTransfer::query()->lockForUpdate()->findOrFail($stockTransfer->id);
            $this->ensureDraft($locked);
            $locked->update([
                'company_id' => $data['company_id'],
                'transfer_date' => $data['transfer_date'],
                'source_warehouse_id' => $data['source_warehouse_id'],
                'destination_warehouse_id' => $data['destination_warehouse_id'],
                'notes' => $data['notes'] ?? null,
            ]);
            $this->replaceItems($locked, $data['items']);
            $service->validateDraft($locked);
        }, 5);

        return redirect()->route('erp.inventory.stock-transfers.show', $stockTransfer)->with('success', 'Stock transfer draft updated.');
    }

    public function destroy(StockTransfer $stockTransfer): RedirectResponse
    {
        $this->authorizeCompany((int) $stockTransfer->company_id);
        DB::transaction(function () use ($stockTransfer) {
            $locked = StockTransfer::query()->lockForUpdate()->findOrFail($stockTransfer->id);
            $this->ensureDraft($locked);
            $locked->delete();
        }, 5);

        return redirect()->route('erp.inventory.stock-transfers.index')->with('success', 'Stock transfer draft deleted.');
    }

    public function post(StockTransfer $stockTransfer, StockTransferService $service): RedirectResponse
    {
        $this->authorizeCompany((int) $stockTransfer->company_id);
        $service->post($stockTransfer, auth()->id());

        return redirect()->route('erp.inventory.stock-transfers.show', $stockTransfer)->with('success', 'Stock transfer posted.');
    }

    private function ensureDraft(StockTransfer $transfer): void
    {
        if (! $transfer->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Only draft stock transfers can be changed.']);
        }
    }

    private function replaceItems(StockTransfer $transfer, array $items): void
    {
        $transfer->items()->delete();
        foreach ($items as $item) {
            $variantId = ! empty($item['product_variant_id']) ? (int) $item['product_variant_id'] : null;
            $transfer->items()->create([
                'product_id' => (int) $item['product_id'],
                'product_variant_id' => $variantId,
                'variant_key' => $variantId ?? 0,
                'quantity' => (string) $item['quantity'],
                'unit_cost' => null,
                'total_cost' => null,
            ]);
        }
    }
}

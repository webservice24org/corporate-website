<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\ERP\Purchase\StorePurchaseRequisitionRequest;
use App\Http\Requests\ERP\Purchase\UpdatePurchaseRequisitionRequest;
use App\Models\Department;
use App\Models\Product;
use App\Models\PurchaseRequisitionItem;
use App\Models\User;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use App\Services\ERP\Purchase\PurchaseRequisitionService;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class PurchaseRequisitionController extends Controller
{
    public function __construct(
        private readonly PurchaseRequisitionService $service,
        private readonly PurchaseCompanyAccess $access,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'company_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:draft,submitted,approved,rejected'],
        ]);
        $query = $this->service->visible($actor)->with(['company:id,name', 'department:id,name']);
        if (! empty($filters['company_id'])) {
            $this->service->companies($actor, 'erp.purchase-requisitions.view')->findOrFail($filters['company_id']);
            $query->where('company_id', $filters['company_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $query->where('number', 'like', '%'.$filters['search'].'%');
        }

        return Inertia::render('ERP/Purchase/Requisitions/Index', [
            'requisitions' => $query->latest('id')->paginate(20)->withQueryString(),
            'companies' => $this->service->companies($actor, 'erp.purchase-requisitions.view')->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request): Response
    {
        $companies = $this->service->companies($this->actor($request), 'erp.purchase-requisitions.create')
            ->orderBy('name')->get(['id', 'name', 'currency_code']);
        $input = $request->validate(['company_id' => ['nullable', 'integer', 'min:1']]);
        $onlyCompany = $companies->count() === 1 ? $companies->first() : null;
        $companyId = empty($input['company_id']) ? ($onlyCompany === null ? null : (int) $onlyCompany->getKey()) : (int) $input['company_id'];
        if ($companyId !== null) {
            $this->service->companies($this->actor($request), 'erp.purchase-requisitions.create')->findOrFail($companyId);
        }
        $products = Product::query()->where('company_id', $companyId ?? 0)->where('is_active', true)
            ->with(['variants' => fn ($query) => $query->where('is_active', true)->select('id', 'product_id', 'name', 'sku', 'purchase_price')])
            ->orderBy('name')->orderBy('id')->limit(21)->get(['id', 'company_id', 'name', 'sku', 'purchase_price', 'has_variants']);

        return Inertia::render('ERP/Purchase/Requisitions/Form', [
            'requisition' => null, 'items' => [], 'companies' => $companies, 'defaultCompanyId' => $companyId,
            'initialProducts' => $products->take(20)->map(fn (Product $product): array => $this->catalogProduct($product))->values(),
            'initialHasMore' => $products->count() > 20,
            'initialDepartments' => $companyId === null ? [] : Department::query()->where('company_id', $companyId)->where('status', 'active')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StorePurchaseRequisitionRequest $request): RedirectResponse
    {
        $document = $this->service->create($this->actor($request), $request->validated());

        return ($this->actor($request)->can('erp.purchase-requisitions.view')
            ? redirect()->route('erp.purchase.requisitions.show', $document->getKey())
            : redirect()->route('erp.purchase.requisitions.create'))->with('success', 'Purchase requisition saved as draft.');
    }

    public function edit(Request $request, int $requisition): Response
    {
        $document = $this->service->find($this->actor($request), $requisition, 'erp.purchase-requisitions.edit');
        abort_unless($document->status === 'draft', 403, 'Only draft requisitions can be edited.');
        $items = PurchaseRequisitionItem::query()->where('purchase_requisition_id', $requisition)->orderBy('id')->get();
        $products = Product::query()->where('company_id', $document->company_id)->whereIn('id', $items->pluck('product_id'))
            ->with(['variants' => fn ($query) => $query->where('is_active', true)])->get(['id', 'company_id', 'name', 'sku', 'purchase_price', 'has_variants']);

        $pageProducts = Product::query()->where('company_id', $document->company_id)->where('is_active', true)
            ->with(['variants' => fn ($query) => $query->where('is_active', true)->select('id', 'product_id', 'name', 'sku', 'purchase_price')])
            ->orderBy('name')->orderBy('id')->limit(21)->get(['id', 'company_id', 'name', 'sku', 'purchase_price', 'has_variants']);
        $products = $products->concat($pageProducts->take(20))->unique('id')->values();

        return Inertia::render('ERP/Purchase/Requisitions/Form', [
            'requisition' => $document->load(['company:id,name,currency_code', 'department:id,name']), 'items' => $items,
            'initialProducts' => $products->map(fn (Product $product): array => $this->catalogProduct($product))->values(),
            'initialDepartments' => Department::query()->where('company_id', $document->company_id)->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'defaultCompanyId' => (int) $document->company_id, 'initialHasMore' => $pageProducts->count() > 20,
            'companies' => [],
        ]);
    }

    public function update(UpdatePurchaseRequisitionRequest $request, int $requisition): RedirectResponse
    {
        $this->service->update($this->actor($request), $requisition, $request->validated());

        return redirect()->route($this->actor($request)->can('erp.purchase-requisitions.view')
            ? 'erp.purchase.requisitions.show' : 'erp.purchase.requisitions.edit', $requisition)->with('success', 'Draft requisition updated.');
    }

    public function show(Request $request, int $requisition): Response
    {
        $document = $this->service->find($this->actor($request), $requisition, 'erp.purchase-requisitions.view');
        $companyId = (int) $document->company_id;
        $items = PurchaseRequisitionItem::query()->where('purchase_requisition_id', $requisition)->with([
            'product' => fn ($query) => $query->withoutGlobalScope(SoftDeletingScope::class)->where('company_id', $companyId)->select('id', 'company_id', 'name', 'sku'),
            'product_variant' => fn ($query) => $query->whereHas('product', fn ($product) => $product->withoutGlobalScope(SoftDeletingScope::class)->where('company_id', $companyId))->select('id', 'product_id', 'name', 'sku'),
        ])->orderBy('id')->get();
        $history = DB::table('purchase_workflow_events')->leftJoin('users', 'users.id', '=', 'purchase_workflow_events.actor_id')
            ->where('purchase_workflow_events.company_id', $companyId)->where('document_type', 'requisition')->where('document_id', $requisition)
            ->orderBy('purchase_workflow_events.id')->get(['purchase_workflow_events.id', 'from_status', 'to_status', 'comment', 'purchase_workflow_events.created_at', 'users.name as actor_name']);
        $changes = DB::table('purchase_requisition_audit_logs')->leftJoin('users', 'users.id', '=', 'purchase_requisition_audit_logs.actor_id')
            ->where('purchase_requisition_audit_logs.company_id', $companyId)->where('purchase_requisition_id', $requisition)
            ->orderByDesc('purchase_requisition_audit_logs.id')->limit(50)->get(['purchase_requisition_audit_logs.id', 'action', 'purchase_requisition_audit_logs.created_at', 'users.name as actor_name']);

        return Inertia::render('ERP/Purchase/Requisitions/Show', [
            'requisition' => $document->load(['company:id,name', 'department:id,name']), 'items' => $items,
            'requester' => User::query()->find($document->requested_by, ['id', 'name']),
            'approver' => $document->approved_by === null ? null : User::query()->find($document->approved_by, ['id', 'name']),
            'history' => $history, 'changes' => $changes,
        ]);
    }

    public function submit(Request $request, int $requisition): RedirectResponse
    {
        return $this->transition($request, $requisition, 'submit');
    }

    public function approve(Request $request, int $requisition): RedirectResponse
    {
        return $this->transition($request, $requisition, 'approve');
    }

    public function reject(Request $request, int $requisition): RedirectResponse
    {
        return $this->transition($request, $requisition, 'reject');
    }

    public function catalog(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $permission = $actor->can('erp.purchase-requisitions.create') ? 'erp.purchase-requisitions.create' : 'erp.purchase-requisitions.edit';
        $data = $request->validate(['company_id' => ['required', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $companyId = (int) $data['company_id'];
        $this->access->authorize($actor, $companyId, $permission);
        $query = Product::query()->where('company_id', $companyId)->where('is_active', true);
        if (! empty($data['search'])) {
            $search = '%'.$data['search'].'%';
            $query->where(fn ($query) => $query->where('name', 'like', $search)->orWhere('sku', 'like', $search)->orWhere('product_code', 'like', $search));
        }
        $products = $query->with(['variants' => fn ($query) => $query->where('is_active', true)->select('id', 'product_id', 'name', 'sku', 'purchase_price')])->orderBy('name')->orderBy('id')
            ->paginate(20, ['id', 'company_id', 'name', 'sku', 'purchase_price', 'has_variants']);

        return response()->json([
            'products' => $products->getCollection()->map(fn (Product $product): array => $this->catalogProduct($product))->values()->all(),
            'has_more' => $products->hasMorePages(),
            'departments' => Department::query()->where('company_id', $companyId)->where('status', 'active')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function transition(Request $request, int $id, string $action): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'comment' => [$action === 'reject' ? 'required' : 'nullable', 'string', 'max:2000']]);
        $this->service->transition($this->actor($request), $id, $action, (int) $data['revision'], $data['comment'] ?? null);

        return redirect()->route('erp.purchase.requisitions.show', $id)->with('success', 'Requisition action completed.');
    }

    /** @return array<string, mixed> */
    private function catalogProduct(Product $product): array
    {
        return [
            'id' => $product->getKey(), 'name' => $product->name, 'sku' => $product->sku,
            'has_variants' => $product->has_variants, 'purchase_price' => $product->purchase_price,
            'variants' => $product->variants->map(fn ($variant): array => $variant->only(['id', 'name', 'sku', 'purchase_price']))->all(),
        ];
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

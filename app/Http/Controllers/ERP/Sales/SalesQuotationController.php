<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Sales;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesDocumentEvent;
use App\Models\SalesOrder;
use App\Models\SalesQuotation;
use App\Models\User;
use App\Services\ERP\Sales\SalesCompanyAccess;
use App\Services\ERP\Sales\SalesQuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SalesQuotationController extends Controller
{
    public function __construct(private readonly SalesQuotationService $service, private readonly SalesCompanyAccess $access) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $filters = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', 'in:draft,submitted,approved,sent,accepted,rejected,expired,cancelled']]);
        $companies = $this->service->companies($actor, 'erp.sales-quotations.view')->orderBy('name')->get(['id', 'name', 'currency_code']);
        if (! empty($filters['company_id'])) {
            $this->access->authorize($actor, (int) $filters['company_id'], 'erp.sales-quotations.view');
        }
        $query = SalesQuotation::query()->whereIn('company_id', $companies->modelKeys())
            ->when(! empty($filters['company_id']), fn ($q) => $q->where('company_id', $filters['company_id']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['search']), fn ($q) => $q->where('number', 'like', '%'.$filters['search'].'%'));

        return Inertia::render('ERP/Sales/Quotations/Index', ['quotations' => $query->latest('id')->paginate(20)->withQueryString(), 'companies' => $companies, 'filters' => $filters]);
    }

    public function create(Request $request): Response
    {
        $actor = $this->actor($request);
        $companies = $this->service->companies($actor, 'erp.sales-quotations.create')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'currency_code']);
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1']]);
        $companyId = isset($data['company_id']) ? (int) $data['company_id'] : $companies->first()?->getKey();

        return Inertia::render('ERP/Sales/Quotations/Form', ['quotation' => null, 'companies' => $companies, 'items' => [], 'defaultCompanyId' => $companyId, 'catalog' => $companyId === null ? $this->emptyCatalog() : $this->options($actor, (int) $companyId, '', null), 'today' => now()->toDateString()]);
    }

    public function edit(Request $request, int $quotation): Response
    {
        $actor = $this->actor($request);
        $document = $this->service->find($actor, $quotation, 'erp.sales-quotations.edit');
        abort_unless($document->getAttribute('status') === 'draft', 422);

        return Inertia::render('ERP/Sales/Quotations/Form', ['quotation' => $document, 'companies' => Company::query()->whereKey($document->getAttribute('company_id'))->get(['id', 'name', 'currency_code']), 'items' => $document->items()->orderBy('line_number')->get(), 'defaultCompanyId' => $document->getAttribute('company_id'), 'catalog' => $this->options($actor, (int) $document->getAttribute('company_id'), '', $document), 'today' => now()->toDateString()]);
    }

    public function catalog(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:120'], 'customer_id' => ['nullable', 'integer', 'min:1']]);

        return response()->json($this->options($this->actor($request), (int) $data['company_id'], (string) ($data['search'] ?? ''), null, empty($data['customer_id']) ? null : (int) $data['customer_id']));
    }

    public function store(Request $request): RedirectResponse
    {
        $document = $this->service->save($this->actor($request), $request->all());

        return redirect()->route('erp.sales.quotations.show', $document->getKey())->with('success', 'Quotation created.');
    }

    public function update(Request $request, int $quotation): RedirectResponse
    {
        $document = $this->service->save($this->actor($request), $request->all(), $quotation);

        return redirect()->route('erp.sales.quotations.show', $document->getKey())->with('success', 'Quotation saved.');
    }

    public function show(Request $request, int $quotation): Response
    {
        return $this->display($request, $quotation, false);
    }

    public function print(Request $request, int $quotation): Response
    {
        return $this->display($request, $quotation, true);
    }

    public function action(Request $request, int $quotation, string $action): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:2000']]);
        if ($action === 'resolve-lead') {
            $this->service->resolveLead($this->actor($request), $quotation, (int) $data['revision']);
        } else {
            $this->service->transition($this->actor($request), $quotation, $action, (int) $data['revision'], $data['reason'] ?? null);
        }

        return redirect()->route('erp.sales.quotations.show', $quotation)->with('success', 'Quotation updated.');
    }

    private function display(Request $request, int $id, bool $printing): Response
    {
        $document = $this->service->find($this->actor($request), $id);
        $actor = $this->actor($request);
        $orderId = $actor->can('erp.sales-orders.view') ? SalesOrder::query()->where('company_id', $document->getAttribute('company_id'))->where('sales_quotation_id', $document->getKey())->value('id') : null;

        return Inertia::render('ERP/Sales/Quotations/Show', ['quotation' => $document, 'salesOrderUrl' => $orderId === null ? null : '/erp/sales/orders/'.$orderId, 'items' => $document->items()->orderBy('line_number')->get(), 'events' => SalesDocumentEvent::query()->where('company_id', $document->getAttribute('company_id'))->where('document_type', 'quotation')->where('document_id', $id)->orderBy('id')->get(['id', 'action', 'actor_snapshot', 'reason', 'occurred_at']), 'printMode' => $printing]);
    }

    /** @return array<string, mixed> */
    private function options(User $actor, int $companyId, string $search, ?SalesQuotation $document, ?int $customerId = null): array
    {
        $permission = $actor->can('erp.sales-quotations.create') ? 'erp.sales-quotations.create' : 'erp.sales-quotations.edit';
        $this->access->authorize($actor, $companyId, $permission);
        $this->access->authorize($actor, $companyId, 'erp.sales-customers.select');
        $customerId ??= $document?->getAttribute('customer_id') === null ? null : (int) $document->getAttribute('customer_id');
        $productIds = $document?->items()->pluck('product_id')->all() ?? [];
        $productQuery = Product::query()->where('company_id', $companyId)->where('is_active', true);
        $products = (clone $productQuery)->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))->orderBy('name')->limit(100)->get();
        $products = $products->merge((clone $productQuery)->whereIn('id', $productIds)->get())->unique('id')->values();
        $customers = Customer::query()->where('company_id', $companyId)->where('is_active', true)->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))->orderBy('name')->limit(100)->get(['id', 'name']);
        if ($customerId !== null) {
            $selected = Customer::query()->where('company_id', $companyId)->findOrFail($customerId, ['id', 'name']);
            $customers = $customers->merge([$selected])->unique('id')->values();
        }
        $leads = $actor->can('erp.leads.view') ? Lead::query()->where('company_id', $companyId)->where('is_active', true)->when($search !== '', fn ($q) => $q->where('contact_name', 'like', '%'.$search.'%'))->orderBy('contact_name')->limit(100)->get(['id', 'contact_name']) : collect();
        $opportunities = $actor->can('erp.opportunities.view') ? Opportunity::query()->where('company_id', $companyId)->where('is_active', true)->whereIn('party_type', [Customer::class, (new Customer)->getMorphClass()])->when($search !== '', fn ($q) => $q->where('title', 'like', '%'.$search.'%'))->orderBy('title')->limit(100)->get(['id', 'title']) : collect();
        if ($document !== null && $actor->can('erp.leads.view') && $document->getAttribute('lead_id') !== null) {
            $leads = $leads->merge(Lead::query()->where('company_id', $companyId)->where('is_active', true)->whereKey($document->getAttribute('lead_id'))->get(['id', 'contact_name']))->unique('id')->values();
        }
        if ($document !== null && $actor->can('erp.opportunities.view') && $document->getAttribute('opportunity_id') !== null) {
            $opportunities = $opportunities->merge(Opportunity::query()->where('company_id', $companyId)->where('is_active', true)->whereKey($document->getAttribute('opportunity_id'))->get(['id', 'title']))->unique('id')->values();
        }

        return [
            'products' => $products->map(fn (Product $product): array => ['id' => $product->getKey(), 'name' => $product->getAttribute('name'), 'selling_price' => $product->getAttribute('selling_price'), 'has_variants' => (bool) $product->getAttribute('has_variants'), 'variants' => ProductVariant::query()->where('product_id', $product->getKey())->where('is_active', true)->get(['id', 'name', 'selling_price'])->toArray()])->all(),
            'customers' => $customers->toArray(), 'leads' => $leads->toArray(), 'opportunities' => $opportunities->toArray(),
            'contacts' => $customerId === null ? [] : CustomerContact::query()->where('customer_id', $customerId)->get(['id', 'name'])->toArray(),
            'addresses' => $customerId === null ? [] : CustomerAddress::query()->where('customer_id', $customerId)->get(['id', 'address_type', 'label', 'address'])->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyCatalog(): array
    {
        return ['products' => [], 'customers' => [], 'leads' => [], 'opportunities' => [], 'contacts' => [], 'addresses' => []];
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}

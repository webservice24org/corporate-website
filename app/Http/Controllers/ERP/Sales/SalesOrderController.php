<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Sales;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\SalesDocumentEvent;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesQuotation;
use App\Models\User;
use App\Services\ERP\Sales\SalesCompanyAccess;
use App\Services\ERP\Sales\SalesOrderService;
use App\Services\ERP\Sales\SalesQuotationMath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SalesOrderController extends Controller
{
    public function __construct(private readonly SalesOrderService $service, private readonly SalesCompanyAccess $access, private readonly SalesQuotationMath $math) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $filters = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', 'in:draft,submitted,approved,confirmed,partially_delivered,delivered,cancelled,closed,rejected']]);
        $companies = $this->service->companies($actor, 'erp.sales-orders.view')->orderBy('name')->get(['id', 'name', 'currency_code']);
        if (! empty($filters['company_id'])) {
            $this->access->authorize($actor, (int) $filters['company_id'], 'erp.sales-orders.view');
        }
        $rows = SalesOrder::query()->whereIn('company_id', $companies->modelKeys())
            ->when(! empty($filters['company_id']), fn ($q) => $q->where('company_id', $filters['company_id']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['search']), fn ($q) => $q->where('number', 'like', '%'.$filters['search'].'%'))->latest('id')->paginate(20)->withQueryString();

        return Inertia::render('ERP/Sales/Orders/Index', ['orders' => $rows, 'companies' => $companies, 'filters' => $filters]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'sales_quotation_id' => ['nullable', 'integer', 'min:1']]);
        $companies = $this->service->companies($actor, 'erp.sales-orders.create')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'currency_code', 'timezone']);
        $companyId = isset($data['company_id']) ? (int) $data['company_id'] : $companies->first()?->getKey();
        if ($companyId !== null) {
            $this->access->authorize($actor, (int) $companyId, 'erp.sales-orders.create');
        }
        $quoteId = empty($data['sales_quotation_id']) ? null : (int) $data['sales_quotation_id'];
        if ($quoteId !== null && $companyId !== null) {
            abort_unless($actor->can('erp.sales-quotations.view'), 403);
            SalesQuotation::query()->where('company_id', $companyId)->findOrFail($quoteId);
            $existing = SalesOrder::query()->where('company_id', $companyId)->where('sales_quotation_id', $quoteId)->first();
            if ($existing !== null) {
                $this->service->find($actor, (int) $existing->getKey());

                return redirect()->route('erp.sales.orders.show', $existing->getKey());
            }
        }
        $company = $companies->firstWhere('id', $companyId);
        $today = now((string) ($company?->getAttribute('timezone') ?? config('app.timezone')))->toDateString();

        return Inertia::render('ERP/Sales/Orders/Form', ['order' => null, 'items' => [], 'companies' => $companies, 'defaultCompanyId' => $companyId, 'defaultQuotationId' => $quoteId, 'sources' => $companyId === null ? [] : $this->sources($actor, (int) $companyId, '', $quoteId), 'today' => $today]);
    }

    public function sourcesJson(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:120']]);

        return response()->json(['sources' => $this->sources($this->actor($request), (int) $data['company_id'], (string) ($data['search'] ?? ''))]);
    }

    public function edit(Request $request, int $order): Response
    {
        $actor = $this->actor($request);
        $document = $this->service->find($actor, $order, 'erp.sales-orders.edit');
        abort_unless($document->getAttribute('status') === 'draft', 422);

        return Inertia::render('ERP/Sales/Orders/Form', ['order' => $document, 'items' => $this->items($document), 'companies' => Company::query()->whereKey($document->getAttribute('company_id'))->get(['id', 'name', 'currency_code']), 'defaultCompanyId' => $document->getAttribute('company_id'), 'defaultQuotationId' => $document->getAttribute('sales_quotation_id'), 'sources' => [], 'today' => now()->toDateString()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $document = $this->service->create($this->actor($request), $request->all());

        return redirect()->route('erp.sales.orders.show', $document->getKey())->with('success', 'Sales order ready.');
    }

    public function update(Request $request, int $order): RedirectResponse
    {
        $document = $this->service->update($this->actor($request), $order, $request->all());

        return redirect()->route('erp.sales.orders.show', $document->getKey())->with('success', 'Sales order saved.');
    }

    public function show(Request $request, int $order): Response
    {
        return $this->display($request, $order, false);
    }

    public function print(Request $request, int $order): Response
    {
        return $this->display($request, $order, true);
    }

    public function credit(Request $request, int $order): JsonResponse
    {
        return response()->json($this->service->creditPreview($this->actor($request), $order));
    }

    public function action(Request $request, int $order, string $action): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:2000']]);
        $this->service->transition($this->actor($request), $order, $action, (int) $data['revision'], $data['reason'] ?? null);

        return redirect()->route('erp.sales.orders.show', $order)->with('success', 'Sales order updated.');
    }

    private function display(Request $request, int $id, bool $printing): Response
    {
        $actor = $this->actor($request);
        $order = $this->service->find($actor, $id);
        $companyId = (int) $order->getAttribute('company_id');
        $quote = SalesQuotation::query()->where('company_id', $companyId)->find($order->getAttribute('sales_quotation_id'), ['id', 'number']);

        return Inertia::render('ERP/Sales/Orders/Show', [
            'order' => $order, 'items' => $this->items($order), 'printMode' => $printing,
            'quotationNumber' => $quote?->getAttribute('number'), 'quotationUrl' => $quote !== null && $actor->can('erp.sales-quotations.view') ? '/erp/sales/quotations/'.$quote->getKey() : null,
            'events' => SalesDocumentEvent::query()->where('company_id', $companyId)->where('document_type', 'order')->where('document_id', $id)->orderBy('id')->get(['id', 'action', 'actor_snapshot', 'reason', 'occurred_at']),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function sources(User $actor, int $companyId, string $search, ?int $selectedId = null): array
    {
        $company = $this->access->authorize($actor, $companyId, 'erp.sales-orders.create');
        abort_unless($actor->can('erp.sales-quotations.view'), 403);
        $today = now((string) $company->getAttribute('timezone'))->toDateString();
        $query = SalesQuotation::query()->where('company_id', $companyId)->where('status', 'accepted')->whereNotNull('accepted_at')->whereNotNull('customer_id')->where('valid_until', '>=', $today)
            ->whereNotIn('id', SalesOrder::query()->where('company_id', $companyId)->select('sales_quotation_id'));
        $quotes = (clone $query)->when($search !== '', fn ($q) => $q->where('number', 'like', '%'.$search.'%'))->latest('id')->limit(100)->get();
        if ($selectedId !== null) {
            $selected = (clone $query)->whereKey($selectedId)->firstOrFail();
            $quotes = $quotes->merge([$selected])->unique('id')->values();
        }

        return $quotes->map(fn (SalesQuotation $quote): array => ['id' => $quote->getKey(), 'number' => $quote->getAttribute('number'), 'customer_name' => ($quote->getAttribute('customer_snapshot') ?? [])['name'] ?? '', 'currency_code' => $quote->getAttribute('currency_code'), 'grand_total' => $quote->getAttribute('grand_total'), 'valid_until' => $quote->getAttribute('valid_until')?->format('Y-m-d')])->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function items(SalesOrder $order): array
    {
        return SalesOrderItem::query()->where('sales_order_id', $order->getKey())->orderBy('line_number')->get()->map(function (SalesOrderItem $line): array {
            $remaining = $this->math->scaled((string) $line->getAttribute('quantity'), 10000) - $this->math->scaled((string) $line->getAttribute('delivered_quantity'), 10000) - $this->math->scaled((string) $line->getAttribute('cancelled_quantity'), 10000);

            return [...$line->toArray(), 'remaining_quantity' => $this->math->format($remaining, 4)];
        })->all();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}

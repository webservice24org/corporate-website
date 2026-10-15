<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Sales;

use App\Http\Controllers\Controller;
use App\Models\SalesDelivery;
use App\Models\SalesDeliveryItem;
use App\Models\SalesDocumentEvent;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\User;
use App\Services\ERP\Sales\SalesCompanyAccess;
use App\Services\ERP\Sales\SalesOrderService;
use App\Services\ERP\Sales\SalesReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SalesReturnController extends Controller
{
    public function __construct(private readonly SalesReturnService $returns, private readonly SalesOrderService $orders, private readonly SalesCompanyAccess $access) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $filters = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', 'in:draft,inspected,posted,cancelled']]);
        $companies = $this->orders->companies($actor, 'erp.sales-returns.view')->orderBy('name')->get(['id', 'name']);
        if (! empty($filters['company_id'])) {
            $this->access->authorize($actor, (int) $filters['company_id'], 'erp.sales-returns.view');
        }
        $rows = SalesReturn::query()->whereIn('company_id', $companies->modelKeys())->when(! empty($filters['company_id']), fn ($q) => $q->where('company_id', $filters['company_id']))->when(! empty($filters['search']), fn ($q) => $q->where('number', 'like', '%'.$filters['search'].'%'))->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))->latest('id')->paginate(20)->withQueryString()->through(fn (SalesReturn $row): array => $this->header($row));

        return Inertia::render('ERP/Sales/Returns/Index', ['returns' => $rows, 'companies' => $companies, 'filters' => $filters]);
    }

    public function create(Request $request): Response
    {
        return $this->form($request);
    }

    public function edit(Request $request, int $return): Response
    {
        return $this->form($request, $return);
    }

    public function store(Request $request): RedirectResponse
    {
        $row = $this->returns->save($this->actor($request), $request->all());

        return to_route('erp.sales.returns.show', $row->getKey());
    }

    public function update(Request $request, int $return): RedirectResponse
    {
        $this->returns->save($this->actor($request), $request->all(), $return);

        return to_route('erp.sales.returns.show', $return);
    }

    public function inspect(Request $request, int $return): RedirectResponse
    {
        $this->returns->inspect($this->actor($request), $return, $request->all());

        return to_route('erp.sales.returns.show', $return);
    }

    public function post(Request $request, int $return): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'string', 'max:100']]);
        $this->returns->postReturn($this->actor($request), $return, (int) $data['revision'], (string) $data['idempotency_key']);

        return to_route('erp.sales.returns.show', $return);
    }

    public function cancel(Request $request, int $return): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u']]);
        $this->returns->cancel($this->actor($request), $return, (int) $data['revision'], (string) $data['reason']);

        return to_route('erp.sales.returns.show', $return);
    }

    public function show(Request $request, int $return): Response
    {
        return $this->display($request, $return, false);
    }

    public function print(Request $request, int $return): Response
    {
        return $this->display($request, $return, true);
    }

    private function form(Request $request, ?int $id = null): Response
    {
        $actor = $this->actor($request);
        $permission = 'erp.sales-returns.'.($id === null ? 'create' : 'edit');
        abort_unless($actor->can('erp.sales-deliveries.view'), 403);
        $row = $id === null ? null : $this->returns->find($actor, $id, $permission);
        if ($row !== null) {
            abort_unless($row->getAttribute('status') === 'draft', 403);
        }
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'sales_delivery_id' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:120']]);
        $companies = $this->orders->companies($actor, $permission)->where('status', 'active')->orderBy('name')->get(['id', 'name']);
        $companyId = $row?->getAttribute('company_id') ?? ($data['company_id'] ?? $companies->first()?->getKey());
        $deliveryId = $row?->getAttribute('sales_delivery_id') ?? ($data['sales_delivery_id'] ?? null);
        $deliveries = [];
        $sourceItems = [];
        $today = now()->toDateString();
        $sourceDelivery = null;
        if ($companyId !== null) {
            $company = $this->access->authorize($actor, (int) $companyId, $permission);
            $today = now((string) $company->getAttribute('timezone'))->toDateString();
            $query = SalesDelivery::query()->where('company_id', $companyId)->where('status', 'posted')->whereNotNull('posted_at')->whereIn('id', SalesDeliveryItem::query()->where('fulfillment_type', 'physical')->select('sales_delivery_id'));
            $options = (clone $query)->when(! empty($data['search']), fn ($q) => $q->where('number', 'like', '%'.$data['search'].'%'))->latest('id')->limit(100)->get();
            if ($deliveryId !== null) {
                $delivery = (clone $query)->findOrFail((int) $deliveryId);
                $options = $options->merge([$delivery])->unique('id')->values();
                $progress = $this->returns->sourceProgress($actor, (int) $deliveryId, $permission);
                $sourceDelivery = ['id' => $delivery->getKey(), 'number' => $delivery->getAttribute('number')];
                $sourceItems = SalesDeliveryItem::query()->where('sales_delivery_id', $deliveryId)->where('fulfillment_type', 'physical')->orderBy('id')->get()->map(fn (SalesDeliveryItem $item): array => ['id' => $item->getKey(), 'product_snapshot' => $item->getAttribute('product_snapshot'), 'remaining' => bcsub((string) $item->getAttribute('quantity'), $progress[(int) $item->getKey()]['accepted'], 4), 'invoiced_quantity' => $item->getAttribute('invoiced_quantity')])->all();
            }
            $deliveries = $options->map(fn (SalesDelivery $d): array => ['id' => $d->getKey(), 'number' => $d->getAttribute('number'), 'customer_name' => ($d->getAttribute('customer_snapshot') ?? [])['name'] ?? ''])->all();
        }

        return Inertia::render('ERP/Sales/Returns/Form', ['returnDocument' => $row === null ? null : $this->header($row), 'companies' => $companies, 'deliveries' => $deliveries, 'sourceDelivery' => $sourceDelivery, 'sourceItems' => $sourceItems, 'savedItems' => $row === null ? [] : $this->items($row), 'defaultCompanyId' => $companyId === null ? null : (int) $companyId, 'today' => $today, 'search' => $data['search'] ?? '']);
    }

    /** @return array<string, mixed> */
    private function header(SalesReturn $row): array
    {
        $terms = $row->getAttribute('terms_snapshot') ?? [];

        return [...$row->only(['id', 'company_id', 'sales_delivery_id', 'number', 'status', 'revision', 'currency_code', 'customer_snapshot', 'notes']), 'document_date' => $row->getAttribute('document_date')->format('Y-m-d'), 'inspected_at' => $row->getAttribute('inspected_at')?->toIso8601String(), 'posted_at' => $row->getAttribute('posted_at')?->toIso8601String(), 'issuer' => $terms['issuer'] ?? null, 'source' => $terms['return_source'] ?? null, 'reason' => $terms['return_reason'] ?? '', 'inspection_reason' => $terms['inspection_reason'] ?? null];
    }

    /** @return list<array<string, mixed>> */
    private function items(SalesReturn $row): array
    {
        return SalesReturnItem::query()->where('sales_return_id', $row->getKey())->orderBy('line_number')->get()->map(fn (SalesReturnItem $item): array => [...$item->only(['id', 'sales_delivery_item_id', 'product_snapshot', 'description', 'quantity', 'accepted_quantity', 'restocked_quantity', 'disposition', 'unbilled_quantity', 'credit_review_quantity']), 'damaged_quantity' => bcsub((string) $item->getAttribute('accepted_quantity'), (string) $item->getAttribute('restocked_quantity'), 4), 'rejected_quantity' => bcsub((string) $item->getAttribute('quantity'), (string) $item->getAttribute('accepted_quantity'), 4)])->all();
    }

    private function display(Request $request, int $id, bool $print): Response
    {
        $actor = $this->actor($request);
        $row = $this->returns->find($actor, $id);
        $this->returns->sourceProgress($actor, (int) $row->getAttribute('sales_delivery_id'));
        $events = $print ? [] : SalesDocumentEvent::query()->where('company_id', $row->getAttribute('company_id'))->where('document_type', 'return')->where('document_id', $id)->orderBy('id')->get(['id', 'action', 'actor_snapshot', 'reason', 'occurred_at']);

        return Inertia::render('ERP/Sales/Returns/Show', ['returnDocument' => $this->header($row), 'items' => $this->items($row), 'events' => $events, 'printMode' => $print, 'deliveryUrl' => $actor->can('erp.sales-deliveries.view') ? route('erp.sales.deliveries.show', $row->getAttribute('sales_delivery_id')) : null]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}

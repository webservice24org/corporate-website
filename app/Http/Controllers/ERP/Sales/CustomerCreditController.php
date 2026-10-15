<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Sales;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerCreditAllocation;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\ERP\Sales\CustomerCreditAllocationService;
use App\Services\ERP\Sales\SalesCompanyAccess;
use App\Services\ERP\Sales\SalesOrderService;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CustomerCreditController extends Controller
{
    public function __construct(private readonly CustomerCreditAllocationService $credits, private readonly SalesOrderService $orders, private readonly SalesCompanyAccess $access) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'customer_id' => ['nullable', 'integer', 'min:1']]);
        $companies = $this->orders->companies($actor, 'erp.customer-receivables.view')->orderBy('name')->get(['id', 'name']);
        $companyId = $data['company_id'] ?? $companies->first()?->getKey();
        $customerId = $data['customer_id'] ?? null;
        $customers = [];
        $invoices = [];
        $allocations = [];
        if ($companyId !== null) {
            $this->access->authorize($actor, (int) $companyId, 'erp.customer-receivables.view');
            $customers = Customer::query()->withoutGlobalScope(SoftDeletingScope::class)->where('company_id', $companyId)->whereIn('id', SalesInvoice::query()->where('company_id', $companyId)->where('status', 'issued')->select('customer_id'))->orderBy('name')->get(['id', 'name']);
            if ($customerId !== null) {
                abort_unless($customers->contains('id', (int) $customerId), 404);
                $state = $this->credits->read($actor, (int) $companyId, (int) $customerId);
                $headers = SalesInvoice::query()->where('company_id', $companyId)->where('customer_id', $customerId)->where('status', 'issued')->get()->keyBy('id');
                foreach ($headers as $invoice) {
                    $invoices[] = ['id' => $invoice->getKey(), 'number' => $invoice->getAttribute('number'), ...$state[(int) $invoice->getKey()], 'url' => $actor->can('erp.sales-invoices.view') ? route('erp.sales.invoices.show', $invoice->getKey()) : null];
                }
                foreach (CustomerCreditAllocation::query()->where('company_id', $companyId)->whereIn('source_sales_invoice_id', $headers->modelKeys())->latest('id')->get() as $a) {
                    $allocations[] = [...$a->only(['id', 'source_sales_invoice_id', 'sales_invoice_id', 'amount', 'reason', 'reverses_allocation_id']), 'posted_at' => $a->getAttribute('posted_at')?->toIso8601String(), 'source_number' => $headers->get((int) $a->getAttribute('source_sales_invoice_id'))?->getAttribute('number'), 'target_number' => $headers->get((int) $a->getAttribute('sales_invoice_id'))?->getAttribute('number'), 'active' => $a->getAttribute('reverses_allocation_id') === null && ! CustomerCreditAllocation::query()->where('reverses_allocation_id', $a->getKey())->exists()];
                }
            }
        }

        return Inertia::render('ERP/Sales/CreditNotes/Allocations', ['companies' => $companies, 'customers' => $customers, 'filters' => ['company_id' => $companyId, 'customer_id' => $customerId], 'invoices' => $invoices, 'allocations' => $allocations]);
    }

    public function allocate(Request $request): RedirectResponse
    {
        $row = $this->credits->allocate($this->actor($request), $request->all());

        return $this->backToCustomer($row);
    }

    public function reverse(Request $request, int $allocation): RedirectResponse
    {
        $row = $this->credits->reverse($this->actor($request), $allocation, $request->all());

        return $this->backToCustomer($row);
    }

    private function backToCustomer(CustomerCreditAllocation $row): RedirectResponse
    {
        $invoice = SalesInvoice::query()->findOrFail($row->getAttribute('source_sales_invoice_id'));

        return to_route('erp.sales.customer-credits.index', ['company_id' => $row->getAttribute('company_id'), 'customer_id' => $invoice->getAttribute('customer_id')]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Sales;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Services\ERP\Sales\SalesCompanyAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SalesCustomerController extends Controller
{
    private const FIELDS = ['id', 'company_id', 'customer_code', 'customer_type', 'name', 'business_name', 'contact_person', 'phone', 'email', 'credit_limit', 'payment_terms_days', 'is_active'];

    public function __construct(private readonly SalesCompanyAccess $access) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $companies = $this->companies($actor);
        $filters = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', 'in:active,inactive,all']]);
        $companyId = isset($filters['company_id']) ? (int) $filters['company_id'] : ($companies->first()?->getKey());
        if ($companyId !== null) {
            $this->access->authorize($actor, (int) $companyId, 'erp.sales-customers.view');
        }
        $search = trim((string) ($filters['search'] ?? ''));
        $status = (string) ($filters['status'] ?? 'active');
        $customers = Customer::query()->where('company_id', $companyId ?? 0)
            ->when($status !== 'all', fn ($query) => $query->where('is_active', $status === 'active'))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('name', 'like', '%'.$search.'%')->orWhere('business_name', 'like', '%'.$search.'%')->orWhere('customer_code', 'like', '%'.$search.'%');
                });
            })->orderBy('name')->paginate(20, self::FIELDS)->withQueryString();

        return Inertia::render('ERP/Sales/Customers/Index', [
            'companies' => $companies, 'customers' => $customers,
            'filters' => ['company_id' => $companyId, 'search' => $search, 'status' => $status],
        ]);
    }

    public function show(Request $request, int $customer): Response
    {
        $actor = $this->actor($request);
        $validated = $request->validate(['company_id' => ['required', 'integer', 'min:1']]);
        $companyId = (int) $validated['company_id'];
        $company = $this->access->authorize($actor, $companyId, 'erp.sales-customers.view');
        $record = Customer::query()->where('company_id', $companyId)->findOrFail($customer, self::FIELDS);

        return Inertia::render('ERP/Sales/Customers/Show', [
            'customer' => $record, 'company' => $company->only(['id', 'name', 'currency_code', 'status']),
            'contacts' => $record->contacts()->orderByDesc('is_primary')->orderBy('name')->get(['id', 'name', 'designation', 'phone', 'email', 'is_primary']),
            'addresses' => $record->addresses()->orderBy('address_type')->orderByDesc('is_primary')->get(['id', 'address_type', 'label', 'address', 'postal_code', 'is_primary']),
            'crmUrl' => $actor->can('erp.customers.view') ? '/erp/crm/customers/'.$record->getKey() : null,
        ]);
    }

    /** @return Collection<int, Company> */
    private function companies(User $actor): Collection
    {
        abort_unless($actor->can('erp.sales-customers.view'), 403);
        $query = Company::query();
        if (! $actor->hasRole('super-admin')) {
            $companyId = $actor->employee()->value('company_id');
            abort_if($companyId === null, 403);
            $query->whereKey($companyId);
        }

        return $query->orderBy('name')->get(['id', 'name', 'currency_code', 'status']);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}

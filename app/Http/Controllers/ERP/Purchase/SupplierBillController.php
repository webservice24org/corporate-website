<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Purchase;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillItem;
use App\Models\User;
use App\Services\ERP\Purchase\SupplierBillService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class SupplierBillController extends Controller
{
    public function __construct(private readonly SupplierBillService $service) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:draft,approved,cancelled'], 'company_id' => ['nullable', 'integer', 'min:1']]);
        $query = $this->service->visible($actor)->with(['company:id,name', 'supplier:id,name', 'goods_receipt:id,number']);
        if (! empty($filters['company_id'])) {
            $this->service->companies($actor, 'erp.supplier-bills.view')->findOrFail($filters['company_id']);
            $query->where('company_id', $filters['company_id']);
        }
        if (! empty($filters['search'])) {
            $query->where(function ($query) use ($filters): void {
                $query->where('number', 'like', '%'.$filters['search'].'%')->orWhere('supplier_invoice_number', 'like', '%'.$filters['search'].'%');
            });
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return Inertia::render('ERP/Purchase/Bills/Index', ['bills' => $query->latest('id')->paginate(20)->withQueryString(),
            'companies' => $this->service->companies($actor, 'erp.supplier-bills.view')->orderBy('name')->get(['id', 'name']), 'filters' => $filters]);
    }

    public function create(Request $request): Response
    {
        $actor = $this->actor($request);
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'receipt_id' => ['nullable', 'integer', 'min:1']]);
        $companies = $this->service->companies($actor, 'erp.supplier-bills.create')->orderBy('name')->get(['id', 'name']);
        $only = $companies->count() === 1 ? $companies->first() : null;
        $companyId = empty($data['company_id']) ? ($only === null ? null : (int) $only->getKey()) : (int) $data['company_id'];
        $receipt = empty($data['receipt_id']) ? null : $this->service->sources($actor)->findOrFail((int) $data['receipt_id']);
        if ($receipt !== null) {
            if (! empty($data['company_id']) && $companyId !== (int) $receipt->company_id) {
                abort(404);
            }
            $companyId = (int) $receipt->company_id;
        }
        if ($companyId !== null) {
            $this->service->companies($actor, 'erp.supplier-bills.create')->findOrFail($companyId);
        }
        $receipts = $this->service->sources($actor)->where('company_id', $companyId ?? 0)->latest('id')->get(['id', 'number']);

        return $this->form(null, $receipt, $companies->toArray(), $receipts->toArray(), $companyId);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([...$this->rules(), 'goods_receipt_id' => ['required', 'integer', 'min:1']]);
        $bill = $this->service->create($this->actor($request), $data);

        return redirect()->route('erp.purchase.bills.show', $bill->getKey())->with('success', 'Bill saved as draft. No payable or stock changed.');
    }

    public function edit(Request $request, int $bill): Response
    {
        $record = $this->service->find($this->actor($request), $bill, 'erp.supplier-bills.edit');
        abort_unless($record->status === 'draft', 403);
        $receipt = GoodsReceipt::query()->where('company_id', $record->company_id)->findOrFail($record->goods_receipt_id);

        return $this->form($record, $receipt, [], [['id' => (int) $receipt->getKey(), 'number' => $receipt->number]], (int) $record->company_id);
    }

    public function update(Request $request, int $bill): RedirectResponse
    {
        $data = $request->validate([...$this->rules(), 'revision' => ['required', 'integer', 'min:1'], 'goods_receipt_id' => ['prohibited']]);
        $this->service->update($this->actor($request), $bill, $data);

        return redirect()->route('erp.purchase.bills.show', $bill)->with('success', 'Bill draft updated.');
    }

    public function show(Request $request, int $bill): Response
    {
        $actor = $this->actor($request);
        $record = $this->service->find($actor, $bill, 'erp.supplier-bills.view');
        $items = SupplierBillItem::query()->where('supplier_bill_id', $bill)->orderBy('id')->get()->map(function (SupplierBillItem $line) use ($record): array {
            $source = GoodsReceiptItem::query()->where('goods_receipt_id', $record->goods_receipt_id)->find($line->goods_receipt_item_id);

            return [...$line->toArray(), 'credit_review_quantity' => $source === null ? null : $this->service->creditReviewQuantity($source)];
        });
        $history = DB::table('supplier_bill_audit_logs')->leftJoin('users', 'users.id', '=', 'supplier_bill_audit_logs.actor_id')
            ->where('supplier_bill_id', $bill)->where('supplier_bill_audit_logs.company_id', $record->company_id)->latest('supplier_bill_audit_logs.id')->limit(50)
            ->get(['supplier_bill_audit_logs.id', 'action', 'comment', 'supplier_bill_audit_logs.created_at', 'users.name as actor_name']);
        // Bill permission permits seeing the linked payable for this bill; list access is separate.
        $payable = DB::table('supplier_payables')->where('company_id', $record->company_id)->where('supplier_bill_id', $bill)->first();
        if ($payable !== null) {
            $payable->amount = bcadd((string) $payable->amount, '0', 2);
            $payable->paid_amount = bcadd((string) $payable->paid_amount, '0', 2);
            $payable->credited_amount = bcadd((string) $payable->credited_amount, '0', 2);
            $payable->outstanding = bcsub(bcsub($payable->amount, $payable->paid_amount, 2), $payable->credited_amount, 2);
        }

        return Inertia::render('ERP/Purchase/Bills/Show', ['bill' => $record->load(['company:id,name', 'supplier:id,name', 'goods_receipt:id,number', 'purchase_order:id,number']),
            'items' => $items, 'history' => $history, 'payable' => $payable]);
    }

    public function approve(Request $request, int $bill): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $this->service->approve($this->actor($request), $bill, (int) $data['revision']);

        return redirect()->route('erp.purchase.bills.show', $bill)->with('success', 'Supplier bill approved and payable recorded.');
    }

    public function cancel(Request $request, int $bill): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000']]);
        $this->service->cancel($this->actor($request), $bill, (int) $data['revision'], $data['reason']);

        return redirect()->route('erp.purchase.bills.show', $bill)->with('success', 'Draft bill cancelled. No payable changed.');
    }

    public function payables(Request $request): Response
    {
        $actor = $this->actor($request);
        $permission = 'erp.supplier-payables.view';
        $filters = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'supplier_id' => ['nullable', 'integer', 'min:1'], 'due' => ['nullable', 'in:overdue,undated,current']]);
        $companies = $this->service->companies($actor, $permission);
        if (! empty($filters['company_id'])) {
            (clone $companies)->findOrFail($filters['company_id']);
        }
        if (! empty($filters['supplier_id'])) {
            Supplier::query()->whereIn('company_id', (clone $companies)->select('id'))->findOrFail($filters['supplier_id']);
        }
        $asOf = now()->toDateString();
        $query = DB::table('supplier_payables')->join('supplier_bills', 'supplier_bills.id', '=', 'supplier_payables.supplier_bill_id')
            ->join('suppliers', 'suppliers.id', '=', 'supplier_payables.supplier_id')->join('companies', 'companies.id', '=', 'supplier_payables.company_id')
            ->whereIn('supplier_payables.company_id', (clone $companies)->select('id'))->where('supplier_bills.status', 'approved')
            ->whereColumn('supplier_bills.company_id', 'supplier_payables.company_id')->whereColumn('supplier_bills.supplier_id', 'supplier_payables.supplier_id')
            ->whereColumn('suppliers.company_id', 'supplier_payables.company_id')->where('supplier_payables.paid_amount', '>=', 0)->where('supplier_payables.credited_amount', '>=', 0)->whereRaw('supplier_payables.amount > supplier_payables.paid_amount + supplier_payables.credited_amount');
        if (! empty($filters['company_id'])) {
            $query->where('supplier_payables.company_id', $filters['company_id']);
        }
        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_payables.supplier_id', $filters['supplier_id']);
        }
        if (($filters['due'] ?? '') === 'overdue') {
            $query->where('supplier_payables.due_date', '<', $asOf);
        } elseif (($filters['due'] ?? '') === 'undated') {
            $query->whereNull('supplier_payables.due_date');
        } elseif (($filters['due'] ?? '') === 'current') {
            $query->where('supplier_payables.due_date', '>=', $asOf);
        }
        $columns = ['supplier_payables.id', 'supplier_payables.company_id', 'supplier_payables.supplier_id', 'supplier_payables.supplier_bill_id', 'supplier_payables.currency_code',
            'supplier_payables.amount', 'supplier_payables.paid_amount', 'supplier_payables.credited_amount', 'supplier_payables.due_date', 'supplier_bills.number as bill_number', 'supplier_bills.supplier_invoice_number',
            'suppliers.name as supplier_name', 'companies.name as company_name'];
        $groups = [];
        foreach ((clone $query)->select($columns)->orderBy('supplier_payables.id')->cursor() as $row) {
            $key = $row->company_id.'-'.$row->supplier_id.'-'.$row->currency_code;
            if (! isset($groups[$key])) {
                $groups[$key] = ['company_id' => (int) $row->company_id, 'supplier_id' => (int) $row->supplier_id, 'company_name' => $row->company_name,
                    'supplier_name' => $row->supplier_name, 'currency_code' => $row->currency_code, 'outstanding' => '0.00', 'overdue' => '0.00'];
            }
            $amount = bcsub(bcsub((string) $row->amount, (string) $row->paid_amount, 2), (string) $row->credited_amount, 2);
            $groups[$key]['outstanding'] = bcadd($groups[$key]['outstanding'], $amount, 2);
            if ($row->due_date !== null && $row->due_date < $asOf) {
                $groups[$key]['overdue'] = bcadd($groups[$key]['overdue'], $amount, 2);
            }
        }
        $payables = $query->select($columns)->orderByDesc('supplier_payables.id')->paginate(20)->withQueryString();
        $payables->getCollection()->transform(function ($row) use ($asOf) {
            $row->amount = bcadd((string) $row->amount, '0', 2);
            $row->paid_amount = bcadd((string) $row->paid_amount, '0', 2);
            $row->credited_amount = bcadd((string) $row->credited_amount, '0', 2);
            $row->outstanding = bcsub(bcsub($row->amount, $row->paid_amount, 2), $row->credited_amount, 2);
            $row->overdue = $row->due_date !== null && $row->due_date < $asOf;

            return $row;
        });

        return Inertia::render('ERP/Purchase/Bills/Payables', ['payables' => $payables, 'summary' => array_values($groups), 'asOf' => $asOf, 'filters' => $filters,
            'companies' => (clone $companies)->orderBy('name')->get(['id', 'name']),
            'suppliers' => Supplier::query()->whereIn('company_id', (clone $companies)->select('id'))->orderBy('name')->get(['id', 'name', 'company_id'])]);
    }

    /** @param array<int, array<string, mixed>> $companies @param array<int, array<string, mixed>> $receipts */
    private function form(?SupplierBill $bill, ?GoodsReceipt $receipt, array $companies, array $receipts, ?int $companyId): Response
    {
        return Inertia::render('ERP/Purchase/Bills/Form', ['bill' => $bill, 'companies' => $companies, 'receipts' => $receipts, 'companyId' => $companyId,
            'receipt' => $receipt?->load(['purchase_order:id,number,currency_code,supplier_id', 'purchase_order.supplier:id,name']),
            'sourceLines' => $receipt === null ? [] : $this->service->sourceLines($receipt), 'billItems' => $bill === null ? [] : SupplierBillItem::query()->where('supplier_bill_id', $bill->getKey())->get(),
            'invoiceDate' => $bill === null ? now()->toDateString() : $bill->getRawOriginal('invoice_date'), 'dueDate' => $bill?->getRawOriginal('due_date')]);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return ['supplier_invoice_number' => ['required', 'string', 'max:100', 'regex:/\S/u'], 'invoice_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:invoice_date'], 'notes' => ['nullable', 'string', 'max:10000'],
            'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*' => ['array:goods_receipt_item_id,quantity'],
            'items.*.goods_receipt_item_id' => ['required', 'integer', 'min:1', 'distinct'], 'items.*.quantity' => ['required', 'numeric', 'min:0', 'max:1000000', 'regex:/^[0-9]+(?:\.[0-9]{1,4})?$/D'],
            'company_id' => ['prohibited'], 'supplier_id' => ['prohibited'], 'purchase_order_id' => ['prohibited'], 'currency_code' => ['prohibited'], 'grand_total' => ['prohibited'],
            'subtotal' => ['prohibited'], 'tax_total' => ['prohibited'], 'discount_total' => ['prohibited'], 'number' => ['prohibited'], 'status' => ['prohibited'], 'approved_by' => ['prohibited'], 'created_by' => ['prohibited']];
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

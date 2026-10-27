<?php

declare(strict_types=1);

namespace App\Services\ERP\Purchase;

use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseReturn;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

final class PurchaseReportService
{
    public function __construct(private readonly PurchaseRequisitionService $requisitions, private readonly SupplierBillService $billing) {}

    /** @return EloquentBuilder<Company> */
    public function companies(User $actor): EloquentBuilder
    {
        return $this->requisitions->companies($actor, 'erp.purchase-reports.view');
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function options(User $actor, array $filters): array
    {
        $companies = $this->companies($actor);
        if (! empty($filters['company_id'])) {
            (clone $companies)->findOrFail($filters['company_id']);
        }
        if (! empty($filters['supplier_id'])) {
            Supplier::query()->whereIn('company_id', (clone $companies)->select('id'))->findOrFail($filters['supplier_id']);
        }
        $currencies = DB::table('purchase_orders')->whereIn('company_id', (clone $companies)->select('id'))->select('currency_code')
            ->union(DB::table('supplier_bills')->whereIn('company_id', (clone $companies)->select('id'))->select('currency_code'))
            ->orderBy('currency_code')->pluck('currency_code')->all();

        return ['companies' => (clone $companies)->orderBy('name')->get(['id', 'name']),
            'suppliers' => Supplier::query()->whereIn('company_id', (clone $companies)->select('id'))->orderBy('name')->get(['id', 'company_id', 'name']), 'currencies' => $currencies];
    }

    /** @param array<string, mixed> $filters */
    private function scope(Builder $query, User $actor, array $filters, string $company, string $supplier, string $currency): Builder
    {
        $query->whereIn($company, $this->companies($actor)->select('id'));
        if (! empty($filters['company_id'])) {
            $query->where($company, $filters['company_id']);
        }
        if (! empty($filters['supplier_id'])) {
            $query->where($supplier, $filters['supplier_id']);
        }
        if (! empty($filters['currency'])) {
            $query->where($currency, $filters['currency']);
        }

        return $query;
    }

    /** @param array<string, mixed> $filters */
    private function period(Builder $query, array $filters, string $column): Builder
    {
        $timezone = (string) config('app.timezone');
        $from = Carbon::parse((string) $filters['from_date'], $timezone)->startOfDay()->format('Y-m-d H:i:s');
        $to = Carbon::parse((string) $filters['to_date'], $timezone)->endOfDay()->format('Y-m-d H:i:s');

        return $query->whereBetween($column, [$from, $to]);
    }

    /** @param array<string, mixed> $filters */
    private function orders(User $actor, array $filters): Builder
    {
        return $this->scope(DB::table('purchase_orders as po')->join('companies as c', 'c.id', '=', 'po.company_id')
            ->join('suppliers as s', 's.id', '=', 'po.supplier_id')->whereColumn('s.company_id', 'po.company_id'), $actor, $filters, 'po.company_id', 'po.supplier_id', 'po.currency_code');
    }

    /** @param array<string, mixed> $filters */
    private function bills(User $actor, array $filters): Builder
    {
        return $this->scope(DB::table('supplier_bills as b')->join('companies as c', 'c.id', '=', 'b.company_id')
            ->join('suppliers as s', 's.id', '=', 'b.supplier_id')->whereColumn('s.company_id', 'b.company_id'), $actor, $filters, 'b.company_id', 'b.supplier_id', 'b.currency_code');
    }

    /** @param array<string, mixed> $filters */
    private function receipts(User $actor, array $filters): Builder
    {
        return $this->scope(DB::table('goods_receipts as gr')->join('purchase_orders as po', 'po.id', '=', 'gr.purchase_order_id')
            ->join('companies as c', 'c.id', '=', 'gr.company_id')->join('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->whereColumn('po.company_id', 'gr.company_id')->whereColumn('s.company_id', 'gr.company_id'), $actor, $filters, 'gr.company_id', 'po.supplier_id', 'po.currency_code');
    }

    /** @param array<string, mixed> $filters */
    private function returns(User $actor, array $filters): Builder
    {
        return $this->scope(DB::table('purchase_returns as pr')->join('purchase_orders as po', 'po.id', '=', 'pr.purchase_order_id')
            ->join('goods_receipts as gr', 'gr.id', '=', 'pr.goods_receipt_id')->join('companies as c', 'c.id', '=', 'pr.company_id')
            ->join('suppliers as s', 's.id', '=', 'pr.supplier_id')->whereColumn('po.company_id', 'pr.company_id')->whereColumn('gr.company_id', 'pr.company_id')
            ->whereColumn('gr.purchase_order_id', 'pr.purchase_order_id')->whereColumn('po.supplier_id', 'pr.supplier_id')->whereColumn('s.company_id', 'pr.company_id'),
            $actor, $filters, 'pr.company_id', 'pr.supplier_id', 'po.currency_code');
    }

    /** @param array<string, mixed> $filters */
    private function payables(User $actor, array $filters): Builder
    {
        return $this->scope(DB::table('supplier_payables as ap')->join('supplier_bills as b', 'b.id', '=', 'ap.supplier_bill_id')
            ->join('companies as c', 'c.id', '=', 'ap.company_id')->join('suppliers as s', 's.id', '=', 'ap.supplier_id')->where('b.status', 'approved')
            ->whereColumn('b.company_id', 'ap.company_id')->whereColumn('b.supplier_id', 'ap.supplier_id')->whereColumn('b.currency_code', 'ap.currency_code')
            ->whereColumn('s.company_id', 'ap.company_id')->whereColumn('ap.amount', 'b.grand_total')->where('ap.paid_amount', '>=', 0)
            ->whereColumn('ap.paid_amount', '<=', 'ap.amount')->where('ap.credited_amount', '>=', 0)->whereRaw('ap.amount > ap.paid_amount + ap.credited_amount'), $actor, $filters, 'ap.company_id', 'ap.supplier_id', 'ap.currency_code');
    }

    /** @param array<string, mixed> $filters */
    public function query(User $actor, array $filters, string $type): Builder
    {
        if ($type === 'orders') {
            return $this->period($this->orders($actor, $filters)->whereIn('po.status', ['ordered', 'partially_received', 'received', 'closed']), $filters, 'po.ordered_at')
                ->select(['po.id', 'po.id as document_id', 'po.number', 'po.company_id', 'c.name as company_name', 'po.supplier_id', 's.name as supplier_name', 'po.currency_code',
                    'po.ordered_at as event_at', 'po.expected_delivery_at as due_date', 'po.status', 'po.grand_total as amount'])->orderByDesc('po.ordered_at')->orderByDesc('po.id');
        }
        if ($type === 'bills') {
            return $this->period($this->bills($actor, $filters)->where('b.status', 'approved'), $filters, 'b.approved_at')
                ->leftJoin('supplier_payables as ap', function ($join): void {
                    $join->on('ap.supplier_bill_id', '=', 'b.id')->on('ap.company_id', '=', 'b.company_id')->on('ap.supplier_id', '=', 'b.supplier_id');
                })->select(['b.id', 'b.id as document_id', 'b.number', 'b.supplier_invoice_number', 'b.company_id', 'c.name as company_name', 'b.supplier_id', 's.name as supplier_name',
                    'b.currency_code', 'b.approved_at as event_at', 'b.invoice_date', 'b.due_date', 'b.status', 'b.grand_total as amount', 'ap.id as payable_id', 'ap.currency_code as payable_currency', 'ap.amount as payable_amount', 'ap.paid_amount as payable_paid', 'ap.credited_amount as payable_credited'])
                ->orderByDesc('b.approved_at')->orderByDesc('b.id');
        }
        if ($type === 'payables') {
            return $this->payables($actor, $filters)->select(['ap.id', 'b.id as document_id', 'b.number', 'b.supplier_invoice_number', 'ap.company_id', 'c.name as company_name', 'ap.supplier_id',
                's.name as supplier_name', 'ap.currency_code', 'ap.created_at as event_at', 'ap.due_date', 'ap.amount', 'ap.paid_amount', 'ap.credited_amount'])->orderByDesc('ap.id');
        }
        if ($type === 'receipts') {
            return $this->period($this->receipts($actor, $filters)->where('gr.status', 'posted'), $filters, 'gr.received_at')
                ->join('goods_receipt_items as gi', 'gi.goods_receipt_id', '=', 'gr.id')->join('purchase_order_items as oi', 'oi.id', '=', 'gi.purchase_order_item_id')->whereColumn('oi.purchase_order_id', 'po.id')
                ->leftJoin('warehouses as w', 'w.id', '=', 'gr.warehouse_id')->leftJoin('stock_movements as sm', 'sm.id', '=', 'gi.stock_movement_id')
                ->select(['gi.id', 'gr.id as document_id', 'gr.number', 'gr.company_id', 'c.name as company_name', 'po.supplier_id', 's.name as supplier_name', 'po.currency_code',
                    'gr.received_at as event_at', 'gr.status', 'po.number as order_number', 'w.name as warehouse_name', 'gr.warehouse_id', 'oi.product_name', 'oi.variant_name', 'oi.product_id', 'oi.product_variant_id',
                    'gi.quantity_received as quantity', 'gi.quantity_rejected', 'gi.is_stock_item', 'gi.unit_cost', 'gi.stock_movement_id', 'sm.total_cost as amount',
                    'sm.company_id as movement_company', 'sm.warehouse_id as movement_warehouse', 'sm.product_id as movement_product', 'sm.product_variant_id as movement_variant', 'sm.quantity as movement_quantity',
                    'sm.unit_cost as movement_cost', 'sm.movement_type', 'sm.direction', 'sm.reference_type', 'sm.reference_id'])->orderByDesc('gr.received_at')->orderByDesc('gi.id');
        }

        return $this->period($this->returns($actor, $filters)->where('pr.status', 'posted'), $filters, 'pr.posted_at')
            ->join('purchase_return_items as ri', 'ri.purchase_return_id', '=', 'pr.id')->join('goods_receipt_items as gi', 'gi.id', '=', 'ri.goods_receipt_item_id')->whereColumn('gi.goods_receipt_id', 'gr.id')
            ->join('purchase_order_items as oi', 'oi.id', '=', 'gi.purchase_order_item_id')->whereColumn('oi.purchase_order_id', 'po.id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'pr.warehouse_id')->leftJoin('stock_movements as sm', 'sm.id', '=', 'ri.stock_movement_id')
            ->select(['ri.id', 'pr.id as document_id', 'pr.number', 'pr.company_id', 'c.name as company_name', 'pr.supplier_id', 's.name as supplier_name', 'po.currency_code',
                'pr.posted_at as event_at', 'pr.status', 'po.number as order_number', 'gr.number as receipt_number', 'w.name as warehouse_name', 'pr.warehouse_id', 'oi.product_name', 'oi.variant_name', 'oi.product_id', 'oi.product_variant_id',
                'ri.quantity', 'ri.is_stock_item', 'ri.unit_cost', 'ri.source_unit_cost', 'ri.stock_movement_id', 'sm.total_cost as amount',
                'sm.company_id as movement_company', 'sm.warehouse_id as movement_warehouse', 'sm.product_id as movement_product', 'sm.product_variant_id as movement_variant', 'sm.quantity as movement_quantity',
                'sm.unit_cost as movement_cost', 'sm.movement_type', 'sm.direction', 'sm.reference_type', 'sm.reference_id'])->orderByDesc('pr.posted_at')->orderByDesc('ri.id');
    }

    /** @return array<string, mixed> */
    public function row(stdClass $row, string $type): array
    {
        $data = (array) $row;
        $precision = in_array($type, ['receipts', 'returns'], true) ? 4 : 2;
        $data['amount'] = $row->amount === null ? null : bcadd((string) $row->amount, '0', $precision);
        $data['integrity'] = 'ok';
        if ($type === 'receipts' || $type === 'returns') {
            $data['quantity'] = bcadd((string) $row->quantity, '0', 4);
            $data['unit_cost'] = bcadd((string) $row->unit_cost, '0', 4);
            if ($type === 'returns' && $row->source_unit_cost !== null) {
                $data['source_unit_cost'] = bcadd((string) $row->source_unit_cost, '0', 4);
            }
            if ($type === 'receipts') {
                $data['quantity_rejected'] = bcadd((string) $row->quantity_rejected, '0', 4);
            }
            $positive = bccomp($data['quantity'], '0', 4) > 0;
            $expected = (bool) $row->is_stock_item && $positive;
            $matches = $row->stock_movement_id !== null && $row->amount !== null
                && (int) $row->movement_company === (int) $row->company_id && (int) $row->movement_warehouse === (int) $row->warehouse_id
                && (int) $row->movement_product === (int) $row->product_id && (int) ($row->movement_variant ?? 0) === (int) ($row->product_variant_id ?? 0)
                && (int) $row->reference_id === (int) $row->document_id && bccomp((string) $row->movement_quantity, $data['quantity'], 4) === 0
                && bccomp((string) $row->movement_cost, $data['unit_cost'], 4) === 0
                && bccomp((string) $row->amount, bcmul($data['quantity'], $data['unit_cost'], 4), 4) === 0
                && $row->movement_type === ($type === 'receipts' ? StockMovement::TYPE_PURCHASE_RECEIPT : StockMovement::TYPE_PURCHASE_RETURN)
                && $row->direction === ($type === 'receipts' ? StockMovement::DIRECTION_IN : StockMovement::DIRECTION_OUT)
                && $row->reference_type === ($type === 'receipts' ? GoodsReceipt::class : PurchaseReturn::class);
            if ($row->is_stock_item === null || ($expected && ! $matches) || (! $expected && $row->stock_movement_id !== null)) {
                $data['integrity'] = 'review_stock_link';
                $data['amount'] = null; // Never invent or count an unmatched movement value.
            } elseif (! $expected) {
                $data['amount'] = '0.0000';
                $data['integrity'] = $positive ? 'non_stock' : 'no_accepted_stock';
            }
            foreach (['movement_company', 'movement_warehouse', 'movement_product', 'movement_variant', 'movement_quantity', 'movement_cost', 'movement_type', 'direction', 'reference_type', 'reference_id'] as $key) {
                unset($data[$key]);
            }
        }
        if ($type === 'bills' && ($row->payable_id === null || $row->payable_currency !== $row->currency_code || bccomp((string) $row->payable_amount, $data['amount'], 2) !== 0 || bccomp((string) $row->payable_paid, '0', 2) < 0 || bccomp((string) $row->payable_credited, '0', 2) < 0 || bccomp(bcadd((string) $row->payable_paid, (string) $row->payable_credited, 2), (string) $row->payable_amount, 2) > 0)) {
            $data['integrity'] = 'review_payable_link';
        }
        if ($type === 'payables') {
            $data['paid_amount'] = bcadd((string) $row->paid_amount, '0', 2);
            $data['credited_amount'] = bcadd((string) $row->credited_amount, '0', 2);
            $data['outstanding'] = bcsub(bcsub($data['amount'], $data['paid_amount'], 2), $data['credited_amount'], 2);
            $data['overdue'] = $row->due_date !== null && $row->due_date < now()->toDateString();
        }

        return $data;
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function dashboard(User $actor, array $filters): array
    {
        $counts = [
            'orders' => $this->period($this->orders($actor, $filters)->whereIn('po.status', ['ordered', 'partially_received', 'received', 'closed']), $filters, 'po.ordered_at')->count(),
            'receipts' => $this->period($this->receipts($actor, $filters)->where('gr.status', 'posted'), $filters, 'gr.received_at')->count(),
            'returns' => $this->period($this->returns($actor, $filters)->where('pr.status', 'posted'), $filters, 'pr.posted_at')->count(),
            'bills' => $this->period($this->bills($actor, $filters)->where('b.status', 'approved'), $filters, 'b.approved_at')->count(),
        ];
        $money = $suppliers = $months = [];
        $issues = ['stock_links' => 0, 'payable_links' => 0];
        foreach (['orders', 'receipts', 'returns', 'bills', 'payables'] as $type) {
            foreach ($this->query($actor, $filters, $type)->cursor() as $raw) {
                $row = $this->row($raw, $type);
                $key = $row['company_id'].'-'.$row['currency_code'];
                if (! isset($money[$key])) {
                    $money[$key] = ['company_id' => (int) $row['company_id'], 'company_name' => $row['company_name'], 'currency_code' => $row['currency_code'],
                        'order_value' => '0.00', 'receipt_stock_value' => '0.0000', 'return_stock_value' => '0.0000', 'bill_value' => '0.00', 'outstanding' => '0.00', 'overdue' => '0.00'];
                }
                if ($row['integrity'] === 'review_stock_link') {
                    $issues['stock_links']++;
                }
                if ($row['integrity'] === 'review_payable_link') {
                    $issues['payable_links']++;
                }
                $field = match ($type) {
                    'orders' => 'order_value', 'receipts' => 'receipt_stock_value', 'returns' => 'return_stock_value', 'bills' => 'bill_value', default => 'outstanding'
                };
                $amount = $type === 'payables' ? $row['outstanding'] : ($row['amount'] ?? '0');
                $money[$key][$field] = bcadd($money[$key][$field], $amount, in_array($type, ['receipts', 'returns'], true) ? 4 : 2);
                if ($type === 'payables' && $row['overdue']) {
                    $money[$key]['overdue'] = bcadd($money[$key]['overdue'], $amount, 2);
                }
                if ($type === 'bills' || $type === 'payables') {
                    $supplierKey = $key.'-'.$row['supplier_id'];
                    if (! isset($suppliers[$supplierKey])) {
                        $suppliers[$supplierKey] = ['company_id' => (int) $row['company_id'], 'supplier_id' => (int) $row['supplier_id'], 'company_name' => $row['company_name'],
                            'supplier_name' => $row['supplier_name'], 'currency_code' => $row['currency_code'], 'bill_count' => 0, 'bill_value' => '0.00', 'outstanding' => '0.00'];
                    }
                    $supplierField = $type === 'bills' ? 'bill_value' : 'outstanding';
                    $suppliers[$supplierKey][$supplierField] = bcadd($suppliers[$supplierKey][$supplierField], $amount, 2);
                    if ($type === 'bills') {
                        $suppliers[$supplierKey]['bill_count']++;
                        $month = substr((string) $row['event_at'], 0, 7);
                        $monthKey = $month.'-'.$key;
                        if (! isset($months[$monthKey])) {
                            $months[$monthKey] = ['month' => $month, 'company_name' => $row['company_name'], 'currency_code' => $row['currency_code'], 'bill_count' => 0, 'bill_value' => '0.00'];
                        }
                        $months[$monthKey]['bill_count']++;
                        $months[$monthKey]['bill_value'] = bcadd($months[$monthKey]['bill_value'], $amount, 2);
                    }
                }
            }
        }
        $creditReviews = 0;
        $sourceIds = $this->bills($actor, $filters)->where('b.status', 'approved')
            ->join('supplier_bill_items as bi', 'bi.supplier_bill_id', '=', 'b.id')
            ->join('goods_receipt_items as gi', 'gi.id', '=', 'bi.goods_receipt_item_id')->whereColumn('gi.goods_receipt_id', 'b.goods_receipt_id')
            ->join('goods_receipts as gr', 'gr.id', '=', 'gi.goods_receipt_id')->whereColumn('gr.company_id', 'b.company_id')->select('gi.id');
        foreach (GoodsReceiptItem::query()->whereIn('id', $sourceIds)->cursor() as $source) {
            if (bccomp($this->billing->creditReviewQuantity($source), '0', 4) > 0) {
                $creditReviews++;
            }
        }
        ksort($months);
        $current = ['credit_review_source_lines' => $creditReviews,
            'late_open_orders' => $this->orders($actor, $filters)->whereIn('po.status', ['ordered', 'partially_received'])->where('po.expected_delivery_at', '<', now()->toDateString())->count(),
            'awaiting_order_approval' => $this->orders($actor, $filters)->where('po.status', 'submitted')->count(),
            'awaiting_receiving' => $this->orders($actor, $filters)->whereIn('po.status', ['ordered', 'partially_received'])->count(),
            'draft_receipts' => $this->receipts($actor, $filters)->where('gr.status', 'draft')->count(),
            'unposted_returns' => $this->returns($actor, $filters)->whereIn('pr.status', ['draft', 'approved'])->count(),
            'draft_bills' => $this->bills($actor, $filters)->where('b.status', 'draft')->count(),
            'orders_without_release_date' => $this->orders($actor, $filters)->whereIn('po.status', ['ordered', 'partially_received', 'received', 'closed'])->whereNull('po.ordered_at')->count(),
            'receipts_without_received_date' => $this->receipts($actor, $filters)->where('gr.status', 'posted')->whereNull('gr.received_at')->count(),
            'returns_without_posted_date' => $this->returns($actor, $filters)->where('pr.status', 'posted')->whereNull('pr.posted_at')->count(),
            'bills_without_approved_date' => $this->bills($actor, $filters)->where('b.status', 'approved')->whereNull('b.approved_at')->count(),
            'approved_bills_without_valid_payable' => $this->bills($actor, $filters)->where('b.status', 'approved')->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('supplier_payables as ap')->whereColumn('ap.supplier_bill_id', 'b.id')->whereColumn('ap.company_id', 'b.company_id')->whereColumn('ap.supplier_id', 'b.supplier_id')->whereColumn('ap.currency_code', 'b.currency_code')->whereColumn('ap.amount', 'b.grand_total')->where('ap.paid_amount', '>=', 0)->whereColumn('ap.paid_amount', '<=', 'ap.amount')->where('ap.credited_amount', '>=', 0)->whereRaw('ap.paid_amount + ap.credited_amount <= ap.amount');
            })->count()];

        return ['counts' => $counts, 'money' => array_values($money), 'supplierSummary' => array_values($suppliers), 'months' => array_values($months), 'issues' => $issues, 'current' => $current,
            'asOf' => now()->toDateString(), 'timezone' => config('app.timezone')];
    }
}

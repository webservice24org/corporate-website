<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use App\Models\Company;
use App\Models\Customer;
use App\Models\SalesOrder;
use App\Models\SalesQuotation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SalesOrderService
{
    public function __construct(
        private readonly SalesCompanyAccess $access,
        private readonly SalesDocumentGuard $guard,
        private readonly SalesQuotationService $quotations,
        private readonly SalesOrderSourceService $source,
        private readonly SalesCreditExposureService $credit,
        private readonly SalesDocumentNumberService $numbers,
        private readonly SalesQuotationMath $math,
        private readonly SalesAuditService $audit,
    ) {}

    /** @return Builder<Company> */
    public function companies(User $actor, string $permission): Builder
    {
        return $this->quotations->companies($actor, $permission);
    }

    public function find(User $actor, int $id, string $permission = 'erp.sales-orders.view'): SalesOrder
    {
        $order = SalesOrder::query()->whereIn('company_id', $this->companies($actor, $permission)->select('id'))->findOrFail($id);
        $this->access->authorize($actor, (int) $order->getAttribute('company_id'), $permission);

        return $order;
    }

    /** @param array<string, mixed> $input */
    public function create(User $actor, array $input): SalesOrder
    {
        $data = Validator::make($input, [
            'company_id' => ['required', 'integer', 'min:1'], 'sales_quotation_id' => ['required', 'integer', 'min:1'],
            'document_date' => ['required', 'date_format:Y-m-d'], 'expected_delivery_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:document_date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        return DB::transaction(function () use ($actor, $data): SalesOrder {
            $companyId = (int) $data['company_id'];
            $company = $this->access->authorize($actor, $companyId, 'erp.sales-orders.create', true);
            $quoteId = (int) $data['sales_quotation_id'];
            // Scope and lock source even on a retry; never reveal another company's order.
            $this->quotations->find($actor, $quoteId);
            SalesQuotation::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($quoteId);
            $existing = SalesOrder::query()->where('company_id', $companyId)->where('sales_quotation_id', $quoteId)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }
            $quote = $this->source->quotation($actor, $company, $quoteId);
            $date = (string) $data['document_date'];
            if ($date < $quote->getAttribute('document_date')->format('Y-m-d') || $date > $quote->getAttribute('valid_until')->format('Y-m-d') || $date > now((string) $company->getAttribute('timezone'))->toDateString()) {
                throw ValidationException::withMessages(['document_date' => 'Order date must be within quotation validity and cannot be in the future.']);
            }
            $terms = $quote->getAttribute('terms_snapshot') ?? [];
            $terms['sales_order_source'] = ['id' => $quoteId, 'number' => $quote->getAttribute('number'), 'quotation_notes' => $quote->getAttribute('notes'), 'revision' => (int) $quote->getAttribute('revision'), 'hash' => $this->source->fingerprint($quote), 'configuration' => $this->source->configuration($quote)];
            $order = SalesOrder::query()->create([
                ...$quote->only(SalesOrderSourceService::HEADER), 'terms_snapshot' => $terms,
                'company_id' => $companyId, 'sales_quotation_id' => $quoteId, 'number' => $this->numbers->next($companyId, 'order', $date),
                'document_date' => $date, 'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'notes' => $data['notes'] ?? $quote->getAttribute('notes'), 'status' => 'draft', 'revision' => 1,
                'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ]);
            foreach ($quote->items()->orderBy('line_number')->get() as $line) {
                $order->items()->create([...$line->only(SalesOrderSourceService::LINE), 'sales_quotation_item_id' => $line->getKey(), 'line_number' => $line->getAttribute('line_number')]);
            }
            $this->audit->record($actor, $companyId, 'order', (int) $order->getKey(), 'create', [], ['sales_quotation_id' => $quoteId, 'grand_total' => $order->getAttribute('grand_total')]);

            return $order;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function update(User $actor, int $id, array $input): SalesOrder
    {
        $data = Validator::make($input, ['revision' => ['required', 'integer', 'min:1'], 'expected_delivery_date' => ['nullable', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:5000']])->validate();

        return DB::transaction(function () use ($actor, $id, $data): SalesOrder {
            $order = $this->locked($actor, $id, 'edit', (int) $data['revision']);
            $delivery = $data['expected_delivery_date'] ?? null;
            if ($delivery !== null && $delivery < $order->getAttribute('document_date')->format('Y-m-d')) {
                throw ValidationException::withMessages(['expected_delivery_date' => 'Expected delivery cannot precede the order date.']);
            }
            $before = ['revision' => $order->getAttribute('revision'), 'expected_delivery_date' => $order->getAttribute('expected_delivery_date')?->format('Y-m-d'), 'notes' => $order->getAttribute('notes')];
            $order->forceFill(['expected_delivery_date' => $delivery, 'notes' => $data['notes'] ?? null, 'revision' => (int) $data['revision'] + 1, 'updated_by' => $actor->getKey()])->save();
            $this->audit->record($actor, (int) $order->getAttribute('company_id'), 'order', $id, 'edit', $before, ['revision' => $order->getAttribute('revision'), 'expected_delivery_date' => $delivery, 'notes' => $data['notes'] ?? null]);

            return $order;
        }, 3);
    }

    public function transition(User $actor, int $id, string $action, int $revision, ?string $reason = null): SalesOrder
    {
        return DB::transaction(function () use ($actor, $id, $action, $revision, $reason): SalesOrder {
            $permission = app(SalesDomainRegistry::class)->permission('order', $action);
            $found = $this->find($actor, $id, $permission);
            $companyId = (int) $found->getAttribute('company_id');
            $company = $this->access->authorize($actor, $companyId, $permission, true);
            $order = SalesOrder::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            $override = $action === 'credit-override';
            if ($override) {
                $this->access->authorize($actor, $companyId, 'erp.sales-orders.confirm', true);
                $this->guard->assertIndependentApprover($actor, $order);
            }
            if (in_array($action, ['confirm', 'credit-override'], true) && $order->getAttribute('status') === 'confirmed') {
                return $order;
            }
            $order = $this->locked($actor, $id, $action, $revision);
            $before = ['status' => $order->getAttribute('status'), 'revision' => $revision];
            if (in_array($action, ['submit', 'approve', 'confirm', 'credit-override'], true)) {
                $quote = $this->source->quotation($actor, $company, (int) $order->getAttribute('sales_quotation_id'));
                $this->source->assertOrder($order, $quote);
            }
            if (in_array($action, ['confirm', 'credit-override'], true)) {
                if ($order->getAttribute('approved_by') === null || (int) $order->getAttribute('approved_by') === (int) $order->getAttribute('created_by')) {
                    throw ValidationException::withMessages(['status' => 'Confirmation requires a recorded independent order approval.']);
                }
                $customer = Customer::query()->where('company_id', $companyId)->where('is_active', true)->lockForUpdate()->findOrFail($order->getAttribute('customer_id'));
                $assessment = $this->credit->assess($customer, $order);
                if (! $assessment['within_limit'] && ! $override) {
                    throw ValidationException::withMessages(['credit' => 'Projected exposure '.$assessment['projected_exposure'].' exceeds customer credit limit '.$assessment['credit_limit'].' '.$assessment['currency_code'].'.']);
                }
                if ($override && ($reason === null || trim($reason) === '')) {
                    throw ValidationException::withMessages(['reason' => 'Credit override requires a reason.']);
                }
                $order->setAttribute('credit_check_snapshot', $assessment);
                $order->setAttribute('credit_override_by', $override ? $actor->getKey() : null);
                $order->setAttribute('credit_override_reason', $override ? trim((string) $reason) : null);
                $order->setAttribute('confirmed_at', now()->toDateTimeString());
            }
            if ($action === 'cancel') {
                app(SalesReservationService::class)->releaseForCancellation($actor, $order, (string) $reason);
                $this->cancelRemainder($order);
                $order->setAttribute('cancelled_at', now()->toDateTimeString());
                $order->setAttribute('cancellation_reason', $reason);
            }
            if ($action === 'close') {
                $this->assertFulfilled($order);
                $order->setAttribute('closed_at', now()->toDateTimeString());
            }
            if ($action === 'submit') {
                $order->setAttribute('submitted_at', now()->toDateTimeString());
            }
            if ($action === 'approve') {
                $order->setAttribute('approved_by', $actor->getKey());
                $order->setAttribute('approved_at', now()->toDateTimeString());
            }
            $target = match ($action) {
                'submit' => 'submitted', 'approve' => 'approved', 'reject' => 'rejected',
                'confirm', 'credit-override' => 'confirmed', 'cancel' => 'cancelled', 'close' => 'closed',
                default => throw ValidationException::withMessages(['action' => 'Unsupported order transition.']),
            };
            $order->forceFill(['status' => $target, 'revision' => $revision + 1, 'updated_by' => $actor->getKey()])->save();
            $after = ['status' => $target, 'revision' => $revision + 1];
            if ($override) {
                $this->audit->record($actor, $companyId, 'order', $id, 'credit-override', $before, ['credit' => $order->getAttribute('credit_check_snapshot')], $reason);
            }
            $this->audit->record($actor, $companyId, 'order', $id, $override ? 'confirm' : $action, $before, [...$after, 'credit' => $order->getAttribute('credit_check_snapshot')], $reason);

            return $order;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function creditPreview(User $actor, int $id): array
    {
        return DB::transaction(function () use ($actor, $id): array {
            $found = $this->find($actor, $id, 'erp.sales-orders.confirm');
            $order = $this->locked($actor, $id, 'confirm', (int) $found->getAttribute('revision'));
            $companyId = (int) $order->getAttribute('company_id');
            $company = $this->access->authorize($actor, $companyId, 'erp.sales-orders.confirm', true);
            $quote = $this->source->quotation($actor, $company, (int) $order->getAttribute('sales_quotation_id'));
            $this->source->assertOrder($order, $quote);
            $customer = Customer::query()->where('company_id', $companyId)->where('is_active', true)->lockForUpdate()->findOrFail($order->getAttribute('customer_id'));

            return $this->credit->assess($customer, $order);
        }, 3);
    }

    private function locked(User $actor, int $id, string $action, int $revision): SalesOrder
    {
        $found = $this->find($actor, $id, app(SalesDomainRegistry::class)->permission('order', $action));
        $document = $this->guard->lockForAction($actor, (int) $found->getAttribute('company_id'), 'order', $id, $action);
        if (! $document instanceof SalesOrder) {
            throw new \LogicException('Unexpected Sales order model.');
        }
        $this->guard->assertRevision($document, $revision);

        return $document;
    }

    private function cancelRemainder(SalesOrder $order): void
    {
        $lines = $order->items()->orderBy('id')->lockForUpdate()->get();
        $ids = $lines->modelKeys();
        $reservations = DB::table('sales_stock_reservations')->whereIn('sales_order_item_id', $ids)->lockForUpdate()->get();
        foreach ($reservations as $reservation) {
            if (bccomp(bcsub(bcsub((string) $reservation->allocated_quantity, (string) $reservation->released_quantity, 4), (string) $reservation->consumed_quantity, 4), '0', 4) !== 0) {
                throw ValidationException::withMessages(['status' => 'Release active reservations through the reservation workflow before cancelling.']);
            }
        }
        $remaining = 0;
        foreach ($lines as $line) {
            $qty = $this->math->scaled((string) $line->getAttribute('quantity'), 10000);
            $delivered = $this->math->scaled((string) $line->getAttribute('delivered_quantity'), 10000);
            $cancelled = $this->math->scaled((string) $line->getAttribute('cancelled_quantity'), 10000);
            $remaining += $qty - $delivered - $cancelled;
            $line->setAttribute('cancelled_quantity', $this->math->format($qty - $delivered, 4));
            $line->save();
        }
        if ($remaining <= 0) {
            throw ValidationException::withMessages(['status' => 'Order has no unfulfilled remainder to cancel.']);
        }
    }

    private function assertFulfilled(SalesOrder $order): void
    {
        if ($order->getAttribute('confirmed_at') === null) {
            throw ValidationException::withMessages(['status' => 'Only confirmed fulfillment can be closed.']);
        }
        $delivered = 0;
        $lines = $order->items()->lockForUpdate()->get();
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Order has no fulfillment lines.']);
        }
        foreach ($lines as $line) {
            $qty = $this->math->scaled((string) $line->getAttribute('quantity'), 10000);
            $done = $this->math->scaled((string) $line->getAttribute('delivered_quantity'), 10000);
            $cancelled = $this->math->scaled((string) $line->getAttribute('cancelled_quantity'), 10000);
            if ($done + $cancelled !== $qty) {
                throw ValidationException::withMessages(['status' => 'Fulfill or cancel every remaining quantity before closing.']);
            }
            $delivered += $done;
        }
        $reservations = DB::table('sales_stock_reservations')->whereIn('sales_order_item_id', $lines->modelKeys())->lockForUpdate()->get();
        foreach ($reservations as $reservation) {
            if (bccomp(bcsub(bcsub((string) $reservation->allocated_quantity, (string) $reservation->released_quantity, 4), (string) $reservation->consumed_quantity, 4), '0', 4) !== 0) {
                throw ValidationException::withMessages(['status' => 'Reservation counters must be fully released or consumed before closing.']);
            }
        }
        if ($delivered === 0) {
            throw ValidationException::withMessages(['status' => 'A fully cancelled order has no fulfillment to close.']);
        }
    }
}

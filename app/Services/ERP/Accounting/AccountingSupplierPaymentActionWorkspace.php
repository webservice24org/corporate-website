<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\SupplierPaymentAuditLog;
use App\Models\SupplierPaymentReversalRequest;
use App\Models\SupplierPaymentTarget;
use App\Models\User;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentLedger;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentOperations;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentPosting;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentReversalService;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Safe read projections only. Source services still authorize and re-verify every write under locks. */
final class AccountingSupplierPaymentActionWorkspace
{
    public function __construct(private readonly AccountingSupplierPaymentWorkspace $workspace, private readonly AccountingSupplierPaymentService $payments, private readonly AccountingSupplierPaymentReversalService $reversals, private readonly AccountingSupplierPaymentPosting $posting, private readonly AccountingSupplierPaymentOperations $operations, private readonly AccountingSupplierPaymentLedger $ledger) {}

    /** @return array<string, mixed> */
    public function detail(User $actor, int $id): array
    {
        $found = $this->payments->find($actor, $id);
        $companyId = (int) $found->getAttribute('company_id');

        return DB::transaction(function () use ($actor, $id, $companyId): array {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $shown = $this->payments->find($actor, $id);
            $rootId = (int) ($shown->getAttribute('reverses_payment_id') ?? $id);
            $root = SupplierPayment::query()->where('company_id', $companyId)->findOrFail($rootId);
            $context = $this->workspace->context($actor, $companyId);
            $linked = SupplierPayment::query()->where('company_id', $companyId)->where('reverses_payment_id', $rootId)->first();
            $reviews = SupplierPaymentReversalRequest::query()->where('company_id', $companyId)->where('supplier_payment_id', $rootId)->orderByDesc('id')->get();
            $pending = $reviews->first(static fn (SupplierPaymentReversalRequest $review): bool => in_array($review->getAttribute('status'), ['draft', 'submitted', 'approved'], true));
            $issues = [];
            $position = null;
            $verified = false;
            if ($root->getAttribute('status') === 'posted') {
                try {
                    $this->posting->verify($companyId, $rootId);
                    $position = $this->operations->position($root);
                    $billIds = array_unique(array_column($this->operations->history($root), 'supplier_bill_id'));
                    sort($billIds, SORT_NUMERIC);
                    foreach ($billIds as $billId) {
                        $this->balance($root, $billId);
                    } $verified = true;
                } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
                    $issues[] = 'Payment finance or payable history could not be verified. Review source reconciliation before making a new allocation or reversal.';
                    $position = null;
                }
            }
            $allocationRows = SupplierPaymentAllocation::query()->where('company_id', $companyId)->where('supplier_payment_id', $rootId)->orderBy('id')->get();
            $compensated = [];
            foreach ($allocationRows as $row) {
                if ($row->getAttribute('reverses_allocation_id') !== null) {
                    $compensated[(int) $row->getAttribute('reverses_allocation_id')] = true;
                }
            }
            $allocations = $allocationRows->map(static fn (SupplierPaymentAllocation $row): array => ['id' => (int) $row->getKey(), 'supplier_bill_id' => (int) $row->getAttribute('supplier_bill_id'), 'amount' => (string) $row->getAttribute('amount'), 'kind' => $row->getAttribute('reverses_allocation_id') === null ? 'allocation' : 'compensation', 'original_allocation_id' => $row->getAttribute('reverses_allocation_id') === null ? null : (int) $row->getAttribute('reverses_allocation_id'), 'is_active' => $row->getAttribute('reverses_allocation_id') === null && ! isset($compensated[(int) $row->getKey()]), 'posted_at' => self::time($row->getAttribute('posted_at'))])->all();
            $billNames = SupplierBill::query()->where('company_id', $companyId)->where('supplier_id', $root->getAttribute('supplier_id'))->whereIn('id', array_unique([...$allocationRows->pluck('supplier_bill_id')->all(), ...SupplierPaymentTarget::query()->where('company_id', $companyId)->where('supplier_payment_id', $rootId)->pluck('supplier_bill_id')->all()]))->pluck('number', 'id')->all();
            $originalShown = $shown->getAttribute('reverses_payment_id') === null;
            $eligible = $originalShown && $verified && $linked === null && $pending === null && $context['writable'];
            $activeCount = $position === null ? 0 : count($position['active']);
            $capabilities = ['allocate' => $eligible && $context['postingEnabled'] && $actor->can('erp.supplier-payments.allocate'), 'prepareReversal' => $eligible && $actor->can('erp.supplier-payments.reverse') && $activeCount <= 200];
            if ($eligible && $actor->can('erp.supplier-payments.reverse') && $activeCount > 200) {
                $issues[] = 'This payment exceeds the installed full-reversal limit of 200 active allocations. A partial refund is not permitted.';
            }
            $cashIds = [$rootId];
            if ($linked !== null) {
                $cashIds[] = (int) $linked->getKey();
            }
            $effects = AccountingSourceEffect::query()->where('company_id', $companyId)->where(function ($query) use ($cashIds, $allocationRows): void {
                $query->where(fn ($cash) => $cash->where('source_kind', 'supplier_payment')->whereIn('source_id', $cashIds))->orWhere(fn ($allocation) => $allocation->where('source_kind', 'supplier_payment_allocation')->whereIn('source_id', $allocationRows->pluck('id')));
            })->orderBy('id')->get();
            $journals = $actor->can('erp.journal-entries.view') ? JournalEntry::query()->where('company_id', $companyId)->whereIn('id', $effects->pluck('journal_entry_id')->filter())->pluck('id')->all() : [];
            $finance = $effects->map(static fn (AccountingSourceEffect $effect): array => ['id' => (int) $effect->getKey(), 'source_kind' => (string) $effect->getAttribute('source_kind'), 'source_id' => (int) $effect->getAttribute('source_id'), 'event' => (string) $effect->getAttribute('event_kind'), 'effect' => (string) $effect->getAttribute('effect_kind'), 'outcome' => (string) $effect->getAttribute('outcome'), 'date' => $effect->getAttribute('accounting_date')->format('Y-m-d'), 'journal_url' => in_array((int) $effect->getAttribute('journal_entry_id'), $journals, true) ? '/erp/accounting/journals/'.(int) $effect->getAttribute('journal_entry_id') : null])->all();
            $history = SupplierPaymentAuditLog::query()->where('company_id', $companyId)->where('supplier_payment_id', $rootId)->orderByDesc('id')->limit(100)->get()->map(static fn (SupplierPaymentAuditLog $row): array => ['id' => (int) $row->getKey(), 'action' => (string) $row->getAttribute('action'), 'actor_id' => (int) $row->getAttribute('actor_id'), 'reason' => $row->getAttribute('reason'), 'created_at' => self::time($row->getAttribute('created_at'))])->all();

            return [...$context, 'actionCapabilities' => $capabilities, 'financeIssues' => $issues, 'financeVerified' => $verified, 'position' => $position === null ? null : ['revision' => $position['root']['revision'], 'history_hash' => $position['history_hash'], 'allocated' => $position['allocated'], 'available' => $linked === null ? $position['available'] : '0.00', 'cash_reversed' => $linked !== null, 'history_count' => $position['history_count']], 'originalPayment' => $shown->getAttribute('reverses_payment_id') === null ? null : $this->workspace->summary($root), 'linkedReversal' => $linked === null ? null : $this->workspace->summary($linked), 'pendingReversalId' => $pending === null ? null : (int) $pending->getKey(), 'reversalRequests' => $reviews->map(fn (SupplierPaymentReversalRequest $review): array => $this->review($review))->all(), 'allocations' => $allocations, 'billNames' => (object) $billNames, 'finance' => $finance, 'history' => $history];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function allocation(User $actor, int $id): array
    {
        $root = $this->payments->find($actor, $id, 'erp.supplier-payments.allocate');
        $base = $this->workspace->detail($actor, $id);
        $detail = $this->detail($actor, $id);
        $data = [...$base, ...$detail];
        if (! $detail['actionCapabilities']['allocate']) {
            return [...$data, 'bills' => [], 'operationKey' => null];
        }
        $options = $this->workspace->options($actor, (int) $root->getAttribute('company_id'), (int) $root->getAttribute('supplier_id'));
        $bills = [];
        DB::transaction(function () use ($root, $options, &$bills): void {
            Company::query()->whereKey($root->getAttribute('company_id'))->lockForUpdate()->firstOrFail();
            foreach ($options['bills'] as $bill) {
                try {
                    $balance = $this->balance($root, $bill['id']);
                    $bills[] = [...$bill, 'paid' => $balance['paid'], 'credited' => $balance['credited'], 'outstanding' => $balance['outstanding'], 'selectable' => true, 'issue' => null];
                } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
                    $bills[] = [...$bill, 'selectable' => false, 'issue' => 'Recognized AP or payable history needs review.'];
                }
            }
        });

        return [...$data, 'bills' => $bills, 'operationKey' => (string) Str::uuid()];
    }

    /** @return array<string, mixed> */
    public function reversal(User $actor, int $id): array
    {
        $row = $this->reversals->find($actor, $id, 'erp.supplier-payments.view');
        $rootId = (int) $row->getAttribute('supplier_payment_id');
        $base = $this->workspace->detail($actor, $rootId);
        $data = [...$base, ...$this->detail($actor, $rootId)];
        $status = (string) $row->getAttribute('status');
        $owner = in_array((int) $actor->getKey(), [(int) $row->getAttribute('created_by'), (int) $row->getAttribute('last_edited_by')], true);
        $dateCurrent = $row->getAttribute('document_date')->format('Y-m-d') === $data['today'];
        $writable = (bool) $data['writable'];
        $verified = (bool) $data['financeVerified'];
        $cap = ['edit' => $writable && $verified && $status === 'draft' && $actor->can('erp.supplier-payments.reverse'), 'submit' => $writable && $verified && $dateCurrent && $status === 'draft' && $actor->can('erp.supplier-payments.reverse'), 'approve' => $writable && $verified && $dateCurrent && $status === 'submitted' && ! $owner && $actor->can('erp.supplier-payments.approve'), 'reject' => $writable && $status === 'submitted' && $actor->can('erp.supplier-payments.approve'), 'cancel' => $writable && in_array($status, ['draft', 'submitted', 'approved'], true) && $actor->can('erp.supplier-payments.reverse'), 'post' => $data['postingEnabled'] && $verified && $dateCurrent && $status === 'approved' && $actor->can('erp.supplier-payments.reverse')];

        return [...$data, 'reversalRequest' => $this->review($row), 'reversalCapabilities' => $cap, 'reversalDateCurrent' => $dateCurrent, 'reversalPostingKey' => $cap['post'] ? (string) Str::uuid() : null];
    }

    /** @return array<string, mixed> */
    private function review(SupplierPaymentReversalRequest $row): array
    {
        $data = ['id' => (int) $row->getKey(), 'company_id' => (int) $row->getAttribute('company_id'), 'supplier_payment_id' => (int) $row->getAttribute('supplier_payment_id'), 'revision' => (int) $row->getAttribute('revision'), 'status' => (string) $row->getAttribute('status'), 'document_date' => $row->getAttribute('document_date')->format('Y-m-d'), 'reason' => (string) $row->getAttribute('reason'), 'cancellation_reason' => $row->getAttribute('cancellation_reason'), 'reversal_payment_id' => $row->getAttribute('reversal_payment_id') === null ? null : (int) $row->getAttribute('reversal_payment_id')];
        foreach (['created_by', 'last_edited_by', 'submitted_by', 'approved_by', 'posted_by', 'cancelled_by'] as $field) {
            $data[$field] = $row->getAttribute($field) === null ? null : (int) $row->getAttribute($field);
        }
        foreach (['submitted_at', 'approved_at', 'posted_at', 'cancelled_at'] as $field) {
            $data[$field] = self::time($row->getAttribute($field));
        }

        return $data;
    }

    /** @return array{payable_id: int, amount: string, paid: string, credited: string, outstanding: string} */
    private function balance(SupplierPayment $root, int $bill): array
    {
        return $this->ledger->bill((int) $root->getAttribute('company_id'), (int) $root->getAttribute('supplier_id'), (string) $root->getAttribute('currency_code'), $bill, true);
    }

    private static function time(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : ($value === null ? null : (string) $value);
    }
}

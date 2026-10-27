<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source;

use App\Models\Accounting\AccountingCutover;
use App\Models\Accounting\AccountingCutoverSource;
use App\Models\Accounting\AccountingPurchaseEvent;
use App\Models\Accounting\AccountingSalesEvent;
use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingCompanyAccess;
use App\Services\ERP\Accounting\Adjustments\AccountingBankAdjustmentEvidence;
use App\Services\ERP\Accounting\Banking\AccountingBankingWorkspace;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseFinanceEvidence;
use App\Services\ERP\Accounting\Source\Purchase\AccountingPurchaseEvidence;
use App\Services\ERP\Accounting\Source\Sales\AccountingReceiptEvidence;
use App\Services\ERP\Accounting\Source\Sales\AccountingSalesEvidence;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferReversalEvidence;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Authorized, bounded, read-only verification. No retry/post/activate/repair operation is reachable from here. */
final class AccountingSourceReconciliation
{
    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingReconciliationCatalog $catalog, private readonly AccountingSourceEventRegistry $registry, private readonly AccountingPurchaseEvidence $purchase, private readonly AccountingSalesEvidence $sales, private readonly AccountingReceiptEvidence $receipts, private readonly AccountingReceiptManifestReader $manifests, private readonly AccountingBusinessDate $dates) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function page(User $actor, int $companyId, array $input = []): array
    {
        $this->access->authorize($actor, $companyId, 'erp.accounting-reports.view');
        $data = Validator::make($input, ['kind' => ['nullable', Rule::in(array_keys($this->catalog->definitions()))], 'after_kind' => ['nullable', 'required_with:after_id', 'regex:/\A[A-Za-z0-9_-]{1,40}\z/'], 'after_id' => ['nullable', 'required_with:after_kind', 'integer', 'min:1'], 'limit' => ['nullable', 'integer', 'min:1', 'max:50']])->validate();

        return DB::transaction(function () use ($actor, $companyId, $data): array {
            // Native writers already serialize on company. This short bounded read uses the same ordering.
            $company = $this->access->authorize($actor, $companyId, 'erp.accounting-reports.view');
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $settings = AccountingSetting::query()->where('company_id', $companyId)->first();
            $plan = AccountingCutover::query()->where('company_id', $companyId)->where('status', '<>', 'aborted')->orderByDesc('id')->first();
            $state = $settings === null ? 'unconfigured' : (string) $settings->getAttribute('state');
            $activation = $this->date($settings?->getAttribute('activation_date'));
            $cutover = $this->date($plan?->getAttribute('cutover_date'));
            $boundary = $activation ?? $cutover;
            $query = $this->candidates($companyId);
            $kind = empty($data['kind']) ? null : (string) $data['kind'];
            if ($kind !== null) {
                $query->where('source_kind', $kind);
            }
            $total = (clone $query)->count();
            if (! empty($data['after_kind'])) {
                $query->where(function (Builder $q) use ($data): void {
                    $q->where('source_kind', '>', $data['after_kind'])->orWhere(fn (Builder $same) => $same->where('source_kind', $data['after_kind'])->where('source_id', '>', (int) $data['after_id']));
                });
            }
            $limit = isset($data['limit']) ? (int) $data['limit'] : 25;
            $candidates = $query->orderBy('source_kind')->orderBy('source_id')->limit($limit + 1)->get();
            $more = $candidates->count() > $limit;
            $rows = [];
            $counts = [];
            foreach ($candidates->take($limit) as $candidate) {
                $row = $this->inspect($actor, $company, (string) $candidate->source_kind, (int) $candidate->source_id, $state, $boundary);
                $rows[] = $row;
                $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
            }
            $last = $rows === [] ? null : $rows[count($rows) - 1];

            return ['company_id' => $companyId, 'currency' => (string) $company->getAttribute('currency_code'), 'accounting_state' => $state, 'activation_date' => $activation, 'cutover_date' => $cutover, 'cutover_status' => $plan?->getAttribute('status'), 'observed_at' => CarbonImmutable::now('UTC')->toIso8601String(), 'total_candidates' => $total, 'page_counts' => $counts, 'rows' => $rows, 'has_more' => $more, 'next' => $more && $last !== null ? ['after_kind' => $last['source_kind'], 'after_id' => $last['source_id']] : null, 'filter_kind' => $kind, 'limit' => $limit];
        });
    }

    private function candidates(int $company): Builder
    {
        $union = DB::table('accounting_source_effects')->where('company_id', $company)->select('source_kind', 'source_id');
        foreach (['accounting_sales_events', 'accounting_purchase_events'] as $table) {
            $union->unionAll(DB::table($table)->where('company_id', $company)->select('source_kind', 'source_id'));
        }
        foreach ($this->catalog->definitions() as $kind => $definition) {
            $class = $definition['model'];
            $model = new $class;
            $q = DB::table($model->getTable())->where('company_id', $company)->selectRaw('? as source_kind, id as source_id', [$kind]);
            if ($definition['status'] !== null) {
                $q->where(fn (Builder $native) => $native->where('status', $definition['status'])->orWhereNotNull($definition['stamp']));
            }
            $union->unionAll($q);
        }
        // Source observations in active preparation plans are candidates, never recognition authority.
        $union->unionAll(DB::table('accounting_cutover_sources')->where('company_id', $company)->whereIn('source_kind', array_keys($this->catalog->definitions()))->whereIn('cutover_id', AccountingCutover::query()->where('company_id', $company)->where('status', '<>', 'aborted')->select('id'))->select('source_kind', 'source_id'));
        $distinct = DB::query()->fromSub($union, 'candidate_union')->select('source_kind', 'source_id')->distinct();

        return DB::query()->fromSub($distinct, 'sources')->select('source_kind', 'source_id');
    }

    /** @return array<string, mixed> */
    private function inspect(User $actor, Company $company, string $kind, int $id, string $state, ?string $boundary): array
    {
        $companyId = (int) $company->getKey();
        $definition = $this->catalog->definitions()[$kind] ?? null;
        $effects = AccountingSourceEffect::query()->where('company_id', $companyId)->where('source_kind', $kind)->where('source_id', $id)->orderBy('effect_kind')->get();
        $salesAdmissions = AccountingSalesEvent::query()->where('company_id', $companyId)->where('source_kind', $kind)->where('source_id', $id)->count();
        $purchaseAdmissions = AccountingPurchaseEvent::query()->where('company_id', $companyId)->where('source_kind', $kind)->where('source_id', $id)->count();
        $coverage = AccountingCutoverSource::query()->where('company_id', $companyId)->where('source_kind', $kind)->where('source_id', $id)->whereIn('cutover_id', AccountingCutover::query()->where('company_id', $companyId)->where('status', '<>', 'aborted')->select('id'))->get();
        $declared = false;
        foreach ($coverage as $observation) {
            $opening = $observation->getAttribute('opening_state');
            if (is_array($opening) && ($opening['recognition_state'] ?? null) === 'recognized') {
                $declared = true;
            }
        }
        $journals = [];
        foreach ($effects as $effect) {
            $journalId = $effect->getAttribute('journal_entry_id');
            $journals[] = ['effect_kind' => (string) $effect->getAttribute('effect_kind'), 'event_kind' => (string) $effect->getAttribute('event_kind'), 'outcome' => (string) $effect->getAttribute('outcome'), 'accounting_date' => $this->date($effect->getAttribute('accounting_date')), 'journal_id' => $journalId === null ? null : (int) $journalId, 'url' => $journalId !== null && $actor->can('erp.journal-entries.view') ? '/erp/accounting/journals/'.(int) $journalId : null];
        }
        $result = ['source_kind' => $kind, 'source_id' => $id, 'number' => '#'.$id, 'native_state' => null, 'source_date' => null, 'currency' => null, 'document_amount' => null, 'source_url' => null, 'coverage_observed' => $coverage->isNotEmpty(), 'coverage_declared' => $declared, 'admission_count' => $salesAdmissions + $purchaseAdmissions, 'effects' => $journals, 'status' => 'unsupported_source', 'message' => 'This source contract is not installed in this workspace.', 'correction' => $this->correction($kind)];
        if ($definition === null) {
            return $result;
        }
        $class = $definition['model'];
        $header = $class::query()->withoutGlobalScope(SoftDeletingScope::class)->where('company_id', $companyId)->find($id);
        if ($header === null) {
            return $this->result($result, 'missing_source', 'Financial or cutover evidence points to a missing source in this company.');
        }
        $stamp = $header->getAttribute($definition['stamp']);
        $final = ($definition['status'] === null || $header->getAttribute('status') === $definition['status']) && $stamp instanceof DateTimeInterface;
        $result['number'] = (string) ($header->getAttribute('number') ?? $header->getAttribute('document_number') ?? '#'.$id);
        $result['native_state'] = $definition['status'] === null ? ($final ? 'posted' : 'incomplete') : $header->getAttribute('status');
        $sourceInstant = $kind === 'goods_receipt' ? $header->getAttribute('received_at') : $stamp;
        $result['source_date'] = $definition['date'] === null ? ($sourceInstant instanceof DateTimeInterface ? $this->dates->fromInstant($company, CarbonImmutable::instance($sourceInstant)) : null) : $this->date($header->getAttribute($definition['date']));
        $result['currency'] = $this->currency($company, $kind, $header);
        $result['document_amount'] = $definition['amount'] === null ? null : (string) $header->getAttribute($definition['amount']);
        if ($definition['permission'] !== null && $definition['path'] !== null && $actor->can($definition['permission']) && ($kind !== 'bank_adjustment' || AccountingBankingWorkspace::mayRead($actor, 'adjustments')) && $header->getAttribute('deleted_at') === null) {
            $result['source_url'] = $definition['path'].$id.'?company_id='.$companyId;
        }
        if (! $final) {
            return $this->result($result, 'source_state_conflict', 'The source is not in its required final state or lacks its final timestamp.');
        }
        if ($result['currency'] !== $company->getAttribute('currency_code')) {
            return $this->result($result, 'currency_review', 'Source currency or its linked parent needs review before supported currency/cutover treatment can be established.');
        }
        if ($kind === 'expense_finance') {
            if ($declared || $salesAdmissions !== 0 || $purchaseAdmissions !== 0) {
                return $this->result($result, 'coverage_conflict', 'Expense finance uses its own native recognition/settlement manifests.');
            }
            try {
                $reader = app(AccountingExpenseFinanceEvidence::class);
                $proof = $reader->verify($companyId, $id);
                $reader->balance($companyId, (int) $proof['plan']->getAttribute('expense_id'));
            } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
                return $this->result($result, 'integrity_error', 'Expense approval, operation, payable and journal evidence could not be verified.');
            }

            return $this->result($result, 'verified_live', 'Expense recognition or settlement verifies against its approved native instruction and exact journal.');
        }
        if (in_array($kind, ['cash_transfer', 'cash_transfer_reversal', 'bank_adjustment'], true)) {
            if ($declared || $salesAdmissions !== 0 || $purchaseAdmissions !== 0) {
                return $this->result($result, 'coverage_conflict', 'A bank adjustment/transfer must use its own native operation, without sales/purchase admissions or opening coverage.');
            }
            try {
                if ($kind === 'bank_adjustment') {
                    app(AccountingBankAdjustmentEvidence::class)->family($companyId, $id);
                } elseif ($kind === 'cash_transfer') {
                    app(AccountingCashTransferReversalEvidence::class)->verifyForOriginal($companyId, $id);
                } else {
                    app(AccountingCashTransferReversalEvidence::class)->verify($companyId, $id);
                }
            } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
                return $this->result($result, 'integrity_error', 'Native bank/transfer operation and its complete two-leg journal could not be verified.');
            }

            return $this->result($result, 'verified_live', 'Native transfer approval, complete operation and both cash legs verify without income or expense.');
        }
        $admissions = $salesAdmissions + $purchaseAdmissions;
        if ($admissions === 0 && $effects->isEmpty()) {
            if ($declared) {
                if ($kind === 'sales_invoice') {
                    try {
                        $proof = app(Sales\AccountingLegacyReceivableCoverage::class)->verify($company, $id);
                        $result['coverage_opening_journal'] = ['id' => $proof['opening_journal_id'], 'url' => $actor->can('erp.journal-entries.view') ? '/erp/accounting/journals/'.$proof['opening_journal_id'] : null];

                        return $this->result($result, 'verified_opening_ar', 'Legacy cutover AR verifies against its source-linked posted opening. This does not assert original stock/COGS coverage.');
                    } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) { /* Keep unverified declarations in review. */
                    }
                }

                return $this->result($result, 'coverage_review', 'Coverage is declared, but a trusted coverage-aware adapter must verify its original identity and balances.');
            }
            if ($coverage->isNotEmpty() || ($boundary !== null && $result['source_date'] !== null && $result['source_date'] < $boundary)) {
                return $this->result($result, 'legacy_pending', 'Legacy source needs reconciled opening coverage. A prepared observation is not recognized finance.');
            }
            if (in_array($state, ['unconfigured', 'disabled', 'ready'], true)) {
                return $this->result($result, 'operational_only', 'No live accounting evidence exists; this is consistent with pre-activation operational use and still needs cutover review.');
            }

            return $this->result($result, 'missing_finance', 'Final source has no required live financial evidence. Reconcile it; ordinary retries must not backfill history.');
        }
        if ($declared) {
            return $this->result($result, 'coverage_conflict', 'Both live recognition and declared opening coverage exist. Check for duplicate recognition.');
        }
        $expectedFamilyCount = in_array($definition['family'], ['purchase', 'supplier_payment'], true) ? $purchaseAdmissions : $salesAdmissions;
        if ($admissions !== 1 || $expectedFamilyCount !== 1 || $effects->isEmpty()) {
            return $this->result($result, 'incomplete_finance', 'Admission and complete required financial effects are missing or conflicting.');
        }
        try {
            $event = $this->event($kind, $header);
            $required = array_keys($this->registry->definition($kind, $event));
            $actual = [];
            foreach ($effects as $effect) {
                if ($effect->getAttribute('event_token') !== 'original' || $effect->getAttribute('event_kind') !== $event) {
                    throw ValidationException::withMessages(['identity' => 'Event conflict.']);
                } $actual[] = (string) $effect->getAttribute('effect_kind');
            }
            sort($required);
            sort($actual);
            if ($required !== $actual) {
                return $this->result($result, 'incomplete_finance', 'The required financial effect bundle is incomplete or has extra members.');
            }
            if ($definition['family'] === 'purchase') {
                $this->purchase->recognized($companyId, $kind, $id);
            } elseif ($definition['family'] === 'supplier_payment') {
                $paymentId = $kind === 'supplier_payment' ? (int) ($header->getAttribute('reverses_payment_id') ?? $id) : (int) $header->getAttribute('supplier_payment_id');
                app(Purchase\AccountingSupplierPaymentPosting::class)->verify($companyId, $paymentId);
            } elseif ($definition['family'] === 'receipt') {
                $this->receipts->recognized($companyId, $kind, $id);
                $receiptId = $kind === 'customer_receipt' ? $id : (int) $header->getAttribute('customer_receipt_id');
                $this->manifests->verify($companyId, $receiptId);
            } else {
                $this->sales->recognized($companyId, $kind, $id);
            }
        } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
            // Do not disclose raw SQL, private snapshots, file paths or original exception payloads.
            return $this->result($result, 'integrity_error', 'Stored source, dependency, manifest, mapping or journal evidence could not be verified. Review the original records before any correction.');
        }

        return $this->result($result, 'verified_live', 'Installed source evidence, required effects and linked journals or registered zero-GL outcomes verify.');
    }

    private function currency(Company $company, string $kind, Model $header): ?string
    {
        $native = $header->getAttribute('currency_code');
        if (is_string($native) && $native !== '') {
            return $native;
        }
        $companyId = (int) $company->getKey();
        $currency = match ($kind) {
            'stock_transfer' => $company->getAttribute('currency_code'),
            'goods_receipt' => DB::table('purchase_orders')->where('company_id', $companyId)->where('id', $header->getAttribute('purchase_order_id'))->value('currency_code'),
            'purchase_return' => DB::table('goods_receipts as r')->join('purchase_orders as o', 'o.id', '=', 'r.purchase_order_id')->where('r.company_id', $companyId)->where('o.company_id', $companyId)->where('r.id', $header->getAttribute('goods_receipt_id'))->value('o.currency_code'),
            'supplier_payment_allocation' => DB::table('supplier_payments')->where('company_id', $companyId)->where('id', $header->getAttribute('supplier_payment_id'))->value('currency_code'),
            'customer_receipt_allocation' => DB::table('customer_receipts')->where('company_id', $companyId)->where('id', $header->getAttribute('customer_receipt_id'))->value('currency_code'),
            'customer_credit_allocation' => DB::table('sales_invoices')->where('company_id', $companyId)->where('id', $header->getAttribute('source_sales_invoice_id'))->value('currency_code'),
            default => null,
        };

        return is_string($currency) && $currency !== '' ? $currency : null;
    }

    private function event(string $kind, Model $source): string
    {
        return match ($kind) {
            'supplier_bill' => 'approved', 'supplier_credit', 'sales_invoice', 'sales_credit_note' => 'issued',
            'supplier_payment_allocation', 'customer_receipt_allocation', 'customer_credit_allocation' => $source->getAttribute('reverses_allocation_id') === null ? 'created' : 'reversed',
            'supplier_payment' => $source->getAttribute('reverses_payment_id') === null ? 'posted' : 'reversed',
            'customer_receipt' => $source->getAttribute('reverses_receipt_id') === null ? 'posted' : 'reversed',
            default => 'posted',
        };
    }

    private function correction(string $kind): string
    {
        return match ($kind) {
            'bank_adjustment' => 'Use the independently approved native bank adjustment refund workflow and its opposite statement movement; matching itself creates no journal.',
            'cash_transfer', 'cash_transfer_reversal' => 'Use the authorized native transfer reversal workflow; do not manually reverse automatic transfer journals.',
            'sales_delivery', 'sales_invoice', 'sales_return', 'sales_credit_note' => 'Use the native linked return/credit workflow. Physical and commercial corrections remain separate; do not reverse an automatic journal manually.',
            'supplier_payment', 'supplier_payment_allocation' => 'Initial payment, later allocation/compensation and approved full reversal batches are verified. Use the linked native workflow; do not manually reverse automatic payment journals.',
            'customer_receipt', 'customer_receipt_allocation' => 'Use native allocation compensation or full receipt reversal on an eligible open accounting date. Resolve spent-credit dependencies first.',
            'customer_credit_allocation' => 'Use the native credit-allocation compensation. It must preserve the original endpoints and amount without a second cash or revenue effect.',
            'goods_receipt', 'supplier_bill', 'purchase_return', 'supplier_credit' => 'Use the linked purchase return and approved supplier-credit workflow; physical return alone does not reduce billed AP.',
            default => 'Reconcile the original source and its period/cutover evidence before changing financial history.',
        };
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function result(array $row, string $status, string $message): array
    {
        return [...$row, 'status' => $status, 'message' => $message];
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : null;
    }
}

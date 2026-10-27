<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Books;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingBankAdjustment;
use App\Models\Accounting\AccountingBankAdjustmentOperation;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingCashTransfer;
use App\Models\Accounting\AccountingCashTransferReversalOperation;
use App\Models\Accounting\AccountingCashTransferReversalRequest;
use App\Models\Accounting\AccountingExpenseFinancePlan;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use App\Models\Company;
use App\Models\CustomerReceipt;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingDecimal;
use App\Services\ERP\Accounting\AccountingMappingCatalog;
use App\Services\ERP\Accounting\Adjustments\AccountingBankAdjustmentEvidence;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseFinanceEvidence;
use App\Services\ERP\Accounting\Transfers\AccountingCashTransferReversalEvidence;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Posted GL and supported-source verification are distinct read projections. Never posts or repairs. */
final class AccountingCashBookReader
{
    public const PERMISSION = 'erp.cash-books.view';

    public const EXPORT_PERMISSION = 'erp.cash-books.export';

    public function __construct(
        private readonly PurchaseCompanyAccess $access,
        private readonly AccountingBusinessDate $dates,
        private readonly AccountingDecimal $decimal,
        private readonly AccountingMappingCatalog $catalog,
        private readonly AccountingCashBookContract $contract,
        private readonly AccountingCashBookSourceEvidence $sources,
    ) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function book(User $actor, int $companyId, int $bindingId, array $input = []): array
    {
        return $this->projection($actor, $companyId, $bindingId, $input, false);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function bookExport(User $actor, int $companyId, int $bindingId, array $input = []): array
    {
        return $this->projection($actor, $companyId, $bindingId, $input, true);
    }

    /** Complete matching input for a separately authorized native bank workspace, not a download endpoint.
     * @param array<string, mixed> $input @return array<string, mixed> */
    public function reconciliationBook(User $actor, int $companyId, int $bindingId, array $input = []): array
    {
        $this->access->authorize($actor, $companyId, 'erp.bank-reconciliations.view');
        $this->access->authorize($actor, $companyId, self::PERMISSION);
        AccountingCashAccount::query()->where('company_id', $companyId)->where('kind', 'bank')->findOrFail($bindingId);

        return $this->projection($actor, $companyId, $bindingId, $input, true, false);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function projection(User $actor, int $companyId, int $bindingId, array $input, bool $allRows, bool $download = true): array
    {
        $this->access->authorize($actor, $companyId, self::PERMISSION);

        return DB::transaction(function () use ($actor, $companyId, $bindingId, $input, $allRows, $download): array {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $companyId, self::PERMISSION);
            if (! $download) {
                $this->access->authorize($actor, $companyId, 'erp.bank-reconciliations.view');
            }
            if ($allRows && $download) {
                $this->access->authorize($actor, $companyId, self::EXPORT_PERMISSION);
            }
            $binding = AccountingCashAccount::query()->where('company_id', $companyId)->findOrFail($bindingId);
            $account = Account::query()->where('company_id', $companyId)->findOrFail((int) $binding->getAttribute('account_id'));
            if ($binding->getAttribute('currency_code') !== $company->getAttribute('currency_code')) {
                $this->fail('The binding currency differs from company currency; an FX/migration contract is required.');
            }
            $data = $this->filters($company, $input);
            $issues = [];
            $lines = [];
            $excluded = 0;
            if (! $this->catalog->cashRole((string) $binding->getAttribute('role')) || $binding->getAttribute('kind') !== (str_starts_with((string) $binding->getAttribute('role'), 'cash:') ? 'cash' : 'bank') || $account->getAttribute('cash_flow_role') !== 'cash' || $account->getAttribute('normal_side') !== 'debit') {
                $issues[] = $this->issue('binding_classification_review', null, null);
            }
            $candidates = DB::table('journal_entry_lines as l')->join('journal_entries as j', function ($join): void {
                $join->on('j.id', '=', 'l.journal_entry_id')->on('j.company_id', '=', 'l.company_id');
            })->where('j.company_id', $companyId)
                ->where(function ($query) use ($bindingId, $account): void {
                    $query->where('l.cash_account_id', $bindingId)->orWhere('l.account_id', $account->getKey());
                })
                ->where(function ($query): void {
                    $query->where('j.status', 'posted')->orWhereNotNull('j.posted_at')->orWhereNotNull('j.posted_by');
                })
                ->whereDate('j.accounting_date', '<=', $data['to'])->orderBy('j.accounting_date')->orderBy('j.id')->orderBy('l.id')
                ->get(['l.id', 'l.journal_entry_id']);
            $cache = [];
            foreach ($candidates as $candidate) {
                $lineId = (int) $candidate->id;
                $journalId = (int) $candidate->journal_entry_id;
                $row = JournalEntryLine::query()->where('company_id', $companyId)->findOrFail($lineId);
                if (! isset($cache[$journalId])) {
                    $journal = JournalEntry::query()->where('company_id', $companyId)->findOrFail($journalId);
                    $proof = null;
                    if ($journal->getAttribute('status') === 'posted' && $journal->getAttribute('posted_at') !== null && $journal->getAttribute('posted_by') !== null && $journal->getAttribute('currency_code') === $binding->getAttribute('currency_code')) {
                        try {
                            $proof = $this->sources->verify($actor, $binding, $journal);
                        } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
                            $issues[] = $this->issue('cash_source_integrity_or_adapter_review', $journalId, null);
                        }
                    }
                    $cache[$journalId] = ['journal' => $journal, 'proof' => $proof];
                }
                $journal = $cache[$journalId]['journal'];
                $proof = $cache[$journalId]['proof'];
                if ($journal->getAttribute('status') !== 'posted') {
                    $issues[] = $this->issue('inconsistent_posting_state', $journalId, $lineId);

                    continue;
                }
                $markers = $journal->getAttribute('posted_at') !== null && $journal->getAttribute('posted_by') !== null;
                if (! $markers) {
                    $issues[] = $this->issue('incomplete_posting_markers', $journalId, $lineId);
                }
                if ($journal->getAttribute('currency_code') !== $binding->getAttribute('currency_code')) {
                    $issues[] = $this->issue('foreign_currency_cash_line', $journalId, $lineId);
                    $excluded++;

                    continue;
                }
                if ((int) $row->getAttribute('account_id') !== (int) $account->getKey()) {
                    $issues[] = $this->issue('binding_gl_identity_mismatch', $journalId, $lineId);
                    $excluded++;

                    continue;
                }
                try {
                    $debit = $this->decimal->money((string) $row->getAttribute('debit'));
                    $credit = $this->decimal->money((string) $row->getAttribute('credit'));
                    if ((bccomp($debit, '0', 2) > 0) === (bccomp($credit, '0', 2) > 0)) {
                        $this->fail('Invalid cash line sides.');
                    }
                } catch (ValidationException) {
                    $issues[] = $this->issue('invalid_cash_amount', $journalId, $lineId);
                    $excluded++;

                    continue;
                }
                $recorded = $row->getAttribute('cash_account_id') === null ? null : (int) $row->getAttribute('cash_account_id');
                if ($recorded !== $bindingId) {
                    $issues[] = $this->issue('unbound_or_wrong_binding_cash_line', $journalId, $lineId);
                }
                $verified = $markers && $recorded === $bindingId && $proof !== null;
                if ($verified && ($row->getAttribute('source_line_type') !== $proof['source_kind'] || (int) $row->getAttribute('source_line_id') !== $proof['source_id'] || $debit !== $proof['debit'] || $credit !== $proof['credit'])) {
                    $verified = false;
                    $issues[] = $this->issue('cash_source_line_mismatch', $journalId, $lineId);
                }
                $lines[] = [
                    'company_id' => $companyId,
                    'binding_id' => $bindingId,
                    'recorded_binding_id' => $recorded,
                    'journal_id' => $journalId,
                    'journal_line_id' => $lineId,
                    'date' => $journal->getAttribute('accounting_date')->format('Y-m-d'),
                    'currency' => (string) $binding->getAttribute('currency_code'),
                    'number' => $verified ? $proof['number'] : (string) $journal->getAttribute('number'),
                    'source_kind' => $verified ? $proof['source_kind'] : null,
                    'source_id' => $verified ? $proof['source_id'] : null,
                    'event' => $verified ? $proof['event'] : null,
                    'source_url' => $verified ? $proof['source_url'] : null,
                    'journal_url' => $actor->can('erp.journal-entries.view') ? '/erp/accounting/journals/'.$journalId : null,
                    'debit' => $debit,
                    'credit' => $credit,
                    'verified' => $verified,
                ];
            }
            // A missing cash line must not turn an already-final native payment/receipt into a clean empty book.
            foreach (['supplier_payment' => SupplierPayment::class, 'customer_receipt' => CustomerReceipt::class] as $kind => $class) {
                foreach ($class::query()->where('company_id', $companyId)->where('cash_account_id', $bindingId)
                    ->where(function ($query): void {
                        $query->where('status', 'posted')->orWhereNotNull('posted_at');
                    })->get() as $native) {
                    $effect = AccountingSourceEffect::query()->where('company_id', $companyId)->where('source_kind', $kind)->where('source_id', $native->getKey())->where('effect_kind', 'cash')->first();
                    $date = $effect === null ? $native->getAttribute('document_date') : $effect->getAttribute('accounting_date');
                    if (! $date instanceof DateTimeInterface || $date->format('Y-m-d') > $data['to']) {
                        continue;
                    }
                    if ($effect === null) {
                        $issues[] = $this->issue('native_cash_line_missing_or_unbound', null, null);

                        continue;
                    }
                    $exists = JournalEntryLine::query()->where('company_id', $companyId)
                        ->where('journal_entry_id', $effect->getAttribute('journal_entry_id'))->where('account_id', $account->getKey())->where('cash_account_id', $bindingId)
                        ->where('source_line_type', $kind)->where('source_line_id', $native->getKey())->exists();
                    if (! $exists) {
                        $issues[] = $this->issue('native_cash_line_missing_or_unbound', (int) $effect->getAttribute('journal_entry_id'), null);
                    } elseif (! JournalEntry::query()->where('company_id', $companyId)->whereKey($effect->getAttribute('journal_entry_id'))->where('status', 'posted')->whereNotNull('posted_at')->whereNotNull('posted_by')->exists()) {
                        $issues[] = $this->issue('native_cash_journal_missing_or_unposted', (int) $effect->getAttribute('journal_entry_id'), null);
                    }
                }
            }
            // Verify the whole two-leg transfer even when this book's own cash line was deleted.
            foreach (AccountingCashTransfer::query()->where('company_id', $companyId)
                ->where(function ($query) use ($bindingId): void {
                    $query->where('source_cash_account_id', $bindingId)->orWhere('destination_cash_account_id', $bindingId);
                })
                ->where(function ($query): void {
                    $query->where('status', 'posted')->orWhereNotNull('posted_at')->orWhereNotNull('posted_by');
                })
                ->whereDate('document_date', '<=', $data['to'])->get() as $transfer) {
                try {
                    app(AccountingCashTransferReversalEvidence::class)->verifyForOriginal($companyId, (int) $transfer->getKey());
                } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
                    $issues[] = $this->issue('native_cash_transfer_evidence_review', null, null);
                }
            }
            foreach (AccountingCashTransferReversalRequest::query()->where('company_id', $companyId)
                ->where(function ($query) use ($bindingId): void {
                    $query->where('source_cash_account_id', $bindingId)->orWhere('destination_cash_account_id', $bindingId);
                })
                ->where(function ($query) use ($companyId): void {
                    $query->where('status', 'posted')->orWhereNotNull('posted_at')->orWhereNotNull('posted_by')
                        ->orWhereIn('id', AccountingCashTransferReversalOperation::query()->where('company_id', $companyId)->select('reversal_request_id'))
                        ->orWhereIn('id', AccountingSourceEffect::query()->where('company_id', $companyId)->where('source_kind', 'cash_transfer_reversal')->select('source_id'));
                })->whereDate('document_date', '<=', $data['to'])->get() as $reversal) {
                try {
                    app(AccountingCashTransferReversalEvidence::class)->verify($companyId, (int) $reversal->getKey());
                } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
                    $issues[] = $this->issue('native_cash_transfer_reversal_evidence_review', null, null);
                }
            }
            // Native evidence must not disappear from coverage when its bank cash leg is missing.
            foreach (AccountingBankAdjustment::query()->where('company_id', $companyId)->where('cash_account_id', $bindingId)
                ->where(function ($q) use ($companyId): void {
                    $q->where('status', 'posted')->orWhereNotNull('posted_at')->orWhereNotNull('posted_by')
                        ->orWhereIn('id', AccountingBankAdjustmentOperation::query()->where('company_id', $companyId)->select('adjustment_id'))
                        ->orWhereIn('id', AccountingSourceEffect::query()->where('company_id', $companyId)->where('source_kind', 'bank_adjustment')->select('source_id'));
                })
                ->whereDate('document_date', '<=', $data['to'])->get() as $adjustment) {
                try {
                    app(AccountingBankAdjustmentEvidence::class)->family($companyId, (int) $adjustment->getKey());
                } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
                    $issues[] = $this->issue('native_bank_adjustment_evidence_review', null, null);
                }
            }
            // Persisted safe bank identity also catches an orphan effect after native/header/cash-leg damage.
            foreach (AccountingSourceEffect::query()->where('company_id', $companyId)->where('source_kind', 'bank_adjustment')
                ->where('source_snapshot->evidence->source->facts->cash_account_id', $bindingId)->whereDate('accounting_date', '<=', $data['to'])->pluck('source_id')->all() as $sourceId) {
                try {
                    app(AccountingBankAdjustmentEvidence::class)->family($companyId, (int) $sourceId);
                } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
                    $issues[] = $this->issue('native_bank_adjustment_evidence_review', null, null);
                }
            }
            foreach (AccountingExpenseFinancePlan::query()->where('company_id', $companyId)->where(function ($q): void {
                $q->where('status', 'posted')->orWhereNotNull('posted_at');
            })->whereDate('document_date', '<=', $data['to'])->get() as $plan) {
                $snapshot = $plan->getAttribute('snapshot');
                if (! is_array($snapshot) || ! is_array($snapshot['cash'] ?? null) || ($snapshot['cash']['id'] ?? null) !== $bindingId) {
                    continue;
                }
                try {
                    $reader = app(AccountingExpenseFinanceEvidence::class);
                    $reader->verify($companyId, (int) $plan->getKey());
                    $reader->balance($companyId, (int) $plan->getAttribute('expense_id'));
                } catch (ValidationException|ModelNotFoundException|\InvalidArgumentException) {
                    $issues[] = $this->issue('native_expense_cash_evidence_review', null, null);
                }
            }
            $result = $this->contract->project($lines, $data['from'], $data['to'], $data['page'], $data['per_page'], $allRows);

            return [
                'company' => $company->only(['id', 'code', 'name']),
                'binding' => ['id' => $bindingId, ...$binding->only(['name', 'role', 'kind', 'currency_code', 'status']), 'account_id' => (int) $account->getKey()],
                ...$result,
                'coverage_basis' => 'posted_gl_with_verified_native_receipt_supplier_transfer_reversal_bank_adjustment_and_expense_sources',
                'complete' => $issues === [],
                'balance_status' => $issues === [] ? 'verified_within_supported_scope' : 'review_required',
                'exceptions' => $issues,
                'excluded_posted_lines' => $excluded,
                'posted_totals_complete' => $excluded === 0,
                'date_basis' => 'immutable_journal_accounting_date',
            ];
        }, 3);
    }

    /** @param array<string, mixed> $input @return array{from: string, to: string, page: int, per_page: int} */
    private function filters(Company $company, array $input): array
    {
        if (array_diff(array_keys($input), ['from', 'to', 'page', 'per_page']) !== []) {
            $this->fail('Unsupported cashbook filter.');
        }
        $today = $this->dates->today($company);
        $data = Validator::make($input, [
            'from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.$today],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.$today, ...(! empty($input['from']) ? ['after_or_equal:from'] : [])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ])->validate();

        return ['from' => $data['from'] ?? '1900-01-01', 'to' => $data['to'] ?? $today, 'page' => (int) ($data['page'] ?? 1), 'per_page' => (int) ($data['per_page'] ?? 25)];
    }

    /** @return array<string, mixed> */
    private function issue(string $code, ?int $journal, ?int $line): array
    {
        return ['code' => $code, 'journal_id' => $journal, 'journal_line_id' => $line];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['cash_book' => $message]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Adjustments;

use App\Models\Accounting\AccountingBankAdjustment;
use App\Models\Accounting\AccountingBankAdjustmentOperation;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingDomainRegistry;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\Source\AccountingPreparedSourceEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Scoped explicit counter resolution. No new mandatory global mapping roles or legacy-profile reinterpretation. */
final class AccountingBankAdjustmentMapping
{
    public function __construct(private readonly AccountingBankAdjustmentProof $proof, private readonly AccountingBankAdjustmentContract $contract, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingMappingService $mappings) {}

    /** @param list<string> $roles @return array{version_id: int, payload_hash: string, accounts: array<string, int>}|null */
    public function resolve(AccountingPreparedSourceEvent $event, array $roles): ?array
    {
        if ($event->sourceKind !== 'bank_adjustment') {
            return null;
        }
        if (count($event->effects) !== 1 || ! isset($event->effects[0]) || $event->effects[0]->kind !== 'cash' || $event->effects[0]->noJournalReason !== null) {
            $this->fail();
        }
        $row = AccountingBankAdjustment::query()->where('company_id', $event->companyId)->findOrFail($event->sourceId);
        $expected = $this->proof->event($row);
        if ($this->canonical->hash($this->proof->envelope($event)) !== $this->canonical->hash($this->proof->envelope($expected)) || $this->canonical->hash($event->effects[0]->lines) !== $this->canonical->hash($expected->effects[0]->lines)) {
            $this->fail();
        }
        $bank = $row->getAttribute('binding_snapshot');
        $required = [(string) $bank['role'], AccountingBankAdjustmentContract::COUNTER_ROLE];
        sort($required);
        sort($roles);
        if ($required !== $roles) {
            $this->fail();
        }
        $this->mappings->verifyRecordedProfile($event->companyId, (int) $row->getAttribute('mapping_version_id'), $event->currency, AccountingDomainRegistry::VERSION, (string) $row->getAttribute('mapping_hash'));
        $this->contract->counter($event->companyId, (int) $row->getAttribute('counter_account_id'), (string) $row->getAttribute('kind'), true);
        $binding = AccountingCashAccount::query()->where('company_id', $event->companyId)->findOrFail($bank['id']);
        if ($row->getAttribute('reverses_id') === null) {
            if ($binding->getAttribute('status') !== 'active') {
                $this->fail();
            }
            $company = Company::query()->findOrFail($event->companyId);
            $current = $this->mappings->resolveForSource($company, $event->accountingDate, [$bank['role']]);
            if ($current['version_id'] !== (int) $row->getAttribute('mapping_version_id') || $current['payload_hash'] !== $row->getAttribute('mapping_hash') || ($current['accounts'][$bank['role']] ?? null) !== $bank['account_id']) {
                $this->fail();
            }
        }

        return ['version_id' => (int) $row->getAttribute('mapping_version_id'), 'payload_hash' => (string) $row->getAttribute('mapping_hash'), 'accounts' => [(string) $bank['role'] => (int) $bank['account_id'], AccountingBankAdjustmentContract::COUNTER_ROLE => (int) $row->getAttribute('counter_account_id')]];
    }

    /** Only the exact bank leg of an approved native full reversal may use its original inactive binding. */
    public function line(int $company, string $kind, int $id, int $account, int $cash, string $debit, string $credit, ?string $role = null, ?JournalEntry $journal = null): bool
    {
        if ($kind !== 'bank_adjustment' || DB::transactionLevel() < 1 || AccountingBankAdjustmentOperation::query()->where('company_id', $company)->where('adjustment_id', $id)->exists() || AccountingSourceEffect::query()->where('company_id', $company)->where('source_kind', $kind)->where('source_id', $id)->exists()) {
            return false;
        }
        $row = AccountingBankAdjustment::query()->where('company_id', $company)->find($id);
        if ($row === null || $row->getAttribute('reverses_id') === null) {
            return false;
        }
        $this->proof->event($row);
        $expected = $this->proof->lines($row)[0];
        $binding = AccountingCashAccount::query()->where('company_id', $company)->find($cash);
        if ($binding === null || ! in_array($binding->getAttribute('status'), ['active', 'inactive'], true)) {
            return false;
        }
        if ($journal !== null && ($journal->getAttribute('entry_kind') !== 'automatic' || $journal->getAttribute('status') !== 'draft' || (int) $journal->getAttribute('company_id') !== $company || (int) $journal->getAttribute('mapping_version_id') !== (int) $row->getAttribute('mapping_version_id') || $journal->getAttribute('currency_code') !== $row->getAttribute('currency_code') || $journal->getAttribute('accounting_date')->format('Y-m-d') !== $row->getAttribute('document_date')->format('Y-m-d') || $journal->getAttribute('document_date')?->format('Y-m-d') !== $row->getAttribute('document_date')->format('Y-m-d'))) {
            return false;
        }

        return $expected['account_id'] === $account && $expected['cash_account_id'] === $cash && $expected['debit'] === $debit && $expected['credit'] === $credit && ($role === null || $role === $expected['role']);
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['bank_adjustment_mapping' => 'Approved original bank/counter accounts and profile must remain eligible and agree with the exact native adjustment.']);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Transfers;

use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingCashTransferReversalOperation;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntry;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingDomainRegistry;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\Source\AccountingPreparedSourceEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Reconstructs original role/GL identities and exact inactive-binding lines; no caller-controlled bypass. */
final class AccountingCashTransferReversalMapping
{
    public function __construct(private readonly AccountingCashTransferReversalNativeProof $native, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingMappingService $mappings) {}

    /** @param list<string> $roles @return array{version_id: int, payload_hash: string, accounts: array<string, int>}|null */
    public function resolve(AccountingPreparedSourceEvent $event, array $roles): ?array
    {
        if ($event->sourceKind !== 'cash_transfer_reversal') {
            return null;
        }
        if (count($event->effects) !== 1 || ! isset($event->effects[0]) || $event->effects[0]->kind !== 'cash' || $event->effects[0]->noJournalReason !== null) {
            $this->fail();
        }
        $proof = $this->native->verify($event->companyId, $event->sourceId);
        $expected = $this->native->event($proof);
        if ($this->canonical->hash($this->native->envelope($event)) !== $this->canonical->hash($this->native->envelope($expected)) || $this->canonical->hash($event->effects[0]->lines) !== $this->canonical->hash($expected->effects[0]->lines)) {
            $this->fail();
        }
        $original = $proof['original'];
        $accounts = [];
        foreach ([$original['source'], $original['destination']] as $binding) {
            $accounts[$binding['role']] = $binding['account_id'];
        }
        $required = array_keys($accounts);
        sort($required, SORT_STRING);
        sort($roles, SORT_STRING);
        if ($roles !== $required) {
            $this->fail();
        }
        $this->mappings->verifyRecordedProfile($event->companyId, $original['mapping_version_id'], $event->currency, AccountingDomainRegistry::VERSION, $original['mapping_hash']);

        return ['version_id' => $original['mapping_version_id'], 'payload_hash' => $original['mapping_hash'], 'accounts' => $accounts];
    }

    public function line(int $company, string $kind, int $id, int $account, int $cash, string $debit, string $credit, ?string $role = null, ?JournalEntry $journal = null): bool
    {
        if ($kind !== 'cash_transfer_reversal' || DB::transactionLevel() < 1) {
            return false;
        }
        // Once any member exists, this proof cannot authorize another new journal line.
        if (AccountingCashTransferReversalOperation::query()->where('company_id', $company)->where('reversal_request_id', $id)->exists()
            || AccountingSourceEffect::query()->where('company_id', $company)->where('source_kind', $kind)->where('source_id', $id)->exists()) {
            return false;
        }
        $binding = AccountingCashAccount::query()->where('company_id', $company)->find($cash);
        if ($binding === null || ! in_array($binding->getAttribute('status'), ['active', 'inactive'], true)) {
            return false;
        }
        $proof = $this->native->verify($company, $id);
        $row = $proof['request'];
        if ($journal !== null && ($journal->getAttribute('entry_kind') !== 'automatic' || $journal->getAttribute('status') !== 'draft' || (int) $journal->getAttribute('company_id') !== $company || (int) $journal->getAttribute('mapping_version_id') !== $proof['original']['mapping_version_id'] || $journal->getAttribute('currency_code') !== $row->getAttribute('currency_code') || $journal->getAttribute('accounting_date')->format('Y-m-d') !== $row->getAttribute('document_date')->format('Y-m-d') || $journal->getAttribute('document_date')?->format('Y-m-d') !== $row->getAttribute('document_date')->format('Y-m-d'))) {
            return false;
        }
        foreach ($proof['plan']['lines'] as $line) {
            if ($line['account_id'] === $account && $line['cash_account_id'] === $cash && $line['debit'] === $debit && $line['credit'] === $credit && ($role === null || $line['role'] === $role)) {
                return true;
            }
        }

        return false;
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['cash_transfer_reversal' => 'Reversal financial lines must preserve the exact verified original account identities.']);
    }
}

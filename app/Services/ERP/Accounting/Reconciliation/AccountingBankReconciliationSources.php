<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reconciliation;

use App\Models\Accounting\AccountingBankStatement;
use App\Models\Accounting\AccountingBankStatementLine;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntry;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\Books\AccountingCashBookReader;
use App\Services\ERP\Accounting\Statements\AccountingBankStatementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Internal, read-only verified sources. No posting, replay or repair path. Caller owns authorization and company lock. */
final class AccountingBankReconciliationSources
{
    public function __construct(private readonly AccountingBankStatementService $statements, private readonly AccountingCashBookReader $books, private readonly AccountingCanonicalPayload $canonical) {}

    /** @return array<string, mixed> */
    public function statement(int $company, int $bank, int $id): array
    {
        $this->transaction();
        $row = AccountingBankStatement::query()->where('company_id', $company)->where('cash_account_id', $bank)->findOrFail($id);
        $payload = $this->statements->verifyOwned($company, $id);
        $hash = $this->canonical->hash($payload);
        $identity = ['statement_id' => $id, 'company_id' => $company, 'cash_account_id' => $bank, 'currency_code' => $payload['metadata']['currency_code'], 'starts_on' => $payload['metadata']['starts_on'], 'ends_on' => $payload['metadata']['ends_on'], 'payload_hash' => $hash, 'source_hash' => (string) $row->getAttribute('source_hash')];
        $lines = AccountingBankStatementLine::query()->where('company_id', $company)->where('cash_account_id', $bank)->where('statement_id', $id)->orderBy('line_number')->get();
        $rows = [];
        foreach ($lines as $index => $line) {
            $native = $payload['lines'][$index];
            $lineId = (int) $line->getKey();
            $direction = bccomp($native['money_in'], '0', 2) > 0 ? 'in' : 'out';
            $rows[$lineId] = ['id' => $lineId, 'date' => $native['date'], 'direction' => $direction, 'amount' => $direction === 'in' ? $native['money_in'] : $native['money_out'],
                'proof_hash' => $this->canonical->hash(['identity' => $identity, 'line_id' => $lineId, 'line' => $native])];
        }

        return ['identity' => $identity, 'rows' => $rows, 'opening_balance' => $payload['metadata']['opening_balance'], 'closing_balance' => $payload['metadata']['closing_balance']];
    }

    /** @return array<string, mixed> */
    public function book(User $actor, int $company, int $bank, string $to): array
    {
        $this->transaction();
        $report = $this->books->reconciliationBook($actor, $company, $bank, ['from' => '1900-01-01', 'to' => $to]);
        if (! $report['complete'] || ! $report['posted_totals_complete'] || $report['binding']['kind'] !== 'bank') {
            $this->fail('The complete bankbook source evidence must verify before matching. Unsupported or damaged source lines remain exceptions.');
        }
        $rows = [];
        foreach ($report['rows'] as $row) {
            if (! $row['verified'] || (int) $row['recorded_binding_id'] !== $bank) {
                $this->fail('A bankbook row is not fully verified for this bank.');
            }
            $journal = JournalEntry::query()->where('company_id', $company)->findOrFail($row['journal_id']);
            $effects = AccountingSourceEffect::query()->where('company_id', $company)->where('journal_entry_id', $journal->getKey())->get();
            if ($effects->count() !== 1) {
                $this->fail('Bankbook source identity is missing or ambiguous.');
            }
            $effect = $effects->firstOrFail();
            $direction = bccomp($row['debit'], '0', 2) > 0 ? 'in' : 'out';
            $id = (int) $row['journal_line_id'];
            $proof = ['company_id' => $company, 'cash_account_id' => $bank, 'account_id' => (int) $report['binding']['account_id'], 'currency_code' => $report['binding']['currency_code'],
                'journal_id' => (int) $journal->getKey(), 'journal_line_id' => $id, 'journal_hash' => (string) $journal->getAttribute('payload_hash'), 'effect_id' => (int) $effect->getKey(), 'source_hash' => (string) $effect->getAttribute('source_payload_hash'),
                'date' => $row['date'], 'source_kind' => $row['source_kind'], 'source_id' => $row['source_id'], 'debit' => $row['debit'], 'credit' => $row['credit']];
            $rows[$id] = ['id' => $id, 'date' => $row['date'], 'direction' => $direction, 'amount' => $direction === 'in' ? $row['debit'] : $row['credit'], 'proof_hash' => $this->canonical->hash($proof)];
        }

        return ['rows' => $rows, 'currency_code' => $report['binding']['currency_code']];
    }

    private function transaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Reconciliation source reads require the authorized company transaction.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['bank_reconciliation_sources' => $message]);
    }
}

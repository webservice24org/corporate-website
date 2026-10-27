<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Adjustments;

use App\Models\Accounting\AccountingBankAdjustment;
use App\Models\Accounting\AccountingBankStatement;
use App\Models\Accounting\AccountingBankStatementLine;
use App\Models\Accounting\AccountingCashAccount;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\Source\AccountingPreparedSourceEvent;
use App\Services\ERP\Accounting\Source\AccountingSourceEffectPlan;
use App\Services\ERP\Accounting\Statements\AccountingBankStatementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Reconstructs native intent only. A reversal consults the complete original operation, never its own new financial members. */
final class AccountingBankAdjustmentProof
{
    public function __construct(private readonly AccountingBankAdjustmentContract $contract, private readonly AccountingBankStatementService $statements, private readonly AccountingCanonicalPayload $canonical) {}

    /** @return array<string, mixed> */
    public function source(int $company, int $lineId): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Bank source verification requires the authorized company transaction.');
        }
        $line = AccountingBankStatementLine::query()->where('company_id', $company)->findOrFail($lineId);
        $statementId = (int) $line->getAttribute('statement_id');
        $statement = AccountingBankStatement::query()->where('company_id', $company)->findOrFail($statementId);
        $payload = $this->statements->verifyOwned($company, $statementId);
        $number = (int) $line->getAttribute('line_number');
        $native = $payload['lines'][$number - 1] ?? null;
        if (! is_array($native)) {
            $this->fail();
        }
        $direction = bccomp($native['money_in'], '0', 2) > 0 ? 'in' : 'out';

        return ['company_id' => $company, 'cash_account_id' => (int) $statement->getAttribute('cash_account_id'), 'statement_id' => $statementId, 'line_id' => $lineId, 'line_number' => $number,
            'currency_code' => (string) $statement->getAttribute('currency_code'), 'date' => $native['date'], 'ends_on' => $payload['metadata']['ends_on'], 'direction' => $direction, 'amount' => $direction === 'in' ? $native['money_in'] : $native['money_out'],
            'payload_hash' => $this->canonical->hash($payload), 'source_hash' => (string) $statement->getAttribute('source_hash'), 'line_hash' => $this->canonical->hash($native)];
    }

    /** @return array<string, mixed> */
    public function verify(AccountingBankAdjustment $row): array
    {
        $this->contract->review($row);
        $company = (int) $row->getAttribute('company_id');
        $source = $this->source($company, (int) $row->getAttribute('statement_line_id'));
        $binding = AccountingCashAccount::query()->where('company_id', $company)->findOrFail((int) $row->getAttribute('cash_account_id'));
        if ($source['cash_account_id'] !== (int) $binding->getKey() || $source['amount'] !== $row->getAttribute('amount') || $source['currency_code'] !== $row->getAttribute('currency_code') || $binding->getAttribute('currency_code') !== $source['currency_code'] || ($source['date'] > $row->getAttribute('document_date')->format('Y-m-d') || $source['ends_on'] < $row->getAttribute('document_date')->format('Y-m-d')) || $this->canonical->hash($source) !== $this->canonical->hash($row->getAttribute('source_snapshot')) || $this->canonical->hash($this->contract->binding($binding)) !== $this->canonical->hash($row->getAttribute('binding_snapshot')) || $this->canonical->hash($this->contract->counter($company, (int) $row->getAttribute('counter_account_id'), (string) $row->getAttribute('kind'), false)) !== $this->canonical->hash($row->getAttribute('counter_snapshot'))) {
            $this->fail();
        }
        $expected = $row->getAttribute('kind') === 'charge' ? 'out' : 'in';
        if ($row->getAttribute('reverses_id') !== null) {
            $original = app(AccountingBankAdjustmentEvidence::class)->verify($company, (int) $row->getAttribute('reverses_id'));
            $parent = $original['adjustment'];
            if ($parent->getAttribute('reverses_id') !== null || $parent->getAttribute('kind') !== $row->getAttribute('kind') || $parent->getAttribute('amount') !== $row->getAttribute('amount') || (int) $parent->getAttribute('cash_account_id') !== (int) $row->getAttribute('cash_account_id') || (int) $parent->getAttribute('counter_account_id') !== (int) $row->getAttribute('counter_account_id') || (int) $parent->getAttribute('mapping_version_id') !== (int) $row->getAttribute('mapping_version_id') || $parent->getAttribute('mapping_hash') !== $row->getAttribute('mapping_hash') || $original['operation']->getAttribute('payload_hash') !== $row->getAttribute('original_hash') || $parent->getAttribute('document_date')->format('Y-m-d') > $row->getAttribute('document_date')->format('Y-m-d') || $source['date'] < $parent->getAttribute('source_snapshot')['date']) {
                $this->fail();
            }
            foreach (['binding_snapshot', 'counter_snapshot'] as $field) {
                if ($this->canonical->hash($parent->getAttribute($field)) !== $this->canonical->hash($row->getAttribute($field))) {
                    $this->fail();
                }
            }
            $expected = $expected === 'in' ? 'out' : 'in';
        } elseif ($row->getAttribute('original_hash') !== null) {
            $this->fail();
        }
        if ($source['direction'] !== $expected) {
            $this->fail();
        }

        return $source;
    }

    /** @return list<array<string, mixed>> */
    public function lines(AccountingBankAdjustment $row): array
    {
        $binding = $row->getAttribute('binding_snapshot');
        $bankIn = $row->getAttribute('kind') === 'interest';
        if ($row->getAttribute('reverses_id') !== null) {
            $bankIn = ! $bankIn;
        } $amount = (string) $row->getAttribute('amount');

        return [
            ['role' => $binding['role'], 'account_id' => (int) $binding['account_id'], 'cash_account_id' => (int) $binding['id'], 'debit' => $bankIn ? $amount : '0.00', 'credit' => $bankIn ? '0.00' : $amount],
            ['role' => AccountingBankAdjustmentContract::COUNTER_ROLE, 'account_id' => (int) $row->getAttribute('counter_account_id'), 'cash_account_id' => null, 'debit' => $bankIn ? '0.00' : $amount, 'credit' => $bankIn ? $amount : '0.00'],
        ];
    }

    public function event(AccountingBankAdjustment $row): AccountingPreparedSourceEvent
    {
        $source = $this->verify($row);
        if ($row->getAttribute('status') !== 'posted' || $row->getAttribute('posted_at') === null || (int) $row->getAttribute('posted_by') < 1 || (int) $row->getAttribute('revision') !== (int) $row->getAttribute('posting_revision') + 1 || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $row->getAttribute('posting_key_hash')) || $row->getAttribute('cancelled_at') !== null || (int) $row->getAttribute('active_statement_line_id') !== (int) $row->getAttribute('statement_line_id')) {
            $this->fail();
        }
        if ($row->getAttribute('reverses_id') === null ? $row->getAttribute('active_reversal_of_id') !== null : (int) $row->getAttribute('active_reversal_of_id') !== (int) $row->getAttribute('reverses_id')) {
            $this->fail();
        }
        $lines = [];
        foreach ($this->lines($row) as $line) {
            unset($line['account_id']);
            $lines[] = [...$line, 'source_line_type' => 'bank_adjustment', 'source_line_id' => (int) $row->getKey()];
        }
        $proof = $this->canonical->hash(['intent' => $this->contract->intent($row), 'key_hash' => (string) $row->getAttribute('posting_key_hash'), 'revision' => (int) $row->getAttribute('posting_revision'), 'posted_by' => (int) $row->getAttribute('posted_by'), 'posted_at' => $row->getAttribute('posted_at')->format('Y-m-d H:i:s')]);
        $date = $row->getAttribute('document_date')->format('Y-m-d');
        $references = [];
        if ($row->getAttribute('reverses_id') !== null) {
            $parent = AccountingBankAdjustment::query()->where('company_id', $row->getAttribute('company_id'))->findOrFail((int) $row->getAttribute('reverses_id'));
            $references[] = ['source_kind' => 'bank_adjustment', 'source_id' => (int) $parent->getKey(), 'origin_date' => $parent->getAttribute('document_date')->format('Y-m-d')];
        }

        return new AccountingPreparedSourceEvent((int) $row->getAttribute('company_id'), 'bank_adjustment', (int) $row->getKey(), 'posted', (string) $row->getAttribute('posting_key_hash'), (string) $row->getAttribute('currency_code'), $date, $date, $source['date'],
            ['source_number' => 'BA-'.$row->getKey(), 'cash_account_id' => (int) $row->getAttribute('cash_account_id'), 'source_line_id' => (int) $row->getAttribute('statement_line_id'), 'amount' => (string) $row->getAttribute('amount'), 'currency_code' => (string) $row->getAttribute('currency_code'), 'original_id' => $row->getAttribute('reverses_id') === null ? null : (int) $row->getAttribute('reverses_id'), 'evidence_hash' => $proof], [new AccountingSourceEffectPlan('cash', $lines, ['gl_amount' => (string) $row->getAttribute('amount'), 'evidence_hash' => $proof])], $references);
    }

    /** @return array<string, mixed> */
    public function envelope(AccountingPreparedSourceEvent $event): array
    {
        return ['source_contract' => 'source.v1', 'company_id' => $event->companyId, 'source_kind' => $event->sourceKind, 'source_id' => $event->sourceId, 'event_kind' => $event->eventKind, 'event_token' => $event->eventToken, 'currency' => $event->currency, 'document_date' => $event->documentDate, 'accounting_date' => $event->accountingDate, 'origin_date' => $event->originDate, 'facts' => $event->facts, 'effect_proofs' => ['cash' => $event->effects[0]->proof], 'references' => $event->references];
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['bank_adjustment_proof' => 'The approved native bank adjustment, statement evidence or original reversal identity does not verify.']);
    }
}

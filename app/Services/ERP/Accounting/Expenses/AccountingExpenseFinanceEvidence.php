<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Expenses;

use App\Models\Accounting\AccountingExpense;
use App\Models\Accounting\AccountingExpenseFinanceClaim;
use App\Models\Accounting\AccountingExpenseFinanceOperation;
use App\Models\Accounting\AccountingExpenseFinancePlan;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingDomainRegistry;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\Source\AccountingAutomaticEvidence;
use App\Services\ERP\Accounting\Source\AccountingPreparedSourceEvent;

final class AccountingExpenseFinanceEvidence
{
    public function __construct(private readonly AccountingExpenseFinanceProof $proof, private readonly AccountingExpenseFinanceContract $contract, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingMappingService $mappings, private readonly AccountingAutomaticEvidence $journals) {}

    /** @return array<string, mixed> */
    public function envelope(AccountingPreparedSourceEvent $e): array
    {
        return ['source_contract' => 'source.v1', 'company_id' => $e->companyId, 'source_kind' => $e->sourceKind, 'source_id' => $e->sourceId, 'event_kind' => $e->eventKind, 'event_token' => $e->eventToken, 'currency' => $e->currency, 'document_date' => $e->documentDate, 'accounting_date' => $e->accountingDate, 'origin_date' => $e->originDate, 'facts' => $e->facts, 'effect_proofs' => ['financial' => $e->effects[0]->proof], 'references' => $e->references];
    }

    /** @return array<string, mixed> */
    public function operationPayload(AccountingExpenseFinancePlan $plan, AccountingSourceEffect $effect, JournalEntry $journal): array
    {
        return ['contract' => AccountingExpenseFinanceContract::VERSION, 'company_id' => (int) $plan->getAttribute('company_id'), 'plan_id' => (int) $plan->getKey(), 'expense_id' => (int) $plan->getAttribute('expense_id'), 'kind' => (string) $plan->getAttribute('kind'), 'amount' => (string) $plan->getAttribute('amount'), 'intent_hash' => (string) $plan->getAttribute('intent_hash'), 'posting_key_hash' => (string) $plan->getAttribute('posting_key_hash'), 'history_hash' => (string) $plan->getAttribute('history_hash'), 'effect_id' => (int) $effect->getKey(), 'source_hash' => (string) $effect->getAttribute('source_payload_hash'), 'journal_id' => (int) $journal->getKey(), 'journal_hash' => (string) $journal->getAttribute('payload_hash'), 'mapping_hash' => $plan->getAttribute('snapshot')['mapping']['payload_hash']];
    }

    /** @return array{plan: AccountingExpenseFinancePlan, operation: AccountingExpenseFinanceOperation, effect: AccountingSourceEffect, journal: JournalEntry} */
    public function verify(int $company, int $id): array
    {
        $plan = AccountingExpenseFinancePlan::query()->where('company_id', $company)->findOrFail($id);
        $this->proof->verify($plan);
        $event = $this->proof->event($plan);
        $snapshot = $plan->getAttribute('snapshot');

        $operation = AccountingExpenseFinanceOperation::query()->where('company_id', $company)->where('plan_id', $id)->firstOrFail();

        $effects = AccountingSourceEffect::query()->where('company_id', $company)->where('source_kind', 'expense_finance')->where('source_id', $id)->get();

        if ($effects->count() !== 1) {
            $this->contract->fail('The exact expense source-effect bundle is missing or ambiguous.');
        }

        $effect = $effects->firstOrFail();
        $body = $effect->getAttribute('source_snapshot');
        $body = is_array($body) ? ($body['evidence'] ?? null) : null;

        $hash = $this->canonical->hash($this->envelope($event));

        if ((int) $operation->getAttribute('effect_id') !== (int) $effect->getKey() || (int) $operation->getAttribute('expense_id') !== $snapshot['expense_id'] || $effect->getAttribute('source_payload_hash') !== $hash || (int) $effect->getAttribute('mapping_version_id') !== $snapshot['mapping']['version_id'] || $effect->getAttribute('contract_version') !== AccountingDomainRegistry::VERSION || $effect->getAttribute('accounting_date')->format('Y-m-d') !== $snapshot['date'] || $effect->getAttribute('event_kind') !== 'posted' || $effect->getAttribute('event_token') !== $plan->getAttribute('posting_key_hash') || $effect->getAttribute('effect_kind') !== 'financial' || $effect->getAttribute('outcome') !== 'posted' || $effect->getAttribute('no_journal_reason') !== null || $effect->getAttribute('journal_entry_id') === null || ! is_array($body)) {
            $this->contract->fail('Expense financial membership or source fingerprint differs.');
        }

        $full = $effect->getAttribute('source_snapshot');

        if (($full['evidence_hash'] ?? null) !== $this->canonical->hash($body) || ! is_array($body['source'] ?? null) || $this->canonical->hash($body['source']) !== $hash || ($body['bundle_effects'] ?? null) !== ['financial'] || ($body['mapping_hash'] ?? null) !== $snapshot['mapping']['payload_hash']) {
            $this->contract->fail('Expense source evidence is damaged.');
        }

        $this->mappings->verifyRecordedProfile($company, $snapshot['mapping']['version_id'], $snapshot['currency'], AccountingDomainRegistry::VERSION, $snapshot['mapping']['payload_hash']);

        $journal = JournalEntry::query()->where('company_id', $company)->findOrFail($effect->getAttribute('journal_entry_id'));

        if ((int) $journal->getAttribute('mapping_version_id') !== $snapshot['mapping']['version_id'] || $journal->getAttribute('currency_code') !== $snapshot['currency'] || $journal->getAttribute('accounting_date')->format('Y-m-d') !== $snapshot['date'] || $journal->getAttribute('payload_hash') !== ($body['journal_hash'] ?? null)) {
            $this->contract->fail('Expense journal identity changed.');
        }

        $this->journals->verify($journal, $hash, 'financial', $snapshot['mapping']['payload_hash']);

        $actual = $this->journals->lines($journal);
        $expected = $this->proof->lines($plan);

        if (count($actual) !== count($expected) || JournalEntryLine::query()->where('company_id', $company)->where('source_line_type', 'expense_finance')->where('source_line_id', $id)->count() !== count($expected)) {
            $this->contract->fail('Expense journal lines are missing or duplicated.');
        }

        foreach ($expected as $index => $line) {
            // Role selects the approved GL during preparation; it is not a persisted journal column.
            unset($line['role']);
            foreach ($line as $field => $value) {
                if (! array_key_exists($field, $actual[$index]) || $actual[$index][$field] !== $value) {
                    $this->contract->fail('Expense journal differs from approved account/party/money intent.');
                }
            }
        }

        $payload = $this->operationPayload($plan, $effect, $journal);

        if ($this->canonical->hash($operation->getAttribute('payload')) !== $this->canonical->hash($payload) || $operation->getAttribute('payload_hash') !== $this->canonical->hash($payload) || (($snapshot['kind'] === 'recognition' ? $snapshot['expense_id'] : null) !== ($operation->getAttribute('recognition_expense_id') === null ? null : (int) $operation->getAttribute('recognition_expense_id')))) {
            $this->contract->fail('Missing or damaged expense operation manifests are never repaired by retry.');
        }

        if ($snapshot['cash'] !== null && $this->canonical->hash($this->contract->binding($company, $snapshot['cash']['id'], false)) !== $this->canonical->hash($snapshot['cash'])) {
            $this->contract->fail('Recorded cash binding identity changed.');
        }

        if ($snapshot['kind'] === 'recognition') {
            $expense = AccountingExpense::query()->where('company_id', $company)->findOrFail($snapshot['expense_id']);

            $claim = AccountingExpenseFinanceClaim::query()->where('company_id', $company)->where('expense_id', $snapshot['expense_id'])->firstOrFail();

            if (! in_array($expense->getAttribute('status'), ['posted', 'reversed'], true) || (int) $claim->getAttribute('plan_id') !== $id || $claim->getAttribute('claim_hash') !== $expense->getAttribute('claim_hash') || $claim->getAttribute('intent_hash') !== $snapshot['expense_hash']) {
                $this->contract->fail('Recognized expense/native claim does not agree.');
            }
        } elseif ($snapshot['kind'] === 'settlement') {
            $parent = $this->verify($company, $snapshot['parent_plan']);
            $p = $parent['plan']->getAttribute('snapshot');

            if ($parent['operation']->getAttribute('payload_hash') !== $snapshot['parent_hash'] || $p['kind'] !== 'recognition' || $p['expense_id'] !== $snapshot['expense_id'] || $p['liability'] === null || $this->canonical->hash($p['liability']) !== $this->canonical->hash($snapshot['liability']) || $p['date'] > $snapshot['date']) {
                $this->contract->fail('Settlement must settle its original recognized expense payable.');
            }
        } else {
            $parent = $this->verify($company, $snapshot['reverses_plan']);
            $p = $parent['plan']->getAttribute('snapshot');
            if ($p['kind'] === 'reversal' || $p['kind'] !== $snapshot['reverses_kind'] || $p['expense_id'] !== $snapshot['expense_id'] || $p['amount'] !== $snapshot['amount'] || $parent['operation']->getAttribute('payload_hash') !== $snapshot['original_hash'] || $p['date'] > $snapshot['date']) {
                $this->contract->fail('Reversal must compensate its exact recorded original operation.');
            }
            foreach (['net', 'tax', 'tax_treatment', 'expense_account', 'tax_account', 'liability', 'cash', 'mapping', 'payee_type', 'payee_id', 'branch_id', 'department_id'] as $field) {
                if ($this->canonical->hash(['value' => $p[$field]]) !== $this->canonical->hash(['value' => $snapshot[$field]])) {
                    $this->contract->fail('Reversal cannot remap original economic accounts, parties, dimensions or amounts.');
                }
            }
        }

        return ['plan' => $plan, 'operation' => $operation, 'effect' => $effect, 'journal' => $journal];
    }

    /** @return array{recognition: array<string, mixed>|null, recognized: string, settled: string, outstanding: string, status: string} */
    public function balance(int $company, int $expense): array
    {
        $recognition = null;
        $settled = '0.00';
        $recognized = '0.00';
        $reversed = [];
        $recognitionReversed = false;
        foreach (AccountingExpenseFinancePlan::query()->where('company_id', $company)->where('expense_id', $expense)->where('status', 'posted')->orderBy('id')->get() as $plan) {
            $proof = $this->verify($company, (int) $plan->getKey());
            $s = $plan->getAttribute('snapshot');
            if ($s['kind'] === 'recognition') {
                if ($recognition !== null) {
                    $this->contract->fail('Duplicate expense recognition.');
                } $recognition = $proof;
                $recognized = $s['cash'] === null ? $s['amount'] : '0.00';
            } elseif ($s['kind'] === 'settlement') {
                $settled = bcadd($settled, $s['amount'], 2);
            } else {
                if (isset($reversed[$s['reverses_plan']])) {
                    $this->contract->fail('Duplicate expense compensation.');
                } $reversed[$s['reverses_plan']] = true;
                if ($s['reverses_kind'] === 'settlement') {
                    $settled = bcsub($settled, $s['amount'], 2);
                } else {
                    $recognitionReversed = true;
                }
            }
        }
        if (bccomp($settled, '0', 2) < 0 || bccomp($settled, $recognized, 2) > 0 || ($recognitionReversed && bccomp($settled, '0', 2) !== 0)) {
            $this->contract->fail('Expense payable and settlement compensation totals disagree.');
        }
        $row = AccountingExpense::query()->where('company_id', $company)->findOrFail($expense);
        if (in_array($row->getAttribute('status'), ['posted', 'reversed'], true) !== ($recognition !== null) || ($row->getAttribute('status') === 'reversed') !== $recognitionReversed) {
            $this->contract->fail('Posted/reversed expense recognition history is missing.');
        }
        $outstanding = $recognitionReversed ? '0.00' : bcsub($recognized, $settled, 2);
        $status = $recognitionReversed ? 'reversed' : ($recognition === null ? 'unrecognized' : ($recognition['plan']->getAttribute('snapshot')['cash'] !== null ? 'paid_now' : (bccomp($outstanding, '0', 2) === 0 ? 'settled' : (bccomp($settled, '0', 2) > 0 ? 'part_paid' : 'unpaid'))));

        return ['recognition' => $recognition, 'recognized' => $recognized, 'settled' => $settled, 'outstanding' => $outstanding, 'status' => $status];
    }
}

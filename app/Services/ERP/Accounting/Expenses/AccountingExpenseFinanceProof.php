<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Expenses;

use App\Models\Accounting\AccountingExpense;
use App\Models\Accounting\AccountingExpenseFinanceEvent;
use App\Models\Accounting\AccountingExpenseFinancePlan;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\Source\AccountingPreparedSourceEvent;
use App\Services\ERP\Accounting\Source\AccountingSourceEffectPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class AccountingExpenseFinanceProof
{
    public function __construct(private readonly AccountingCanonicalPayload $canonical, private readonly AccountingExpenseFinanceContract $contract, private readonly AccountingExpenseGuard $guard) {}

    /** @return array<string, mixed> */
    public function state(AccountingExpenseFinancePlan $plan): array
    {
        $out = ['id' => (int) $plan->getKey(), 'company_id' => (int) $plan->getAttribute('company_id'), 'expense_id' => (int) $plan->getAttribute('expense_id'), 'status' => (string) $plan->getAttribute('status'), 'revision' => (int) $plan->getAttribute('revision'), 'intent_hash' => (string) $plan->getAttribute('intent_hash'), 'request_key_hash' => (string) $plan->getAttribute('request_key_hash'), 'request_hash' => (string) $plan->getAttribute('request_hash')];

        foreach (['created_by', 'approved_by', 'posted_by', 'active_recognition_id', 'payment_claim_hash'] as $field) {
            $value = $plan->getAttribute($field);
            $out[$field] = $value === null ? null : ($field === 'payment_claim_hash' ? (string) $value : (int) $value);
        }

        foreach (['created_at', 'approved_at', 'posted_at'] as $field) {
            $value = $plan->getAttribute($field);
            $out[$field] = $value === null ? null : $value->format('Y-m-d H:i:s');
        }

        if ($plan->getAttribute('kind') === 'reversal') {
            $value = $plan->getAttribute('active_reversal_id');
            $out['active_reversal_id'] = $value === null ? null : (int) $value;
        }
        $out['posting_key_hash'] = $plan->getAttribute('posting_key_hash');

        return $out;
    }

    /** @param array<string, mixed>|null $before */
    public function append(User $actor, AccountingExpenseFinancePlan $plan, string $action, string $reason, ?array $before): void
    {
        $at = CarbonImmutable::now('UTC');
        $previous = $plan->getAttribute('history_hash');
        $payload = ['contract' => AccountingExpenseFinanceContract::VERSION, 'before' => $before, 'after' => $this->state($plan), 'action' => $action, 'actor_id' => (int) $actor->getKey(), 'reason' => $reason, 'occurred_at' => $at->format('Y-m-d H:i:s'), 'previous_hash' => $previous];
        $hash = $this->canonical->hash($payload);

        $event = AccountingExpenseFinanceEvent::query()->create(['company_id' => $plan->getAttribute('company_id'), 'plan_id' => $plan->getKey(), 'revision' => $plan->getAttribute('revision'), 'action' => $action, 'actor_id' => $actor->getKey(), 'reason' => $reason, 'occurred_at' => $at, 'previous_hash' => $previous, 'payload' => $payload, 'payload_hash' => $hash]);

        if (DB::table($plan->getTable())->where('id', $plan->getKey())->where('company_id', $plan->getAttribute('company_id'))->where('revision', $plan->getAttribute('revision'))->where('history_head_id', $plan->getAttribute('history_head_id'))->where('history_hash', $previous)->update(['history_head_id' => $event->getKey(), 'history_hash' => $hash]) !== 1) {
            $this->contract->fail('Finance history head changed.');
        }

        $plan->refresh();
    }

    public function verify(AccountingExpenseFinancePlan $plan): void
    {
        $snapshot = $plan->getAttribute('snapshot');

        if (! is_array($snapshot) || $this->contract->intent($snapshot, (int) $plan->getAttribute('created_by')) !== $plan->getAttribute('intent_hash') || $snapshot['expense_id'] !== (int) $plan->getAttribute('expense_id') || $snapshot['company_id'] !== (int) $plan->getAttribute('company_id') || $snapshot['kind'] !== $plan->getAttribute('kind') || $snapshot['amount'] !== $plan->getAttribute('amount') || $snapshot['currency'] !== $plan->getAttribute('currency_code') || $snapshot['date'] !== $plan->getAttribute('document_date')->format('Y-m-d')) {
            $this->contract->fail('Finance instruction snapshot changed.');
        }

        if ($snapshot['kind'] === 'reversal') {
            $value = $plan->getAttribute('active_reversal_id');
            if (($plan->getAttribute('status') === 'cancelled' ? null : $snapshot['reverses_plan']) !== ($value === null ? null : (int) $value)) {
                $this->contract->fail('Reversal reservation differs from its original operation.');
            }
        }
        $activeRecognition = $plan->getAttribute('active_recognition_id');
        $paymentClaim = $plan->getAttribute('payment_claim_hash');

        if ($plan->getAttribute('status') === 'cancelled') {
            if ($activeRecognition !== null || $paymentClaim !== null) {
                $this->contract->fail('Cancelled finance must release only its unposted reservations.');
            }
        } else {
            if (($snapshot['kind'] === 'recognition' ? $snapshot['expense_id'] : null) !== ($activeRecognition === null ? null : (int) $activeRecognition) || $paymentClaim !== $snapshot['payment_claim']) {
                $this->contract->fail('Finance reservation identity changed.');
            }
        }

        $expense = AccountingExpense::query()->where('company_id', $plan->getAttribute('company_id'))->findOrFail($plan->getAttribute('expense_id'));

        if ($snapshot['expense_hash'] !== $expense->getAttribute('approved_hash') || $snapshot['expense_hash'] !== $this->guard->intent($expense)) {
            $this->contract->fail('Approved expense identity changed.');
        }

        $before = null;
        $hash = null;
        $head = null;
        $revision = 0;

        foreach (AccountingExpenseFinanceEvent::query()->where('company_id', $plan->getAttribute('company_id'))->where('plan_id', $plan->getKey())->orderBy('revision')->get() as $event) {
            $payload = $event->getAttribute('payload');
            $revision++;

            if (! is_array($payload) || ! is_array($payload['after'] ?? null)) {
                $this->contract->fail('Finance history is malformed.');
            }

            $after = $payload['after'];

            $expected = ['contract' => AccountingExpenseFinanceContract::VERSION, 'before' => $before, 'after' => $after, 'action' => $event->getAttribute('action'), 'actor_id' => (int) $event->getAttribute('actor_id'), 'reason' => $event->getAttribute('reason'), 'occurred_at' => $event->getAttribute('occurred_at')->format('Y-m-d H:i:s'), 'previous_hash' => $hash];

            if ((int) $event->getAttribute('revision') !== $revision || $event->getAttribute('previous_hash') !== $hash || $this->canonical->hash($payload) !== $this->canonical->hash($expected) || $event->getAttribute('payload_hash') !== $this->canonical->hash($payload) || ($after['id'] ?? null) !== (int) $plan->getKey() || ($after['company_id'] ?? null) !== (int) $plan->getAttribute('company_id') || ($after['expense_id'] ?? null) !== (int) $plan->getAttribute('expense_id') || ($after['revision'] ?? null) !== $revision || ($after['intent_hash'] ?? null) !== $plan->getAttribute('intent_hash')) {
                $this->contract->fail('Finance history and protected head disagree.');
            }

            $action = $event->getAttribute('action');
            $actor = (int) $event->getAttribute('actor_id');
            $old = $before['status'] ?? null;
            $status = $after['status'] ?? null;

            $valid = match ($action) {
                'submit' => $old === null && $status === 'submitted' && $actor === $after['created_by'], 'approve' => $old === 'submitted' && $status === 'approved' && $actor === $after['approved_by'] && $actor !== $after['created_by'], 'cancel' => in_array($old, ['submitted', 'approved'], true) && $status === 'cancelled', 'post' => $old === 'approved' && $status === 'posted' && $actor === $after['posted_by'], default => false
            };

            if (! $valid) {
                $this->contract->fail('Finance review transition is invalid.');
            }

            $before = $after;
            $hash = $event->getAttribute('payload_hash');
            $head = (int) $event->getKey();
        }

        if ($before === null) {
            $this->contract->fail('Finance history is missing.');
        }

        if ($revision !== (int) $plan->getAttribute('revision') || $head !== (int) $plan->getAttribute('history_head_id') || $hash !== $plan->getAttribute('history_hash') || $this->canonical->hash($before) !== $this->canonical->hash($this->state($plan))) {
            $this->contract->fail('Missing finance history is not repaired.');
        }
    }

    /** @return list<array<string, mixed>> */
    public function lines(AccountingExpenseFinancePlan $plan): array
    {
        $s = $plan->getAttribute('snapshot');
        if ($s['kind'] === 'reversal') {
            $original = AccountingExpenseFinancePlan::query()->where('company_id', $plan->getAttribute('company_id'))->findOrFail($s['reverses_plan']);
            if ($original->getAttribute('kind') === 'reversal') {
                $this->contract->fail('Reversal-of-reversal is not admitted.');
            }
            $this->verify($original);
            $lines = [];
            foreach ($this->lines($original) as $line) {
                $debit = $line['debit'];
                $line['debit'] = $line['credit'];
                $line['credit'] = $debit;
                $line['source_line_id'] = (int) $plan->getKey();
                $lines[] = $line;
            }

            return $lines;
        }

        $id = (int) $plan->getKey();
        $base = ['source_line_type' => 'expense_finance', 'source_line_id' => $id, 'branch_id' => $s['branch_id'], 'department_id' => $s['department_id'], 'party_type' => null, 'party_id' => null, 'cash_account_id' => null];
        $lines = [];

        if ($s['kind'] === 'recognition') {
            $amount = $s['tax_treatment'] === 'included_in_cost' ? $s['amount'] : $s['net'];

            $lines[] = [...$base, 'role' => 'expense_cost', 'account_id' => $s['expense_account']['id'], 'debit' => $amount, 'credit' => '0.00'];

            if ($s['tax_account'] !== null) {
                $lines[] = [...$base, 'role' => 'input_tax', 'account_id' => $s['tax_account']['id'], 'debit' => $s['tax'], 'credit' => '0.00'];
            }

            $counter = $s['cash'] ?? $s['liability'];
            $lines[] = [...$base, 'role' => $s['cash'] === null ? 'expense_payable' : $counter['role'], 'account_id' => $counter['account_id'] ?? $counter['id'], 'cash_account_id' => $s['cash'] === null ? null : $counter['id'], 'debit' => '0.00', 'credit' => $s['amount']];
        } else {
            $lines[] = [...$base, 'role' => 'expense_payable', 'account_id' => $s['liability']['id'], 'debit' => $s['amount'], 'credit' => '0.00'];

            $lines[] = [...$base, 'role' => $s['cash']['role'], 'account_id' => $s['cash']['account_id'], 'cash_account_id' => $s['cash']['id'], 'debit' => '0.00', 'credit' => $s['amount']];
        }

        return $lines;
    }

    public function event(AccountingExpenseFinancePlan $plan): AccountingPreparedSourceEvent
    {
        $this->verify($plan);
        if ($plan->getAttribute('status') !== 'posted') {
            $this->contract->fail('Use the approved native finance posting transition.');
        }

        $s = $plan->getAttribute('snapshot');
        $lines = [];
        foreach ($this->lines($plan) as $line) {
            unset($line['account_id']);
            $lines[] = $line;
        }

        $effect = 'financial';

        return new AccountingPreparedSourceEvent((int) $plan->getAttribute('company_id'), 'expense_finance', (int) $plan->getKey(), 'posted', (string) $plan->getAttribute('posting_key_hash'), $s['currency'], $s['date'], $s['date'], $s['origin_date'], ['source_number' => 'EF-'.$plan->getKey(), 'amount' => $s['amount'], 'currency_code' => $s['currency'], 'recognition_date' => $s['date'], 'evidence_hash' => (string) $plan->getAttribute('intent_hash'), 'cash_account_id' => $s['cash'] === null ? null : $s['cash']['id'], 'original_id' => $s['expense_id']], [new AccountingSourceEffectPlan($effect, $lines, ['gl_amount' => $s['amount'], 'evidence_hash' => (string) $plan->getAttribute('intent_hash')])]);
    }
}

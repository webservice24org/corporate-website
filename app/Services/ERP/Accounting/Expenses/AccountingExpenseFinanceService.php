<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Expenses;

use App\Models\Accounting\AccountingExpenseFinanceClaim;
use App\Models\Accounting\AccountingExpenseFinanceOperation;
use App\Models\Accounting\AccountingExpenseFinancePlan;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntry;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingDecimal;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\Source\AccountingSourceOrchestrator;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class AccountingExpenseFinanceService
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingExpenseWorkflow $native, private readonly AccountingExpenseHistory $history, private readonly AccountingExpenseFinanceContract $contract, private readonly AccountingExpenseFinanceProof $proof, private readonly AccountingExpenseFinanceEvidence $evidence, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingDecimal $decimal, private readonly AccountingExpenseContract $text, private readonly AccountingMappingService $mappings, private readonly AccountingSourceOrchestrator $writer) {}

    private function permission(User $actor, string $action): void
    {
        if (! $actor->exists || ! in_array('erp.expense-finance.'.$action, AccountingExpenseFinanceContract::PERMISSIONS, true) || ! $actor->can('erp.expense-finance.'.$action) || ! $actor->can('erp.expense-finance.view') || ! $actor->can('erp.expenses.view')) {
            throw new AuthorizationException('Expense finance needs its native view/action permissions.');
        }
    }

    public function find(User $actor, int $id, string $action = 'view'): AccountingExpenseFinancePlan
    {
        $this->permission($actor, $action);
        $q = AccountingExpenseFinancePlan::query();

        if (! $actor->hasRole('super-admin')) {
            $q->whereIn('company_id', $actor->employee()->select('company_id'));
        }

        $row = $q->findOrFail($id);
        if ($action !== 'view' && $row->getAttribute('kind') === 'reversal' && ! $actor->can('erp.expense-finance.reverse')) {
            throw new AuthorizationException('Expense reversal authority is required.');
        }

return $row;
    }

    /** @param array<string, mixed> $input */
    public function create(User $actor, array $input): AccountingExpenseFinancePlan
    {
        $this->permission($actor, 'create');

        $allowed = ['expense_id', 'native_revision', 'kind', 'amount', 'date', 'liability_account_id', 'cash_account_id', 'payment_reference', 'original_plan_id', 'key', 'reason'];

        if (array_diff(array_keys($input), $allowed) !== []) {
            $this->contract->fail('Only documented native finance fields are accepted; journals, employees, suppliers and sources derive from the approved expense.');
        }

        $data = Validator::make($input, ['expense_id' => ['required', 'integer', 'min:1'], 'native_revision' => ['required', 'integer', 'min:1'], 'kind' => ['required', 'in:recognition,settlement,reversal'], 'original_plan_id' => ['nullable', 'integer', 'min:1'], 'amount' => ['nullable'], 'date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'], 'liability_account_id' => ['nullable', 'integer', 'min:1'], 'cash_account_id' => ['nullable', 'integer', 'min:1'], 'payment_reference' => ['nullable', 'string', 'max:100'], 'key' => ['required', 'string'], 'reason' => ['required', 'string', 'max:2000']])->validate();

        foreach (['expense_id', 'native_revision', 'liability_account_id', 'cash_account_id', 'original_plan_id'] as $field) {
            $data[$field] = isset($data[$field]) ? (int) $data[$field] : null;
        }

        $data['reason'] = $this->text->text($data['reason'], 2000);
        $key = $this->text->key($data['key']);

        if ($data['kind'] === 'recognition') {
            if (isset($data['amount']) || isset($data['date']) || isset($data['cash_account_id']) || ! empty($data['payment_reference'])) {
                $this->contract->fail('Recognition uses the approved native amounts, date and funding.');
            }
        } elseif ($data['kind'] === 'settlement') {
            if ($data['original_plan_id'] !== null) {
                $this->contract->fail('Only reversals accept an original finance instruction.');
            }
            if ($data['liability_account_id'] !== null || $data['cash_account_id'] === null || empty($data['date']) || empty($data['payment_reference'])) {
                $this->contract->fail('Settlement needs a concrete cash binding, date and unique payment reference; its payable derives from recognition.');
            }
            $data['amount'] = $this->decimal->money($data['amount'] ?? null);
            if (bccomp($data['amount'], '0', 2) <= 0) {
                $this->contract->fail('Settlement must be positive.');
            }
            $data['payment_reference'] = $this->text->reference($data['payment_reference']);
        }

        if ($data['kind'] === 'reversal') {
            if (! $actor->can('erp.expense-finance.reverse')) {
                throw new AuthorizationException('Expense reversal authority is required.');
            }
            if ($data['original_plan_id'] === null || empty($data['date']) || isset($data['amount']) || $data['cash_account_id'] !== null || $data['liability_account_id'] !== null || ! empty($data['payment_reference'])) {
                $this->contract->fail('A full reversal accepts only its original plan and new accounting date; accounts and amounts derive from the original.');
            }
        } elseif ($data['original_plan_id'] !== null) {
            $this->contract->fail('Original plan belongs only to reversal requests.');
        }
        $request = $data;
        unset($request['key']);
        if ($data['kind'] !== 'reversal') {
            unset($request['original_plan_id']);
        }
        $requestHash = $this->canonical->hash(['actor_id' => (int) $actor->getKey(), 'input' => $request]);

        $expense = $this->native->find($actor, $data['expense_id']);
        $company = (int) $expense->getAttribute('company_id');

        return DB::transaction(function () use ($actor, $data, $key, $requestHash, $company): AccountingExpenseFinancePlan {
            $owner = Company::query()->whereKey($company)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $company, 'erp.expense-finance.create');

            $existing = AccountingExpenseFinancePlan::query()->where('company_id', $company)->where('request_key_hash', $key)->first();

            if ($existing !== null) {
                if ($existing->getAttribute('request_hash') !== $requestHash) {
                    $this->contract->fail('Finance request key conflicts with the original command.');
                }
                $this->proof->verify($existing);
                if ($existing->getAttribute('status') === 'posted') {
                    $this->evidence->verify($company, (int) $existing->getKey());
                }

                return $existing;
            }

            $expense = $this->native->find($actor, $data['expense_id']);
            $this->history->verify($expense);

            if ((int) $expense->getAttribute('revision') !== $data['native_revision']) {
                $this->contract->fail('Expense revision changed.');
            }

            if ($data['kind'] === 'recognition') {
                if (AccountingExpenseFinancePlan::query()->where('company_id', $company)->where('active_recognition_id', $expense->getKey())->exists()) {
                    $this->contract->fail('An active finance instruction already owns expense recognition.');
                }

                $this->native->validateForRecognition($actor, $expense);
                $snapshot = $this->contract->recognition($owner, $expense, $data['liability_account_id']);
            } elseif ($data['kind'] === 'settlement') {
                $balance = $this->evidence->balance($company, (int) $expense->getKey());
                $parent = $balance['recognition'];

                if ($parent === null || bccomp($data['amount'], $balance['outstanding'], 2) > 0) {
                    $this->contract->fail('Settle only an existing recognized expense payable, without overpayment.');
                }

                $snapshot = $parent['plan']->getAttribute('snapshot');

                if ($snapshot['liability'] === null || $data['date'] < $snapshot['date']) {
                    $this->contract->fail('Paid-now expenses have no payable; settlement cannot precede recognition.');
                }

                $cash = $this->contract->binding($company, $data['cash_account_id']);
                $map = $this->mappings->resolveForSource($owner, $data['date'], [$cash['role']]);

                $claim = $this->canonical->hash(['company_id' => $company, 'payee_type' => $snapshot['payee_type'], 'payee_identity' => $snapshot['payee_id'] ?? $expense->getAttribute('payee_snapshot')['label'], 'payment_reference' => $data['payment_reference']]);

                if (AccountingExpenseFinancePlan::query()->where('company_id', $company)->where('payment_claim_hash', $claim)->exists()) {
                    $this->contract->fail('This payment reference already belongs to an active expense settlement.');
                }

                $snapshot = [...$snapshot, 'kind' => 'settlement', 'date' => $data['date'], 'origin_date' => $data['date'], 'amount' => $data['amount'], 'cash' => $cash, 'mapping' => ['version_id' => $map['version_id'], 'payload_hash' => $map['payload_hash']], 'payment_claim' => $claim, 'parent_plan' => (int) $parent['plan']->getKey(), 'parent_hash' => (string) $parent['operation']->getAttribute('payload_hash')];
            } else {
                $snapshot = app(AccountingExpenseReversal::class)->prepare($actor, $owner, $data['original_plan_id'], (int) $expense->getKey(), $data['date']);
            }
            $this->contract->live($owner, $snapshot);
            $now = CarbonImmutable::now('UTC');

            $plan = AccountingExpenseFinancePlan::query()->create(['company_id' => $company, 'expense_id' => $expense->getKey(), 'active_recognition_id' => $data['kind'] === 'recognition' ? $expense->getKey() : null, 'active_reversal_id' => $data['kind'] === 'reversal' ? $data['original_plan_id'] : null, 'payment_claim_hash' => $snapshot['payment_claim'], 'kind' => $data['kind'], 'status' => 'submitted', 'revision' => 1, 'document_date' => $snapshot['date'], 'currency_code' => $snapshot['currency'], 'amount' => $snapshot['amount'], 'snapshot' => $snapshot, 'intent_hash' => $this->contract->intent($snapshot, (int) $actor->getKey()), 'request_key_hash' => $key, 'request_hash' => $requestHash, 'created_by' => $actor->getKey(), 'created_at' => $now]);

            $this->proof->append($actor, $plan, 'submit', $data['reason'], null);

            return $plan;
        }, 3);
    }

    public function change(User $actor, int $id, int $revision, string $action, string $reason, ?string $key = null): AccountingExpenseFinancePlan
    {
        if (! in_array($action, ['approve', 'cancel', 'post'], true)) {
            $this->contract->fail('Unknown finance transition.');
        }

        $plan = $this->find($actor, $id, $action);
        $company = (int) $plan->getAttribute('company_id');
        $reason = $this->text->text($reason, 2000);
        $keyHash = $action === 'post' ? $this->text->key($key ?? '') : '';

        return DB::transaction(function () use ($actor, $id, $revision, $action, $reason, $keyHash, $company): AccountingExpenseFinancePlan {
            $owner = Company::query()->whereKey($company)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $company, 'erp.expense-finance.'.$action);
            $plan = $this->find($actor, $id, $action);
            $this->proof->verify($plan);

            if ($plan->getAttribute('status') === 'posted') {
                if ($action !== 'post' || $plan->getAttribute('posting_key_hash') !== $keyHash || (int) $plan->getAttribute('revision') !== $revision + 1) {
                    $this->contract->fail('Posted finance is immutable; replay its original post revision/key.');
                }

                $this->evidence->verify($company, $id);
                $result = $this->writer->execute($actor, $company, 'expense_finance', $id, 'posted', $keyHash);

                if ($result['mode'] !== 'replayed') {
                    $this->contract->fail('Recorded expense finance must replay without new writes.');
                }

                return $plan;
            }

            if ((int) $plan->getAttribute('revision') !== $revision || $plan->getAttribute('status') === 'cancelled') {
                $this->contract->fail('Use the current unposted instruction revision.');
            }

            $before = $this->proof->state($plan);
            $expense = $this->native->find($actor, (int) $plan->getAttribute('expense_id'));
            $this->history->verify($expense);
            $s = $plan->getAttribute('snapshot');

            if ($action === 'cancel') {
                $plan->forceFill(['status' => 'cancelled', 'revision' => $revision + 1, 'active_recognition_id' => null, 'active_reversal_id' => null, 'payment_claim_hash' => null])->save();
                $this->proof->append($actor, $plan, 'cancel', $reason, $before);

                return $plan;
            }

            if ($s['kind'] === 'recognition') {
                if ($expense->getAttribute('status') !== 'approved') {
                    $this->contract->fail('Expense must remain approved until recognition.');
                }
                $this->native->validateForRecognition($actor, $expense);
            } elseif ($s['kind'] === 'settlement') {
                $balance = $this->evidence->balance($company, (int) $expense->getKey());
                if (bccomp($s['amount'], $balance['outstanding'], 2) > 0) {
                    $this->contract->fail('Outstanding expense payable changed; overpayment is blocked.');
                }
            } else {
                app(AccountingExpenseReversal::class)->eligible($company, $s);
            }

            $this->contract->live($owner, $s);

            if ($action === 'approve') {
                if ($plan->getAttribute('status') !== 'submitted') {
                    $this->contract->fail('Only submitted instructions can be approved.');
                }

                $excluded = [(int) $plan->getAttribute('created_by'), (int) $expense->getAttribute('created_by'), (int) $expense->getAttribute('last_edited_by'), (int) $expense->getAttribute('submitted_by')];

                if (in_array((int) $actor->getKey(), $excluded, true)) {
                    throw new AuthorizationException('Expense finance approval must be independent of the instruction maker and native expense contributors.');
                }

                $plan->forceFill(['status' => 'approved', 'revision' => $revision + 1, 'approved_by' => $actor->getKey(), 'approved_at' => CarbonImmutable::now('UTC')])->save();
                $this->proof->append($actor, $plan, 'approve', $reason, $before);

                return $plan;
            }

            if ($plan->getAttribute('status') !== 'approved') {
                $this->contract->fail('Post only independently approved expense finance.');
            }

            if (AccountingSourceEffect::query()->where('company_id', $company)->where('source_kind', 'expense_finance')->where('source_id', $id)->exists() || AccountingExpenseFinanceOperation::query()->where('company_id', $company)->where('plan_id', $id)->exists()) {
                $this->contract->fail('Financial members already exist under an unposted instruction. Missing manifests are not repaired.');
            }

            $plan->forceFill(['status' => 'posted', 'revision' => $revision + 1, 'posted_by' => $actor->getKey(), 'posted_at' => CarbonImmutable::now('UTC'), 'posting_key_hash' => $keyHash])->save();
            $this->proof->append($actor, $plan, 'post', $reason, $before);

            $result = AccountingExpenseFinanceContext::run($company, $id, fn (): array => $this->writer->execute($actor, $company, 'expense_finance', $id, 'posted', $keyHash));

            if ($result['mode'] !== 'recorded' || count($result['effect_ids']) !== 1) {
                $this->contract->fail('Expense finance requires enabled accounting and a complete recorded journal; no bypass/placeholder posting is allowed.');
            }

            $effect = AccountingSourceEffect::query()->where('company_id', $company)->findOrFail($result['effect_ids'][0]);
            $journal = JournalEntry::query()->where('company_id', $company)->findOrFail($effect->getAttribute('journal_entry_id'));

            $payload = $this->evidence->operationPayload($plan, $effect, $journal);

            AccountingExpenseFinanceOperation::query()->create(['company_id' => $company, 'plan_id' => $id, 'expense_id' => $expense->getKey(), 'recognition_expense_id' => $s['kind'] === 'recognition' ? $expense->getKey() : null, 'effect_id' => $effect->getKey(), 'payload' => $payload, 'payload_hash' => $this->canonical->hash($payload)]);

            if ($s['kind'] === 'recognition') {
                AccountingExpenseFinanceClaim::query()->create(['company_id' => $company, 'expense_id' => $expense->getKey(), 'plan_id' => $id, 'claim_hash' => $expense->getAttribute('claim_hash'), 'intent_hash' => $expense->getAttribute('approved_hash')]);

                $nativeBefore = $this->history->summary($expense);
                $expense->forceFill(['status' => 'posted', 'revision' => (int) $expense->getAttribute('revision') + 1, 'updated_by' => $actor->getKey()])->save();
                $this->history->append($expense, $actor, 'post', 'Expense finance EF-'.$id, $nativeBefore);
                $expense->refresh();
            }

            if ($s['kind'] === 'reversal' && $s['reverses_kind'] === 'recognition') {
                $nativeBefore = $this->history->summary($expense);
                $expense->forceFill(['status' => 'reversed', 'revision' => (int) $expense->getAttribute('revision') + 1, 'updated_by' => $actor->getKey()])->save();
                $this->history->append($expense, $actor, 'reverse', 'Expense reversal EF-'.$id, $nativeBefore);
                $expense->refresh();
            }
            $this->evidence->balance($company, (int) $expense->getKey());

            return $plan;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function payable(User $actor, int $expense): array
    {
        $this->permission($actor, 'view');
        $found = $this->native->find($actor, $expense);
        $company = (int) $found->getAttribute('company_id');

        return DB::transaction(function () use ($actor, $expense, $company): array {
            Company::query()->whereKey($company)->lockForUpdate()->firstOrFail();
            $row = $this->native->find($actor, $expense);
            $this->history->verify($row);
            $b = $this->evidence->balance($company, $expense);
            $plans = [];

            foreach (AccountingExpenseFinancePlan::query()->where('company_id', $company)->where('expense_id', $expense)->orderByDesc('id')->limit(100)->get() as $plan) {
                $this->proof->verify($plan);
                $plans[] = ['id' => (int) $plan->getKey(), 'kind' => (string) $plan->getAttribute('kind'), 'status' => (string) $plan->getAttribute('status'), 'revision' => (int) $plan->getAttribute('revision'), 'amount' => (string) $plan->getAttribute('amount'), 'date' => $plan->getAttribute('document_date')->format('Y-m-d')];
            }

            return ['expense_id' => $expense, 'company_id' => $company, 'currency' => (string) $row->getAttribute('currency_code'), 'native_revision' => (int) $row->getAttribute('revision'), 'native_status' => (string) $row->getAttribute('status'), 'payee_type' => (string) $row->getAttribute('payee_type'), 'financial_status' => $b['status'], 'recognized_payable' => $b['recognized'], 'settled' => $b['settled'], 'outstanding' => $b['outstanding'], 'recognition_plan_id' => $b['recognition'] === null ? null : (int) $b['recognition']['plan']->getKey(), 'plans' => $plans, 'plans_truncated' => AccountingExpenseFinancePlan::query()->where('company_id', $company)->where('expense_id', $expense)->count() > 100, 'cohort' => 'standalone expense payables; separate from purchase-bill AP and payroll'];
        });
    }

    /** @return array<string, mixed> */
    public function detail(User $actor, int $id): array
    {
        $found = $this->find($actor, $id);
        $company = (int) $found->getAttribute('company_id');

        return DB::transaction(function () use ($actor, $id, $company): array {
            Company::query()->whereKey($company)->lockForUpdate()->firstOrFail();
            $row = $this->find($actor, $id);
            $this->proof->verify($row);
            $s = $row->getAttribute('snapshot');
            $balance = $this->evidence->balance($company, $s['expense_id']);
            $op = $row->getAttribute('status') === 'posted' ? $this->evidence->verify($company, $id) : null;

            return ['data' => ['id' => $id, 'company_id' => $company, 'expense_id' => $s['expense_id'], 'kind' => $s['kind'], 'status' => $row->getAttribute('status'), 'revision' => (int) $row->getAttribute('revision'), 'date' => $s['date'], 'amount' => $s['amount'], 'currency' => $s['currency'], 'payee_type' => $s['payee_type'], 'payee_id' => $s['payee_id'], 'cash_account_id' => $s['cash']['id'] ?? null, 'liability_account_id' => $s['liability']['id'] ?? null, 'approved_by' => $row->getAttribute('approved_by') === null ? null : (int) $row->getAttribute('approved_by'), 'journal_id' => $op === null ? null : (int) $op['journal']->getKey()], 'payable' => ['recognized' => $balance['recognized'], 'settled' => $balance['settled'], 'outstanding' => $balance['outstanding'], 'status' => $balance['status']], 'cohort' => 'dedicated expense payables; purchase-bill AP and payroll liabilities remain separate'];
        });
    }
}

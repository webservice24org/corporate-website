<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reconciliation;

use App\Models\Accounting\AccountingBankReconciliation;
use App\Models\Accounting\AccountingBankReconciliationLeg;
use App\Models\Accounting\AccountingBankReconciliationOperation;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\Adjustments\AccountingBankAdjustmentMatchPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Rebuilds every bank allocation in commit order; historical overspending cannot be hidden by a later undo. */
final class AccountingBankReconciliationLedger
{
    public function __construct(private readonly AccountingBankReconciliationSources $sources, private readonly AccountingBankReconciliationContract $contract, private readonly AccountingCanonicalPayload $canonical) {}

    /** Internal caller holds the company's authorized transaction/lock. @return array<string, mixed> */
    public function read(User $actor, int $company, int $bank, ?int $candidateStatement = null): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Matching ledger requires a company snapshot transaction.');
        }
        app(AccountingBankFinalizationEvidence::class)->bank($company, $bank);
        $roots = AccountingBankReconciliation::query()->where('company_id', $company)->where('cash_account_id', $bank)->orderBy('id')->get();
        $statements = [];
        $rootState = [];
        $latest = '1900-01-01';
        foreach ($roots as $root) {
            $statementId = (int) $root->getAttribute('statement_id');
            $source = $this->sources->statement($company, $bank, $statementId);
            $statements[$statementId] = $source;
            $snapshot = $this->rootSnapshot($root, $source['identity']);
            if ((int) $root->getAttribute('created_by') < 1 || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $root->getAttribute('creation_key_hash')) || $root->getAttribute('contract_version') !== AccountingBankReconciliationContract::VERSION || $root->getAttribute('currency_code') !== $source['identity']['currency_code'] || $this->canonical->hash($snapshot) !== $root->getAttribute('snapshot_hash') || $this->canonical->hash($root->getAttribute('snapshot')) !== $root->getAttribute('snapshot_hash') || $root->getAttribute('creation_request_hash') !== $this->creationRequest((int) $root->getAttribute('created_by'), $statementId, (string) $root->getAttribute('reason'))) {
                $this->fail();
            }
            $rootState[(int) $root->getKey()] = ['row' => $root, 'statement_id' => $statementId, 'revision' => 1, 'head' => (string) $root->getAttribute('snapshot_hash'), 'last_id' => null, 'last_actor' => (int) $root->getAttribute('created_by'), 'last_at' => $root->getAttribute('created_at')->format('Y-m-d H:i:s')];
            $latest = max($latest, $source['identity']['ends_on']);
        }
        if ($candidateStatement !== null && ! isset($statements[$candidateStatement])) {
            $source = $this->sources->statement($company, $bank, $candidateStatement);
            $statements[$candidateStatement] = $source;
            $latest = max($latest, $source['identity']['ends_on']);
        }
        $book = $this->sources->book($actor, $company, $bank, $latest);
        $usedStatement = [];
        $usedBook = [];
        $operations = [];
        $active = [];
        foreach ($statements as $source) {
            if ($source['identity']['currency_code'] !== $book['currency_code']) {
                $this->fail();
            }
        }
        $rows = AccountingBankReconciliationOperation::query()->where('company_id', $company)->where('cash_account_id', $bank)->orderBy('id')->get();
        foreach ($rows as $operation) {
            $rootId = (int) $operation->getAttribute('reconciliation_id');
            if (! isset($rootState[$rootId])) {
                $this->fail();
            }
            $state = $rootState[$rootId];
            $source = $statements[$state['statement_id']];
            $payload = $operation->getAttribute('payload');
            if (! is_array($payload) || ! isset($payload['statement_legs'], $payload['book_legs']) || ! is_array($payload['statement_legs']) || ! is_array($payload['book_legs'])) {
                $this->fail();
            }
            $kind = (string) $operation->getAttribute('kind');
            $target = $operation->getAttribute('target_match_id') === null ? null : (int) $operation->getAttribute('target_match_id');
            $revision = (int) $operation->getAttribute('revision_from');
            if ($revision !== $state['revision']) {
                $this->fail();
            }
            if ($kind === 'match' && $target === null) {
                $command = ['kind' => $kind, 'revision' => $revision, 'reason' => $this->contract->reason((string) $operation->getAttribute('reason')), 'target_match_id' => null,
                    'statement_allocations' => $this->allocationInput($payload['statement_legs']), 'book_allocations' => $this->allocationInput($payload['book_legs'])];
                $plan = $this->plan($command, $source, $book['rows'], $usedStatement, $usedBook);
            } elseif ($kind === 'unmatch' && $target !== null && isset($active[$target], $operations[$target]) && $operations[$target]['root_id'] === $rootId) {
                $command = ['kind' => $kind, 'revision' => $revision, 'reason' => $this->contract->reason((string) $operation->getAttribute('reason')), 'target_match_id' => $target, 'statement_allocations' => [], 'book_allocations' => []];
                $plan = $operations[$target]['plan'];
            } else {
                $this->fail();
            }
            $request = $this->contract->requestHash((int) $operation->getAttribute('actor_id'), $rootId, $command);
            $body = $this->body($state['row'], $command, $plan, (int) $operation->getAttribute('actor_id'), (string) $operation->getAttribute('key_hash'), $request, $operation->getAttribute('occurred_at')->format('Y-m-d H:i:s'), $state['head'], $target === null ? null : $operations[$target]['hash']);
            $hash = $this->canonical->hash($body);
            if (! preg_match('/\A[a-f0-9]{64}\z/', (string) $operation->getAttribute('key_hash')) || (int) $operation->getAttribute('actor_id') < 1 || $operation->getAttribute('request_hash') !== $request || $operation->getAttribute('direction') !== $plan['direction'] || $operation->getAttribute('total') !== $plan['total'] || $operation->getAttribute('payload_hash') !== $hash || $this->canonical->hash($payload) !== $hash) {
                $this->fail();
            }
            $this->legs($operation, $plan);
            $this->apply($usedStatement, $usedBook, $plan, $kind === 'match');
            $id = (int) $operation->getKey();
            $operations[$id] = ['root_id' => $rootId, 'hash' => $hash, 'plan' => $plan, 'kind' => $kind, 'actor_id' => (int) $operation->getAttribute('actor_id'), 'reason' => (string) $operation->getAttribute('reason'), 'occurred_at' => $operation->getAttribute('occurred_at')->format(DATE_ATOM), 'target_match_id' => $target];
            if ($kind === 'match') {
                $active[$id] = true;
            } else {
                unset($active[$target]);
            }
            $rootState[$rootId] = [...$state, 'revision' => $revision + 1, 'head' => $hash, 'last_id' => $id, 'last_actor' => (int) $operation->getAttribute('actor_id'), 'last_at' => $operation->getAttribute('occurred_at')->format('Y-m-d H:i:s')];
        }
        foreach ($rootState as $state) {
            $root = $state['row'];
            $last = $root->getAttribute('last_operation_id') === null ? null : (int) $root->getAttribute('last_operation_id');
            if ((int) $root->getAttribute('revision') !== $state['revision'] || $root->getAttribute('last_operation_hash') !== $state['head'] || $last !== $state['last_id'] || (int) $root->getAttribute('updated_by') !== $state['last_actor'] || $root->getAttribute('updated_at')->format('Y-m-d H:i:s') !== $state['last_at']) {
                $this->fail();
            }
        }
        foreach ($active as $id => $_active) {
            app(AccountingBankAdjustmentMatchPolicy::class)->verify($company, $bank, $operations[$id]['plan']);
        }

        return ['roots' => $rootState, 'statements' => $statements, 'book' => $book['rows'], 'used_statement' => $usedStatement, 'used_book' => $usedBook, 'operations' => $operations, 'active' => $active];
    }

    /** @param array<string, mixed> $command @param array<string, mixed> $source @param array<int, array<string, mixed>> $book @param array<int, string> $usedStatement @param array<int, string> $usedBook @return array<string, mixed> */
    public function plan(array $command, array $source, array $book, array $usedStatement, array $usedBook): array
    {
        $eligible = array_filter($book, static fn (array $row): bool => $row['date'] <= $source['identity']['ends_on']);
        $statement = $this->contract->freeze($command['statement_allocations'], $source['rows'], $usedStatement);
        $ledger = $this->contract->freeze($command['book_allocations'], $eligible, $usedBook);
        if ($statement['direction'] !== $ledger['direction'] || $statement['total'] !== $ledger['total']) {
            $this->fail('Selected statement and book amounts must agree exactly in the same direction.');
        }

        return ['direction' => $statement['direction'], 'total' => $statement['total'], 'statement_legs' => $statement['legs'], 'book_legs' => $ledger['legs']];
    }

    /** @param array<string, mixed> $command @param array<string, mixed> $plan @return array<string, mixed> */
    public function body(AccountingBankReconciliation $root, array $command, array $plan, int $actor, string $keyHash, string $requestHash, string $at, string $previous, ?string $targetHash): array
    {
        return ['version' => AccountingBankReconciliationContract::VERSION, 'company_id' => (int) $root->getAttribute('company_id'), 'cash_account_id' => (int) $root->getAttribute('cash_account_id'), 'reconciliation_id' => (int) $root->getKey(),
            'kind' => $command['kind'], 'revision_from' => $command['revision'], 'actor_id' => $actor, 'key_hash' => $keyHash, 'request_hash' => $requestHash, 'reason_hash' => hash('sha256', $command['reason']), 'occurred_at' => $at,
            'previous_hash' => $previous, 'root_snapshot_hash' => (string) $root->getAttribute('snapshot_hash'), 'target_match_id' => $command['target_match_id'], 'target_hash' => $targetHash,
            'direction' => $plan['direction'], 'total' => $plan['total'], 'statement_legs' => $plan['statement_legs'], 'book_legs' => $plan['book_legs']];
    }

    /** @param array<string, mixed> $identity @return array<string, mixed> */
    public function rootSnapshot(AccountingBankReconciliation $root, array $identity): array
    {
        return ['version' => AccountingBankReconciliationContract::VERSION, 'identity' => $identity, 'actor_id' => (int) $root->getAttribute('created_by'), 'created_at' => $root->getAttribute('created_at')->format('Y-m-d H:i:s'),
            'key_hash' => (string) $root->getAttribute('creation_key_hash'), 'reason_hash' => hash('sha256', $this->contract->reason((string) $root->getAttribute('reason')))];
    }

    public function creationRequest(int $actor, int $statement, string $reason): string
    {
        return $this->canonical->hash(['actor_id' => $actor, 'statement_id' => $statement, 'reason_hash' => hash('sha256', $this->contract->reason($reason))]);
    }

    /**
     * @param  array<int, string>  $usedStatement
     * @param  array<int, string>  $usedBook
     * @param  array<string, mixed>  $plan
     *
     * @param-out array<int, string> $usedStatement
     * @param-out array<int, string> $usedBook
     */
    public function apply(array &$usedStatement, array &$usedBook, array $plan, bool $matching): void
    {
        foreach ($plan['statement_legs'] as $leg) {
            $id = (int) $leg['id'];
            $amount = (string) $leg['amount'];
            $current = $usedStatement[$id] ?? '0.00';
            $next = $matching ? bcadd($current, $amount, 2) : bcsub($current, $amount, 2);
            if (bccomp($next, '0', 2) < 0) {
                $this->fail();
            }
            $usedStatement[$id] = $next;
        }
        foreach ($plan['book_legs'] as $leg) {
            $id = (int) $leg['id'];
            $amount = (string) $leg['amount'];
            $current = $usedBook[$id] ?? '0.00';
            $next = $matching ? bcadd($current, $amount, 2) : bcsub($current, $amount, 2);
            if (bccomp($next, '0', 2) < 0) {
                $this->fail();
            }
            $usedBook[$id] = $next;
        }
    }

    /** @param array<int|string, mixed> $legs @return list<array{id: int, amount: string}> */
    private function allocationInput(array $legs): array
    {
        $input = [];
        foreach ($legs as $leg) {
            if (! is_array($leg) || ! isset($leg['id'], $leg['amount'])) {
                $this->fail();
            } $input[] = ['id' => $leg['id'], 'amount' => $leg['amount']];
        }

        return $this->contract->allocations($input);
    }

    /** @param array<string, mixed> $plan */
    private function legs(AccountingBankReconciliationOperation $operation, array $plan): void
    {
        $rows = AccountingBankReconciliationLeg::query()->where('company_id', $operation->getAttribute('company_id'))->where('cash_account_id', $operation->getAttribute('cash_account_id'))->where('operation_id', $operation->getKey())->get();
        $actual = ['statement_legs' => [], 'book_legs' => []];
        foreach ($rows as $row) {
            $side = $row->getAttribute('side');
            $statement = $row->getAttribute('statement_line_id');
            $book = $row->getAttribute('journal_line_id');
            if (($side === 'statement' && ($statement === null || $book !== null)) || ($side === 'book' && ($book === null || $statement !== null)) || ! in_array($side, ['statement', 'book'], true)) {
                $this->fail();
            }
            $actual[$side.'_legs'][] = ['id' => (int) ($side === 'statement' ? $statement : $book), 'amount' => (string) $row->getAttribute('amount'), 'direction' => (string) $row->getAttribute('direction'), 'proof_hash' => (string) $row->getAttribute('proof_hash')];
        }
        foreach (['statement_legs', 'book_legs'] as $side) {
            usort($actual[$side], static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
            if ($this->canonical->hash($actual[$side]) !== $this->canonical->hash($plan[$side])) {
                $this->fail();
            }
        }
    }

    private function fail(string $message = 'Reconciliation source proofs, operation chain, relational legs and protected history head must agree. Missing evidence cannot be repaired.'): never
    {
        throw ValidationException::withMessages(['bank_reconciliation_integrity' => $message]);
    }
}

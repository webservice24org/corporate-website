<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reconciliation;

use App\Models\Accounting\AccountingBankReconciliation;
use App\Models\Accounting\AccountingBankReconciliationLeg;
use App\Models\Accounting\AccountingBankReconciliationOperation;
use App\Models\Accounting\AccountingBankStatement;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\Adjustments\AccountingBankAdjustmentMatchPolicy;
use App\Services\ERP\Accounting\Statements\AccountingBankStatementService;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Native matching only. No GL/statement mutation, implicit fees, write-offs, netting, approval or finalization. */
final class AccountingBankReconciliationService
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingBankStatementService $statements, private readonly AccountingBankReconciliationContract $contract, private readonly AccountingBankReconciliationLedger $ledger, private readonly AccountingCanonicalPayload $canonical, private readonly AccountingBankReconciliationReport $reports) {}

    public function open(User $actor, int $statementId, string $reason, string $key): AccountingBankReconciliation
    {
        $this->permission($actor, 'erp.bank-reconciliations.create');
        $statement = $this->statements->find($actor, $statementId);
        $company = (int) $statement->getAttribute('company_id');
        $bank = (int) $statement->getAttribute('cash_account_id');
        $reason = $this->contract->reason($reason);
        $keyHash = $this->contract->key($key);
        $request = $this->ledger->creationRequest((int) $actor->getKey(), $statementId, $reason);

        return DB::transaction(function () use ($actor, $statementId, $company, $bank, $reason, $keyHash, $request): AccountingBankReconciliation {
            $owner = Company::query()->whereKey($company)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $company, 'erp.bank-reconciliations.create');
            $existing = AccountingBankReconciliation::query()->where('company_id', $company)->where('creation_key_hash', $keyHash)->first();
            if ($existing !== null) {
                if ($existing->getAttribute('creation_request_hash') !== $request || (int) $existing->getAttribute('statement_id') !== $statementId || (int) $existing->getAttribute('created_by') !== (int) $actor->getKey()) {
                    $this->fail('Creation key belongs to another reconciliation intent.');
                }
                $this->ledger->read($actor, $company, $bank);

                return $existing;
            }
            app(AccountingBankFinalizationGuard::class)->assertDate($company, $bank, AccountingBankStatement::query()->where('company_id', $company)->findOrFail($statementId)->getAttribute('ends_on')->format('Y-m-d'));
            if ($owner->getAttribute('status') !== 'active') {
                $this->fail('New matching work requires an active company.');
            }
            if (AccountingBankReconciliation::query()->where('company_id', $company)->where('statement_id', $statementId)->exists()) {
                $this->fail('This statement already has its reconciliation workspace. Reuse its existing ID.');
            }
            $state = $this->ledger->read($actor, $company, $bank, $statementId);
            $source = $state['statements'][$statementId];
            $now = CarbonImmutable::now('UTC');
            $root = new AccountingBankReconciliation;
            $root->forceFill(['company_id' => $company, 'cash_account_id' => $bank, 'statement_id' => $statementId, 'currency_code' => $source['identity']['currency_code'],
                'contract_version' => AccountingBankReconciliationContract::VERSION, 'reason' => $reason, 'creation_key_hash' => $keyHash, 'creation_request_hash' => $request,
                'created_by' => (int) $actor->getKey(), 'updated_by' => (int) $actor->getKey(), 'created_at' => $now, 'updated_at' => $now, 'last_operation_id' => null]);
            $snapshot = $this->ledger->rootSnapshot($root, $source['identity']);
            $hash = $this->canonical->hash($snapshot);
            $root->forceFill(['snapshot' => $snapshot, 'snapshot_hash' => $hash, 'last_operation_hash' => $hash])->save();
            $this->ledger->read($actor, $company, $bank);

            return $root;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function match(User $actor, int $id, array $input): AccountingBankReconciliationOperation
    {
        return $this->change($actor, $id, 'match', $input);
    }

    /** Whole-group compensation; partial matching remains possible through explicit new groups. @param array<string, mixed> $input */
    public function unmatch(User $actor, int $id, array $input): AccountingBankReconciliationOperation
    {
        return $this->change($actor, $id, 'unmatch', $input);
    }

    public function find(User $actor, int $id): AccountingBankReconciliation
    {
        $this->permission($actor, 'erp.bank-reconciliations.view');
        $query = AccountingBankReconciliation::query();
        if (! $actor->hasRole('super-admin')) {
            $query->whereIn('company_id', $actor->employee()->select('company_id'));
        }

        return $query->findOrFail($id);
    }

    /** Verified, provisional DTO. No model, source body, key/hash or financial write is returned. @return array<string, mixed> */
    public function review(User $actor, int $id): array
    {
        $root = $this->find($actor, $id);
        $company = (int) $root->getAttribute('company_id');
        $bank = (int) $root->getAttribute('cash_account_id');

        return DB::transaction(function () use ($actor, $id, $company, $bank): array {
            Company::query()->whereKey($company)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $company, 'erp.bank-reconciliations.view');
            $state = $this->ledger->read($actor, $company, $bank);

            return $this->reports->project($id, $state);
        }, 3);
    }

    /** @param array<string, mixed> $input */
    private function change(User $actor, int $id, string $kind, array $input): AccountingBankReconciliationOperation
    {
        $permission = 'erp.bank-reconciliations.'.$kind;
        $this->permission($actor, $permission);
        $found = $this->find($actor, $id);
        $company = (int) $found->getAttribute('company_id');
        $bank = (int) $found->getAttribute('cash_account_id');
        $command = $this->contract->command($kind, $input);
        $request = $this->contract->requestHash((int) $actor->getKey(), $id, $command);

        return DB::transaction(function () use ($actor, $id, $company, $bank, $permission, $command, $request, $kind): AccountingBankReconciliationOperation {
            $owner = Company::query()->whereKey($company)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $company, $permission);
            $root = AccountingBankReconciliation::query()->where('company_id', $company)->where('cash_account_id', $bank)->lockForUpdate()->findOrFail($id);
            $existing = AccountingBankReconciliationOperation::query()->where('company_id', $company)->where('key_hash', $command['key_hash'])->first();
            if ($existing !== null) {
                if ((int) $existing->getAttribute('reconciliation_id') !== $id || (int) $existing->getAttribute('actor_id') !== (int) $actor->getKey() || $existing->getAttribute('request_hash') !== $request || $existing->getAttribute('kind') !== $kind) {
                    $this->fail('Operation key belongs to another actor, revision, group or reconciliation.');
                }
                $this->ledger->read($actor, $company, $bank);

                return $existing;
            }
            $state = $this->ledger->read($actor, $company, $bank);
            $current = $state['roots'][$id];
            app(AccountingBankFinalizationGuard::class)->assertDate($company, $bank, $state['statements'][$current['statement_id']]['identity']['ends_on']);
            if ($owner->getAttribute('status') !== 'active' || $command['revision'] !== $current['revision']) {
                $this->fail('Company or reconciliation revision changed. Reload before matching.');
            }
            if ($kind === 'match') {
                $plan = $this->ledger->plan($command, $state['statements'][$current['statement_id']], $state['book'], $state['used_statement'], $state['used_book']);
                $targetHash = null;
                app(AccountingBankAdjustmentMatchPolicy::class)->verify($company, $bank, $plan);
            } else {
                $target = $command['target_match_id'];
                if (! isset($state['active'][$target], $state['operations'][$target]) || $state['operations'][$target]['root_id'] !== $id) {
                    $this->fail('Only an active match group in this reconciliation can be undone once.');
                }
                $plan = $state['operations'][$target]['plan'];
                $targetHash = $state['operations'][$target]['hash'];
            }
            $now = CarbonImmutable::now('UTC');
            $body = $this->ledger->body($root, $command, $plan, (int) $actor->getKey(), $command['key_hash'], $request, $now->format('Y-m-d H:i:s'), $current['head'], $targetHash);
            $hash = $this->canonical->hash($body);
            $operation = AccountingBankReconciliationOperation::query()->create(['company_id' => $company, 'cash_account_id' => $bank, 'reconciliation_id' => $id, 'kind' => $kind, 'revision_from' => $command['revision'],
                'key_hash' => $command['key_hash'], 'request_hash' => $request, 'reason' => $command['reason'], 'actor_id' => (int) $actor->getKey(), 'occurred_at' => $now,
                'target_match_id' => $command['target_match_id'], 'direction' => $plan['direction'], 'total' => $plan['total'], 'payload' => $body, 'payload_hash' => $hash]);
            foreach (['statement_legs' => 'statement', 'book_legs' => 'book'] as $side => $label) {
                foreach ($plan[$side] as $leg) {
                    AccountingBankReconciliationLeg::query()->create(['company_id' => $company, 'cash_account_id' => $bank, 'operation_id' => (int) $operation->getKey(), 'side' => $label,
                        'statement_line_id' => $label === 'statement' ? $leg['id'] : null, 'journal_line_id' => $label === 'book' ? $leg['id'] : null, 'amount' => $leg['amount'], 'direction' => $leg['direction'], 'proof_hash' => $leg['proof_hash']]);
                }
            }
            $root->forceFill(['revision' => $current['revision'] + 1, 'last_operation_id' => (int) $operation->getKey(), 'last_operation_hash' => $hash, 'updated_by' => (int) $actor->getKey(), 'updated_at' => $now])->save();
            $this->ledger->read($actor, $company, $bank);

            return $operation;
        }, 3);
    }

    private function permission(User $actor, string $permission): void
    {
        if (! $actor->exists || ! in_array($permission, AccountingBankReconciliationContract::PERMISSIONS, true) || ! $actor->can($permission) || ! $actor->can('erp.bank-reconciliations.view') || ! $actor->can('erp.bank-statements.view') || ! $actor->can('erp.cash-books.view')) {
            throw new AuthorizationException('Native reconciliation, statement-view and bankbook-view authority are required.');
        }
    }

    private function authorize(User $actor, int $company, string $permission): void
    {
        $this->permission($actor, $permission);
        $this->access->authorize($actor, $company, $permission);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['bank_reconciliation' => $message]);
    }
}

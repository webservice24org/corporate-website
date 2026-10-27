<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Adjustments;

use App\Models\Accounting\AccountingBankAdjustment;
use App\Models\Accounting\AccountingBankAdjustmentAuditEvent;
use App\Models\Accounting\AccountingBankAdjustmentOperation;
use App\Models\Accounting\AccountingBankStatementLine;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingConfigurationGuard;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\Reconciliation\AccountingBankReconciliationLedger;
use App\Services\ERP\Accounting\Source\AccountingSourceOrchestrator;
use App\Services\ERP\Accounting\Source\AccountingSourcePeriod;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AccountingBankAdjustmentWorkflow
{
    public function __construct(private readonly PurchaseCompanyAccess $access, private readonly AccountingBankAdjustmentContract $contract, private readonly AccountingBankAdjustmentProof $proof, private readonly AccountingBankAdjustmentEvidence $evidence, private readonly AccountingConfigurationGuard $configuration, private readonly AccountingMappingService $mappings, private readonly AccountingBusinessDate $dates, private readonly AccountingSourcePeriod $periods, private readonly AccountingBankReconciliationLedger $matching, private readonly AccountingSourceOrchestrator $orchestrator, private readonly AccountingCanonicalPayload $canonical) {}

    /** @param array<string, mixed> $input */
    public function prepare(User $actor, array $input): AccountingBankAdjustment
    {
        return $this->create($actor, $input, null);
    }

    /** A separate full opposite bank statement row is required; no reverse-of-reversal or partial native refund. @param array<string, mixed> $input */
    public function prepareReversal(User $actor, int $original, array $input): AccountingBankAdjustment
    {
        $this->find($actor, $original, 'erp.bank-adjustments.reverse');

        return $this->create($actor, $input, $original);
    }

    public function find(User $actor, int $id, string $permission = 'erp.bank-adjustments.view'): AccountingBankAdjustment
    {
        $this->permission($actor, $permission);
        $query = AccountingBankAdjustment::query();
        if (! $actor->hasRole('super-admin')) {
            $query->whereIn('company_id', $actor->employee()->select('company_id'));
        }

        return $query->findOrFail($id);
    }

    /** @param array<string, mixed> $input */
    private function create(User $actor, array $input, ?int $original): AccountingBankAdjustment
    {
        $permission = $original === null ? 'erp.bank-adjustments.create' : 'erp.bank-adjustments.reverse';
        $this->permission($actor, $permission);
        $allowed = $original === null ? ['statement_line_id', 'kind', 'counter_account_id', 'document_date', 'reason', 'key'] : ['statement_line_id', 'document_date', 'reason', 'key'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            $this->fail('Only statement source, supported kind/counter, date, reason and request key are accepted.');
        }
        $rules = ['statement_line_id' => ['required', 'integer', 'min:1'], 'document_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'], 'reason' => ['required', 'string'], 'key' => ['required', 'string']];
        if ($original === null) {
            $rules['kind'] = ['required', 'in:charge,interest'];
            $rules['counter_account_id'] = ['required', 'integer', 'min:1'];
        }
        $data = Validator::make($input, $rules)->validate();
        $data['reason'] = $this->contract->reason($data['reason']);
        $keyHash = $this->contract->key($data['key']);
        $query = AccountingBankStatementLine::query();
        if (! $actor->hasRole('super-admin')) {
            $query->whereIn('company_id', $actor->employee()->select('company_id'));
        }
        $line = $query->findOrFail((int) $data['statement_line_id']);
        $companyId = (int) $line->getAttribute('company_id');
        $request = $data;
        unset($request['key']);
        if (isset($request['counter_account_id'])) {
            $request['counter_account_id'] = (int) $request['counter_account_id'];
        } $request['statement_line_id'] = (int) $request['statement_line_id'];
        $requestHash = $this->canonical->hash(['actor_id' => (int) $actor->getKey(), 'original_id' => $original, 'input' => $request]);

        return DB::transaction(function () use ($actor, $companyId, $permission, $data, $keyHash, $requestHash, $original): AccountingBankAdjustment {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $companyId, $permission);
            $existing = AccountingBankAdjustment::query()->where('company_id', $companyId)->where('creation_key_hash', $keyHash)->first();
            if ($existing !== null) {
                if ((int) $existing->getAttribute('created_by') !== (int) $actor->getKey() || $existing->getAttribute('creation_request_hash') !== $requestHash) {
                    $this->fail('Creation key belongs to a different bank adjustment intent.');
                }
                $this->proof->verify($existing);
                if ($existing->getAttribute('status') === 'posted') {
                    $this->evidence->family($companyId, (int) $existing->getKey());
                }

return $existing;
            }
            $this->configuration->lockForSetup($company);
            $source = $this->proof->source($companyId, (int) $data['statement_line_id']);
            $bank = AccountingCashAccount::query()->where('company_id', $companyId)->findOrFail($source['cash_account_id']);
            $this->available($actor, $companyId, $source);
            if (AccountingBankAdjustment::query()->where('company_id', $companyId)->where('active_statement_line_id', $source['line_id'])->exists()) {
                $this->fail('This bank statement movement already has an active or posted adjustment.');
            }
            if ($original === null) {
                if ($bank->getAttribute('status') !== 'active') {
                    $this->fail('A new bank charge/interest requires an active bank binding.');
                }
                $kind = $data['kind'];
                $counter = (int) $data['counter_account_id'];
                $counterSnapshot = $this->contract->counter($companyId, $counter, $kind, true);
                $bindingSnapshot = $this->contract->binding($bank);
                $mapping = $this->mappings->resolveForSource($company, $data['document_date'], [$bindingSnapshot['role']]);
                if (($mapping['accounts'][$bindingSnapshot['role']] ?? null) !== $bindingSnapshot['account_id']) {
                    $this->fail('Current profile does not match the concrete bank binding.');
                }
                $originalHash = null;
            } else {
                $parent = AccountingBankAdjustment::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($original);
                $recognized = $this->evidence->verify($companyId, $original);
                $this->evidence->family($companyId, $original);
                if ($parent->getAttribute('reverses_id') !== null || AccountingBankAdjustment::query()->where('company_id', $companyId)->where('active_reversal_of_id', $original)->exists()) {
                    $this->fail('Only one native full reversal of an original adjustment is allowed.');
                }
                $kind = (string) $parent->getAttribute('kind');
                $counter = (int) $parent->getAttribute('counter_account_id');
                $counterSnapshot = $parent->getAttribute('counter_snapshot');
                $bindingSnapshot = $parent->getAttribute('binding_snapshot');
                $mapping = ['version_id' => (int) $parent->getAttribute('mapping_version_id'), 'payload_hash' => (string) $parent->getAttribute('mapping_hash')];
                $originalHash = (string) $recognized['operation']->getAttribute('payload_hash');
                if ((int) $parent->getAttribute('cash_account_id') !== (int) $bank->getKey() || $parent->getAttribute('amount') !== $source['amount'] || $data['document_date'] < $parent->getAttribute('document_date')->format('Y-m-d')) {
                    $this->fail('A full refund must use the original bank, amount and a date no earlier than the original posting.');
                }
            }
            $row = AccountingBankAdjustment::query()->create(['company_id' => $companyId, 'cash_account_id' => (int) $bank->getKey(), 'statement_line_id' => $source['line_id'], 'active_statement_line_id' => $source['line_id'], 'counter_account_id' => $counter, 'kind' => $kind,
                'reverses_id' => $original, 'active_reversal_of_id' => $original, 'original_hash' => $originalHash, 'amount' => $source['amount'], 'currency_code' => $source['currency_code'], 'document_date' => $data['document_date'], 'reason' => $data['reason'],
                'source_snapshot' => $source, 'binding_snapshot' => $bindingSnapshot, 'counter_snapshot' => $counterSnapshot, 'mapping_version_id' => $mapping['version_id'], 'mapping_hash' => $mapping['payload_hash'],
                'creation_key_hash' => $keyHash, 'creation_request_hash' => $requestHash, 'created_by' => (int) $actor->getKey(), 'updated_by' => (int) $actor->getKey(), 'last_edited_by' => (int) $actor->getKey()]);
            $this->proof->verify($row);
            $this->date($company, $row);
            $this->audit($actor, $row, 'created', $data['reason'], null);

            return $row;
        }, 3);
    }

    public function edit(User $actor, int $id, int $revision, string $date, string $reason): AccountingBankAdjustment
    {
        $reason = $this->contract->reason($reason);
        Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();

        return $this->change($actor, $id, $revision, 'edit', $reason, $date);
    }

    public function submit(User $actor, int $id, int $revision): AccountingBankAdjustment
    {
        return $this->change($actor, $id, $revision, 'submit');
    }

    public function approve(User $actor, int $id, int $revision): AccountingBankAdjustment
    {
        return $this->change($actor, $id, $revision, 'approve');
    }

    public function reject(User $actor, int $id, int $revision, string $reason): AccountingBankAdjustment
    {
        return $this->change($actor, $id, $revision, 'reject', $this->contract->reason($reason));
    }

    public function cancel(User $actor, int $id, int $revision, string $reason): AccountingBankAdjustment
    {
        return $this->change($actor, $id, $revision, 'cancel', $this->contract->reason($reason));
    }

    private function change(User $actor, int $id, int $revision, string $action, ?string $reason = null, ?string $date = null): AccountingBankAdjustment
    {
        $permission = 'erp.bank-adjustments.'.($action === 'reject' ? 'approve' : $action);
        $found = $this->find($actor, $id, $permission);
        $companyId = (int) $found->getAttribute('company_id');

        return DB::transaction(function () use ($actor, $id, $revision, $action, $reason, $date, $permission, $companyId): AccountingBankAdjustment {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $companyId, $permission);
            $this->configuration->lockForSetup($company);
            $row = AccountingBankAdjustment::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            $this->contract->review($row);
            $before = $this->summary($row);
            $state = $row->getAttribute('status');
            if ($revision < 1 || (int) $row->getAttribute('revision') !== $revision || ! in_array($state, ['draft', 'submitted', 'approved'], true)) {
                $this->fail('Use the current unposted adjustment revision.');
            }
            if ($action === 'cancel') {
                $row->forceFill(['status' => 'cancelled', 'active_statement_line_id' => null, 'active_reversal_of_id' => null, 'cancelled_by' => (int) $actor->getKey(), 'cancelled_at' => CarbonImmutable::now('UTC'), 'cancellation_reason' => $reason]);
            } elseif ($action === 'reject') {
                if ($state !== 'submitted') {
                    $this->fail('Only submitted adjustments can return to draft.');
                }
                $row->forceFill(['status' => 'draft', 'submitted_by' => null, 'submitted_at' => null, 'submitted_hash' => null, 'approved_by' => null, 'approved_at' => null, 'approved_hash' => null]);
            } elseif ($action === 'edit') {
                if ($state !== 'draft') {
                    $this->fail('Only draft date/reason may be edited.');
                }
                $row->forceFill(['document_date' => $date, 'reason' => $reason, 'last_edited_by' => (int) $actor->getKey()]);
                $this->proof->verify($row);
                $this->date($company, $row);
            } else {
                if ($state !== ($action === 'submit' ? 'draft' : 'submitted')) {
                    $this->fail('Adjustment review state changed.');
                }
                $this->proof->verify($row);
                $this->eligible($company, $row);
                $this->date($company, $row);
                $this->periods->lock($actor, $company, $row->getAttribute('document_date')->format('Y-m-d'));
                $this->available($actor, $companyId, $row->getAttribute('source_snapshot'));
                $hash = $this->contract->intent($row);
                if ($action === 'submit') {
                    $row->forceFill(['status' => 'submitted', 'submitted_by' => (int) $actor->getKey(), 'submitted_at' => CarbonImmutable::now('UTC'), 'submitted_hash' => $hash]);
                } else {
                    if (in_array((int) $actor->getKey(), [(int) $row->getAttribute('created_by'), (int) $row->getAttribute('last_edited_by'), (int) $row->getAttribute('submitted_by')], true)) {
                        throw new AuthorizationException('Approval must be independent of the maker, editor and submitter.');
                    } $row->forceFill(['status' => 'approved', 'approved_by' => (int) $actor->getKey(), 'approved_at' => CarbonImmutable::now('UTC'), 'approved_hash' => $hash]);
                }
            }
            $row->forceFill(['revision' => $revision + 1, 'updated_by' => (int) $actor->getKey()])->save();
            $this->audit($actor, $row, $action, $reason ?? (string) $row->getAttribute('reason'), $before);

            return $row;
        }, 3);
    }

    public function post(User $actor, int $id, int $revision, string $key): AccountingBankAdjustment
    {
        $found = $this->find($actor, $id, 'erp.bank-adjustments.post');
        $companyId = (int) $found->getAttribute('company_id');
        $keyHash = $this->contract->key($key);

        return DB::transaction(function () use ($actor, $companyId, $id, $revision, $keyHash): AccountingBankAdjustment {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $companyId, 'erp.bank-adjustments.post');
            $row = AccountingBankAdjustment::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            if ($row->getAttribute('status') === 'posted') {
                if ($row->getAttribute('posting_key_hash') !== $keyHash || (int) $row->getAttribute('posting_revision') !== $revision || (int) $row->getAttribute('posted_by') !== (int) $actor->getKey()) {
                    $this->fail('Posted adjustment does not match this retry.');
                }
                $this->evidence->family($companyId, $id);

                return $row;
            }
            if ($row->getAttribute('status') !== 'approved' || (int) $row->getAttribute('revision') !== $revision) {
                $this->fail('Post only the current independently approved adjustment.');
            }
            if (AccountingBankAdjustmentOperation::query()->where('company_id', $companyId)->where('adjustment_id', $id)->exists() || AccountingSourceEffect::query()->where('company_id', $companyId)->where('source_kind', 'bank_adjustment')->where('source_id', $id)->exists() || JournalEntryLine::query()->where('company_id', $companyId)->where('source_line_type', 'bank_adjustment')->where('source_line_id', $id)->exists()) {
                $this->fail('Unposted adjustment already has financial members; no retry repair is permitted.');
            }
            $setting = $this->configuration->lockForSetup($company);
            if ($setting->getAttribute('state') !== 'enabled') {
                $this->fail('Enable accounting through its approved activation workflow before financial bank adjustments.');
            }
            $this->proof->verify($row);
            $this->date($company, $row);
            $this->available($actor, $companyId, $row->getAttribute('source_snapshot'));
            $before = $this->summary($row);
            $row->forceFill(['status' => 'posted', 'revision' => $revision + 1, 'posting_revision' => $revision, 'posting_key_hash' => $keyHash, 'posted_by' => (int) $actor->getKey(), 'posted_at' => CarbonImmutable::now('UTC'), 'updated_by' => (int) $actor->getKey()])->save();
            $result = $this->orchestrator->execute($actor, $companyId, 'bank_adjustment', $id, 'posted', $keyHash);
            if ($result['mode'] !== 'recorded' || count($result['effect_ids']) !== 1) {
                $this->fail('The complete bank adjustment financial bundle must be newly recorded atomically.');
            }
            $effect = AccountingSourceEffect::query()->where('company_id', $companyId)->findOrFail($result['effect_ids'][0]);
            $journal = JournalEntry::query()->where('company_id', $companyId)->findOrFail((int) $effect->getAttribute('journal_entry_id'));
            $payload = $this->evidence->operationPayload($row, $effect, $journal);
            AccountingBankAdjustmentOperation::query()->create(['company_id' => $companyId, 'adjustment_id' => $id, 'original_adjustment_id' => $row->getAttribute('reverses_id'), 'source_effect_id' => (int) $effect->getKey(), 'journal_entry_id' => (int) $journal->getKey(), 'actor_id' => (int) $actor->getKey(), 'key_hash' => $keyHash, 'request_revision' => $revision, 'contract_version' => AccountingBankAdjustmentContract::VERSION, 'payload' => $payload, 'payload_hash' => $this->canonical->hash($payload), 'occurred_at' => $row->getAttribute('posted_at')]);
            $this->evidence->family($companyId, $id);
            $this->audit($actor, $row, 'posted', (string) $row->getAttribute('reason'), $before);

            return $row;
        }, 3);
    }

    /** @param array<string, mixed> $source */
    private function available(User $actor, int $company, array $source): void
    {
        $state = $this->matching->read($actor, $company, $source['cash_account_id'], $source['statement_id']);
        if (bccomp($state['used_statement'][$source['line_id']] ?? '0.00', '0', 2) !== 0) {
            $this->fail('A statement row already matched to recognized book money cannot create another bank adjustment. Undo the match only if it was incorrect.');
        }
    }

    private function eligible(Company $company, AccountingBankAdjustment $row): void
    {
        $this->contract->counter((int) $company->getKey(), (int) $row->getAttribute('counter_account_id'), (string) $row->getAttribute('kind'), true);
        if ($row->getAttribute('reverses_id') !== null) {
            return;
        }
        $binding = AccountingCashAccount::query()->where('company_id', $company->getKey())->findOrFail((int) $row->getAttribute('cash_account_id'));
        $snapshot = $row->getAttribute('binding_snapshot');
        if ($binding->getAttribute('status') !== 'active') {
            $this->fail('New charges/interest require an active bank binding.');
        }
        $profile = $this->mappings->resolveForSource($company, $row->getAttribute('document_date')->format('Y-m-d'), [$snapshot['role']]);
        if ($profile['version_id'] !== (int) $row->getAttribute('mapping_version_id') || $profile['payload_hash'] !== $row->getAttribute('mapping_hash') || ($profile['accounts'][$snapshot['role']] ?? null) !== $snapshot['account_id']) {
            $this->fail('The profile changed after draft preparation; cancel and prepare a current reviewed request.');
        }
    }

    private function date(Company $company, AccountingBankAdjustment $row): void
    {
        $date = $row->getAttribute('document_date')->format('Y-m-d');
        $source = $row->getAttribute('source_snapshot');
        if ($date < $source['date'] || $date > $source['ends_on'] || $date > $this->dates->today($company)) {
            $this->fail('Posting date must be between the bank movement and statement end, no later than company today. Closed-period carry-forward requires its separate policy; no implicit backposting.');
        }
    }

    private function permission(User $actor, string $permission): void
    {
        if (! $actor->exists || ! in_array($permission, AccountingBankAdjustmentContract::PERMISSIONS, true) || ! $actor->can($permission) || ! $actor->can('erp.bank-adjustments.view') || ! $actor->can('erp.bank-statements.view') || ! $actor->can('erp.cash-books.view') || ! $actor->can('erp.bank-reconciliations.view')) {
            throw new AuthorizationException('Native bank adjustment and source/reconciliation view authority are required.');
        }
    }

    private function authorize(User $actor, int $company, string $permission): void
    {
        $this->permission($actor, $permission);
        $this->access->authorize($actor, $company, $permission);
    }

    /** @return array<string, mixed> */
    private function summary(AccountingBankAdjustment $row): array
    {
        return ['id' => (int) $row->getKey(), 'status' => (string) $row->getAttribute('status'), 'revision' => (int) $row->getAttribute('revision'), 'kind' => (string) $row->getAttribute('kind'), 'amount' => (string) $row->getAttribute('amount'), 'document_date' => $row->getAttribute('document_date')->format('Y-m-d')];
    }

    /** @param array<string, mixed>|null $before */
    private function audit(User $actor, AccountingBankAdjustment $row, string $action, string $reason, ?array $before): void
    {
        AccountingBankAdjustmentAuditEvent::query()->create(['company_id' => (int) $row->getAttribute('company_id'), 'adjustment_id' => (int) $row->getKey(), 'actor_id' => (int) $actor->getKey(), 'action' => $action, 'reason' => $reason, 'before_payload' => $before, 'after_payload' => $this->summary($row), 'occurred_at' => CarbonImmutable::now('UTC')]);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['bank_adjustment' => $message]);
    }
}

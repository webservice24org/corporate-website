<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reconciliation;

use App\Models\Accounting\AccountingBankFinalizationEvent;
use App\Models\Accounting\AccountingBankReconciliationOperation;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AccountingBankFinalizationWorkflow
{
    public const PERMISSIONS = ['erp.bank-reconciliations.submit', 'erp.bank-reconciliations.approve', 'erp.bank-reconciliations.reopen'];

    public function __construct(private readonly AccountingBankReconciliationService $matching, private readonly AccountingBankReconciliationContract $contract, private readonly AccountingBankFinalizationReadiness $readiness, private readonly AccountingBankFinalizationEvidence $evidence, private readonly AccountingCanonicalPayload $canonical, private readonly PurchaseCompanyAccess $access) {}

    /** @param array<string, mixed> $input */
    public function execute(User $actor, int $id, string $kind, array $input): AccountingBankFinalizationEvent
    {
        $permission = match ($kind) {
            'submit' => self::PERMISSIONS[0], 'approve', 'reject' => self::PERMISSIONS[1], 'reopen' => self::PERMISSIONS[2], default => throw new AuthorizationException('Unknown finalization action.')
        };
        $found = $this->matching->find($actor, $id);
        abort_unless($actor->can($permission), 403);
        if (array_diff(array_keys($input), ['revision', 'finalization_revision', 'reason', 'key']) !== []) {
            $this->fail('Only the current revisions, reason and request key are accepted.');
        }
        $data = Validator::make($input, ['revision' => ['required', 'integer', 'min:1'], 'finalization_revision' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:2000'], 'key' => ['required', 'string']])->validate();
        $reason = $this->contract->reason($data['reason']);
        $key = $this->contract->key($data['key']);
        $company = (int) $found->getAttribute('company_id');
        $bank = (int) $found->getAttribute('cash_account_id');
        $request = $this->evidence->request((int) $actor->getKey(), $id, $kind, (int) $data['revision'], (int) $data['finalization_revision'], $reason);

        return DB::transaction(function () use ($actor, $id, $kind, $permission, $data, $reason, $key, $company, $bank, $request): AccountingBankFinalizationEvent {
            $owner = Company::query()->whereKey($company)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $company, $permission);
            $root = $this->matching->find($actor, $id);
            $states = $this->evidence->bank($company, $bank);
            if (! isset($states[$id])) {
                $this->fail('Apply the bank finalization migration first.');
            } $state = $states[$id];
            $existing = AccountingBankFinalizationEvent::query()->where('company_id', $company)->where('key_hash', $key)->first();
            if ($existing !== null) {
                if ((int) $existing->getAttribute('reconciliation_id') !== $id || $existing->getAttribute('request_hash') !== $request) {
                    $this->fail('Request key belongs to another action, actor, reason or revision.');
                }
                $this->current($actor, $states);
                if ($this->readiness->capture($actor, $id)['review']['evidence_state'] !== 'verified') {
                    $this->fail('Current bank evidence requires review; the recorded action cannot be replayed as verified.');
                }

                return $existing;
            }
            if ($owner->getAttribute('status') !== 'active' || (int) $data['revision'] !== (int) $root->getAttribute('revision') || (int) $data['finalization_revision'] !== $state['sequence']) {
                $this->fail('Company or reconciliation revision changed. Reload before committing.');
            }
            $this->current($actor, $states);
            if ($this->readiness->capture($actor, $id)['review']['evidence_state'] !== 'verified') {
                $this->fail('Current bank evidence requires review. No finalization action was recorded.');
            }
            $subject = [];
            if ($kind === 'submit') {
                if ($state['state'] === 'finalized') {
                    $this->fail('Reopen a finalized reconciliation before submitting again.');
                }
                $capture = $this->captureReady($actor, $id);
                $dependencies = $this->dependencies($states, $id);
                $makers = [(int) $root->getAttribute('created_by')];
                foreach (AccountingBankReconciliationOperation::query()->where('company_id', $company)->where('reconciliation_id', $id)->pluck('actor_id')->all() as $maker) {
                    $makers[] = (int) $maker;
                }
                $makers = array_values(array_unique($makers));
                sort($makers, SORT_NUMERIC);
                $subject = ['snapshot' => $capture['snapshot'], 'evidence_hash' => $capture['snapshot_hash'], 'makers' => $makers, 'dependencies' => $dependencies];
            } elseif (in_array($kind, ['approve', 'reject'], true)) {
                if ($state['state'] !== 'submitted') {
                    $this->fail('Only a current submitted reconciliation can be approved or returned for review.');
                }
                $submission = $state['submission'];
                if ($kind === 'approve') {
                    if ((int) $actor->getKey() === $submission['actor_id'] || in_array((int) $actor->getKey(), $submission['subject']['makers'], true)) {
                        throw new AuthorizationException('Finalization approval must be independent of the root maker, matching participants and submitter.');
                    }
                    $capture = $this->captureReady($actor, $id);
                    if ($capture['snapshot_hash'] !== $submission['subject']['evidence_hash'] || $this->dependencies($states, $id) !== $submission['subject']['dependencies']) {
                        $this->fail('Submitted coverage changed. Submit the current evidence for a fresh independent review.');
                    }
                }
                $subject = ['submission_id' => $submission['id'], 'submission_hash' => $submission['hash'], 'evidence_hash' => $submission['subject']['evidence_hash']];
            } else {
                if ($state['state'] !== 'finalized') {
                    $this->fail('Only a finalized reconciliation can be reopened.');
                }
                foreach ($states as $otherId => $other) {
                    if ($otherId !== $id && $other['state'] === 'finalized' && $other['cutoff'] >= $state['cutoff']) {
                        $this->fail('Reopen later finalized bank reconciliations first.');
                    }
                }
                $subject = ['approval_id' => $state['approval']['id'], 'approval_hash' => $state['approval']['hash']];
            }
            $at = CarbonImmutable::now('UTC');
            $sequence = $state['sequence'] + 1;
            $body = $this->evidence->body($company, $bank, $id, $sequence, $kind, (int) $actor->getKey(), $reason, $at->format('Y-m-d H:i:s'), (int) $data['revision'], $key, $request, $state['head_hash'], $subject);
            $hash = $this->canonical->hash($body);
            $event = AccountingBankFinalizationEvent::query()->create(['company_id' => $company, 'cash_account_id' => $bank, 'reconciliation_id' => $id, 'sequence' => $sequence, 'event_kind' => $kind, 'actor_id' => (int) $actor->getKey(), 'reason' => $reason, 'occurred_at' => $at, 'request_revision' => (int) $data['revision'], 'key_hash' => $key, 'request_hash' => $request, 'previous_hash' => $state['head_hash'], 'payload' => $body, 'payload_hash' => $hash]);
            $changed = DB::table('accounting_bank_reconciliations')->where('company_id', $company)->where('id', $id)->where('revision', $data['revision'])->where('finalization_revision', $state['sequence'])->where('finalization_head_id', $state['head_id'])->where('finalization_hash', $state['head_hash'])->update(['finalization_revision' => $sequence, 'finalization_head_id' => (int) $event->getKey(), 'finalization_hash' => $hash]);
            if ($changed !== 1) {
                $this->fail('Finalization head changed; no evidence was committed.');
            }
            $this->evidence->bank($company, $bank);

            return $event;
        }, 3);
    }

    /** Safe presentation DTO. @return array<string, mixed> */
    public function review(User $actor, int $id, ?int $requestedCompany = null): array
    {
        $root = $this->matching->find($actor, $id);
        $company = (int) $root->getAttribute('company_id');
        abort_if($requestedCompany !== null && $requestedCompany !== $company, 403);

        return DB::transaction(function () use ($actor, $id, $company): array {
            $owner = Company::query()->whereKey($company)->lockForUpdate()->firstOrFail();
            $root = $this->matching->find($actor, $id);
            $bank = (int) $root->getAttribute('cash_account_id');
            $stateName = 'review';
            $sequence = (int) $root->getAttribute('finalization_revision');
            $history = [];
            $issues = [];
            $capabilities = ['submit' => false, 'approve' => false, 'reject' => false, 'reopen' => false];
            $stale = false;
            $ready = false;
            $frozen = true;
            try {
                $states = $this->evidence->bank($company, $bank);
                if (! isset($states[$id])) {
                    $this->fail('Apply the bank finalization migration first.');
                } $state = $states[$id];
                $this->current($actor, $states);
                $stateName = $state['state'];
                $capture = $this->readiness->capture($actor, $id);
                $ready = $capture['review']['technical_ready'];
                if ($capture['review']['evidence_state'] !== 'verified') {
                    $this->fail('Current bank evidence requires review.');
                } $frozen = app(AccountingBankFinalizationGuard::class)->frozen($company, $bank, $state['cutoff']);
                $stale = $stateName === 'submitted' && $capture['snapshot_hash'] !== $state['submission']['subject']['evidence_hash'];
                if ($stale) {
                    $issues[] = 'Matching or coverage changed after submission. Resubmit the current evidence before approval.';
                }
                $writable = $owner->getAttribute('status') === 'active';
                $priorReady = true;
                try {
                    $this->dependencies($states, $id);
                } catch (ValidationException) {
                    $priorReady = false;
                    $issues[] = 'Finalize earlier statement workspaces before this one.';
                }
                $capabilities['submit'] = $writable && ! $frozen && $ready && $priorReady && $actor->can(self::PERMISSIONS[0]);
                $capabilities['reject'] = $writable && $stateName === 'submitted' && $actor->can(self::PERMISSIONS[1]);
                $capabilities['approve'] = $writable && $stateName === 'submitted' && ! $stale && $ready && $priorReady && $actor->can(self::PERMISSIONS[1]) && (int) $actor->getKey() !== $state['submission']['actor_id'] && ! in_array((int) $actor->getKey(), $state['submission']['subject']['makers'], true);
                $later = false;
                foreach ($states as $otherId => $other) {
                    if ($otherId !== $id && $other['state'] === 'finalized' && $other['cutoff'] >= $state['cutoff']) {
                        $later = true;
                    }
                }
                $capabilities['reopen'] = $writable && $stateName === 'finalized' && ! $later && $actor->can(self::PERMISSIONS[2]);
                if ($stateName === 'finalized' && $later) {
                    $issues[] = 'Later finalized bank cutoffs must be reopened first.';
                }
                foreach (array_slice(array_reverse($state['events']), 0, 100) as $event) {
                    $history[] = ['id' => (int) $event->getKey(), 'sequence' => (int) $event->getAttribute('sequence'), 'kind' => (string) $event->getAttribute('event_kind'), 'actor_id' => (int) $event->getAttribute('actor_id'), 'reason' => (string) $event->getAttribute('reason'), 'occurred_at' => $event->getAttribute('occurred_at')->format(DATE_ATOM)];
                }
            } catch (ValidationException|ModelNotFoundException|\LogicException|\JsonException) {
                $stateName = 'review';
                $frozen = true;
                $ready = false;
                $capabilities = ['submit' => false, 'approve' => false, 'reject' => false, 'reopen' => false];
                $issues[] = 'Finalization or source evidence requires review. No history is reconstructed and all actions are blocked.';
            }

            return ['reconciliation_id' => $id, 'company_id' => $company, 'revision' => (int) $root->getAttribute('revision'), 'finalization_revision' => $sequence, 'state' => $stateName, 'technical_ready' => $ready, 'stale' => $stale, 'frozen' => $frozen, 'capabilities' => $capabilities, 'issues' => $issues, 'history' => $history, 'historyTruncated' => $sequence > 100, 'requestKey' => (string) Str::uuid(), 'actorId' => (int) $actor->getKey(), 'workspaceUrl' => '/erp/accounting/bank-reconciliations/'.$id, 'readinessUrl' => '/erp/accounting/bank-reconciliations/'.$id.'/readiness'];
        }, 3);
    }

    /** @param array<int, array<string, mixed>> $states */
    private function current(User $actor, array $states): void
    {
        foreach ($states as $id => $state) {
            if ($state['state'] !== 'finalized') {
                continue;
            }
            $capture = $this->captureReady($actor, $id);
            if ($capture['snapshot_hash'] !== $state['approval']['evidence_hash']) {
                $this->fail('Finalized source or matching evidence changed. Investigate the protected history; no retry repair is allowed.');
            }
        }
    }

    /** @return array<string, mixed> */
    private function captureReady(User $actor, int $id): array
    {
        $capture = $this->readiness->capture($actor, $id);
        if (! $capture['review']['technical_ready'] || $capture['snapshot'] === null || $capture['snapshot_hash'] === null) {
            $this->fail('Complete verified statement, opening, adjustment and book coverage is required.');
        }

        return $capture;
    }

    /** @param array<int, array<string, mixed>> $states @return list<array{id: int, approval_id: int, approval_hash: string}> */
    private function dependencies(array $states, int $id): array
    {
        $dependencies = [];
        foreach ($states as $otherId => $other) {
            if ($otherId === $id || $other['cutoff'] >= $states[$id]['cutoff']) {
                continue;
            }
            if ($other['state'] !== 'finalized') {
                $this->fail('Every earlier statement workspace must be independently finalized first.');
            }
            $dependencies[] = ['id' => $otherId, 'approval_id' => $other['approval']['id'], 'approval_hash' => $other['approval']['hash']];
        }

        return $dependencies;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['finalization' => $message]);
    }
}

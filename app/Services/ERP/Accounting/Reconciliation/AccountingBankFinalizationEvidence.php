<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reconciliation;

use App\Models\Accounting\AccountingBankFinalizationEvent;
use App\Models\Accounting\AccountingBankReconciliation;
use App\Models\Accounting\AccountingBankReconciliationOperation;
use App\Models\Accounting\AccountingBankStatement;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Pure evidence replay. Never initializes a head, repairs a row or invokes source posting. */
final class AccountingBankFinalizationEvidence
{
    public const VERSION = 'bank-finalization.v1';

    public function __construct(
        private readonly AccountingCanonicalPayload $canonical,
    ) {}

    /**
     * Caller holds the company transaction/lock.
     *
     * @return array<int, array<string, mixed>>
     */
    public function bank(int $company, int $bank): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Finalization verification requires a company transaction.');
        }
        if (! Schema::hasTable('accounting_bank_finalization_events')) {
            if (Schema::hasColumn('accounting_bank_reconciliations', 'finalization_revision') && AccountingBankReconciliation::query()->where('company_id', $company)->where('cash_account_id', $bank)->where('finalization_revision', '>', 0)->exists()) {
                $this->fail();
            }

            return [];
        }
        $roots = AccountingBankReconciliation::query()->where('company_id', $company)->where('cash_account_id', $bank)->orderBy('id')->get();
        $states = [];
        foreach ($roots as $root) {
            if (array_diff(['finalization_revision', 'finalization_head_id', 'finalization_hash'], array_keys($root->getAttributes())) !== []) {
                $this->fail();
            }
            $id = (int) $root->getKey();
            $statement = AccountingBankStatement::query()->where('company_id', $company)->where('cash_account_id', $bank)->findOrFail((int) $root->getAttribute('statement_id'));
            $states[$id] = ['state' => 'working', 'sequence' => 0, 'head_id' => null, 'head_hash' => null, 'submission' => null, 'approval' => null, 'cutoff' => $statement->getAttribute('ends_on')->format('Y-m-d'), 'events' => [], 'root' => $root];
        }
        foreach (AccountingBankFinalizationEvent::query()->where('company_id', $company)->where('cash_account_id', $bank)->orderBy('id')->get() as $event) {
            $id = (int) $event->getAttribute('reconciliation_id');
            if (! isset($states[$id])) {
                $this->fail();
            }
            $state = $states[$id];
            $payload = $event->getAttribute('payload');
            if (! is_array($payload) || ! is_array($payload['subject'] ?? null)) {
                $this->fail();
            }
            if (app(AccountingBankReconciliationContract::class)->reason((string) $event->getAttribute('reason')) !== $event->getAttribute('reason')) {
                $this->fail();
            }
            $actor = (int) $event->getAttribute('actor_id');
            $kind = (string) $event->getAttribute('event_kind');
            $sequence = $state['sequence'] + 1;
            $request = $this->request($actor, $id, $kind, (int) $event->getAttribute('request_revision'), $sequence - 1, (string) $event->getAttribute('reason'));
            $body = $this->body($company, $bank, $id, $sequence, $kind, $actor, (string) $event->getAttribute('reason'), $event->getAttribute('occurred_at')->format('Y-m-d H:i:s'), (int) $event->getAttribute('request_revision'), (string) $event->getAttribute('key_hash'), $request, $state['head_hash'], $payload['subject']);
            $hash = $this->canonical->hash($body);
            if ($actor < 1 || trim((string) $event->getAttribute('reason')) === '' || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $event->getAttribute('key_hash')) || (int) $event->getAttribute('sequence') !== $sequence || $event->getAttribute('previous_hash') !== $state['head_hash'] || $event->getAttribute('request_hash') !== $request || $event->getAttribute('payload_hash') !== $hash || $this->canonical->hash($payload) !== $hash) {
                $this->fail();
            }
            $subject = $payload['subject'];
            if ($kind === 'submit' && $state['state'] !== 'finalized') {
                $snapshot = $subject['snapshot'] ?? null;
                $makers = $subject['makers'] ?? null;
                if (
                    ! is_array($snapshot) || ! is_array($makers) || $makers === [] || ! array_is_list($makers) || count($makers) !== count(array_unique($makers)) || array_filter($makers, static fn ($maker): bool => ! is_int($maker) || $maker < 1) !== [] || ! in_array((int) $state['root']->getAttribute('created_by'), $makers, true)
                    || ($snapshot['reconciliation_id'] ?? null) !== $id || ($snapshot['company_id'] ?? null) !== $company || ($snapshot['cash_account_id'] ?? null) !== $bank || ($snapshot['statement_id'] ?? null) !== (int) $state['root']->getAttribute('statement_id') || ($snapshot['cutoff'] ?? null) !== $state['cutoff'] || ($snapshot['revision'] ?? null) !== (int) $event->getAttribute('request_revision')
                    || ($subject['evidence_hash'] ?? null) !== $this->canonical->hash($snapshot)
                ) {
                    $this->fail();
                }
                if (($snapshot['contract'] ?? null) !== AccountingBankFinalizationContract::VERSION || ($snapshot['currency_code'] ?? null) !== $state['root']->getAttribute('currency_code') || ! is_array($snapshot['heads'] ?? null) || ! is_array($snapshot['facts'] ?? null)) {
                    $this->fail();
                }
                $facts = $snapshot['facts'];
                if (array_diff(['evidence_verified', 'date_valid', 'currency_agrees', 'statement_coverage_complete', 'workspaces_complete', 'unmatched_statement_rows', 'prior_unmatched_statement_rows', 'pending_adjustments', 'book_complete', 'unsupported_cash_lines', 'bridge'], array_keys($facts)) !== [] || ! is_array($facts['bridge']) || ! app(AccountingBankFinalizationContract::class)->ready(app(AccountingBankFinalizationContract::class)->checks($facts))) {
                    $this->fail();
                }
                $nativeRevision = (int) $event->getAttribute('request_revision');
                $expectedHead = $nativeRevision === 1 ? $state['root']->getAttribute('snapshot_hash') : AccountingBankReconciliationOperation::query()->where('company_id', $company)->where('reconciliation_id', $id)->where('revision_from', $nativeRevision - 1)->value('payload_hash');
                $selfHead = null;
                foreach ($snapshot['heads'] as $head) {
                    if (! is_array($head) || ! is_int($head['id'] ?? null) || ! is_int($head['revision'] ?? null) || ! is_string($head['head'] ?? null)) {
                        $this->fail();
                    }
                    if ($head['id'] === $id) {
                        $selfHead = $head;
                    }
                }
                if (
                    $expectedHead === null
                    || ! is_array($selfHead)
                    || $selfHead['revision'] !== $nativeRevision
                    || $selfHead['head'] !== $expectedHead
                ) {
                    $this->fail();
                }
                if (($subject['dependencies'] ?? null) !== $this->dependencies($states, $id, $snapshot['heads'])) {
                    $this->fail();
                }
                // Every participant up to the submitted revision remains a maker, even if a group was undone.
                $required = AccountingBankReconciliationOperation::query()->where('company_id', $company)->where('reconciliation_id', $id)->where('revision_from', '<', $event->getAttribute('request_revision'))->pluck('actor_id')->all();
                foreach ($required as $maker) {
                    if (! in_array((int) $maker, $makers, true)) {
                        $this->fail();
                    }
                }
                $state['state'] = 'submitted';
                $state['submission'] = ['id' => (int) $event->getKey(), 'hash' => $hash, 'actor_id' => $actor, 'subject' => $subject];
                $state['approval'] = null;
            } elseif (in_array($kind, ['approve', 'reject'], true) && $state['state'] === 'submitted') {
                $submission = $state['submission'];
                if (($subject['submission_id'] ?? null) !== $submission['id'] || ($subject['submission_hash'] ?? null) !== $submission['hash'] || ($subject['evidence_hash'] ?? null) !== $submission['subject']['evidence_hash']) {
                    $this->fail();
                }
                if ($kind === 'approve') {
                    if ($actor === $submission['actor_id'] || in_array($actor, $submission['subject']['makers'], true) || (int) $event->getAttribute('request_revision') !== $submission['subject']['snapshot']['revision']) {
                        $this->fail();
                    }
                    if ($submission['subject']['dependencies'] !== $this->dependencies($states, $id, $submission['subject']['snapshot']['heads'])) {
                        $this->fail();
                    }
                    $state['state'] = 'finalized';
                    $state['approval'] = ['id' => (int) $event->getKey(), 'hash' => $hash, 'actor_id' => $actor, 'evidence_hash' => $subject['evidence_hash']];
                } else {
                    $state['state'] = 'rejected';
                }
            } elseif ($kind === 'reopen' && $state['state'] === 'finalized') {
                foreach ($states as $otherId => $other) {
                    if ($otherId !== $id && $other['state'] === 'finalized' && $other['cutoff'] >= $state['cutoff']) {
                        $this->fail();
                    }
                }
                if (($subject['approval_id'] ?? null) !== $state['approval']['id'] || ($subject['approval_hash'] ?? null) !== $state['approval']['hash']) {
                    $this->fail();
                }
                $state['state'] = 'reopened';
            } else {
                $this->fail();
            }
            $state['sequence'] = $sequence;
            $state['head_id'] = (int) $event->getKey();
            $state['head_hash'] = $hash;
            $state['events'][] = $event;
            $states[$id] = $state;
        }
        foreach ($states as $state) {
            $root = $state['root'];
            $head = $root->getAttribute('finalization_head_id');
            if ((int) $root->getAttribute('finalization_revision') !== $state['sequence'] || ($head === null ? null : (int) $head) !== $state['head_id'] || $root->getAttribute('finalization_hash') !== $state['head_hash']) {
                $this->fail();
            }
        }

        return $states;
    }

    public function request(int $actor, int $id, string $kind, int $revision, int $finalizationRevision, string $reason): string
    {
        return $this->canonical->hash(['actor_id' => $actor, 'reconciliation_id' => $id, 'kind' => $kind, 'revision' => $revision, 'finalization_revision' => $finalizationRevision, 'reason' => $reason]);
    }

    /**
     * @param  array<string, mixed>  $subject
     * @return array<string, mixed>
     */
    public function body(int $company, int $bank, int $id, int $sequence, string $kind, int $actor, string $reason, string $at, int $revision, string $key, string $request, ?string $previous, array $subject): array
    {
        return ['contract' => self::VERSION, 'company_id' => $company, 'cash_account_id' => $bank, 'reconciliation_id' => $id, 'sequence' => $sequence, 'kind' => $kind, 'actor_id' => $actor, 'reason' => $reason, 'occurred_at' => $at, 'request_revision' => $revision, 'key_hash' => $key, 'request_hash' => $request, 'previous_hash' => $previous, 'subject' => $subject];
    }

    /**
     * @param  array<int, array<string, mixed>>  $states
     * @param  array<int|string, mixed>  $heads
     * @return list<array{id: int, approval_id: int, approval_hash: string}>
     */
    private function dependencies(array $states, int $id, array $heads): array
    {
        $out = [];
        $included = [];
        foreach ($heads as $head) {
            if (! is_array($head) || ! is_int($head['id'] ?? null)) {
                $this->fail();
            }
            $included[$head['id']] = true;
        }
        foreach ($states as $otherId => $other) {
            if (! isset($included[$otherId])) {
                continue;
            }
            if ($otherId === $id || $other['cutoff'] >= $states[$id]['cutoff']) {
                continue;
            }
            if ($other['state'] !== 'finalized') {
                $this->fail();
            }
            $out[] = ['id' => $otherId, 'approval_id' => $other['approval']['id'], 'approval_hash' => $other['approval']['hash']];
        }

        return $out;
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['finalization_integrity' => 'Finalization history, submitted evidence, independent approval and protected head must agree. Missing history is never repaired.']);
    }
}

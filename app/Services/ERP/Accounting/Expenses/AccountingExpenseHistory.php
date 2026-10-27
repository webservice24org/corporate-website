<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Expenses;

use App\Models\Accounting\AccountingExpense;
use App\Models\Accounting\AccountingExpenseCategory;
use App\Models\Accounting\AccountingExpenseEvent;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountingExpenseHistory
{
    public function __construct(private readonly AccountingCanonicalPayload $canonical, private readonly AccountingExpenseGuard $guard) {}

    /** @return array<string, mixed> */
    public function summary(Model $row): array
    {
        if ($row instanceof AccountingExpenseCategory) {
            return ['kind' => 'category', 'id' => (int) $row->getKey(), 'company_id' => (int) $row->getAttribute('company_id'), 'code' => (string) $row->getAttribute('code'), 'name' => (string) $row->getAttribute('name'), 'account_id' => (int) $row->getAttribute('account_id'), 'status' => (string) $row->getAttribute('status'), 'revision' => (int) $row->getAttribute('revision'), 'created_by' => (int) $row->getAttribute('created_by'), 'updated_by' => (int) $row->getAttribute('updated_by'), 'creation_key_hash' => (string) $row->getAttribute('creation_key_hash'), 'creation_request_hash' => (string) $row->getAttribute('creation_request_hash')];
        }

        if (! $row instanceof AccountingExpense) {
            throw new \LogicException('Unknown expense audit entity.');
        }

        $out = ['kind' => 'expense', 'id' => (int) $row->getKey(), 'company_id' => (int) $row->getAttribute('company_id'), 'revision' => (int) $row->getAttribute('revision'), 'intent_hash' => $this->guard->intent($row), 'status' => (string) $row->getAttribute('status')];

        foreach (['creation_key_hash', 'creation_request_hash'] as $field) {
            $out[$field] = (string) $row->getAttribute($field);
        }

        foreach (['created_by', 'updated_by', 'last_edited_by', 'submitted_by', 'approved_by', 'cancelled_by'] as $field) {
            $out[$field] = $row->getAttribute($field) === null ? null : (int) $row->getAttribute($field);
        }

        foreach (['submitted_hash', 'approved_hash', 'active_claim_hash', 'cancellation_reason'] as $field) {
            $out[$field] = $row->getAttribute($field);
        }

        foreach (['submitted_at', 'approved_at', 'cancelled_at'] as $field) {
            $out[$field] = $row->getAttribute($field)?->format('Y-m-d H:i:s');
        }

        return $out;
    }

    /** @param array<string, mixed>|null $before */
    public function append(Model $row, User $actor, string $action, string $reason, ?array $before): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Expense audit needs its complete company transaction.');
        }

        $after = $this->summary($row);
        $at = CarbonImmutable::now('UTC');
        $previous = $row->getAttribute('history_hash');

        $payload = ['contract' => AccountingExpenseContract::VERSION, 'before' => $before, 'after' => $after, 'action' => $action, 'actor_id' => (int) $actor->getKey(), 'reason' => $reason, 'occurred_at' => $at->format('Y-m-d H:i:s'), 'previous_hash' => $previous];
        $hash = $this->canonical->hash($payload);

        $event = AccountingExpenseEvent::query()->create(['company_id' => $after['company_id'], 'expense_id' => $after['kind'] === 'expense' ? $after['id'] : null, 'category_id' => $after['kind'] === 'category' ? $after['id'] : null, 'revision' => $after['revision'], 'actor_id' => (int) $actor->getKey(), 'action' => $action, 'reason' => $reason, 'occurred_at' => $at, 'previous_hash' => $previous, 'payload' => $payload, 'payload_hash' => $hash]);

        $changed = DB::table($row->getTable())->where('company_id', $after['company_id'])->where('id', $after['id'])->where('revision', $after['revision'])->where('history_head_id', $row->getAttribute('history_head_id'))->where('history_hash', $previous)->update(['history_head_id' => (int) $event->getKey(), 'history_hash' => $hash]);

        if ($changed !== 1) {
            $this->fail();
        }
    }

    public function verify(Model $row): void
    {
        if ($row instanceof AccountingExpense) {
            app(AccountingExpenseAttachmentLedger::class)->verify($row);
        }

        $actual = $this->summary($row);
        $field = $actual['kind'] === 'expense' ? 'expense_id' : 'category_id';
        $before = null;
        $hash = null;
        $revision = 0;
        $head = null;

        foreach (AccountingExpenseEvent::query()->where('company_id', $actual['company_id'])->where($field, $actual['id'])->orderBy('revision')->get() as $event) {
            $payload = $event->getAttribute('payload');

            if (! is_array($payload) || ! is_array($payload['after'] ?? null) || ($payload['contract'] ?? null) !== AccountingExpenseContract::VERSION || ! $this->same($payload['before'] ?? null, $before) || $event->getAttribute('previous_hash') !== $hash || ($payload['previous_hash'] ?? null) !== $hash || (int) $event->getAttribute('revision') !== ++$revision || $event->getAttribute('payload_hash') !== $this->canonical->hash($payload) || ($payload['action'] ?? null) !== $event->getAttribute('action') || ($payload['actor_id'] ?? null) !== (int) $event->getAttribute('actor_id') || ($payload['reason'] ?? null) !== $event->getAttribute('reason') || ($payload['occurred_at'] ?? null) !== $event->getAttribute('occurred_at')->format('Y-m-d H:i:s')) {
                $this->fail();
            }

            $after = $payload['after'];
            if (array_diff(array_keys($actual), array_keys($after)) !== [] || array_diff(array_keys($after), array_keys($actual)) !== []) {
                $this->fail();
            }

            if (($after['id'] ?? null) !== $actual['id'] || ($after['company_id'] ?? null) !== $actual['company_id'] || ($after['kind'] ?? null) !== $actual['kind'] || ($after['revision'] ?? null) !== $revision) {
                $this->fail();
            }

            if ($revision === 1 && ($event->getAttribute('action') !== 'create' || ($actual['kind'] === 'expense' && $after['status'] !== 'draft'))) {
                $this->fail();
            }

            $this->transition($before, $after, (string) $event->getAttribute('action'), (int) $event->getAttribute('actor_id'));

            $before = $after;
            $hash = $event->getAttribute('payload_hash');
            $head = (int) $event->getKey();
        }

        if ($row instanceof AccountingExpense) {
            $this->guard->review($row);
        }

        if ($revision !== $actual['revision'] || $hash !== $row->getAttribute('history_hash') || $head !== (int) $row->getAttribute('history_head_id') || $this->canonical->hash($before) !== $this->canonical->hash($actual)) {
            $this->fail();
        }
        if ($row instanceof AccountingExpense && in_array($row->getAttribute('status'), ['posted', 'reversed'], true)) {
            app(AccountingExpenseFinanceEvidence::class)->balance((int) $row->getAttribute('company_id'), (int) $row->getKey());
        }
    }

    /** @return list<array<string, mixed>> */
    public function timeline(Model $row): array
    {
        $field = $row instanceof AccountingExpense ? 'expense_id' : 'category_id';

        $events = AccountingExpenseEvent::query()
            ->where('company_id', $row->getAttribute('company_id'))
            ->where($field, $row->getKey())
            ->orderByDesc('revision')
            ->limit(100)
            ->get();

        $timeline = [];

        foreach ($events as $event) {
            $timeline[] = [
                'id' => (int) $event->getKey(),
                'revision' => (int) $event->getAttribute('revision'),
                'action' => (string) $event->getAttribute('action'),
                'actor_id' => (int) $event->getAttribute('actor_id'),
                'reason' => (string) $event->getAttribute('reason'),
                'occurred_at' => $event->getAttribute('occurred_at')->format(DATE_ATOM),
            ];
        }

        return $timeline;
    }

    private function same(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        return is_array($left) && is_array($right) && $this->canonical->hash($left) === $this->canonical->hash($right);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function transition(?array $before, array $after, string $action, int $actor): void
    {
        if (($after['updated_by'] ?? null) !== $actor || ($before !== null && ($after['created_by'] ?? null) !== ($before['created_by'] ?? null))) {
            $this->fail();
        }

        if ($before !== null && ($after['creation_key_hash'] !== $before['creation_key_hash'] || $after['creation_request_hash'] !== $before['creation_request_hash'])) {
            $this->fail();
        }

        if ($before === null) {
            if ($action !== 'create' || ($after['created_by'] ?? null) !== $actor) {
                $this->fail();
            }
        }

        if ($after['kind'] === 'category') {
            if ($before !== null && ($action !== 'update' || $before['code'] !== $after['code'])) {
                $this->fail();
            }

            return;
        }

        $old = $before['status'] ?? null;
        $state = $after['status'] ?? null;

        $valid = match ($action) {
            'create' => $old === null && $state === 'draft',
            'edit' => $old === 'draft' && $state === 'draft',
            'submit' => $old === 'draft' && $state === 'submitted',
            'approve' => $old === 'submitted' && $state === 'approved',
            'reject' => $old === 'submitted' && $state === 'draft',
            'cancel' => in_array($old, ['draft', 'submitted', 'approved'], true) && $state === 'cancelled',
            'post' => $old === 'approved' && $state === 'posted',
            'reverse' => $old === 'posted' && $state === 'reversed',
            default => false,
        };

        if (! $valid || (in_array($action, ['create', 'edit'], true) && $after['last_edited_by'] !== $actor) || ($action === 'submit' && $after['submitted_by'] !== $actor) || ($action === 'approve' && $after['approved_by'] !== $actor) || ($action === 'cancel' && $after['cancelled_by'] !== $actor)) {
            $this->fail();
        }

        if ($before !== null && $action !== 'edit' && ($after['intent_hash'] !== $before['intent_hash'] || $after['last_edited_by'] !== $before['last_edited_by'])) {
            $this->fail();
        }

        if ($state === 'draft' && ($after['submitted_by'] !== null || $after['approved_by'] !== null || $after['submitted_hash'] !== null || $after['approved_hash'] !== null || $after['submitted_at'] !== null || $after['approved_at'] !== null)) {
            $this->fail();
        }

        if (in_array($state, ['submitted', 'approved', 'posted', 'reversed'], true) && ($after['submitted_by'] === null || $after['submitted_at'] === null || $after['submitted_hash'] !== $after['intent_hash'])) {
            $this->fail();
        }

        if ($state === 'submitted' && ($after['approved_by'] !== null || $after['approved_at'] !== null || $after['approved_hash'] !== null)) {
            $this->fail();
        }

        if (in_array($state, ['approved', 'posted', 'reversed'], true) && ($after['approved_hash'] !== $after['intent_hash'] || $after['approved_at'] === null || ! is_int($after['approved_by']) || in_array($after['approved_by'], [$after['created_by'], $after['last_edited_by'], $after['submitted_by']], true))) {
            $this->fail();
        }

        if ($before !== null && in_array($action, ['approve', 'cancel', 'post', 'reverse'], true)) {
            foreach (['submitted_by', 'submitted_at', 'submitted_hash'] as $field) {
                if ($after[$field] !== $before[$field]) {
                    $this->fail();
                }
            }
        }

        if ($before !== null && in_array($action, ['cancel', 'post', 'reverse'], true)) {
            foreach (['approved_by', 'approved_at', 'approved_hash'] as $field) {
                if ($after[$field] !== $before[$field]) {
                    $this->fail();
                }
            }
        }

        if ($state === 'cancelled') {
            if ($after['active_claim_hash'] !== null || $after['cancelled_by'] === null || $after['cancelled_at'] === null || ! is_string($after['cancellation_reason']) || trim($after['cancellation_reason']) === '') {
                $this->fail();
            }
        } elseif ($after['active_claim_hash'] === null || $after['cancelled_by'] !== null || $after['cancelled_at'] !== null || $after['cancellation_reason'] !== null) {
            $this->fail();
        }
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['expense_integrity' => 'Expense/category history and its protected head do not agree. Missing history is not repaired.']);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\AccountingAuditEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/** Internal writer inside the SAME transaction as the authorized state change. Not an HTTP payload logger. */
final class AccountingAuditService
{
    private const FIELDS = ['code', 'name', 'state', 'status', 'revision', 'number', 'currency_code', 'functional_currency', 'contract_version', 'starts_on', 'ends_on', 'accounting_date', 'entry_kind', 'total_debit', 'total_credit', 'mapping_version', 'source_kind', 'source_id', 'account_group_id', 'parent_id', 'normal_side', 'is_postable', 'control_role', 'statement_role', 'cash_flow_role', 'category', 'effective_from', 'payload_hash', 'mapping_count', 'cash_count', 'fiscal_year_id', 'closed_by', 'closed_at', 'line_count', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'posted_by', 'posted_at', 'reverses_journal_id', 'cutover_date', 'strategy'];

    public function __construct(private readonly AccountingCompanyAccess $access, private readonly AccountingDomainRegistry $registry, private readonly AccountingCanonicalPayload $canonical) {}

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    public function record(User $actor, int $companyId, string $entity, int $id, string $action, array $before, array $after, string $reason): AccountingAuditEvent
    {
        $permission = $this->registry->permission($entity, $action);
        $this->access->authorize($actor, $companyId, $permission, true);
        $class = $this->registry->model($entity);
        $document = $class::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => 'Provide an accounting action reason of up to 2,000 characters.']);
        }
        if (in_array($action, ['approve', 'reopen'], true)) {
            $creator = $document->getAttribute($action === 'reopen' ? 'closed_by' : 'created_by');
            if ($creator === null || (int) $creator === (int) $actor->getKey() || ($action === 'approve' && (int) $document->getAttribute('updated_by') === (int) $actor->getKey())) {
                throw new AuthorizationException('An independent accounting reviewer is required.');
            }
        }
        foreach ([$before, $after] as $payload) {
            if (array_diff(array_keys($payload), self::FIELDS) !== []) {
                throw new \InvalidArgumentException('Audit snapshot contains a field outside the accounting whitelist.');
            }
            foreach ($payload as $value) {
                if (! is_scalar($value) && $value !== null) {
                    throw new \InvalidArgumentException('Accounting audit snapshots contain scalar values only.');
                }
                if (is_string($value) && mb_strlen($value) > 500) {
                    throw new \InvalidArgumentException('Accounting audit snapshot value is too long.');
                }
            }
            $this->canonical->hash($payload);
        }

        return AccountingAuditEvent::query()->create(['company_id' => $companyId, 'entity_kind' => $entity, 'entity_id' => $id, 'action' => $action, 'actor_id' => $actor->getKey(), 'actor_snapshot' => ['id' => $actor->getKey(), 'name' => (string) $actor->getAttribute('name')], 'reason' => $reason, 'before_payload' => $before, 'after_payload' => $after, 'occurred_at' => now()->toDateTimeString()]);
    }
}

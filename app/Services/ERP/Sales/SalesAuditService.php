<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use App\Models\SalesDocumentEvent;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Trusted internal event writer. Never pass raw HTTP payloads to before/after. */
final class SalesAuditService
{
    public function __construct(
        private readonly SalesCompanyAccess $access,
        private readonly SalesDomainRegistry $registry,
        private readonly SalesDocumentGuard $guard,
    ) {}

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function record(User $actor, int $companyId, string $type, int $documentId, string $action, array $before = [], array $after = [], ?string $reason = null): SalesDocumentEvent
    {
        if ($action === 'view') {
            throw new InvalidArgumentException('Document views are not posting events.');
        }
        $this->access->authorize($actor, $companyId, $this->registry->permission($type, $action), true);
        $model = $this->registry->model($type);
        $document = $model::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($documentId);
        if (in_array($action, ['approve', 'credit-override'], true)) {
            $this->guard->assertIndependentApprover($actor, $document);
        }
        $reason = $reason === null ? null : trim($reason);
        if (in_array($action, ['reject', 'cancel', 'reverse', 'credit-override'], true) && ($reason === null || $reason === '')) {
            throw ValidationException::withMessages(['reason' => 'This Sales action requires a reason.']);
        }
        if ($reason !== null && mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => 'Reason must not exceed 2000 characters.']);
        }

        return SalesDocumentEvent::query()->create([
            'company_id' => $companyId, 'document_type' => $type, 'document_id' => $document->getKey(),
            'action' => $action, 'actor_id' => $actor->getKey(),
            'actor_snapshot' => ['id' => $actor->getKey(), 'name' => (string) $actor->getAttribute('name')],
            'before_payload' => $before,
            'after_payload' => ['document_number' => (string) $document->getAttribute('number'), 'changes' => $after],
            'reason' => $reason, 'occurred_at' => now()->toDateTimeString(),
        ]);
    }
}

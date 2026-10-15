<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class SalesDocumentGuard
{
    public function __construct(
        private readonly SalesCompanyAccess $access,
        private readonly SalesDomainRegistry $registry,
    ) {}

    /** Scoped read; historical records remain readable in inactive companies. */
    public function view(User $actor, int $companyId, string $type, int $documentId): Model
    {
        $this->access->authorize($actor, $companyId, $this->registry->permission($type, 'view'));
        $model = $this->registry->model($type);

        return $model::query()->where('company_id', $companyId)->findOrFail($documentId);
    }

    /** Call before changing state, inside the SAME transaction as the change and event. */
    public function lockForAction(User $actor, int $companyId, string $type, int $documentId, string $action): Model
    {
        if (in_array($action, ['view', 'create'], true)) {
            throw new InvalidArgumentException('Use a scoped read or the authorized creation service.');
        }
        $this->access->authorize($actor, $companyId, $this->registry->permission($type, $action), true);
        $model = $this->registry->model($type);
        $document = $model::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($documentId);
        $allowed = $this->allowedStates($type, $action);
        if (! in_array((string) $document->getAttribute('status'), $allowed, true)) {
            throw ValidationException::withMessages(['status' => 'This Sales action is not allowed in the current document state.']);
        }
        if (in_array($action, ['approve', 'credit-override'], true)) {
            $this->assertIndependentApprover($actor, $document);
        }

        return $document;
    }

    public function assertIndependentApprover(User $actor, Model $document): void
    {
        $creator = $document->getAttribute('created_by');
        if ($creator === null || (int) $creator === (int) $actor->getKey()) {
            throw new AuthorizationException('Approval requires a different actor and an identifiable document creator.');
        }
    }

    public function assertRevision(Model $document, int $expectedRevision): void
    {
        if ($expectedRevision < 1 || (int) $document->getAttribute('revision') !== $expectedRevision) {
            throw ValidationException::withMessages(['revision' => 'This Sales document changed. Reload it before saving.']);
        }
    }

    /** @return list<string> */
    private function allowedStates(string $type, string $action): array
    {
        return match ($action) {
            'edit' => ['draft'],
            'submit' => ['draft'],
            'approve', 'reject' => ['submitted'],
            'send' => ['approved'],
            'accept', 'expire' => ['sent'],
            'confirm' => ['approved'],
            'close' => $type === 'order' ? ['delivered', 'cancelled'] : ['delivered'],
            'credit-override' => ['approved'],
            'allocate', 'reverse' => ['posted'],
            'inspect' => ['draft'],
            'post' => $type === 'return' ? ['inspected'] : ['draft'],
            'issue' => ['draft'],
            'cancel' => match ($type) {
                'quotation' => ['draft', 'submitted', 'approved', 'sent'],
                'order' => ['draft', 'submitted', 'approved', 'confirmed', 'partially_delivered'],
                default => ['draft'],
            },
            default => throw new InvalidArgumentException('Unsupported guarded Sales action.'),
        };
    }
}

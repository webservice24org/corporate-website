<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Purchase;

use App\Models\Accounting\AccountingAccountMapping;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Services\ERP\Accounting\AccountingMappingService;
use App\Services\ERP\Accounting\Source\AccountingPreparedSourceEvent;
use Illuminate\Validation\ValidationException;

/** Closed exception for a fully verified supplier reversal: reuse its original role/account identity. */
final class AccountingSupplierPaymentReversalMapping
{
    public function __construct(private readonly AccountingSupplierPaymentReversalOperations $operations, private readonly AccountingMappingService $mappings) {}

    /** @param list<string> $roles
     * @return array{version_id: int, payload_hash: string, accounts: array<string, int>}|null
     */
    public function resolve(AccountingPreparedSourceEvent $event, array $roles): ?array
    {
        if ($event->eventKind !== 'reversed' || $event->eventToken !== 'original') {
            return null;
        }
        $original = $this->original($event->companyId, $event->sourceKind, $event->sourceId);
        if ($original === null) {
            return null;
        }
        $effect = AccountingSourceEffect::query()->where('company_id', $event->companyId)->where('source_kind', $original['kind'])->where('source_id', $original['id'])->where('event_token', 'original')->where('effect_kind', $original['effect'])->firstOrFail();
        $snapshot = $effect->getAttribute('source_snapshot');
        $hash = is_array($snapshot) ? ($snapshot['evidence']['mapping_hash'] ?? null) : null;
        if (! is_string($hash)) {
            throw ValidationException::withMessages(['supplier_payment_reversal' => 'Original reversal mapping evidence is missing.']);
        }
        $version = (int) $effect->getAttribute('mapping_version_id');
        $this->mappings->verifyRecordedProfile($event->companyId, $version, $event->currency, (string) $effect->getAttribute('contract_version'), $hash);
        $accounts = [];
        foreach (AccountingAccountMapping::query()->where('company_id', $event->companyId)->where('mapping_version_id', $version)->whereIn('role', $roles)->get() as $row) {
            $accounts[(string) $row->getAttribute('role')] = (int) $row->getAttribute('account_id');
        }
        if (array_diff($roles, array_keys($accounts)) !== []) {
            throw ValidationException::withMessages(['supplier_payment_reversal' => 'Original profile cannot identify every reversal line.']);
        }

        return ['version_id' => $version, 'payload_hash' => $hash, 'accounts' => $accounts];
    }

    public function cashBinding(int $company, string $kind, int $id): bool
    {
        return $kind === 'supplier_payment' && $this->original($company, $kind, $id) !== null;
    }

    /** @return array{kind: string, id: int, effect: string}|null */
    private function original(int $company, string $kind, int $id): ?array
    {
        if ($kind === 'supplier_payment') {
            $cash = SupplierPayment::query()->where('company_id', $company)->find($id);
            if ($cash === null || $cash->getAttribute('reverses_payment_id') === null) {
                return null;
            }
            $proof = $this->operations->forPayment($company, (int) $cash->getAttribute('reverses_payment_id'));
            if ((int) $proof['cash']->getKey() !== $id) {
                throw ValidationException::withMessages(['supplier_payment_reversal' => 'Original cash mapping belongs to another reversal.']);
            }

            return ['kind' => 'supplier_payment', 'id' => (int) $cash->getAttribute('reverses_payment_id'), 'effect' => 'cash'];
        }
        if ($kind !== 'supplier_payment_allocation') {
            return null;
        }
        $row = SupplierPaymentAllocation::query()->where('company_id', $company)->find($id);
        if ($row === null || ! str_contains((string) $row->getAttribute('idempotency_key'), ':reverse:')) {
            return null;
        }
        $root = SupplierPayment::query()->where('company_id', $company)->findOrFail((int) $row->getAttribute('supplier_payment_id'));
        $this->operations->allocation($root, $id);

        return ['kind' => 'supplier_payment_allocation', 'id' => (int) $row->getAttribute('reverses_allocation_id'), 'effect' => 'allocation'];
    }
}

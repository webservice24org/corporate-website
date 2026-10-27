<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Purchase;

use App\Models\Company;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\SupplierPaymentReversalRequest;
use App\Models\User;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read-only recognized starting position. No repair, new native row, journal, or operation registration. */
final class AccountingSupplierPaymentPosition
{
    public function __construct(private readonly AccountingSupplierPaymentService $payments, private readonly PurchaseCompanyAccess $access, private readonly AccountingSupplierPaymentPosting $posting, private readonly AccountingSupplierPaymentEvidence $evidence, private readonly AccountingSupplierPaymentChangeContract $contract) {}

    /** Advisory read. The future writer must repeat verification under its company/settings/source locks.
     * Original and later complete allocation batches are verified before deriving available funds.
     *
     * @return array<string, mixed>
     */
    public function read(User $actor, int $id, string $action = 'allocate'): array
    {
        $permission = match ($action) {
            'allocate' => 'erp.supplier-payments.allocate', 'reverse' => 'erp.supplier-payments.reverse', default => throw new \InvalidArgumentException('Unknown payment position authority.')
        };
        $found = $this->payments->find($actor, $id, $permission);
        $company = (int) $found->getAttribute('company_id');
        $this->access->authorize($actor, $company, $permission);

        return DB::transaction(function () use ($actor, $id, $permission, $company): array {
            Company::query()->whereKey($company)->lockForUpdate()->firstOrFail();
            $payment = SupplierPayment::query()->where('company_id', $company)->lockForUpdate()->findOrFail($id);
            $this->access->authorize($actor, $company, $permission);
            if ($payment->getAttribute('status') !== 'posted' || $payment->getAttribute('reverses_payment_id') !== null || SupplierPayment::query()->where('company_id', $company)->where('reverses_payment_id', $id)->exists() || SupplierPaymentReversalRequest::query()->where('supplier_payment_id', $id)->whereIn('status', ['draft', 'submitted', 'approved', 'posted'])->exists()) {
                $this->fail('Use an original posted payment with no existing reversal dependency.');
            }
            $this->posting->verify($company, $id);
            $root = ['company_id' => $company, 'payment_id' => $id, 'supplier_id' => (int) $payment->getAttribute('supplier_id'), 'cash_account_id' => (int) $payment->getAttribute('cash_account_id'), 'currency_code' => (string) $payment->getAttribute('currency_code'), 'revision' => (int) $payment->getAttribute('revision'), 'amount' => (string) $payment->getAttribute('amount')];
            $history = [];
            // Deliberately do not filter foreign company rows out: any malformed dependent row must fail verification.
            foreach (SupplierPaymentAllocation::query()->where('supplier_payment_id', $id)->orderBy('id')->get() as $allocation) {
                $this->evidence->recognized($company, 'supplier_payment_allocation', (int) $allocation->getKey());
                $history[] = ['id' => (int) $allocation->getKey(), 'company_id' => (int) $allocation->getAttribute('company_id'), 'supplier_payment_id' => (int) $allocation->getAttribute('supplier_payment_id'), 'supplier_bill_id' => (int) $allocation->getAttribute('supplier_bill_id'), 'supplier_payable_id' => (int) $allocation->getAttribute('supplier_payable_id'), 'amount' => (string) $allocation->getAttribute('amount'), 'reverses_allocation_id' => $allocation->getAttribute('reverses_allocation_id') === null ? null : (int) $allocation->getAttribute('reverses_allocation_id')];
            }

            return $this->contract->position($root, $history);
        });
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['supplier_payment' => $message]);
    }
}

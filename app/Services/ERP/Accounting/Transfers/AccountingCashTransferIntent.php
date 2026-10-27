<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Transfers;

use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingCashTransfer;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

/** Native approval fingerprint; private purpose/notes are hashed here and excluded from the financial plan. */
final class AccountingCashTransferIntent
{
    public function __construct(private readonly AccountingCanonicalPayload $canonical, private readonly AccountingCashTransferContract $contract) {}

    public function hash(AccountingCashTransfer $row): string
    {
        $date = $row->getAttribute('document_date');
        $source = $row->getAttribute('source_snapshot');
        $destination = $row->getAttribute('destination_snapshot');
        if (! $date instanceof DateTimeInterface || ! is_array($source) || ! is_array($destination)) {
            $this->fail();
        }
        foreach ([$source, $destination] as $snapshot) {
            $binding = AccountingCashAccount::query()->where('company_id', $row->getAttribute('company_id'))->find((int) ($snapshot['id'] ?? 0));
            if ($binding === null) {
                $this->fail();
            }
            $actual = ['id' => (int) $binding->getKey(), 'company_id' => (int) $binding->getAttribute('company_id'), 'account_id' => (int) $binding->getAttribute('account_id'), 'role' => (string) $binding->getAttribute('role'), 'kind' => (string) $binding->getAttribute('kind'), 'currency_code' => (string) $binding->getAttribute('currency_code')];
            if ($this->canonical->hash($snapshot) !== $this->canonical->hash($actual)) {
                $this->fail();
            }
        }
        $plan = $this->contract->plan((int) $row->getAttribute('company_id'), (string) $row->getAttribute('currency_code'), (string) $row->getAttribute('amount'), $source, $destination);
        if ($source['id'] !== (int) $row->getAttribute('source_cash_account_id') || $destination['id'] !== (int) $row->getAttribute('destination_cash_account_id')) {
            $this->fail();
        }

        return $this->canonical->hash([
            'plan' => $plan, 'source' => $source, 'destination' => $destination,
            'number' => (string) $row->getAttribute('number'), 'document_date' => $date->format('Y-m-d'),
            'mapping_version_id' => (int) $row->getAttribute('mapping_version_id'), 'mapping_hash' => (string) $row->getAttribute('mapping_hash'),
            'purpose' => (string) $row->getAttribute('purpose'), 'notes' => $row->getAttribute('notes'),
            'created_by' => (int) $row->getAttribute('created_by'), 'last_edited_by' => (int) $row->getAttribute('last_edited_by'),
        ]);
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['cash_transfer' => 'Transfer identity or binding snapshots are inconsistent.']);
    }
}

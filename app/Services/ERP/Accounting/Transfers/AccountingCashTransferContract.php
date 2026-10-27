<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Transfers;

use App\Services\ERP\Accounting\AccountingDecimal;
use App\Services\ERP\Accounting\AccountingMappingCatalog;
use Illuminate\Validation\ValidationException;

/** Same-company/currency movement: debit destination, credit source. No revenue, expense, fee or FX leg. */
final class AccountingCashTransferContract
{
    public const VERSION = 'cash-transfer.v1';

    public const PERMISSIONS = [
        'erp.cash-transfers.post', 'erp.cash-transfers.view', 'erp.cash-transfers.create', 'erp.cash-transfers.edit',
        'erp.cash-transfers.submit', 'erp.cash-transfers.approve', 'erp.cash-transfers.cancel',
    ];

    public function __construct(private readonly AccountingDecimal $decimal, private readonly AccountingMappingCatalog $catalog) {}

    public function amount(mixed $value): string
    {
        $amount = $this->decimal->money($value);
        if (bccomp($amount, '0', 2) <= 0) {
            $this->fail('A transfer amount must be positive.');
        }

        return $amount;
    }

    /** @param array<string, mixed> $source @param array<string, mixed> $destination @return array<string, mixed> */
    public function plan(int $company, string $currency, mixed $amount, array $source, array $destination): array
    {
        if ($company < 1 || ! preg_match('/\A[A-Z]{3}\z/', $currency)) {
            $this->fail('Use one normalized company and currency.');
        }
        foreach ([$source, $destination] as $binding) {
            $expected = ['id', 'company_id', 'account_id', 'role', 'kind', 'currency_code'];
            if (array_diff(array_keys($binding), $expected) !== [] || array_diff($expected, array_keys($binding)) !== []) {
                $this->fail('Binding snapshots must contain only the fixed finance identity.');
            }
            foreach (['id', 'company_id', 'account_id'] as $key) {
                if (! is_int($binding[$key]) || $binding[$key] < 1) {
                    $this->fail('Binding identities must be positive normalized integers.');
                }
            }
            if ($binding['company_id'] !== $company || $binding['currency_code'] !== $currency || ! is_string($binding['role']) || ! $this->catalog->cashRole($binding['role']) || $binding['kind'] !== (str_starts_with($binding['role'], 'cash:') ? 'cash' : 'bank')) {
                $this->fail('Transfer accounts must belong to the same company/currency with matching cash/bank roles.');
            }
        }
        if ($source['id'] === $destination['id'] || $source['account_id'] === $destination['account_id'] || $source['role'] === $destination['role']) {
            $this->fail('Choose two distinct finance bindings and GL accounts.');
        }
        $value = $this->amount($amount);

        return [
            'contract_version' => self::VERSION, 'company_id' => $company, 'currency_code' => $currency, 'amount' => $value,
            'lines' => [
                ['role' => $destination['role'], 'account_id' => $destination['account_id'], 'cash_account_id' => $destination['id'], 'debit' => $value, 'credit' => '0.00'],
                ['role' => $source['role'], 'account_id' => $source['account_id'], 'cash_account_id' => $source['id'], 'debit' => '0.00', 'credit' => $value],
            ],
        ];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['cash_transfer' => $message]);
    }
}

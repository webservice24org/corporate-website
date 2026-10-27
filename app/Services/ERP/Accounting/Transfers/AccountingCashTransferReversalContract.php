<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Transfers;

use Illuminate\Validation\ValidationException;

/** Exact original endpoints and amount. This is an unposted reversal plan, not a journal command. */
final class AccountingCashTransferReversalContract
{
    public const VERSION = 'cash-transfer-reversal.v1';

    public const ORIGINAL_VERSION = 'cash-transfer-original.v1';

    public const PERMISSIONS = [
        'erp.cash-transfer-reversals.post', 'erp.cash-transfer-reversals.view', 'erp.cash-transfer-reversals.create',
        'erp.cash-transfer-reversals.edit', 'erp.cash-transfer-reversals.submit',
        'erp.cash-transfer-reversals.approve', 'erp.cash-transfer-reversals.cancel',
    ];

    public function __construct(private readonly AccountingCashTransferContract $transfers) {}

    /** @param array<string, mixed> $original @return array<string, mixed> */
    public function original(array $original): array
    {
        $keys = ['version', 'company_id', 'transfer_id', 'transfer_revision', 'number', 'document_date', 'currency_code', 'amount', 'source', 'destination', 'operation_id', 'operation_hash', 'effect_id', 'source_hash', 'journal_id', 'journal_hash', 'mapping_version_id', 'mapping_hash'];
        if (array_diff(array_keys($original), $keys) !== [] || array_diff($keys, array_keys($original)) !== []) {
            $this->fail('Original transfer proof has unsupported or missing fields.');
        }
        if ($original['version'] !== self::ORIGINAL_VERSION) {
            $this->fail('Unsupported original transfer proof version.');
        }
        foreach (['company_id', 'transfer_id', 'transfer_revision', 'operation_id', 'effect_id', 'journal_id', 'mapping_version_id'] as $key) {
            if (! is_int($original[$key]) || $original[$key] < 1) {
                $this->fail('Original identities require positive normalized integers.');
            }
        }
        if ($original['transfer_revision'] < 4) {
            $this->fail('Original transfer must have completed independent approval and posting.');
        }
        foreach (['operation_hash', 'source_hash', 'journal_hash', 'mapping_hash'] as $key) {
            if (! is_string($original[$key]) || ! preg_match('/\A[a-f0-9]{64}\z/', $original[$key])) {
                $this->fail('Original evidence fingerprints are invalid.');
            }
        }
        if (! is_string($original['document_date']) || ! is_string($original['currency_code']) || ! is_string($original['amount']) || ! is_array($original['source']) || ! is_array($original['destination'])) {
            $this->fail('Original date, currency, amount and fixed endpoints are required.');
        }
        $this->date($original['document_date']);
        if (! is_string($original['number']) || ! preg_match('/\ACT-'.substr($original['document_date'], 0, 4).'-[0-9]{6}\z/', $original['number'])) {
            $this->fail('Original number and document date differ.');
        }
        $plan = $this->transfers->plan($original['company_id'], $original['currency_code'], $original['amount'], $original['source'], $original['destination']);
        if ($plan['amount'] !== $original['amount']) {
            $this->fail('Original amount must be a canonical exact two-decimal string.');
        }

        return $original;
    }

    /** @param array<string, mixed> $original @return array<string, mixed> */
    public function plan(array $original, string $date): array
    {
        $original = $this->original($original);
        $this->date($date);
        if ($date < $original['document_date']) {
            $this->fail('A reversal cannot predate its original transfer.');
        }
        $source = $original['source'];
        $destination = $original['destination'];
        $amount = $original['amount'];

        return [
            'contract_version' => self::VERSION, 'preview_only' => true,
            'company_id' => $original['company_id'], 'original_transfer_id' => $original['transfer_id'], 'original_journal_id' => $original['journal_id'],
            'accounting_date' => $date, 'currency_code' => $original['currency_code'], 'amount' => $amount,
            'lines' => [
                ['role' => $source['role'], 'account_id' => $source['account_id'], 'cash_account_id' => $source['id'], 'debit' => $amount, 'credit' => '0.00'],
                ['role' => $destination['role'], 'account_id' => $destination['account_id'], 'cash_account_id' => $destination['id'], 'debit' => '0.00', 'credit' => $amount],
            ],
        ];
    }

    public function transition(string $from, string $to): void
    {
        $allowed = match ($from) {
            'draft' => ['draft', 'submitted', 'cancelled'],
            'submitted' => ['draft', 'approved', 'cancelled'],
            'approved' => ['cancelled', 'posted'], default => [],
        };
        if (! in_array($to, $allowed, true)) {
            $this->fail('Unsupported reversal state transition. Posting requires approved native intent.');
        }
    }

    public function independent(int $approver, int $maker, int $editor, int $submitter): bool
    {
        return min($approver, $maker, $editor, $submitter) > 0 && ! in_array($approver, [$maker, $editor, $submitter], true);
    }

    private function date(string $date): void
    {
        if (! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date) || $date < '1900-01-01' || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            $this->fail('Use a real YYYY-MM-DD accounting date from 1900 onward.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['cash_transfer_reversal' => $message]);
    }
}

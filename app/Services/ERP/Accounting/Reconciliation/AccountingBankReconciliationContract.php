<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reconciliation;

use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\AccountingDecimal;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AccountingBankReconciliationContract
{
    public const VERSION = 'bank-reconciliation.v1';

    public const PERMISSIONS = ['erp.bank-reconciliations.view', 'erp.bank-reconciliations.create', 'erp.bank-reconciliations.match', 'erp.bank-reconciliations.unmatch'];

    public function __construct(private readonly AccountingDecimal $decimal, private readonly AccountingCanonicalPayload $canonical) {}

    public function key(string $key): string
    {
        if (! preg_match('/\A[A-Za-z0-9_-]{16,64}\z/', $key)) {
            $this->fail('Use a stable opaque key of 16 to 64 letters, digits, underscores or hyphens.');
        }

        return hash('sha256', $key);
    }

    public function reason(string $reason): string
    {
        $reason = trim(str_replace(["\r\n", "\r"], "\n", $reason));
        if ($reason === '' || mb_strlen($reason) > 2000 || ! mb_check_encoding($reason, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)) {
            $this->fail('Provide a plain-text reconciliation reason of up to 2,000 characters.');
        }

        return $reason;
    }

    /** @param array<int|string, array<string, mixed>> $rows @return list<array{id: int, amount: string}> */
    public function allocations(array $rows): array
    {
        if (! array_is_list($rows) || count($rows) < 1 || count($rows) > 100) {
            $this->fail('Select between one and 100 distinct rows on each side.');
        }
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (array_diff(array_keys($row), ['id', 'amount']) !== [] || array_diff(['id', 'amount'], array_keys($row)) !== []) {
                $this->fail('Only an existing row ID and exact amount may be supplied for a match leg.');
            }
            $valid = Validator::make($row, ['id' => ['required', 'integer', 'min:1'], 'amount' => ['required']])->validate();
            $id = (int) $valid['id'];
            $amount = $this->decimal->money($valid['amount']);
            if (isset($seen[$id]) || bccomp($amount, '0', 2) <= 0) {
                $this->fail('Match amounts must be positive exact decimals and row IDs cannot repeat.');
            }
            $seen[$id] = true;
            $out[] = ['id' => $id, 'amount' => $amount];
        }
        usort($out, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $out;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function command(string $kind, array $input): array
    {
        $allowed = $kind === 'match' ? ['revision', 'key', 'reason', 'statement_allocations', 'book_allocations'] : ['revision', 'key', 'reason', 'target_match_id'];
        if (! in_array($kind, ['match', 'unmatch'], true) || array_diff(array_keys($input), $allowed) !== [] || array_diff($allowed, array_keys($input)) !== []) {
            $this->fail('Unsupported reconciliation command fields.');
        }
        $rules = ['revision' => ['required', 'integer', 'min:1'], 'key' => ['required', 'string'], 'reason' => ['required', 'string']];
        if ($kind === 'match') {
            $rules['statement_allocations'] = ['required', 'array'];
            $rules['book_allocations'] = ['required', 'array'];
            $rules['statement_allocations.*'] = ['array'];
            $rules['book_allocations.*'] = ['array'];
        } else {
            $rules['target_match_id'] = ['required', 'integer', 'min:1'];
        }
        $data = Validator::make($input, $rules)->validate();

        return ['kind' => $kind, 'revision' => (int) $data['revision'], 'key_hash' => $this->key($data['key']), 'reason' => $this->reason($data['reason']),
            'statement_allocations' => $kind === 'match' ? $this->allocations($data['statement_allocations']) : [],
            'book_allocations' => $kind === 'match' ? $this->allocations($data['book_allocations']) : [], 'target_match_id' => $kind === 'unmatch' ? (int) $data['target_match_id'] : null];
    }

    /** @param list<array<string, mixed>> $allocations @param array<int, array<string, mixed>> $sources @param array<int, string> $used
     * @return array{legs: list<array<string, mixed>>, direction: string, total: string} */
    public function freeze(array $allocations, array $sources, array $used): array
    {
        $legs = [];
        $total = '0.00';
        $direction = null;
        foreach ($allocations as $allocation) {
            $id = $allocation['id'];
            $source = $sources[$id] ?? null;
            if ($source === null) {
                $this->fail('A selected row is outside this verified bank/statement/date scope.');
            }
            $amount = $this->decimal->money($allocation['amount']);
            $remaining = bcsub($source['amount'], $used[$id] ?? '0.00', 2);
            if (bccomp($amount, $remaining, 2) > 0 || ($direction !== null && $direction !== $source['direction'])) {
                $this->fail('Matching cannot over-allocate rows or net money-in against money-out.');
            }
            $direction = $source['direction'];
            $total = bcadd($total, $amount, 2);
            $legs[] = ['id' => $id, 'amount' => $amount, 'direction' => $direction, 'proof_hash' => $source['proof_hash']];
        }
        if ($direction === null) {
            $this->fail('A match must have rows on both sides.');
        }

        return ['legs' => $legs, 'direction' => $direction, 'total' => $total];
    }

    /** @param array<string, mixed> $command */
    public function requestHash(int $actor, int $root, array $command): string
    {
        return $this->canonical->hash(['version' => self::VERSION, 'actor_id' => $actor, 'reconciliation_id' => $root, 'kind' => $command['kind'], 'revision' => $command['revision'],
            'reason_hash' => hash('sha256', $command['reason']), 'statement_allocations' => $command['statement_allocations'], 'book_allocations' => $command['book_allocations'], 'target_match_id' => $command['target_match_id']]);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['bank_reconciliation' => $message]);
    }
}

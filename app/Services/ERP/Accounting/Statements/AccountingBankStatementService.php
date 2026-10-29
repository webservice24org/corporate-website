<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Statements;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingBankStatement;
use App\Models\Accounting\AccountingBankStatementEvidence;
use App\Models\Accounting\AccountingBankStatementLine;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingBusinessDate;
use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use App\Services\ERP\Accounting\Reconciliation\AccountingBankFinalizationGuard;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Collects statement evidence only. No journal, source adapter, book balance, allocation or matching writes. */
final class AccountingBankStatementService
{
    public function __construct(
        private readonly PurchaseCompanyAccess $access,
        private readonly AccountingBankStatementContract $contract,
        private readonly AccountingBankStatementCsv $csv,
        private readonly AccountingCanonicalPayload $canonical,
        private readonly AccountingBusinessDate $dates,
    ) {}

    /** @param array<string, mixed> $metadata @param array<int|string, array<string, mixed>> $lines @return array<string, mixed> */
    public function preview(User $actor, string $mode, array $metadata, array $lines, ?string $csv = null, ?string $key = null): array
    {
        $this->permission($actor, $mode === 'csv' ? 'erp.bank-statements.import' : 'erp.bank-statements.create');
        $prepared = $this->prepare($mode, $metadata, $lines, $csv);
        $payload = $prepared['payload'];
        $companyId = (int) $payload['metadata']['company_id'];
        $this->access->authorize($actor, $companyId, 'erp.bank-statements.view');

        return DB::transaction(function () use ($actor, $companyId, $prepared, $mode, $key): array {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $companyId, 'erp.bank-statements.view');
            $existingUrl = null;
            $warnings = [];
            if ($key !== null) {
                if (! preg_match('/\A[A-Za-z0-9_-]{16,64}\z/', $key)) {
                    $this->fail('Invalid statement request key.');
                }
                $existing = AccountingBankStatement::query()->where('company_id', $companyId)->where('creation_key_hash', hash('sha256', $key))->first();
                if ($existing !== null) {
                    if ((int) $existing->getAttribute('created_by') !== (int) $actor->getKey() || ! hash_equals((string) $existing->getAttribute('request_hash'), $this->requestHash((int) $actor->getKey(), $mode, $prepared['payload_hash'], $prepared['source_hash']))) {
                        $this->fail('Request key belongs to another actor or statement payload.');
                    }
                    $this->verifyOwned($companyId, (int) $existing->getKey());
                    $existingUrl = '/erp/accounting/bank-statements/'.$existing->getKey();
                }
            }
            if ($existingUrl === null) {
                $warnings = $this->admit($company, $prepared['payload'], $prepared['source_hash']);
            }

            return ['existing_url' => $existingUrl, 'preview_hash' => $prepared['payload_hash'], 'metadata' => $prepared['payload']['metadata'], 'lines' => $this->displayRows($prepared['payload']['lines']), 'totals' => $prepared['payload']['totals'], 'warnings' => $warnings];
        }, 3);
    }

    /** @param array<string, mixed> $metadata @param array<int|string, array<string, mixed>> $lines */
    public function record(User $actor, string $mode, array $metadata, array $lines, ?string $csv, string $key, string $previewHash): AccountingBankStatement
    {
        $permission = $mode === 'csv' ? 'erp.bank-statements.import' : 'erp.bank-statements.create';
        $this->permission($actor, $permission);
        if (! preg_match('/\A[A-Za-z0-9_-]{16,64}\z/', $key) || ! preg_match('/\A[a-f0-9]{64}\z/', $previewHash)) {
            $this->fail('Use a valid request key and preview the exact statement before recording it.');
        }
        $prepared = $this->prepare($mode, $metadata, $lines, $csv);
        if (! hash_equals($prepared['payload_hash'], $previewHash)) {
            $this->fail('Statement changed after preview. Preview it again before recording.');
        }
        $payload = $prepared['payload'];
        $companyId = (int) $payload['metadata']['company_id'];
        $this->access->authorize($actor, $companyId, $permission);
        $requestHash = $this->requestHash((int) $actor->getKey(), $mode, $prepared['payload_hash'], $prepared['source_hash']);

        return DB::transaction(function () use ($actor, $companyId, $permission, $key, $prepared, $requestHash, $mode, $payload): AccountingBankStatement {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $companyId, $permission);
            $existing = AccountingBankStatement::query()->where('company_id', $companyId)->where('creation_key_hash', hash('sha256', $key))->first();
            if ($existing !== null) {
                if ((int) $existing->getAttribute('created_by') !== (int) $actor->getKey() || ! hash_equals((string) $existing->getAttribute('request_hash'), $requestHash)) {
                    $this->fail('Request key belongs to another actor or statement payload.');
                }
                $this->verifyOwned($companyId, (int) $existing->getKey());

                return $existing;
            }
            $this->admit($company, $payload, $prepared['source_hash']);
            $metadata = $payload['metadata'];
            $now = CarbonImmutable::now('UTC');
            $binding = AccountingCashAccount::query()->where('company_id', $companyId)->findOrFail((int) $metadata['cash_account_id']);
            $row = AccountingBankStatement::query()->create([
                ...$metadata, 'source_mode' => $mode, 'contract_version' => AccountingBankStatementContract::VERSION,
                'money_in' => $payload['totals']['money_in'], 'money_out' => $payload['totals']['money_out'], 'row_count' => $payload['totals']['row_count'],
                'binding_snapshot' => $this->binding($binding), 'payload_hash' => $prepared['payload_hash'], 'source_hash' => $prepared['source_hash'],
                'creation_key_hash' => hash('sha256', $key), 'request_hash' => $requestHash, 'created_by' => (int) $actor->getKey(), 'imported_at' => $now,
            ]);
            foreach ($payload['lines'] as $line) {
                AccountingBankStatementLine::query()->create([
                    'company_id' => $companyId, 'cash_account_id' => (int) $metadata['cash_account_id'], 'statement_id' => (int) $row->getKey(),
                    'line_number' => $line['line_number'], 'transaction_date' => $line['date'], 'value_date' => $line['value_date'], 'transaction_id' => $line['transaction_id'],
                    'reference' => $line['reference'], 'description' => $line['description'], 'money_in' => $line['money_in'], 'money_out' => $line['money_out'],
                    'reported_balance' => $line['balance'], 'running_balance' => $line['running_balance'], 'fingerprint' => $line['fingerprint'], 'transaction_hash' => $line['transaction_hash'],
                ]);
            }
            AccountingBankStatementEvidence::query()->create(['company_id' => $companyId, 'statement_id' => (int) $row->getKey(), 'actor_id' => (int) $actor->getKey(),
                'contract_version' => AccountingBankStatementContract::VERSION, 'source_mode' => $mode, 'source_text' => $prepared['source_text'], 'source_hash' => $prepared['source_hash'], 'payload' => $payload, 'payload_hash' => $prepared['payload_hash'], 'recorded_at' => $now]);
            $this->verifyOwned($companyId, (int) $row->getKey());

            return $row;
        }, 3);
    }

    public function find(User $actor, int $id): AccountingBankStatement
    {
        $this->permission($actor, 'erp.bank-statements.view');
        $query = AccountingBankStatement::query();
        if (! $actor->hasRole('super-admin')) {
            $query->whereIn('company_id', $actor->employee()->select('company_id'));
        }

        return $query->findOrFail($id);
    }

    /** Internal evidence reader. Caller must hold the authorized company's transaction/lock. @return array<string, mixed> */
    public function verifyOwned(int $companyId, int $id): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Statement verification requires the authorized company snapshot transaction.');
        }
        $row = AccountingBankStatement::query()->where('company_id', $companyId)->findOrFail($id);
        $evidenceRows = AccountingBankStatementEvidence::query()->where('company_id', $companyId)->where('statement_id', $id)->get();
        if ($evidenceRows->count() !== 1) {
            $this->fail('Statement import evidence is missing or ambiguous. A retry cannot repair it.');
        }
        $evidence = $evidenceRows->firstOrFail();
        $payload = $evidence->getAttribute('payload');
        if (! is_array($payload) || ! isset($payload['metadata'], $payload['lines']) || ! is_array($payload['metadata']) || ! is_array($payload['lines'])) {
            $this->fail('Statement payload is damaged.');
        }
        $mode = (string) $row->getAttribute('source_mode');
        $source = (string) $evidence->getAttribute('source_text');
        if ($mode === 'manual') {
            try {
                $raw = json_decode($source, true, 12, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $this->fail('Manual statement evidence is damaged.');
            }
            if (! is_array($raw) || ! isset($raw['metadata'], $raw['lines']) || ! is_array($raw['metadata']) || ! is_array($raw['lines']) || $this->canonical->hash($raw['metadata']) !== $this->canonical->hash($payload['metadata'])) {
                $this->fail('Manual statement metadata changed.');
            }
            $sourceRows = $raw['lines'];
        } elseif ($mode === 'csv') {
            $sourceRows = $this->csv->read($source);
        } else {
            $this->fail('Unsupported statement evidence mode.');
        }
        foreach ($sourceRows as $sourceRow) {
            if (! is_array($sourceRow)) {
                $this->fail('Statement source rows are damaged.');
            }
        }
        $rebuilt = $this->contract->normalize($payload['metadata'], $sourceRows);
        $hash = $this->canonical->hash($rebuilt);
        $sourceHash = hash('sha256', $source);
        if ($this->canonical->hash($payload) !== $hash || $row->getAttribute('contract_version') !== AccountingBankStatementContract::VERSION || $evidence->getAttribute('contract_version') !== AccountingBankStatementContract::VERSION || $evidence->getAttribute('source_mode') !== $mode || $row->getAttribute('payload_hash') !== $hash || $evidence->getAttribute('payload_hash') !== $hash || $row->getAttribute('source_hash') !== $sourceHash || $evidence->getAttribute('source_hash') !== $sourceHash || (int) $row->getAttribute('created_by') !== (int) $evidence->getAttribute('actor_id') || $row->getAttribute('imported_at')->format('Y-m-d H:i:s') !== $evidence->getAttribute('recorded_at')->format('Y-m-d H:i:s') || ! hash_equals((string) $row->getAttribute('request_hash'), $this->requestHash((int) $row->getAttribute('created_by'), $mode, $hash, $sourceHash))) {
            $this->fail('Statement header and immutable source evidence disagree.');
        }
        $metadata = $rebuilt['metadata'];
        foreach (['company_id', 'cash_account_id'] as $field) {
            if ((int) $row->getAttribute($field) !== $metadata[$field]) {
                $this->fail('Statement ownership changed.');
            }
        }
        foreach (['statement_reference', 'currency_code', 'opening_balance', 'closing_balance'] as $field) {
            if ($row->getAttribute($field) !== $metadata[$field]) {
                $this->fail('Statement header changed.');
            }
        }
        foreach (['starts_on', 'ends_on'] as $field) {
            if ($row->getAttribute($field)->format('Y-m-d') !== $metadata[$field]) {
                $this->fail('Statement period changed.');
            }
        }
        foreach (['money_in', 'money_out', 'row_count'] as $field) {
            if ($row->getAttribute($field) !== $rebuilt['totals'][$field]) {
                $this->fail('Statement totals changed.');
            }
        }
        $binding = AccountingCashAccount::query()->where('company_id', $companyId)->findOrFail((int) $metadata['cash_account_id']);
        $snapshot = $row->getAttribute('binding_snapshot');
        if ($binding->getAttribute('kind') !== 'bank' || $binding->getAttribute('currency_code') !== $metadata['currency_code'] || ! is_array($snapshot) || $this->canonical->hash($snapshot) !== $this->canonical->hash($this->binding($binding))) {
            $this->fail('Original bank binding identity changed.');
        }
        $lines = AccountingBankStatementLine::query()->where('company_id', $companyId)->where('statement_id', $id)->orderBy('line_number')->get();
        if ($lines->count() !== count($rebuilt['lines'])) {
            $this->fail('Statement line evidence is incomplete.');
        }
        foreach ($lines as $index => $line) {
            if ((int) $line->getAttribute('cash_account_id') !== (int) $metadata['cash_account_id'] || $this->canonical->hash($this->line($line)) !== $this->canonical->hash($rebuilt['lines'][$index])) {
                $this->fail('Statement line evidence changed.');
            }
        }

        return $rebuilt;
    }

    /** @param array<string, mixed> $metadata @param array<int|string, array<string, mixed>> $lines @return array<string, mixed> */
    private function prepare(string $mode, array $metadata, array $lines, ?string $csv): array
    {
        if (! in_array($mode, ['csv', 'manual'], true)) {
            $this->fail('Unsupported statement input mode.');
        }
        if ($mode === 'csv') {
            if ($csv === null || $lines !== []) {
                $this->fail('CSV mode requires a file and no manual rows.');
            } $lines = $this->csv->read($csv);
        } elseif ($csv !== null) {
            $this->fail('Manual mode cannot contain an uploaded file.');
        }
        $payload = $this->contract->normalize($metadata, $lines);
        $source = $csv ?? json_encode(['metadata' => $payload['metadata'], 'lines' => $this->rawRows($payload['lines'])], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($source) > AccountingBankStatementContract::MAX_BYTES) {
            $this->fail('Statement evidence exceeds 512 KiB.');
        }

        return ['payload' => $payload, 'payload_hash' => $this->canonical->hash($payload), 'source_text' => $source, 'source_hash' => hash('sha256', $source)];
    }

    /** @param array<string, mixed> $payload @return list<string> */
    private function admit(Company $company, array $payload, string $sourceHash): array
    {
        $m = $payload['metadata'];
        $companyId = (int) $company->getKey();
        $bindingId = (int) $m['cash_account_id'];
        app(AccountingBankFinalizationGuard::class)->assertDate($companyId, $bindingId, $m['starts_on']);
        if ($company->getAttribute('status') !== 'active' || $company->getAttribute('currency_code') !== $m['currency_code'] || $m['ends_on'] > $this->dates->today($company)) {
            $this->fail('New statement evidence requires an active company, its currency and a completed period no later than company today.');
        }
        $binding = AccountingCashAccount::query()->where('company_id', $companyId)->where('kind', 'bank')->findOrFail($bindingId);
        if (! in_array($binding->getAttribute('status'), ['active', 'inactive'], true) || $binding->getAttribute('currency_code') !== $m['currency_code'] || ! Account::query()->where('company_id', $companyId)->whereKey($binding->getAttribute('account_id'))->exists()) {
            $this->fail('Select an owned bank binding in the statement currency.');
        }
        $statements = AccountingBankStatement::query()->where('company_id', $companyId)->where('cash_account_id', $bindingId)->orderBy('starts_on')->get();
        $warnings = [];
        $previous = null;
        $next = null;
        foreach ($statements as $existing) {
            // A damaged earlier statement must be investigated before more evidence is admitted to this bank.
            $this->verifyOwned($companyId, (int) $existing->getKey());
            $start = $existing->getAttribute('starts_on')->format('Y-m-d');
            $end = $existing->getAttribute('ends_on')->format('Y-m-d');
            if ($existing->getAttribute('statement_reference') === $m['statement_reference'] || ($payload['totals']['row_count'] > 0 && $existing->getAttribute('source_hash') === $sourceHash)) {
                $this->fail('This bank already has this statement reference or source file.');
            }
            if ($start <= $m['ends_on'] && $end >= $m['starts_on']) {
                $this->fail('Statement periods for the same bank cannot overlap.');
            }
            if ($end < $m['starts_on']) {
                $previous = $existing;
            }
            if ($next === null && $start > $m['ends_on']) {
                $next = $existing;
            }
        }
        if ($previous !== null) {
            $adjacent = CarbonImmutable::parse($previous->getAttribute('ends_on')->format('Y-m-d'))->addDay()->format('Y-m-d') === $m['starts_on'];
            if ($adjacent && $previous->getAttribute('closing_balance') !== $m['opening_balance']) {
                $this->fail('Opening balance must equal the preceding adjacent statement closing balance.');
            }
            if (! $adjacent) {
                $warnings[] = 'There is a date gap before this statement. Continuous bank coverage is not established.';
            }
        } else {
            $warnings[] = 'This is the earliest recorded statement for this bank; its opening is supplied bank evidence, not a GL opening journal.';
        }
        if ($next !== null && CarbonImmutable::parse($m['ends_on'])->addDay()->format('Y-m-d') === $next->getAttribute('starts_on')->format('Y-m-d') && $m['closing_balance'] !== $next->getAttribute('opening_balance')) {
            $this->fail('Closing balance must equal the following adjacent statement opening balance.');
        }
        $fingerprints = array_column($payload['lines'], 'fingerprint');
        $transactionHashes = array_values(array_filter(array_column($payload['lines'], 'transaction_hash'), static fn ($value): bool => $value !== null));
        foreach (array_chunk($fingerprints, 200) as $chunk) {
            if (AccountingBankStatementLine::query()->where('company_id', $companyId)->where('cash_account_id', $bindingId)->whereIn('fingerprint', $chunk)->exists()) {
                $this->fail('A statement transaction is already recorded for this bank.');
            }
        }
        foreach (array_chunk($transactionHashes, 200) as $chunk) {
            if (AccountingBankStatementLine::query()->where('company_id', $companyId)->where('cash_account_id', $bindingId)->whereIn('transaction_hash', $chunk)->exists()) {
                $this->fail('A bank transaction ID is already recorded for this bank.');
            }
        }

        return $warnings;
    }

    /** @return array<string, mixed> */
    private function binding(AccountingCashAccount $binding): array
    {
        return ['id' => (int) $binding->getKey(), 'company_id' => (int) $binding->getAttribute('company_id'), 'account_id' => (int) $binding->getAttribute('account_id'), 'role' => (string) $binding->getAttribute('role'), 'kind' => (string) $binding->getAttribute('kind'), 'currency_code' => (string) $binding->getAttribute('currency_code')];
    }

    /** @return array<string, mixed> */
    private function line(AccountingBankStatementLine $line): array
    {
        return ['line_number' => (int) $line->getAttribute('line_number'), 'date' => $line->getAttribute('transaction_date')->format('Y-m-d'), 'value_date' => $line->getAttribute('value_date')?->format('Y-m-d'),
            'transaction_id' => (string) $line->getAttribute('transaction_id'), 'reference' => (string) $line->getAttribute('reference'), 'description' => (string) $line->getAttribute('description'),
            'money_in' => (string) $line->getAttribute('money_in'), 'money_out' => (string) $line->getAttribute('money_out'), 'balance' => $line->getAttribute('reported_balance'), 'running_balance' => (string) $line->getAttribute('running_balance'), 'fingerprint' => (string) $line->getAttribute('fingerprint'), 'transaction_hash' => $line->getAttribute('transaction_hash')];
    }

    /** @param array<int|string, array<string, mixed>> $lines @return list<array<string, mixed>> */
    private function rawRows(array $lines): array
    {
        $raw = [];
        foreach ($lines as $line) {
            $raw[] = array_intersect_key($line, array_flip(AccountingBankStatementContract::COLUMNS));
        }

        return $raw;
    }

    /** @param array<int|string, array<string, mixed>> $lines @return list<array<string, mixed>> */
    private function displayRows(array $lines): array
    {
        $safe = [];
        foreach ($lines as $line) {
            $safe[] = array_diff_key($line, ['fingerprint' => true, 'transaction_hash' => true]);
        }

        return $safe;
    }

    private function requestHash(int $actor, string $mode, string $payload, string $source): string
    {
        return $this->canonical->hash(['actor_id' => $actor, 'source_mode' => $mode, 'payload_hash' => $payload, 'source_hash' => $source]);
    }

    private function permission(User $actor, string $permission): void
    {
        if (! $actor->exists || ! $actor->can('erp.bank-statements.view') || ! in_array($permission, AccountingBankStatementContract::PERMISSIONS, true) || ! $actor->can($permission)) {
            throw new AuthorizationException('Native bank statement permission is required.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['statement' => $message]);
    }
}

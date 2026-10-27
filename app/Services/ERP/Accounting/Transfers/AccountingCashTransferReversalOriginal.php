<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Transfers;

use App\Models\Accounting\AccountingCashTransferOperation;
use Illuminate\Support\Facades\DB;

/** Internal read-only capture. Caller authorizes and holds the company/original transfer locks. */
final class AccountingCashTransferReversalOriginal
{
    public function __construct(private readonly AccountingCashTransferEvidence $evidence, private readonly AccountingCashTransferReversalContract $contract) {}

    /** @return array<string, mixed> */
    public function capture(int $company, int $id): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Capture original evidence inside the authorized company transaction.');
        }
        $proof = $this->evidence->verify($company, $id);
        $row = $proof['transfer'];
        $effect = $proof['effect'];
        $journal = $proof['journal'];
        $operation = AccountingCashTransferOperation::query()->where('company_id', $company)->where('cash_transfer_id', $id)->firstOrFail();

        return $this->contract->original([
            'version' => AccountingCashTransferReversalContract::ORIGINAL_VERSION,
            'company_id' => $company, 'transfer_id' => $id, 'transfer_revision' => (int) $row->getAttribute('revision'),
            'number' => (string) $row->getAttribute('number'), 'document_date' => $row->getAttribute('document_date')->format('Y-m-d'),
            'currency_code' => (string) $row->getAttribute('currency_code'), 'amount' => (string) $row->getAttribute('amount'),
            'source' => $row->getAttribute('source_snapshot'), 'destination' => $row->getAttribute('destination_snapshot'),
            'operation_id' => (int) $operation->getKey(), 'operation_hash' => (string) $operation->getAttribute('payload_hash'),
            'effect_id' => (int) $effect->getKey(), 'source_hash' => (string) $effect->getAttribute('source_payload_hash'),
            'journal_id' => (int) $journal->getKey(), 'journal_hash' => (string) $journal->getAttribute('payload_hash'),
            'mapping_version_id' => (int) $row->getAttribute('mapping_version_id'), 'mapping_hash' => (string) $row->getAttribute('mapping_hash'),
        ]);
    }
}

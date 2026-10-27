<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reconciliation;

use App\Services\ERP\Accounting\AccountingCanonicalPayload;
use Illuminate\Validation\ValidationException;

/** Restores only the matching prefixes and book/source commitments actually submitted for approval. No writes. */
final class AccountingBankApprovedSnapshotReader
{
    public function __construct(private readonly AccountingCanonicalPayload $canonical, private readonly AccountingBankReconciliationLedger $ledger, private readonly AccountingBankReconciliationReport $reports) {}

    /** Inputs come from verified finalization evidence and the fully verified live ledger under the company lock.
     * @param  array<string, mixed>  $snapshot  @param array<string, mixed> $live @return array<string, mixed>
     */
    public function project(int $id, array $snapshot, array $live): array
    {
        if (($snapshot['reconciliation_id'] ?? null) !== $id || ! is_array($snapshot['heads'] ?? null) || ! is_array($snapshot['statements'] ?? null) || ! is_array($snapshot['book'] ?? null) || ! is_array($snapshot['bridge'] ?? null) || ! is_int($snapshot['revision'] ?? null) || ! is_string($snapshot['cutoff'] ?? null) || ! is_string($snapshot['currency_code'] ?? null)) {
            $this->fail();
        }
        $sources = [];
        $book = [];
        $roots = [];
        $limits = [];
        $seen = [];
        $heads = [];
        $expected = [];
        foreach ($snapshot['statements'] as $proof) {
            if (! is_array($proof) || ! is_array($proof['identity'] ?? null) || ! is_int($proof['identity']['statement_id'] ?? null)) {
                $this->fail();
            }
            $statementId = (int) $proof['identity']['statement_id'];
            $current = $live['statements'][$statementId] ?? null;
            if (isset($sources[$statementId]) || $current === null || $this->canonical->hash($proof) !== $this->canonical->hash(['identity' => $current['identity'], 'opening' => $current['opening_balance'], 'closing' => $current['closing_balance'], 'rows' => $current['rows']])) {
                $this->fail();
            }
            $sources[$statementId] = $current;
        }
        foreach ($snapshot['book'] as $proof) {
            if (! is_array($proof) || ! is_int($proof['id'] ?? null)) {
                $this->fail();
            }
            $lineId = (int) $proof['id'];
            if (isset($book[$lineId]) || ! isset($live['book'][$lineId]) || $this->canonical->hash($proof) !== $this->canonical->hash($live['book'][$lineId])) {
                $this->fail();
            }
            $book[$lineId] = $proof;
        }
        foreach ($snapshot['heads'] as $head) {
            if (! is_array($head) || ! is_int($head['id'] ?? null) || ! is_int($head['statement_id'] ?? null) || ! is_int($head['revision'] ?? null) || ! is_string($head['head'] ?? null)) {
                $this->fail();
            }
            $rootId = (int) $head['id'];
            $statementId = (int) $head['statement_id'];
            $revision = (int) $head['revision'];
            if ($revision < 1 || isset($roots[$rootId]) || ! isset($sources[$statementId], $live['roots'][$rootId]) || $live['roots'][$rootId]['statement_id'] !== $statementId || $live['roots'][$rootId]['revision'] < $revision) {
                $this->fail();
            }
            $roots[$rootId] = ['statement_id' => $statementId, 'revision' => $revision];
            $limits[$rootId] = $revision - 1;
            $seen[$rootId] = 0;
            $heads[$rootId] = (string) $live['roots'][$rootId]['row']->getAttribute('snapshot_hash');
            $expected[$rootId] = $head['head'];
        }
        if (! isset($roots[$id])) {
            $this->fail();
        }
        /** @var array<int, string> $usedStatement */
        $usedStatement = [];
        /** @var array<int, string> $usedBook */
        $usedBook = [];
        $operations = [];
        $active = [];
        foreach ($live['operations'] as $operationId => $operation) {
            $rootId = $operation['root_id'];
            if (! isset($roots[$rootId])) {
                continue;
            }
            $seen[$rootId]++;
            if ($seen[$rootId] > $limits[$rootId]) {
                continue;
            }
            $sourceRows = $sources[$roots[$rootId]['statement_id']]['rows'];
            $plan = $operation['plan'];
            $matching = $operation['kind'] === 'match';
            foreach (['statement_legs' => $sourceRows, 'book_legs' => $book] as $side => $available) {
                foreach ($plan[$side] as $leg) {
                    $row = $available[$leg['id']] ?? null;
                    if ($row === null || $row['proof_hash'] !== $leg['proof_hash'] || $row['direction'] !== $leg['direction']) {
                        $this->fail();
                    }
                    $used = $side === 'statement_legs' ? ($usedStatement[$leg['id']] ?? '0.00') : ($usedBook[$leg['id']] ?? '0.00');
                    if ($matching && bccomp(bcadd($used, $leg['amount'], 2), $row['amount'], 2) > 0) {
                        $this->fail();
                    }
                }
            }
            if ($matching) {
                $active[$operationId] = true;
            } else {
                if (! isset($active[$operation['target_match_id']])) {
                    $this->fail();
                } unset($active[$operation['target_match_id']]);
            }
            $this->ledger->apply($usedStatement, $usedBook, $plan, $matching);
            $operations[$operationId] = $operation;
            $heads[$rootId] = $operation['hash'];
        }
        foreach ($roots as $rootId => $root) {
            if ($seen[$rootId] < $limits[$rootId] || $heads[$rootId] !== $expected[$rootId]) {
                $this->fail();
            }
        }
        $report = $this->reports->project($id, ['roots' => $roots, 'statements' => $sources, 'book' => $book, 'used_statement' => $usedStatement, 'used_book' => $usedBook, 'operations' => $operations, 'active' => $active]);
        if ($report['revision'] !== $snapshot['revision'] || $report['currency_code'] !== $snapshot['currency_code'] || $sources[$roots[$id]['statement_id']]['identity']['ends_on'] !== $snapshot['cutoff'] || $this->canonical->hash($report['bridge']) !== $this->canonical->hash($snapshot['bridge'])) {
            $this->fail();
        }
        // These are present-day availability concepts, not historical facts stored by the approved cutoff contract.
        foreach ($report['book_rows'] as &$row) {
            unset($row['currently_available'], $row['allocated_to_later_statements']);
        } unset($row);
        $report['allocation_basis'] = 'approved_matching_prefix_with_statement_date_cutoff';

        return $report;
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['export' => 'The approved matching prefix, source commitments and exact bridge could not be reproduced. No replacement snapshot is generated.']);
    }
}

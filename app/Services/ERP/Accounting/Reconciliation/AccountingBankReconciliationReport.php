<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reconciliation;

/** Current matching state against a dated source cutoff. This is not an approved/final reconciliation or an audit-time as-of reconstruction. */
final class AccountingBankReconciliationReport
{
    /** @param array<string, mixed> $state @return array<string, mixed> */
    public function project(int $rootId, array $state): array
    {
        $root = $state['roots'][$rootId];
        $source = $state['statements'][$root['statement_id']];
        $cutoff = $source['identity']['ends_on'];
        $statementRows = [];
        $bookRows = [];
        $matchedStatement = [];
        $matchedBook = [];
        $earlierMatchedNet = '0.00';
        $history = [];
        foreach ($state['operations'] as $id => $operation) {
            if ($operation['root_id'] === $rootId) {
                $history[] = ['id' => $id, 'kind' => $operation['kind'], 'active' => isset($state['active'][$id]), 'total' => $operation['plan']['total'], 'direction' => $operation['plan']['direction'], 'actor_id' => $operation['actor_id'], 'reason' => $operation['reason'], 'occurred_at' => $operation['occurred_at'], 'target_match_id' => $operation['target_match_id']];
            }
            if (! isset($state['active'][$id])) {
                continue;
            }
            $other = $state['roots'][$operation['root_id']];
            $otherSource = $state['statements'][$other['statement_id']];
            if ($otherSource['identity']['ends_on'] > $cutoff) {
                continue;
            }
            foreach ($operation['plan']['book_legs'] as $leg) {
                $matchedBook[$leg['id']] = bcadd($matchedBook[$leg['id']] ?? '0.00', $leg['amount'], 2);
            }
            if ($operation['root_id'] === $rootId) {
                foreach ($operation['plan']['statement_legs'] as $leg) {
                    $matchedStatement[$leg['id']] = bcadd($matchedStatement[$leg['id']] ?? '0.00', $leg['amount'], 2);
                }
            } else {
                $earlierMatchedNet = $this->add($earlierMatchedNet, $operation['plan']['direction'], $operation['plan']['total']);
            }
        }
        $statementOutstandingNet = '0.00';
        $bookOutstandingNet = '0.00';
        $bookClosing = '0.00';
        foreach ($source['rows'] as $id => $row) {
            $remaining = bcsub($row['amount'], $matchedStatement[$id] ?? '0.00', 2);
            $statementOutstandingNet = $this->add($statementOutstandingNet, $row['direction'], $remaining);
            $statementRows[] = ['id' => $id, 'date' => $row['date'], 'direction' => $row['direction'], 'amount' => $row['amount'], 'matched' => $matchedStatement[$id] ?? '0.00', 'remaining' => $remaining];
        }
        foreach ($state['book'] as $id => $row) {
            if ($row['date'] > $cutoff) {
                continue;
            }
            $bookClosing = $this->add($bookClosing, $row['direction'], $row['amount']);
            $remaining = bcsub($row['amount'], $matchedBook[$id] ?? '0.00', 2);
            $available = bcsub($row['amount'], $state['used_book'][$id] ?? '0.00', 2);
            $bookOutstandingNet = $this->add($bookOutstandingNet, $row['direction'], $remaining);
            $bookRows[] = ['id' => $id, 'date' => $row['date'], 'direction' => $row['direction'], 'amount' => $row['amount'], 'matched_through_cutoff' => $matchedBook[$id] ?? '0.00', 'outstanding_through_cutoff' => $remaining, 'currently_available' => $available, 'allocated_to_later_statements' => bcsub($remaining, $available, 2)];
        }
        $openingResidual = bcsub($source['opening_balance'], $earlierMatchedNet, 2);
        $difference = bcsub($source['closing_balance'], $bookClosing, 2);

        return ['reconciliation_id' => $rootId, 'statement_id' => $root['statement_id'], 'company_id' => $source['identity']['company_id'], 'cash_account_id' => $source['identity']['cash_account_id'], 'currency_code' => $source['identity']['currency_code'],
            'revision' => $root['revision'], 'status' => 'working', 'finalized' => false, 'allocation_basis' => 'current_matching_state_with_statement_date_cutoff',
            'statement_rows' => $statementRows, 'book_rows' => $bookRows, 'history' => $history,
            'bridge' => ['statement_opening' => $source['opening_balance'], 'statement_closing' => $source['closing_balance'], 'book_closing' => $bookClosing,
                'statement_minus_book' => $difference, 'unverified_opening_prior_statement_residual' => $openingResidual, 'unmatched_statement_net' => $statementOutstandingNet, 'outstanding_book_net' => $bookOutstandingNet,
                'explained_difference' => bcsub(bcadd($openingResidual, $statementOutstandingNet, 2), $bookOutstandingNet, 2)],
            'message' => 'Provisional working reconciliation. Matching creates no journal. Opening/coverage gaps, unmatched bank items and independent finalization remain separate checks.'];
    }

    private function add(string $total, string $direction, string $amount): string
    {
        return $direction === 'in' ? bcadd($total, $amount, 2) : bcsub($total, $amount, 2);
    }
}

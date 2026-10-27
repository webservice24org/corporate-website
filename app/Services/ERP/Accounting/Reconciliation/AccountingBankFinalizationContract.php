<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reconciliation;

/** Readiness is an observation, never an approval, lock or finalization record. */
final class AccountingBankFinalizationContract
{
    public const VERSION = 'bank-finalization-readiness.v1';

    /** @param array<string, mixed> $facts @return list<array{code: string, label: string, state: string, detail: string}> */
    public function checks(array $facts): array
    {
        $evidence = $facts['evidence_verified'] === true;
        $bridge = $facts['bridge'];

        return [
            $this->check('evidence', 'Complete source and matching evidence', $evidence, 'All bank matching chains, statement evidence and recognized book sources must verify.'),
            $this->check('date', 'Statement cutoff', $facts['date_valid'] === true, 'The statement end must not be in the future in the company timezone.'),
            $this->check('currency', 'Currency agreement', $facts['currency_agrees'] === true, 'Company, bank binding and statement must use the supported company currency.'),
            $this->check('statement_coverage', 'Statement chronology and continuity', $facts['statement_coverage_complete'] === true, 'The imported chain must start with a verified zero opening, remain contiguous without overlaps, and carry each closing balance into the next opening. Nonzero cutover openings need their dedicated verified contract.'),
            $this->check('workspaces', 'Earlier statement workspaces', $facts['workspaces_complete'] === true, 'Every statement through this cutoff must have a verified reconciliation workspace.'),
            $this->check('statement_matches', 'All statement movements explained', $facts['unmatched_statement_rows'] === 0, 'Unmatched bank movements require explicit recognized book entries or correct matching; no balancing journal is manufactured.'),
            $this->check('prior_statement_matches', 'Earlier statement movements explained', $facts['prior_unmatched_statement_rows'] === 0, 'Earlier imported statements cannot leave unmatched bank movements hidden behind the current bridge.'),
            $this->check('adjustments', 'No unresolved bank adjustment requests', $facts['pending_adjustments'] === 0, 'Relevant draft, submitted, approved or unknown-state bank adjustments require review, posting or cancellation.'),
            $this->check('source_coverage', 'Bound and recognized book coverage', $facts['book_complete'] === true && $facts['unsupported_cash_lines'] === 0, 'Manual, opening and unbound cash lines remain blocked until their approved source contracts exist.'),
            $this->check('opening', 'Opening residual explained', $evidence && $this->zero($bridge['unverified_opening_prior_statement_residual'] ?? null), 'An unexplained opening residual is not accepted because the final difference happens to be zero.'),
            $this->check('bridge', 'Exact bridge agrees', $evidence && $this->same($bridge['statement_minus_book'] ?? null, $bridge['explained_difference'] ?? null), 'The statement-minus-book difference must equal the explicit opening, unmatched-statement and outstanding-book bridge.'),
        ];
    }

    /** @param list<array{code: string, label: string, state: string, detail: string}> $checks */
    public function ready(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['state'] !== 'pass') {
                return false;
            }
        }

        return $checks !== [];
    }

    /** @return array{code: string, label: string, state: string, detail: string} */
    private function check(string $code, string $label, bool $passes, string $detail): array
    {
        return ['code' => $code, 'label' => $label, 'state' => $passes ? 'pass' : 'blocked', 'detail' => $detail];
    }

    private function zero(mixed $value): bool
    {
        return is_string($value) && $this->decimal($value) && bccomp($value, '0.00', 2) === 0;
    }

    private function same(mixed $left, mixed $right): bool
    {
        return is_string($left) && is_string($right) && $this->decimal($left) && $this->decimal($right) && bccomp($left, $right, 2) === 0;
    }

    private function decimal(string $value): bool
    {
        return preg_match('/\A-?(?:0|[1-9][0-9]{0,17})\.[0-9]{2}\z/', $value) === 1;
    }
}

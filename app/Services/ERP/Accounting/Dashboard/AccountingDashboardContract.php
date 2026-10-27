<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Dashboard;

/** Exact projections of existing report DTOs. No new financial recognition. */
final class AccountingDashboardContract
{
    /** @param array<string, mixed> $report @return list<array<string, mixed>> */
    public function profit(array $report, string $url): array
    {
        $expense = '0.00';
        foreach ($report['sections'] as $section) {
            if (in_array($section['role'], ['cogs', 'operating_expense', 'other_expense'], true)) {
                $expense = bcadd($expense, $section['amount'], 2);
            }
        }
        $status = $report['classification_complete'] ? 'posted_gl' : 'review';

        return [
            $this->card('revenue', 'Net operating revenue', $report['net_revenue'], 'period', $status, $url, 'Operating revenue less sales allowances; other income is separate in Profit and Loss.'),
            $this->card('expenses', 'Cost and expenses', $expense, 'period', $status, $url, 'COGS plus operating and other expenses. Exact posted GL; current classification.'),
            $this->card('profit', 'Period result', $report['provisional_result'], 'period', $status, $url, 'Includes other income. Journal evidence checked; native completeness requires separate review.'),
        ];
    }

    /** @param array<string, mixed> $report @return list<array<string, mixed>> */
    public function aging(array $report, string $kind, string $url): array
    {
        $total = $report['totals'];
        $overdue = '0.00';
        foreach (['days_1_30', 'days_31_60', 'days_61_90', 'days_91_120', 'over_120'] as $bucket) {
            $overdue = bcadd($overdue, $total['buckets'][$bucket], 2);
        }
        $status = $report['complete'] ? 'supported_scope' : 'review';
        $name = strtoupper($kind);

        return [$this->card($kind, 'Open trade '.$name, $total['open_documents'], 'cutoff', $status, $url, 'Positive open documents; credits, advances and unapplied funds are separate. Legacy gaps remain provisional.'),
            $this->card($kind.'_overdue', 'Overdue trade '.$name, $overdue, 'cutoff', $status, $url, 'Dated aging buckets over zero days; unknown due dates excluded.'),
            $this->card($kind.'_unknown_due', $name.' with unknown due date', $total['buckets']['unknown_due'], 'cutoff', $status, $url, 'Review unknown due dates and historical terms in the aging report.')];
    }

    /** @return array<string, mixed> */
    public function card(string $id, string $label, ?string $value, string $basis, string $status, string $url, string $note, string $unit = 'money'): array
    {
        return ['id' => $id, 'label' => $label, 'value' => $value, 'basis' => $basis, 'status' => $status, 'url' => $url, 'note' => $note, 'unit' => $unit];
    }
}

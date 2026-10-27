<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Reconciliation;

use App\Models\Accounting\AccountingBankFinalizationEvent;
use App\Models\Accounting\AccountingBankStatement;
use App\Models\Accounting\AccountingBankStatementLine;
use App\Models\Accounting\AccountingCashAccount;
use App\Models\Accounting\AccountingSourceEffect;
use App\Models\Accounting\JournalEntryLine;
use App\Models\Company;
use App\Models\User;
use App\Services\ERP\Accounting\Banking\AccountingBankingWorkspace;
use App\Services\ERP\Purchase\PurchaseCompanyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountingBankReconciliationExportService
{
    public const PERMISSION = 'erp.bank-reconciliations.export';

    public function __construct(private readonly AccountingBankReconciliationService $matching, private readonly AccountingBankReconciliationLedger $ledger, private readonly AccountingBankReconciliationReport $reports, private readonly AccountingBankFinalizationEvidence $evidence, private readonly AccountingBankFinalizationWorkflow $workflow, private readonly AccountingBankFinalizationReadiness $readiness, private readonly AccountingBankApprovedSnapshotReader $approved, private readonly PurchaseCompanyAccess $access) {}

    /** @return array<string, string> */
    public function links(User $actor, int $id, bool $verified): array
    {
        if (! $verified || ! $actor->can(self::PERMISSION)) {
            return [];
        }
        $root = $this->matching->find($actor, $id);
        $company = (int) $root->getAttribute('company_id');
        $this->access->authorize($actor, $company, self::PERMISSION);
        $base = '/erp/accounting/bank-reconciliations/'.$id;
        $links = ['current_csv' => $base.'/csv?basis=current', 'current_print' => $base.'/print?basis=current'];
        if (AccountingBankFinalizationEvent::query()->where('company_id', $company)->where('reconciliation_id', $id)->where('event_kind', 'approve')->exists()) {
            $links['approved_csv'] = $base.'/csv?basis=approved';
            $links['approved_print'] = $base.'/print?basis=approved';
        }

        return $links;
    }

    /** Full-result safe DTO. Never accepts pagination/filter limits or a caller-supplied approval/snapshot.
     * @return array<string, mixed>
     */
    public function report(User $actor, int $id, string $basis = 'current', ?int $requestedCompany = null): array
    {
        abort_unless($actor->can(self::PERMISSION), 403);
        $found = $this->matching->find($actor, $id);
        $company = (int) $found->getAttribute('company_id');
        abort_if($requestedCompany !== null && $requestedCompany !== $company, 403);
        if (! in_array($basis, ['current', 'approved'], true)) {
            $this->fail('Choose current or approved export basis.');
        }

        return DB::transaction(function () use ($actor, $id, $basis, $company): array {
            $owner = Company::query()->whereKey($company)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $company, self::PERMISSION);
            $root = $this->matching->find($actor, $id);
            $bankId = (int) $root->getAttribute('cash_account_id');
            $summary = $this->workflow->review($actor, $id);
            if ($summary['state'] === 'review') {
                $this->fail('Bank or finalization evidence requires review. Export is blocked before any response is streamed.');
            }
            $states = $this->evidence->bank($company, $bankId);
            $state = $states[$id];
            $live = $this->ledger->read($actor, $company, $bankId);
            $approval = null;
            $submission = null;
            foreach ($state['events'] as $event) {
                if ($event->getAttribute('event_kind') === 'approve') {
                    $approval = $event;
                }
            }
            $coverage = null;
            if ($basis === 'approved') {
                if ($approval === null) {
                    $this->fail('This reconciliation has no recorded independent approval to export.');
                }
                $subject = $approval->getAttribute('payload')['subject'];
                foreach ($state['events'] as $event) {
                    if ((int) $event->getKey() === $subject['submission_id']) {
                        $submission = $event;
                    }
                }
                if ($submission === null) {
                    $this->fail('The selected approval has no verified submission.');
                }
                $snapshot = $submission->getAttribute('payload')['subject']['snapshot'];
                $report = $this->approved->project($id, $snapshot, $live);
                $checks = app(AccountingBankFinalizationContract::class)->checks($snapshot['facts']);
                $exceptions = [];
                $technicalReady = true;
            } else {
                $report = $this->reports->project($id, $live);
                $coverage = $this->readiness->capture($actor, $id);
                if ($coverage['review']['evidence_state'] !== 'verified') {
                    $this->fail('Complete source evidence must verify before export.');
                }
                $checks = $coverage['review']['checks'];
                $exceptions = $coverage['exceptions'];
                $technicalReady = $coverage['review']['technical_ready'];
            }
            $activeApproval = $approval !== null && $state['state'] === 'finalized' && $state['approval']['id'] === (int) $approval->getKey();
            $label = $basis === 'approved' ? ($activeApproval ? 'FINALIZED — approved cutoff snapshot' : 'HISTORICAL APPROVED SNAPSHOT — currently '.$state['state']) : ($activeApproval ? 'FINALIZED — current through-cutoff projection' : 'WORKING / NOT FINALIZED — '.$state['state']);
            $report['status'] = $basis === 'approved' ? 'approved_snapshot' : $state['state'];
            $report['finalized'] = $activeApproval;
            $statement = AccountingBankStatement::query()->where('company_id', $company)->where('cash_account_id', $bankId)->findOrFail((int) $root->getAttribute('statement_id'));
            $bank = AccountingCashAccount::query()->where('company_id', $company)->where('kind', 'bank')->findOrFail($bankId);
            foreach ($report['statement_rows'] as &$row) {
                $line = AccountingBankStatementLine::query()->where('company_id', $company)->where('cash_account_id', $bankId)->where('statement_id', $statement->getKey())->findOrFail($row['id']);
                $row['reference'] = (string) $line->getAttribute('reference');
                $row['description'] = (string) $line->getAttribute('description');
            }unset($row);
            foreach ($report['book_rows'] as &$row) {
                $line = JournalEntryLine::query()->where('company_id', $company)->where('cash_account_id', $bankId)->findOrFail($row['id']);
                $journal = (int) $line->getAttribute('journal_entry_id');
                $effect = AccountingSourceEffect::query()->where('company_id', $company)->where('journal_entry_id', $journal)->firstOrFail();
                $kind = (string) $effect->getAttribute('source_kind');
                $sourceId = (int) $effect->getAttribute('source_id');
                $row['source'] = str_replace('_', ' ', $kind).' #'.$sourceId;
                $row['source_url'] = $this->sourceUrl($actor, $kind, $sourceId);
                $row['journal_url'] = $actor->can('erp.journal-entries.view') ? '/erp/accounting/journals/'.$journal : null;
            }unset($row);
            $timeline = [];
            $laterEvents = [];
            foreach ($state['events'] as $event) {
                $safe = ['id' => (int) $event->getKey(), 'kind' => (string) $event->getAttribute('event_kind'), 'actor_id' => (int) $event->getAttribute('actor_id'), 'reason' => (string) $event->getAttribute('reason'), 'occurred_at' => $event->getAttribute('occurred_at')->format(DATE_ATOM)];
                if ($basis === 'approved' && (int) $event->getKey() > (int) $approval->getKey()) {
                    $laterEvents[] = $safe;
                } else {
                    $timeline[] = $safe;
                }
            }

            return [
                'id' => $id,
                'company' => (string) $owner->getAttribute('code').' — '.(string) $owner->getAttribute('name'),
                'bank' => (string) $bank->getAttribute('name'),
                'statement_reference' => (string) $statement->getAttribute('statement_reference'),
                'currency' => $report['currency_code'],
                'cutoff' => $statement->getAttribute('ends_on')->format('Y-m-d'),
                'basis' => $basis,
                'status_label' => $label,
                'current_state' => $state['state'],
                'finalized' => $activeApproval,
                'matching_revision' => $report['revision'],
                'current_matching_revision' => (int) $root->getAttribute('revision'),
                'finalization_revision' => $state['sequence'],
                'technical_ready' => $technicalReady,
                'generated_at' => CarbonImmutable::now('UTC')->format(DATE_ATOM),
                'approval' => $approval === null ? null : ['id' => (int) $approval->getKey(), 'actor_id' => (int) $approval->getAttribute('actor_id'), 'occurred_at' => $approval->getAttribute('occurred_at')->format(DATE_ATOM)],
                'report' => $report,
                'checks' => $checks,
                'exceptions' => $exceptions,
                'timeline' => $timeline,
                'later_events' => $laterEvents,
            ];
        }, 3);
    }

    private function sourceUrl(User $actor, string $kind, int $id): ?string
    {
        return match ($kind) {
            'bank_adjustment' => AccountingBankingWorkspace::mayRead($actor, 'adjustments') ? '/erp/accounting/bank-adjustments/'.$id : null,
            'cash_transfer' => $actor->can('erp.cash-transfers.view') ? '/erp/accounting/cash-transfers/'.$id : null,
            'cash_transfer_reversal' => $actor->can('erp.cash-transfer-reversals.view') ? '/erp/accounting/cash-transfer-reversals/'.$id : null,
            'supplier_payment' => $actor->can('erp.supplier-payments.view') ? '/erp/accounting/supplier-payments/'.$id : null,
            'customer_receipt' => $actor->can('erp.customer-receipts.view') ? '/erp/sales/receipts/'.$id : null,
            default => null,
        };
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['export' => $message]);
    }
}

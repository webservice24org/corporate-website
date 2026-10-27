<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\JournalEntryLine;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Current manual/reversal scope only; exact posted-line projection, never a mutable balance. */
final class AccountingLedgerPrototype
{
    public function __construct(private readonly AccountingCompanyAccess $access) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function read(User $actor, int $companyId, array $input): array
    {
        $this->access->authorize($actor, $companyId, 'erp.accounting-reports.view');
        $data = Validator::make($input, ['account_id' => ['required', 'integer', 'min:1'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', ...(! empty($input['from']) ? ['after_or_equal:from'] : [])], 'page' => ['nullable', 'integer', 'min:1', 'max:1000000']])->validate();
        $account = Account::query()->where('company_id', $companyId)->findOrFail((int) $data['account_id']);
        $page = (int) ($data['page'] ?? 1);
        $offset = ($page - 1) * 100;
        $query = JournalEntryLine::query()->join('journal_entries as j', 'j.id', '=', 'journal_entry_lines.journal_entry_id')->where('journal_entry_lines.company_id', $companyId)->where('j.company_id', $companyId)->where('journal_entry_lines.account_id', $account->getKey())->where('j.status', 'posted')->whereNotNull('j.posted_at')->whereIn('j.entry_kind', ['manual', 'reversal']);
        if (! empty($data['to'])) {
            $query->whereDate('j.accounting_date', '<=', $data['to']);
        }
        $opening = '0.00';
        $debit = '0.00';
        $credit = '0.00';
        $balance = '0.00';
        $carry = '0.00';
        $count = 0;
        $entries = [];
        // One ordered statement supplies carry, page, totals and closing consistently. Only the current page is retained in the projection.
        foreach ($query->orderBy('j.accounting_date')->orderBy('j.id')->orderBy('journal_entry_lines.line_number')->orderBy('journal_entry_lines.id')->select(['journal_entry_lines.*', 'j.number as journal_number', 'j.accounting_date as journal_date', 'j.entry_kind as journal_kind', 'j.description as journal_description'])->cursor() as $line) {
            $date = (string) $line->getAttribute('journal_date');
            $d = (string) $line->getAttribute('debit');
            $c = (string) $line->getAttribute('credit');
            $net = bcsub($d, $c, 2);
            $balance = bcadd($balance, $net, 2);
            if (! empty($data['from']) && $date < $data['from']) {
                $opening = bcadd($opening, $net, 2);
                $carry = $opening;

                continue;
            }
            $debit = bcadd($debit, $d, 2);
            $credit = bcadd($credit, $c, 2);
            if ($count < $offset) {
                $carry = $balance;
            }
            if ($count >= $offset && count($entries) < 100) {
                $entries[] = ['id' => (int) $line->getKey(), 'journal_id' => (int) $line->getAttribute('journal_entry_id'), 'number' => (string) $line->getAttribute('journal_number'), 'accounting_date' => $date, 'entry_kind' => (string) $line->getAttribute('journal_kind'), 'description' => (string) $line->getAttribute('journal_description'), 'line_description' => $line->getAttribute('description'), 'debit' => $d, 'credit' => $c, 'balance' => $balance];
            }
            $count++;
        }
        $lastPage = max(1, (int) ceil($count / 100));
        if ($page > $lastPage) {
            throw ValidationException::withMessages(['page' => 'This ledger page no longer exists. Reload the selection.']);
        }

        return ['account' => ['id' => (int) $account->getKey(), ...$account->only(['code', 'name', 'normal_side', 'status'])], 'entries' => $entries, 'opening' => $opening, 'pageCarry' => $carry, 'total_debit' => $debit, 'total_credit' => $credit, 'closing' => $balance, 'total' => $count, 'page' => $page, 'lastPage' => $lastPage, 'canViewJournals' => $actor->can('erp.journal-entries.view')];
    }
}

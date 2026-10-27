<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingJournalDraftService;
use App\Services\ERP\Accounting\AccountingJournalPostingService;
use App\Services\ERP\Accounting\AccountingJournalReversalService;
use App\Services\ERP\Accounting\AccountingJournalWorkflowService;
use App\Services\ERP\Accounting\AccountingJournalWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AccountingJournalController extends Controller
{
    public function __construct(private readonly AccountingJournalWorkspace $workspace, private readonly AccountingJournalDraftService $drafts, private readonly AccountingJournalWorkflowService $workflow, private readonly AccountingJournalPostingService $posting, private readonly AccountingJournalReversalService $reversals) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'q' => ['nullable', 'string', 'max:60'], 'status' => ['nullable', 'in:draft,submitted,approved,posted,cancelled'], 'kind' => ['nullable', 'in:manual,reversal'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])], 'page' => ['nullable', 'integer', 'min:1']]);
        $context = $this->workspace->context($this->actor($request), isset($data['company_id']) ? (int) $data['company_id'] : null);
        $query = JournalEntry::query()->whereIn('entry_kind', ['manual', 'reversal']);
        if ($context['company'] === null) {
            $query->whereRaw('1 = 0');
        } else {
            $query->where('company_id', $context['company']['id']);
        }
        foreach (['status' => 'status', 'kind' => 'entry_kind'] as $field => $column) {
            if (! empty($data[$field])) {
                $query->where($column, $data[$field]);
            }
        }
        if (isset($data['q']) && $data['q'] !== '') {
            $query->where('number', 'like', '%'.$data['q'].'%');
        }
        if (! empty($data['from'])) {
            $query->whereDate('accounting_date', '>=', $data['from']);
        }
        if (! empty($data['to'])) {
            $query->whereDate('accounting_date', '<=', $data['to']);
        }
        $journals = $query->orderByDesc('accounting_date')->orderByDesc('id')->paginate(25, ['id', 'company_id', 'number', 'entry_kind', 'status', 'accounting_date', 'currency_code', 'description', 'revision'])->withQueryString();
        $totals = [];
        foreach (JournalEntryLine::query()->where('company_id', $context['company']['id'] ?? 0)->whereIn('journal_entry_id', $journals->getCollection()->pluck('id'))->get() as $line) {
            $id = (int) $line->getAttribute('journal_entry_id');
            $sum = $totals[$id] ?? ['total_debit' => '0.00', 'total_credit' => '0.00'];
            $totals[$id] = ['total_debit' => bcadd($sum['total_debit'], (string) $line->getAttribute('debit'), 2), 'total_credit' => bcadd($sum['total_credit'], (string) $line->getAttribute('credit'), 2)];
        }
        $journals->through(static fn (JournalEntry $row): array => ['id' => (int) $row->getKey(), ...($totals[(int) $row->getKey()] ?? ['total_debit' => '0.00', 'total_credit' => '0.00']), ...$row->only(['number', 'entry_kind', 'status', 'currency_code', 'description', 'revision']), 'accounting_date' => $row->getAttribute('accounting_date')->format('Y-m-d')]);

        return Inertia::render('ERP/Accounting/Journals/Index', [...$context, 'journals' => $journals, 'filters' => ['q' => $data['q'] ?? '', 'status' => $data['status'] ?? '', 'kind' => $data['kind'] ?? '', 'from' => $data['from'] ?? '', 'to' => $data['to'] ?? '']]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1']]);
        $context = $this->workspace->context($this->actor($request), isset($data['company_id']) ? (int) $data['company_id'] : null);
        if ($context['company'] === null || $context['issues'] !== []) {
            return redirect()->route('erp.accounting.journals.index', ['company_id' => $context['company']['id'] ?? null])->with('error', 'Select an initialized active company before creating a journal.');
        }

        return Inertia::render('ERP/Accounting/Journals/Form', [...$context, ...$this->workspace->options((int) $context['company']['id']), 'journal' => null, 'lines' => []]);
    }

    public function edit(Request $request, int $journal): Response
    {
        $row = $this->workspace->find($this->actor($request), $journal);
        $detail = $this->workspace->detail($this->actor($request), $row);
        abort_unless($detail['capabilities']['edit'], 403);

        return Inertia::render('ERP/Accounting/Journals/Form', $detail);
    }

    public function show(Request $request, int $journal): Response
    {
        return Inertia::render('ERP/Accounting/Journals/Show', $this->workspace->detail($this->actor($request), $this->workspace->find($this->actor($request), $journal)));
    }

    public function print(Request $request, int $journal): Response
    {
        $detail = $this->workspace->detail($this->actor($request), $this->workspace->find($this->actor($request), $journal));

        return Inertia::render('ERP/Accounting/Journals/Print', array_intersect_key($detail, array_flip(['company', 'journal', 'lines', 'accounts', 'branches', 'departments', 'total_debit', 'total_credit'])));
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->redirect($this->drafts->save($this->actor($request), $request->except(['_token', '_method'])), 'Journal draft saved.');
    }

    public function update(Request $request, int $journal): RedirectResponse
    {
        $row = $this->workspace->find($this->actor($request), $journal);
        $input = $request->except(['_token', '_method']);
        abort_unless((int) ($input['company_id'] ?? 0) === (int) $row->getAttribute('company_id'), 403);

        return $this->redirect($this->drafts->save($this->actor($request), $input, $journal), 'Journal saved as draft. Any prior approval was cleared.');
    }

    public function submit(Request $request, int $journal): RedirectResponse
    {
        return $this->transition($request, $journal, 'submit');
    }

    public function approve(Request $request, int $journal): RedirectResponse
    {
        return $this->transition($request, $journal, 'approve');
    }

    public function reject(Request $request, int $journal): RedirectResponse
    {
        return $this->transition($request, $journal, 'reject');
    }

    public function cancel(Request $request, int $journal): RedirectResponse
    {
        return $this->transition($request, $journal, 'cancel');
    }

    public function post(Request $request, int $journal): RedirectResponse
    {
        $row = $this->workspace->find($this->actor($request), $journal);

        return $this->redirect($this->posting->post($this->actor($request), (int) $row->getAttribute('company_id'), $journal, $request->except(['_token', '_method'])), 'Journal posted.');
    }

    public function reverse(Request $request, int $journal): RedirectResponse
    {
        $row = $this->workspace->find($this->actor($request), $journal);

        return $this->redirect($this->reversals->prepare($this->actor($request), (int) $row->getAttribute('company_id'), $journal, $request->except(['_token', '_method'])), 'Linked reversal prepared as a draft. Submit it for independent approval.');
    }

    private function transition(Request $request, int $journal, string $action): RedirectResponse
    {
        $row = $this->workspace->find($this->actor($request), $journal);
        $result = $this->workflow->{$action}($this->actor($request), (int) $row->getAttribute('company_id'), $journal, $request->except(['_token', '_method']));

        return $this->redirect($result, 'Journal workflow updated.');
    }

    private function redirect(JournalEntry $row, string $message): RedirectResponse
    {
        return redirect()->route('erp.accounting.journals.show', $row->getKey())->with('success', $message);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}

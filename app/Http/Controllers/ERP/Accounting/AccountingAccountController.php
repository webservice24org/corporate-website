<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingAccountCatalog;
use App\Services\ERP\Accounting\AccountingAccountService;
use App\Services\ERP\Accounting\AccountingAccountWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

final class AccountingAccountController extends Controller
{
    public function __construct(private readonly AccountingAccountWorkspace $workspace, private readonly AccountingAccountService $service, private readonly AccountingAccountCatalog $catalog) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:120'], 'category' => ['nullable', 'in:all,'.implode(',', array_keys($this->catalog->groups()))], 'status' => ['nullable', 'in:all,active,inactive'], 'posting' => ['nullable', 'in:all,postable,group'], 'mode' => ['nullable', 'in:tree,register'], 'page' => ['nullable', 'integer', 'min:1']]);
        $context = $this->workspace->context($this->actor($request), isset($data['company_id']) ? (int) $data['company_id'] : null);
        $filters = ['company_id' => $context['company']['id'] ?? null, 'search' => trim((string) ($data['search'] ?? '')), 'category' => $data['category'] ?? 'all', 'status' => $data['status'] ?? 'all', 'posting' => $data['posting'] ?? 'all', 'mode' => $data['mode'] ?? 'tree'];
        $rows = $context['accounts'];
        $groups = array_column($context['groups'], null, 'id');
        $byId = array_column($rows, null, 'id');
        $matching = array_values(array_filter($rows, static function (array $row) use ($filters, $groups): bool {
            $needle = mb_strtolower($filters['search']);

            return ($needle === '' || str_contains(mb_strtolower($row['code'].' '.$row['name']), $needle)) && ($filters['category'] === 'all' || ($groups[$row['account_group_id']]['category'] ?? null) === $filters['category']) && ($filters['status'] === 'all' || $row['status'] === $filters['status']) && ($filters['posting'] === 'all' || ($filters['posting'] === 'postable') === $row['is_postable']);
        }));
        $pagination = null;
        if ($filters['mode'] === 'register') {
            $page = isset($data['page']) ? (int) $data['page'] : 1;
            $paginator = new LengthAwarePaginator(array_slice($matching, ($page - 1) * 50, 50), count($matching), 50, $page, ['path' => $request->url(), 'query' => $filters]);
            $display = $paginator->items();
            $pagination = $paginator->toArray();
            unset($pagination['data']);
            foreach ($display as &$row) {
                $row['is_context'] = false;
            } unset($row);
        } else {
            $matches = array_fill_keys(array_column($matching, 'id'), true);
            $keep = $matches;
            foreach ($matching as $row) {
                $parent = $row['parent_id'];
                while ($parent !== null && isset($byId[$parent])) {
                    $keep[$parent] = true;
                    $parent = $byId[$parent]['parent_id'];
                }
            }
            $display = [];
            foreach ($rows as $row) {
                if (isset($keep[$row['id']])) {
                    $row['is_context'] = ! isset($matches[$row['id']]);
                    $display[] = $row;
                }
            }
        }
        $counts = ['total' => count($rows), 'active' => count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'active')), 'postable' => count(array_filter($rows, static fn (array $row): bool => $row['is_postable'])), 'matching' => count($matching)];

        return Inertia::render('ERP/Accounting/Accounts/Index', [...$context, 'accounts' => $display, 'filters' => $filters, 'counts' => $counts, 'pagination' => $pagination]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1']]);
        $context = $this->workspace->context($this->actor($request), isset($data['company_id']) ? (int) $data['company_id'] : null);
        if (! $context['canCreate']) {
            return redirect()->route('erp.accounting.accounts.index', ['company_id' => $context['company']['id'] ?? null])->with('error', $context['issues'][0] ?? 'Select a company and initialize its standard account groups first.');
        }

        return $this->form($context, null);
    }

    public function edit(Request $request, int $account): Response
    {
        $found = $this->workspace->find($this->actor($request), $account);
        $context = $this->workspace->context($this->actor($request), (int) $found->getAttribute('company_id'));

        return $this->form($context, $account);
    }

    public function show(Request $request, int $account): Response
    {
        $actor = $this->actor($request);
        $found = $this->workspace->find($actor, $account);
        $context = $this->workspace->context($actor, (int) $found->getAttribute('company_id'));
        $nodes = array_column($context['accounts'], null, 'id');
        $node = $nodes[$account] ?? abort(404);
        $children = array_values(array_filter($context['accounts'], static fn (array $row): bool => $row['parent_id'] === $account));
        $parent = $node['parent_id'] === null ? null : ($nodes[$node['parent_id']] ?? null);
        unset($context['accounts']);

        return Inertia::render('ERP/Accounting/Accounts/Show', [...$context, 'account' => $node, 'parent' => $parent, 'children' => $children, 'audit' => $this->workspace->audit($actor, $found), 'canViewAudit' => $actor->can('erp.accounting-audit.view')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->service->save($this->actor($request), $request->all());

        return redirect()->route('erp.accounting.accounts.show', $account->getKey())->with('success', 'Account created.');
    }

    public function update(Request $request, int $account): RedirectResponse
    {
        $this->workspace->find($this->actor($request), $account);
        $saved = $this->service->save($this->actor($request), $request->all(), $account);

        return redirect()->route('erp.accounting.accounts.show', $saved->getKey())->with('success', 'Account updated.');
    }

    public function status(Request $request, int $account): RedirectResponse
    {
        $found = $this->workspace->find($this->actor($request), $account);
        $data = $request->validate(['status' => ['required', 'in:active,inactive'], 'revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u'], 'company_id' => ['prohibited']]);
        $this->service->setStatus($this->actor($request), (int) $found->getAttribute('company_id'), $account, (string) $data['status'], (int) $data['revision'], (string) $data['reason']);

        return redirect()->route('erp.accounting.accounts.show', $account)->with('success', 'Account status updated.');
    }

    public function initializeGroups(Request $request): RedirectResponse
    {
        $data = $request->validate(['company_id' => ['required', 'integer', 'min:1']]);
        $this->service->initializeGroups($this->actor($request), (int) $data['company_id']);

        return redirect()->route('erp.accounting.accounts.index', ['company_id' => (int) $data['company_id']])->with('success', 'Standard account groups initialized.');
    }

    /** @param array<string, mixed> $context */
    private function form(array $context, ?int $id): Response
    {
        $nodes = array_column($context['accounts'], null, 'id');
        $account = $id === null ? null : ($nodes[$id] ?? abort(404));
        $excluded = [];
        if ($id !== null) {
            foreach ($context['accounts'] as $row) {
                $cursor = $row['id'];
                while (isset($nodes[$cursor])) {
                    if ($cursor === $id) {
                        $excluded[$row['id']] = true;
                        break;
                    } $parent = $nodes[$cursor]['parent_id'];
                    if ($parent === null) {
                        break;
                    } $cursor = $parent;
                }
            }
        }
        $parents = array_values(array_filter($context['accounts'], static fn (array $row): bool => ! isset($excluded[$row['id']]) && ! $row['is_postable'] && (($row['status'] === 'active' && $row['ancestors_active']) || ($account !== null && $row['id'] === $account['parent_id']))));
        unset($context['accounts']);

        return Inertia::render('ERP/Accounting/Accounts/Form', [...$context, 'account' => $account, 'parents' => $parents, 'canSave' => $account === null ? $context['canCreate'] : $account['can_edit']]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseWorkflow;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Response;

final class AccountingExpenseWorkspaceController extends Controller
{
    public function __construct(private readonly AccountingExpenseWorkspace $workspace) {}

    public function index(Request $r): Response
    {
        return $this->workspace->index($this->actor($r), $r->query(), 'expenses');
    }

    public function categories(Request $r): Response
    {
        return $this->workspace->index($this->actor($r), $r->query(), 'categories');
    }

    public function finance(Request $r): Response
    {
        return $this->workspace->index($this->actor($r), $r->query(), 'finance');
    }

    public function create(Request $r): Response
    {
        return $this->workspace->form($this->actor($r), $r->query(), null);
    }

    public function edit(Request $r, int $expense): Response
    {
        return $this->workspace->form($this->actor($r), $r->query(), $expense);
    }

    public function show(Request $r, int $expense): Response
    {
        return $this->workspace->show($this->actor($r), $expense);
    }

    public function createCategory(Request $r): Response
    {
        return $this->workspace->form($this->actor($r), $r->query(), null, true);
    }

    public function editCategory(Request $r, int $category): Response
    {
        return $this->workspace->form($this->actor($r), $r->query(), $category, true);
    }

    public function createFinance(Request $r): Response
    {
        return $this->workspace->financeForm($this->actor($r), $r->query());
    }

    public function showFinance(Request $r, int $plan): Response
    {
        return $this->workspace->financeShow($this->actor($r), $plan);
    }

    public function options(Request $r): JsonResponse
    {
        $data = Validator::make($r->query(), ['company_id' => ['required', 'integer', 'min:1'], 'mode' => ['required', 'in:native,category,finance'], 'q' => ['nullable', 'string', 'max:100'], 'expense_id' => ['nullable', 'integer', 'min:1'], 'category_id' => ['nullable', 'integer', 'min:1']])->validate();
        $selected = null;
        if (isset($data['expense_id'])) {
            $selected = app(AccountingExpenseWorkflow::class)->find($this->actor($r), (int) $data['expense_id']);
            if ((int) $selected->getAttribute('company_id') !== (int) $data['company_id']) {
                abort(403);
            }
        }
        if (isset($data['category_id'])) {
            if ($data['mode'] !== 'category' || $selected !== null) {
                abort(422);
            } $selected = app(AccountingExpenseWorkflow::class)->category($this->actor($r), (int) $data['category_id']);
            if ((int) $selected->getAttribute('company_id') !== (int) $data['company_id']) {
                abort(403);
            }
        }

        return response()->json($this->workspace->options($this->actor($r), (int) $data['company_id'], $data['mode'], (string) ($data['q'] ?? ''), $selected));
    }

    private function actor(Request $r): User
    {
        $user = $r->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}

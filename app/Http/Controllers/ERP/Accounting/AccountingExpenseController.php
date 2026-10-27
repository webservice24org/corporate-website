<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseReadService;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AccountingExpenseController extends Controller
{
    public function __construct(private readonly AccountingExpenseWorkflow $workflow, private readonly AccountingExpenseReadService $read) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->read->listing($this->actor($request), $request->query()));
    }

    public function categories(Request $request): JsonResponse
    {
        return response()->json($this->read->listing($this->actor($request), $request->query(), true));
    }

    public function show(Request $request, int $expense): JsonResponse
    {
        return response()->json($this->read->detail($this->actor($request), $expense));
    }

    public function category(Request $request, int $category): JsonResponse
    {
        return response()->json($this->read->detail($this->actor($request), $category, true));
    }

    public function store(Request $request): JsonResponse
    {
        $row = $this->workflow->save($this->actor($request), $request->all());

        return response()->json($this->read->detail($this->actor($request), (int) $row->getKey()));
    }

    public function update(Request $request, int $expense): JsonResponse
    {
        $row = $this->workflow->save($this->actor($request), $request->all(), $expense);

        return response()->json($this->read->detail($this->actor($request), (int) $row->getKey()));
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $row = $this->workflow->saveCategory($this->actor($request), $request->all());

        return response()->json($this->read->detail($this->actor($request), (int) $row->getKey(), true));
    }

    public function updateCategory(Request $request, int $category): JsonResponse
    {
        $row = $this->workflow->saveCategory($this->actor($request), $request->all(), $category);

        return response()->json($this->read->detail($this->actor($request), (int) $row->getKey(), true));
    }

    public function transition(Request $request, int $expense, string $action): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['revision', 'reason']) !== []) {
            throw ValidationException::withMessages(['expense' => 'Only revision and reason belong to a review command.']);
        }

        $data = Validator::make($request->all(), ['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000']])->validate();

        $row = $this->workflow->change($this->actor($request), $expense, (int) $data['revision'], $action, $data['reason']);

        return response()->json($this->read->detail($this->actor($request), (int) $row->getKey()));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}

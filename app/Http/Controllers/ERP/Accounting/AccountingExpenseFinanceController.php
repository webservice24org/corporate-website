<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseFinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AccountingExpenseFinanceController extends Controller
{
    public function __construct(private readonly AccountingExpenseFinanceService $service) {}

    public function store(Request $request): JsonResponse
    {
        $plan = $this->service->create($this->actor($request), $request->all());

        return response()->json($this->service->detail($this->actor($request), (int) $plan->getKey()));
    }

    public function show(Request $request, int $plan): JsonResponse
    {
        return response()->json($this->service->detail($this->actor($request), $plan));
    }

    public function payable(Request $request, int $expense): JsonResponse
    {
        return response()->json($this->service->payable($this->actor($request), $expense));
    }

    public function transition(Request $request, int $plan, string $action): JsonResponse
    {
        $allowed = $action === 'post' ? ['revision', 'reason', 'key'] : ['revision', 'reason'];

        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw ValidationException::withMessages(['expense_finance' => 'Only revision, reason and the posting request key are accepted.']);
        }

        $data = Validator::make($request->all(), ['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000'], 'key' => [$action === 'post' ? 'required' : 'prohibited', 'string']])->validate();

        $row = $this->service->change($this->actor($request), $plan, (int) $data['revision'], $action, $data['reason'], $data['key'] ?? null);

        return response()->json($this->service->detail($this->actor($request), (int) $row->getKey()));
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingCashBankReadinessService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AccountingCashBankController extends Controller
{
    public function __construct(private readonly AccountingCashBankReadinessService $readiness) {}

    public function index(Request $request): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        if (array_diff(array_keys($request->query()), ['company_id', 'q', 'kind', 'status', 'page', 'per_page']) !== []) {
            throw ValidationException::withMessages(['filters' => 'Unsupported cash/bank workspace filter.']);
        }
        $data = $request->validate([
            'company_id' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', 'in:cash,bank'],
            'status' => ['nullable', 'in:active,inactive'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $companyId = isset($data['company_id']) ? (int) $data['company_id'] : null;
        unset($data['company_id']);
        $context = $this->readiness->page($actor, $companyId, $data);

        return Inertia::render('ERP/Accounting/CashBank/Index', [
            ...$context,
            'filters' => [
                'q' => $data['q'] ?? '', 'kind' => $data['kind'] ?? '',
                'status' => $data['status'] ?? '', 'per_page' => (int) ($data['per_page'] ?? 25),
            ],
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ERP\Accounting\Reconciliation\AccountingBankFinalizationReadiness;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AccountingBankFinalizationReadinessController extends Controller
{
    public function __construct(private readonly AccountingBankFinalizationReadiness $readiness) {}

    public function show(Request $request, int $reconciliation): Response
    {
        if (array_diff(array_keys($request->query()), ['company_id', 'page']) !== []) {
            throw ValidationException::withMessages(['readiness' => 'Unexpected readiness filter.']);
        }
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'page' => ['nullable', 'integer', 'min:1']]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return Inertia::render('ERP/Accounting/BankReconciliations/Readiness', $this->readiness->review($actor, $reconciliation, isset($data['company_id']) ? (int) $data['company_id'] : null, (int) ($data['page'] ?? 1)));
    }
}

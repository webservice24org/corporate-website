<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\SupplierPaymentReversalRequest;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingSupplierPaymentActionWorkspace;
use App\Services\ERP\Accounting\AccountingSupplierPaymentWorkspace;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentChangeService;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentReversalService;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AccountingSupplierPaymentActionController extends Controller
{
    public function __construct(private readonly AccountingSupplierPaymentWorkspace $workspace, private readonly AccountingSupplierPaymentActionWorkspace $actions, private readonly AccountingSupplierPaymentService $payments, private readonly AccountingSupplierPaymentChangeService $changes, private readonly AccountingSupplierPaymentReversalService $reversals) {}

    public function allocations(Request $request, int $payment): Response|RedirectResponse
    {
        $data = $this->actions->allocation($this->actor($request), $payment);
        if (! $data['actionCapabilities']['allocate']) {
            return $this->backToPayment($payment)->with('error', 'This payment cannot accept a new allocation change. Review its state, finance and reversal requests.');
        }

        return Inertia::render('ERP/Accounting/SupplierPayments/Allocations', $data);
    }

    public function change(Request $request, int $payment): RedirectResponse
    {
        $this->fields($request, ['revision', 'history_hash', 'key', 'reason', 'compensate_ids', 'targets']);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'history_hash' => ['required', 'regex:/\A[a-f0-9]{64}\z/'], 'key' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{16,64}\z/'], 'reason' => ['required', 'string', 'max:2000'], 'compensate_ids' => ['present', 'array', 'max:100'], 'compensate_ids.*' => ['integer', 'min:1', 'distinct'], 'targets' => ['present', 'array', 'max:100'], 'targets.*' => ['array:supplier_bill_id,amount'], 'targets.*.supplier_bill_id' => ['required', 'integer', 'min:1', 'distinct'], 'targets.*.amount' => ['required']]);
        $data['revision'] = (int) $data['revision'];
        $data['compensate_ids'] = array_map(static fn ($id): int => (int) $id, $data['compensate_ids']);
        foreach ($data['targets'] as &$target) {
            $target['supplier_bill_id'] = (int) $target['supplier_bill_id'];
        } unset($target);
        $this->changes->change($this->actor($request), $payment, $data);

        return $this->backToPayment($payment)->with('success', 'Allocation change posted. Original cash was not posted again.');
    }

    public function createReversal(Request $request, int $payment): Response|RedirectResponse
    {
        $actor = $this->actor($request);
        $this->payments->find($actor, $payment, 'erp.supplier-payments.reverse');
        $data = [...$this->workspace->detail($actor, $payment), ...$this->actions->detail($actor, $payment)];
        if (! $data['actionCapabilities']['prepareReversal']) {
            return $this->backToPayment($payment)->with('error', 'This payment cannot accept another reversal request.');
        }

        return Inertia::render('ERP/Accounting/SupplierPayments/ReversalForm', [...$data, 'reversalRequest' => null]);
    }

    public function storeReversal(Request $request, int $payment): RedirectResponse
    {
        $this->fields($request, ['document_date', 'reason']);
        $data = $request->validate(['document_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:2000']]);

        return $this->reviewRedirect($this->reversals->prepare($this->actor($request), $payment, $data), 'Reversal draft prepared. Independent approval is required.');
    }

    public function showReversal(Request $request, int $reversal): Response
    {
        return Inertia::render('ERP/Accounting/SupplierPayments/ReversalShow', $this->actions->reversal($this->actor($request), $reversal));
    }

    public function editReversal(Request $request, int $reversal): Response
    {
        $this->reversals->find($this->actor($request), $reversal, 'erp.supplier-payments.reverse');
        $data = $this->actions->reversal($this->actor($request), $reversal);
        abort_unless($data['reversalCapabilities']['edit'], 403);

        return Inertia::render('ERP/Accounting/SupplierPayments/ReversalForm', $data);
    }

    public function updateReversal(Request $request, int $reversal): RedirectResponse
    {
        $this->fields($request, ['document_date', 'reason', 'revision']);
        $data = $request->validate(['document_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:2000'], 'revision' => ['required', 'integer', 'min:1']]);
        $data['revision'] = (int) $data['revision'];
        $actor = $this->actor($request);
        $row = $this->reversals->find($actor, $reversal, 'erp.supplier-payments.reverse');

        return $this->reviewRedirect($this->reversals->prepare($actor, (int) $row->getAttribute('supplier_payment_id'), $data, $reversal), 'Reversal draft updated.');
    }

    public function submitReversal(Request $request, int $reversal): RedirectResponse
    {
        return $this->transition($request, $reversal, 'submit');
    }

    public function approveReversal(Request $request, int $reversal): RedirectResponse
    {
        return $this->transition($request, $reversal, 'approve');
    }

    public function rejectReversal(Request $request, int $reversal): RedirectResponse
    {
        return $this->transition($request, $reversal, 'reject');
    }

    public function cancelReversal(Request $request, int $reversal): RedirectResponse
    {
        return $this->transition($request, $reversal, 'cancel');
    }

    public function postReversal(Request $request, int $reversal): RedirectResponse
    {
        return $this->transition($request, $reversal, 'post');
    }

    private function transition(Request $request, int $id, string $action): RedirectResponse
    {
        $allowed = ['revision'];
        $rules = ['revision' => ['required', 'integer', 'min:1']];
        if (in_array($action, ['reject', 'cancel'], true)) {
            $allowed[] = 'reason';
            $rules['reason'] = ['required', 'string', 'max:2000'];
        }
        if ($action === 'post') {
            $allowed[] = 'key';
            $rules['key'] = ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{16,64}\z/'];
        }
        $this->fields($request, $allowed);
        $data = $request->validate($rules);
        $actor = $this->actor($request);
        $revision = (int) $data['revision'];
        $row = match ($action) {
            'submit' => $this->reversals->submit($actor, $id, $revision), 'approve' => $this->reversals->approve($actor, $id, $revision), 'reject' => $this->reversals->reject($actor, $id, $revision, $data['reason']), 'cancel' => $this->reversals->cancel($actor, $id, $revision, $data['reason']), 'post' => $this->reversals->post($actor, $id, $revision, $data['key']), default => throw new \LogicException('Unknown reversal action.')
        };

        return $this->reviewRedirect($row, 'Reversal workflow updated.');
    }

    /** @param list<string> $allowed */
    private function fields(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->except(['_token', '_method'])), $allowed) !== []) {
            throw ValidationException::withMessages(['operation' => 'The request contains unsupported fields.']);
        }
    }

    private function backToPayment(int $id): RedirectResponse
    {
        return redirect()->route('erp.accounting.supplier-payments.show', $id);
    }

    private function reviewRedirect(SupplierPaymentReversalRequest $row, string $message): RedirectResponse
    {
        return redirect()->route('erp.accounting.supplier-payments.reversal-requests.show', $row->getKey())->with('success', $message);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}

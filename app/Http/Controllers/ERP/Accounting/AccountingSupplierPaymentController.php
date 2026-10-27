<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentReversalRequest;
use App\Models\User;
use App\Services\ERP\Accounting\AccountingSupplierPaymentActionWorkspace;
use App\Services\ERP\Accounting\AccountingSupplierPaymentWorkspace;
use App\Services\ERP\Accounting\Source\Purchase\AccountingSupplierPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AccountingSupplierPaymentController extends Controller
{
    public function __construct(private readonly AccountingSupplierPaymentWorkspace $workspace, private readonly AccountingSupplierPaymentService $payments, private readonly AccountingSupplierPaymentActionWorkspace $actions) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'q' => ['nullable', 'string', 'max:60'], 'status' => ['nullable', 'in:draft,submitted,approved,posted,cancelled'], 'kind' => ['nullable', 'in:payment,reversal'], 'supplier_id' => ['nullable', 'integer', 'min:1'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])]]);
        $actor = $this->actor($request);
        $context = $this->workspace->context($actor, isset($data['company_id']) ? (int) $data['company_id'] : null);
        $companyId = (int) ($context['company']['id'] ?? 0);
        $options = $companyId === 0 ? ['suppliers' => []] : $this->workspace->options($actor, $companyId, isset($data['supplier_id']) ? (int) $data['supplier_id'] : null);
        $query = SupplierPayment::query()->where('company_id', $companyId);
        if (! empty($data['q'])) {
            $query->where('number', 'like', '%'.$data['q'].'%');
        }
        if (($data['kind'] ?? '') === 'payment') {
            $query->whereNull('reverses_payment_id');
        } elseif (($data['kind'] ?? '') === 'reversal') {
            $query->whereNotNull('reverses_payment_id');
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['supplier_id'])) {
            $query->where('supplier_id', $data['supplier_id']);
        }
        if (! empty($data['from'])) {
            $query->whereDate('document_date', '>=', $data['from']);
        }
        if (! empty($data['to'])) {
            $query->whereDate('document_date', '<=', $data['to']);
        }
        $rows = $query->orderByDesc('document_date')->orderByDesc('id')->paginate(25)->withQueryString();
        $rows->through(fn (SupplierPayment $row): array => $this->workspace->summary($row));
        $parents = SupplierPayment::query()->where('company_id', $companyId);
        if (! empty($data['q'])) {
            $parents->where('number', 'like', '%'.$data['q'].'%');
        }
        if (! empty($data['supplier_id'])) {
            $parents->where('supplier_id', $data['supplier_id']);
        }
        $queue = SupplierPaymentReversalRequest::query()->where('company_id', $companyId)->whereIn('supplier_payment_id', $parents->select('id'))->whereIn('status', ['draft', 'submitted', 'approved']);
        if (! empty($data['status'])) {
            $queue->where('status', $data['status']);
        }
        if (! empty($data['from'])) {
            $queue->whereDate('document_date', '>=', $data['from']);
        }
        if (! empty($data['to'])) {
            $queue->whereDate('document_date', '<=', $data['to']);
        }
        $reviews = $queue->orderByDesc('id')->paginate(10, ['id', 'supplier_payment_id', 'status', 'document_date', 'created_by', 'approved_by'], 'review_page')->withQueryString();
        $numbers = SupplierPayment::query()->where('company_id', $companyId)->whereIn('id', $reviews->getCollection()->pluck('supplier_payment_id'))->pluck('number', 'id')->all();
        $reviews->through(static fn (SupplierPaymentReversalRequest $review): array => ['id' => (int) $review->getKey(), 'original_id' => (int) $review->getAttribute('supplier_payment_id'), 'original_number' => (string) ($numbers[(int) $review->getAttribute('supplier_payment_id')] ?? 'Original payment'), 'status' => (string) $review->getAttribute('status'), 'document_date' => $review->getAttribute('document_date')->format('Y-m-d'), 'created_by' => (int) $review->getAttribute('created_by'), 'approved_by' => $review->getAttribute('approved_by') === null ? null : (int) $review->getAttribute('approved_by')]);

        return Inertia::render('ERP/Accounting/SupplierPayments/Index', [...$context, 'suppliers' => $options['suppliers'], 'payments' => $rows, 'reversalQueue' => $reviews, 'filters' => ['kind' => $data['kind'] ?? '', 'q' => $data['q'] ?? '', 'status' => $data['status'] ?? '', 'supplier_id' => $data['supplier_id'] ?? '', 'from' => $data['from'] ?? '', 'to' => $data['to'] ?? '']]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'supplier_id' => ['nullable', 'integer', 'min:1']]);
        $actor = $this->actor($request);
        $context = $this->workspace->context($actor, isset($data['company_id']) ? (int) $data['company_id'] : null);
        if (! $context['canCreate']) {
            return redirect()->route('erp.accounting.supplier-payments.index', ['company_id' => $context['company']['id'] ?? null])->with('error', 'Select an active, unlocked company before creating a payment.');
        }

        return Inertia::render('ERP/Accounting/SupplierPayments/Form', [...$context, ...$this->workspace->options($actor, (int) $context['company']['id'], isset($data['supplier_id']) ? (int) $data['supplier_id'] : null), 'payment' => null, 'targets' => []]);
    }

    public function show(Request $request, int $payment): Response
    {
        return Inertia::render('ERP/Accounting/SupplierPayments/Show', [...$this->workspace->detail($this->actor($request), $payment), ...$this->actions->detail($this->actor($request), $payment)]);
    }

    public function edit(Request $request, int $payment): Response
    {
        return Inertia::render('ERP/Accounting/SupplierPayments/Form', $this->workspace->detail($this->actor($request), $payment, true));
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->saved($this->payments->save($this->actor($request), $request->except(['_token', '_method'])), 'Payment draft saved.');
    }

    public function update(Request $request, int $payment): RedirectResponse
    {
        return $this->saved($this->payments->save($this->actor($request), $request->except(['_token', '_method']), $payment), 'Payment draft updated.');
    }

    public function submit(Request $request, int $payment): RedirectResponse
    {
        return $this->transition($request, $payment, 'submit');
    }

    public function approve(Request $request, int $payment): RedirectResponse
    {
        return $this->transition($request, $payment, 'approve');
    }

    public function reject(Request $request, int $payment): RedirectResponse
    {
        return $this->transition($request, $payment, 'reject');
    }

    public function cancel(Request $request, int $payment): RedirectResponse
    {
        return $this->transition($request, $payment, 'cancel');
    }

    public function post(Request $request, int $payment): RedirectResponse
    {
        return $this->transition($request, $payment, 'post');
    }

    private function transition(Request $request, int $payment, string $action): RedirectResponse
    {
        $rules = ['revision' => ['required', 'integer', 'min:1']];
        if (in_array($action, ['reject', 'cancel'], true)) {
            $rules['reason'] = ['required', 'string', 'max:2000'];
        }
        if ($action === 'post') {
            $rules['key'] = ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{16,64}\z/'];
        }
        $data = $request->validate($rules);
        $actor = $this->actor($request);
        $revision = (int) $data['revision'];
        $row = match ($action) {
            'submit' => $this->payments->submit($actor, $payment, $revision), 'approve' => $this->payments->approve($actor, $payment, $revision), 'reject' => $this->payments->reject($actor, $payment, $revision, $data['reason']), 'cancel' => $this->payments->cancel($actor, $payment, $revision, $data['reason']), 'post' => $this->payments->post($actor, $payment, $revision, $data['key']), default => throw new \LogicException('Unknown supplier payment action.')
        };

        return $this->saved($row, 'Payment workflow updated.');
    }

    private function saved(SupplierPayment $row, string $message): RedirectResponse
    {
        return redirect()->route('erp.accounting.supplier-payments.show', $row->getKey())->with('success', $message);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}

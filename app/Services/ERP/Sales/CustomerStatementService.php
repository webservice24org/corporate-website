<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class CustomerStatementService
{
    public function __construct(private readonly SalesCompanyAccess $access, private readonly SalesInvoiceBalanceService $balances, private readonly SalesReceiptLedger $ledger, private readonly CustomerCreditLedger $customerCredits) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function read(User $actor, array $input): array
    {
        $data = Validator::make($input, ['company_id' => ['required', 'integer', 'min:1'], 'customer_id' => ['required', 'integer', 'min:1'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']])->validate();

        return DB::transaction(function () use ($actor, $data): array {
            $company = $this->access->authorize($actor, (int) $data['company_id'], 'erp.customer-receivables.view');
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $customer = Customer::query()->withoutGlobalScope(SoftDeletingScope::class)->where('company_id', $company->getKey())->findOrFail((int) $data['customer_id']);
            $invoiceQuery = SalesInvoice::query()->where('company_id', $company->getKey())->where('customer_id', $customer->getKey());
            $receiptQuery = CustomerReceipt::query()->where('company_id', $company->getKey())->where('customer_id', $customer->getKey());
            abort_unless((clone $invoiceQuery)->where('status', 'issued')->exists() || (clone $receiptQuery)->where('status', 'posted')->exists(), 404);
            $this->balances->assertStates([(int) $company->getKey()]);
            $creditState = $this->customerCredits->customer((int) $company->getKey(), (int) $customer->getKey());
            $events = [];
            $current = [];
            foreach ((clone $invoiceQuery)->where('status', 'issued')->orderBy('id')->get() as $invoice) {
                $balance = $this->balances->snapshot($invoice);
                $currency = (string) $invoice->getAttribute('currency_code');
                $current[$currency]['available_credit'] = bcadd($current[$currency]['available_credit'] ?? '0.00', (string) $creditState[(int) $invoice->getKey()]['available'], 2);
                $current[$currency]['outstanding'] = bcadd($current[$currency]['outstanding'] ?? '0.00', (string) $balance['outstanding'], 2);
                $events[] = ['id' => (int) $invoice->getKey(), 'type' => 'invoice', 'number' => $invoice->getAttribute('number'), 'date' => $invoice->getAttribute('document_date')->format('Y-m-d'), 'occurred_at' => $invoice->getAttribute('issued_at')->format('Y-m-d H:i:s.u'), 'currency' => $currency, 'debit' => (string) $invoice->getAttribute('grand_total'), 'credit' => '0.00', 'url' => $actor->can('erp.sales-invoices.view') ? route('erp.sales.invoices.show', $invoice->getKey()) : null];
            }
            foreach ((clone $receiptQuery)->orderBy('id')->get() as $receipt) {
                $state = $this->ledger->state($receipt);
                if ($receipt->getAttribute('status') !== 'posted') {
                    continue;
                }
                $currency = (string) $receipt->getAttribute('currency_code');
                $current[$currency]['available_advance'] = bcadd($current[$currency]['available_advance'] ?? '0.00', $state['available'], 2);
                $events[] = ['id' => (int) $receipt->getKey(), 'type' => $state['is_reversal'] ? 'receipt_reversal' : 'receipt', 'number' => $receipt->getAttribute('number'), 'date' => $receipt->getAttribute('document_date')->format('Y-m-d'), 'occurred_at' => $receipt->getAttribute('posted_at')->format('Y-m-d H:i:s.u'), 'currency' => $currency, 'debit' => $state['is_reversal'] ? (string) $receipt->getAttribute('amount') : '0.00', 'credit' => $state['is_reversal'] ? '0.00' : (string) $receipt->getAttribute('amount'), 'url' => $actor->can('erp.customer-receipts.view') ? route('erp.sales.receipts.show', $receipt->getKey()) : null];
            }
            $invoiceIds = (clone $invoiceQuery)->where('status', 'issued')->pluck('id');
            foreach (SalesCreditNote::query()->where('company_id', $company->getKey())->where('customer_id', $customer->getKey())->whereIn('sales_invoice_id', $invoiceIds)->where('status', 'issued')->get() as $note) {
                $events[] = ['id' => (int) $note->getKey(), 'type' => 'credit_note', 'number' => $note->getAttribute('number'), 'date' => $note->getAttribute('document_date')->format('Y-m-d'), 'occurred_at' => $note->getAttribute('issued_at')->format('Y-m-d H:i:s.u'), 'currency' => (string) $note->getAttribute('currency_code'), 'debit' => '0.00', 'credit' => $this->ledger->money((string) $note->getAttribute('grand_total')), 'url' => $actor->can('erp.sales-credit-notes.view') ? route('erp.sales.credit-notes.show', $note->getKey()) : null];
            }
            usort($events, static fn (array $a, array $b): int => [$a['date'], $a['occurred_at'], $a['type'], $a['id']] <=> [$b['date'], $b['occurred_at'], $b['type'], $b['id']]);
            $groups = [];
            foreach ($events as $event) {
                if ($event['date'] > $data['to']) {
                    continue;
                }
                $currency = $event['currency'];
                $groups[$currency] ??= ['currency' => $currency, 'opening' => '0.00', 'closing' => '0.00', 'rows' => []];
                $delta = bcsub($event['debit'], $event['credit'], 2);
                $groups[$currency]['closing'] = bcadd($groups[$currency]['closing'], $delta, 2);
                if ($event['date'] < $data['from']) {
                    $groups[$currency]['opening'] = bcadd($groups[$currency]['opening'], $delta, 2);
                } else {
                    $groups[$currency]['rows'][] = [...$event, 'balance' => $groups[$currency]['closing']];
                }
            }
            ksort($groups);

            return ['company' => $company->only(['id', 'name', 'address', 'phone', 'email']), 'customer' => $customer->only(['id', 'name', 'business_name', 'phone', 'email']), 'from' => $data['from'], 'to' => $data['to'], 'groups' => array_values($groups), 'currentBalances' => $current];
        }, 3);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ERP\Sales;

use App\Enums\ERP\Sales\SalesDocumentType;
use App\Models\CustomerReceipt;
use App\Models\SalesCreditNote;
use App\Models\SalesDelivery;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesQuotation;
use App\Models\SalesReturn;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class SalesDomainRegistry
{
    /** @return class-string<Model> */
    public function model(string $type): string
    {
        return match ($this->type($type)) {
            SalesDocumentType::Quotation => SalesQuotation::class,
            SalesDocumentType::Order => SalesOrder::class,
            SalesDocumentType::Delivery => SalesDelivery::class,
            SalesDocumentType::Invoice => SalesInvoice::class,
            SalesDocumentType::Receipt => CustomerReceipt::class,
            SalesDocumentType::ReturnDocument => SalesReturn::class,
            SalesDocumentType::CreditNote => SalesCreditNote::class,
        };
    }

    public function permission(string $type, string $action): string
    {
        if (! in_array($action, $this->actions($type), true)) {
            throw new InvalidArgumentException('Unsupported Sales document action.');
        }
        $prefix = match ($this->type($type)) {
            SalesDocumentType::Quotation => 'erp.sales-quotations',
            SalesDocumentType::Order => 'erp.sales-orders',
            SalesDocumentType::Delivery => 'erp.sales-deliveries',
            SalesDocumentType::Invoice => 'erp.sales-invoices',
            SalesDocumentType::Receipt => 'erp.customer-receipts',
            SalesDocumentType::ReturnDocument => 'erp.sales-returns',
            SalesDocumentType::CreditNote => 'erp.sales-credit-notes',
        };

        return $prefix.'.'.$action;
    }

    /** @return list<string> */
    public function actions(string $type): array
    {
        return match ($this->type($type)) {
            SalesDocumentType::Quotation => ['view', 'create', 'edit', 'submit', 'approve', 'reject', 'send', 'accept', 'expire', 'cancel'],
            SalesDocumentType::Order => ['view', 'create', 'edit', 'submit', 'approve', 'reject', 'confirm', 'cancel', 'close', 'credit-override'],
            SalesDocumentType::Delivery => ['view', 'create', 'edit', 'post', 'cancel'],
            SalesDocumentType::Invoice => ['view', 'create', 'edit', 'issue', 'cancel'],
            SalesDocumentType::Receipt => ['view', 'create', 'edit', 'post', 'allocate', 'reverse', 'cancel'],
            SalesDocumentType::ReturnDocument => ['view', 'create', 'edit', 'inspect', 'post', 'cancel'],
            SalesDocumentType::CreditNote => ['view', 'create', 'edit', 'issue', 'cancel'],
        };
    }

    /** @return list<string> */
    public function permissions(): array
    {
        $permissions = [];
        foreach (SalesDocumentType::cases() as $type) {
            foreach ($this->actions($type->value) as $action) {
                $permissions[] = $this->permission($type->value, $action);
            }
        }

        return [...$permissions,
            'erp.sales-reservations.view', 'erp.sales-reservations.allocate', 'erp.sales-reservations.release',
            'erp.customer-receivables.view', 'erp.sales-reports.view', 'erp.sales-reports.export',
            'erp.sales-customers.view', 'erp.sales-customers.select',
            'erp.customer-credits.allocate', 'erp.customer-credits.reverse',
        ];
    }

    private function type(string $type): SalesDocumentType
    {
        return SalesDocumentType::tryFrom($type)
            ?? throw new InvalidArgumentException('Unsupported Sales document type.');
    }
}

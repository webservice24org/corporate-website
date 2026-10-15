<?php

declare(strict_types=1);

namespace App\Enums\ERP\Sales;

enum SalesDocumentType: string
{
    case Quotation = 'quotation';
    case Order = 'order';
    case Delivery = 'delivery';
    case Invoice = 'invoice';
    case Receipt = 'receipt';
    case ReturnDocument = 'return';
    case CreditNote = 'credit_note';

    public function prefix(): string
    {
        return match ($this) {
            self::Quotation => 'SQ',
            self::Order => 'SO',
            self::Delivery => 'SD',
            self::Invoice => 'SI',
            self::Receipt => 'CR',
            self::ReturnDocument => 'SR',
            self::CreditNote => 'SCN',
        };
    }
}

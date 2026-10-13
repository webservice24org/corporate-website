<?php

declare(strict_types=1);

namespace App\DTOs\Purchase;

final readonly class SupplierData
{
    public function __construct(
        public string $code,
        public string $name,
        public string $currencyCode,
        public string $status = 'active',
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $taxNumber = null,
        public int $paymentTermsDays = 0,
        public ?string $notes = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            code: trim((string) $data['code']),
            name: trim((string) $data['name']),
            currencyCode: strtoupper(trim((string) $data['currency_code'])),
            status: (string) ($data['status'] ?? 'active'),
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            taxNumber: $data['tax_number'] ?? null,
            paymentTermsDays: (int) ($data['payment_terms_days'] ?? 0),
            notes: $data['notes'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code, 'name' => $this->name,
            'currency_code' => $this->currencyCode, 'status' => $this->status,
            'email' => $this->email, 'phone' => $this->phone,
            'tax_number' => $this->taxNumber,
            'payment_terms_days' => $this->paymentTermsDays,
            'notes' => $this->notes,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\DTOs\Purchase\SupplierData;
use App\Models\Company;
use App\Services\ERP\Purchase\SupplierService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PurchaseSupplierFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $code): Company
    {
        return Company::query()->create([
            'name' => 'Company '.$code,
            'code' => $code,
            'status' => 'active',
            'currency_code' => 'BDT',
        ]);
    }

    private function data(string $code, string $name): SupplierData
    {
        return SupplierData::fromArray([
            'code' => $code, 'name' => $name,
            'currency_code' => 'bdt',
            'payment_terms_days' => 30,
        ]);
    }

    public function test_supplier_creation_and_update_are_company_scoped(): void
    {
        $service = app(SupplierService::class);
        $a = $this->company('C-A');
        $b = $this->company('C-B');
        $supplier = $service->create($a, $this->data('S-001', 'Original'));
        $this->assertSame('BDT', $supplier->currency_code);
        $this->assertSame((int) $a->id, (int) $supplier->company_id);
        $updated = $service->update((int) $a->id, (int) $supplier->id, $this->data('S-001', 'Updated'));
        $this->assertSame('Updated', $updated->name);
        $this->expectException(ModelNotFoundException::class);
        $service->find((int) $b->id, (int) $supplier->id);
    }

    public function test_duplicate_codes_are_rejected_within_company_but_allowed_across_companies(): void
    {
        $service = app(SupplierService::class);
        $a = $this->company('C-A');
        $b = $this->company('C-B');
        $service->create($a, $this->data('S-001', 'One'));
        $service->create($b, $this->data('S-001', 'Other company'));
        $this->expectException(ValidationException::class);
        $service->create($a, $this->data('S-001', 'Duplicate'));
    }

    public function test_update_cannot_cross_company_boundary(): void
    {
        $service = app(SupplierService::class);
        $a = $this->company('C-A');
        $b = $this->company('C-B');
        $supplier = $service->create($a, $this->data('S-001', 'Original'));
        $this->expectException(ModelNotFoundException::class);
        $service->update((int) $b->id, (int) $supplier->id, $this->data('S-002', 'Hijacked'));
    }
}

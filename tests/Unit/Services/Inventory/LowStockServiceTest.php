<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Inventory;

use App\Services\Inventory\LowStockService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LowStockServiceTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function stockStatusCases(): array
    {
        return [
            'zero stock' => [
                '0',
                '5',
                '20',
                'out_of_stock',
            ],
            'negative stock' => [
                '-2',
                '5',
                '20',
                'out_of_stock',
            ],
            'critical stock' => [
                '4',
                '5',
                '20',
                'critical',
            ],
            'minimum boundary' => [
                '5',
                '5',
                '20',
                'critical',
            ],
            'low stock' => [
                '12',
                '5',
                '20',
                'low_stock',
            ],
            'reorder boundary' => [
                '20',
                '5',
                '20',
                'low_stock',
            ],
            'healthy stock' => [
                '35',
                '5',
                '20',
                'healthy',
            ],
            'zero thresholds' => [
                '10',
                '0',
                '0',
                'healthy',
            ],
            'decimal quantity' => [
                '4.9999',
                '5',
                '20',
                'critical',
            ],
        ];
    }

    #[DataProvider('stockStatusCases')]
    public function test_stock_classification(
        string $available,
        string $minimum,
        string $reorder,
        string $expected
    ): void {
        $service = new LowStockService;

        $this->assertSame(
            $expected,
            $service->classify(
                $available,
                $minimum,
                $reorder
            )
        );
    }

    public function test_suggested_reorder_quantity(): void
    {
        $service = new LowStockService;

        $this->assertSame(
            '8.0000',
            $service->suggestedQuantity('12', '20')
        );

        $this->assertSame(
            '0.0000',
            $service->suggestedQuantity('25', '20')
        );

        $this->assertSame(
            '20.0000',
            $service->suggestedQuantity('0', '20')
        );
    }
}

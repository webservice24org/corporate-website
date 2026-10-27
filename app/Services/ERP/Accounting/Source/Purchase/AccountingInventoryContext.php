<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Source\Purchase;

use App\Models\GoodsReceipt;
use App\Models\PurchaseReturn;
use App\Models\SalesDelivery;
use App\Models\SalesReturn;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use Illuminate\Validation\ValidationException;

/** Internal, synchronous source-service scope. Always cleared, including failed transactions. */
final class AccountingInventoryContext
{
    /** @var list<array{company: int, kind: string, id: int, actor: int}> */
    private static array $stack = [];

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public static function run(int $company, string $kind, int $id, int $actor, callable $operation): mixed
    {
        self::$stack[] = ['company' => $company, 'kind' => $kind, 'id' => $id, 'actor' => $actor];
        try {
            return $operation();
        } finally {
            array_pop(self::$stack);
        }
    }

    public static function check(int $company, string $movementType, string $direction, ?string $referenceType, ?int $referenceId, ?int $actor): void
    {
        $entry = self::$stack === [] ? null : self::$stack[array_key_last(self::$stack)];
        $types = match ($referenceType) {
            SalesReturn::class => ['sales_return', [StockMovement::TYPE_SALE_RETURN => StockMovement::DIRECTION_IN]],
            SalesDelivery::class => ['sales_delivery', [StockMovement::TYPE_SALE => StockMovement::DIRECTION_OUT]],
            GoodsReceipt::class => ['goods_receipt', [StockMovement::TYPE_PURCHASE_RECEIPT => StockMovement::DIRECTION_IN]],
            PurchaseReturn::class => ['purchase_return', [StockMovement::TYPE_PURCHASE_RETURN => StockMovement::DIRECTION_OUT]],
            StockTransfer::class => ['stock_transfer', [StockMovement::TYPE_TRANSFER_OUT => StockMovement::DIRECTION_OUT, StockMovement::TYPE_TRANSFER_IN => StockMovement::DIRECTION_IN]],
            default => null,
        };
        if ($entry === null || $types === null || $entry['company'] !== $company || $entry['kind'] !== $types[0] || $entry['id'] !== $referenceId || $entry['actor'] !== $actor || ($types[1][$movementType] ?? null) !== $direction) {
            throw ValidationException::withMessages(['accounting_source' => 'Enabled accounting requires its installed parent-document posting service. Standalone opening, adjustment and uninstalled source writes require a reconciled, approved adapter.']);
        }
    }
}

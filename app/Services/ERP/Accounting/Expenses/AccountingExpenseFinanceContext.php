<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Expenses;

use Illuminate\Support\Facades\DB;

final class AccountingExpenseFinanceContext
{
    /** @var list<array{int, int}> */
    private static array $stack = [];

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(int $company, int $plan, callable $callback): mixed
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Expense source admission requires the complete caller transaction.');
        }

        self::$stack[] = [$company, $plan];
        try {
            return $callback();
        } finally {
            array_pop(self::$stack);
        }
    }

    public static function permits(int $company, int $plan): bool
    {
        return in_array([$company, $plan], self::$stack, true) && DB::transactionLevel() > 0;
    }
}

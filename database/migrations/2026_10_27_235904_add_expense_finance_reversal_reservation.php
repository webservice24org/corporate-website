<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_expense_finance_plans') || Schema::hasColumn('accounting_expense_finance_plans', 'active_reversal_id')) {
            throw new RuntimeException('Install 6.9C before the expense reversal reservation; the column must not already exist.');
        }
        $owned = false;
        foreach (Schema::getIndexes('accounting_expense_finance_plans') as $index) {
            if ($index['unique'] && $index['columns'] === ['id', 'company_id']) {
                $owned = true;
            }
        } if (! $owned) {
            throw new RuntimeException('Expense finance owned key is missing. No DDL was attempted.');
        }
        Schema::table('accounting_expense_finance_plans', function (Blueprint $t): void {
            $t->unsignedBigInteger('active_reversal_id')->nullable();
            $t->unique('active_reversal_id', 'aed_one_reverse');
            $t->foreign(['active_reversal_id', 'company_id'], 'aed_reverse_original')->references(['id', 'company_id'])->on('accounting_expense_finance_plans')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('accounting_expense_finance_plans')->where('kind', 'reversal')->exists()) {
            throw new RuntimeException('Retained reversal history requires its reservation schema.');
        }
        Schema::table('accounting_expense_finance_plans', function (Blueprint $t): void {
            $t->dropForeign('aed_reverse_original');
            $t->dropUnique('aed_one_reverse');
            $t->dropColumn('active_reversal_id');
        });
    }
};

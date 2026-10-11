<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\OpportunityStage;
use Illuminate\Database\Seeder;

class OpportunitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (Company::query()->get() as $company) {
            $stages = [
                ['name' => 'Prospecting', 'code' => 'prospecting', 'probability' => 10, 'sort_order' => 10, 'is_won' => false, 'is_lost' => false],
                ['name' => 'Qualified', 'code' => 'qualified', 'probability' => 25, 'sort_order' => 20, 'is_won' => false, 'is_lost' => false],
                ['name' => 'Proposal', 'code' => 'proposal', 'probability' => 50, 'sort_order' => 30, 'is_won' => false, 'is_lost' => false],
                ['name' => 'Negotiation', 'code' => 'negotiation', 'probability' => 75, 'sort_order' => 40, 'is_won' => false, 'is_lost' => false],
                ['name' => 'Won', 'code' => 'won', 'probability' => 100, 'sort_order' => 50, 'is_won' => true, 'is_lost' => false],
                ['name' => 'Lost', 'code' => 'lost', 'probability' => 0, 'sort_order' => 60, 'is_won' => false, 'is_lost' => true],
            ];

            foreach ($stages as $stage) {
                OpportunityStage::updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'code' => $stage['code'],
                    ],
                    [
                        ...$stage,
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
<?php

namespace App\Http\Controllers\ERP\CRM;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\OpportunityStage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class CrmAnalyticsController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'company_id' => [
                'nullable',
                'integer',
                'exists:companies,id',
            ],

            'from' => [
                'nullable',
                'date',
            ],

            'to' => [
                'nullable',
                'date',
                'after_or_equal:from',
            ],
        ]);

        $companyId =
            isset(
                $filters['company_id']
            )
                ? (int)
                    $filters['company_id']
                : null;

        $from =
            ! empty(
                $filters['from']
            )
                ? Carbon::parse(
                    $filters['from']
                )->startOfDay()
                : now()
                    ->subDays(29)
                    ->startOfDay();

        $to =
            ! empty(
                $filters['to']
            )
                ? Carbon::parse(
                    $filters['to']
                )->endOfDay()
                : now()
                    ->endOfDay();

        $leadBase =
            Lead::query()
                ->when(
                    $companyId,
                    fn (
                        $query,
                        $companyId
                    ) => $query->where(
                        'company_id',
                        $companyId
                    )
                );

        $leadPeriod =
            (clone $leadBase)
                ->whereBetween(
                    'created_at',
                    [
                        $from,
                        $to,
                    ]
                );

        $newLeads =
            (clone $leadPeriod)
                ->count();

        $convertedLeads =
            (clone $leadBase)
                ->whereNotNull(
                    'converted_at'
                )
                ->whereBetween(
                    'converted_at',
                    [
                        $from,
                        $to,
                    ]
                )
                ->count();

        $conversionRate =
            $newLeads > 0
                ? round(
                    (
                        $convertedLeads /
                        $newLeads
                    ) * 100,
                    2
                )
                : 0;

        $qualifiedLeads =
            (clone $leadPeriod)
                ->whereHas(
                    'status',
                    function (
                        $query
                    ) {
                        $query
                            ->where(
                                'is_won',
                                false
                            )
                            ->where(
                                'is_lost',
                                false
                            )
                            ->where(
                                'probability',
                                '>=',
                                25
                            );
                    }
                )
                ->count();

        $opportunityBase =
            Opportunity::query()
                ->when(
                    $companyId,
                    fn (
                        $query,
                        $companyId
                    ) => $query->where(
                        'company_id',
                        $companyId
                    )
                );

        $openOpportunityBase =
            (clone $opportunityBase)
                ->whereHas(
                    'stage',
                    fn (
                        $query
                    ) => $query
                        ->where(
                            'is_won',
                            false
                        )
                        ->where(
                            'is_lost',
                            false
                        )
                )
                ->where(
                    'is_active',
                    true
                );

        $openPipelineCount =
            (clone $openOpportunityBase)
                ->count();

        $openPipelineValue =
            (float)
                (clone $openOpportunityBase)
                    ->sum(
                        'expected_value'
                    );

        $weightedPipelineValue =
            (float)
                (clone $openOpportunityBase)
                    ->selectRaw(
                        'COALESCE(SUM(expected_value * probability / 100), 0) AS weighted_total'
                    )
                    ->value(
                        'weighted_total'
                    );

        $wonBase =
            (clone $opportunityBase)
                ->whereHas(
                    'stage',
                    fn (
                        $query
                    ) => $query->where(
                        'is_won',
                        true
                    )
                )
                ->whereBetween(
                    'won_at',
                    [
                        $from,
                        $to,
                    ]
                );

        $wonCount =
            (clone $wonBase)
                ->count();

        $wonValue =
            (float)
                (clone $wonBase)
                    ->sum(
                        'expected_value'
                    );

        $lostBase =
            (clone $opportunityBase)
                ->whereHas(
                    'stage',
                    fn (
                        $query
                    ) => $query->where(
                        'is_lost',
                        true
                    )
                )
                ->whereBetween(
                    'lost_at',
                    [
                        $from,
                        $to,
                    ]
                );

        $lostCount =
            (clone $lostBase)
                ->count();

        $stageFunnel =
            OpportunityStage::query()
                ->when(
                    $companyId,
                    fn (
                        $query,
                        $companyId
                    ) => $query->where(
                        'company_id',
                        $companyId
                    )
                )
                ->where(
                    'is_active',
                    true
                )
                ->orderBy(
                    'company_id'
                )
                ->orderBy(
                    'sort_order'
                )
                ->get([
                    'id',
                    'company_id',
                    'name',
                    'code',
                    'probability',
                    'is_won',
                    'is_lost',
                    'sort_order',
                ])
                ->map(
                    function (
                        OpportunityStage $stage
                    ) use (
                        $companyId
                    ) {
                        $query =
                            Opportunity::query()
                                ->where(
                                    'opportunity_stage_id',
                                    $stage->id
                                );

                        if (
                            $companyId
                        ) {
                            $query->where(
                                'company_id',
                                $companyId
                            );
                        }

                        return [
                            'id' => $stage->id,

                            'company_id' => $stage->company_id,

                            'name' => $stage->name,

                            'probability' => $stage->probability,

                            'is_won' => $stage->is_won,

                            'is_lost' => $stage->is_lost,

                            'count' => $query->count(),

                            'value' => (float)
                                    $query->sum(
                                        'expected_value'
                                    ),
                        ];
                    }
                )
                ->values();

        $salespersonPerformance =
            User::query()
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'email',
                ])
                ->map(
                    function (
                        User $user
                    ) use (
                        $companyId,
                        $from,
                        $to
                    ) {
                        $base =
                            Opportunity::query()
                                ->where(
                                    'salesperson_user_id',
                                    $user->id
                                )
                                ->when(
                                    $companyId,
                                    fn (
                                        $query,
                                        $companyId
                                    ) => $query->where(
                                        'company_id',
                                        $companyId
                                    )
                                );

                        $open =
                            (clone $base)
                                ->whereHas(
                                    'stage',
                                    fn (
                                        $query
                                    ) => $query
                                        ->where(
                                            'is_won',
                                            false
                                        )
                                        ->where(
                                            'is_lost',
                                            false
                                        )
                                )
                                ->where(
                                    'is_active',
                                    true
                                );

                        $won =
                            (clone $base)
                                ->whereHas(
                                    'stage',
                                    fn (
                                        $query
                                    ) => $query->where(
                                        'is_won',
                                        true
                                    )
                                )
                                ->whereBetween(
                                    'won_at',
                                    [
                                        $from,
                                        $to,
                                    ]
                                );

                        $lost =
                            (clone $base)
                                ->whereHas(
                                    'stage',
                                    fn (
                                        $query
                                    ) => $query->where(
                                        'is_lost',
                                        true
                                    )
                                )
                                ->whereBetween(
                                    'lost_at',
                                    [
                                        $from,
                                        $to,
                                    ]
                                );

                        return [
                            'id' => $user->id,

                            'name' => $user->name,

                            'email' => $user->email,

                            'open_count' => (clone $open)
                                ->count(),

                            'open_value' => (float)
                                    (clone $open)
                                        ->sum(
                                            'expected_value'
                                        ),

                            'won_count' => (clone $won)
                                ->count(),

                            'won_value' => (float)
                                    (clone $won)
                                        ->sum(
                                            'expected_value'
                                        ),

                            'lost_count' => (clone $lost)
                                ->count(),
                        ];
                    }
                )
                ->filter(
                    fn (
                        array $row
                    ) => $row['open_count'] >
                            0 ||
                        $row['won_count'] >
                            0 ||
                        $row['lost_count'] >
                            0
                )
                ->sortByDesc(
                    'won_value'
                )
                ->values();

        $upcomingCloses =
            (clone $openOpportunityBase)
                ->with([
                    'company:id,name',
                    'stage:id,name',
                    'salesperson:id,name,email',
                    'party',
                ])
                ->whereNotNull(
                    'expected_close_date'
                )
                ->whereDate(
                    'expected_close_date',
                    '>=',
                    now()
                        ->toDateString()
                )
                ->orderBy(
                    'expected_close_date'
                )
                ->limit(10)
                ->get();

        return Inertia::render(
            'ERP/CRM/Analytics/Index',
            [
                'metrics' => [
                    'new_leads' => $newLeads,

                    'qualified_leads' => $qualifiedLeads,

                    'converted_leads' => $convertedLeads,

                    'conversion_rate' => $conversionRate,

                    'open_pipeline_count' => $openPipelineCount,

                    'open_pipeline_value' => $openPipelineValue,

                    'weighted_pipeline_value' => $weightedPipelineValue,

                    'won_count' => $wonCount,

                    'won_value' => $wonValue,

                    'lost_count' => $lostCount,
                ],

                'stageFunnel' => $stageFunnel,

                'salespersonPerformance' => $salespersonPerformance,

                'upcomingCloses' => $upcomingCloses,

                'companies' => Company::query()
                    ->orderBy(
                        'name'
                    )
                    ->get([
                        'id',
                        'name',
                    ]),

                'filters' => [
                    'company_id' => $companyId
                            ? (string)
                                $companyId
                            : '',

                    'from' => $from
                        ->toDateString(),

                    'to' => $to
                        ->toDateString(),
                ],
            ]
        );
    }
}

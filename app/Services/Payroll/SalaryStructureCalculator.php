<?php

namespace App\Services\Payroll;

use App\Models\SalaryComponent;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SalaryStructureCalculator
{
    public function calculate(
        Collection $components,
        array $submittedItems
    ): array {
        $componentsById =
            $components->keyBy('id');

        $submittedByComponent =
            collect($submittedItems)
                ->keyBy(
                    fn (array $item) => (int)
                        $item[
                            'salary_component_id'
                        ]
                );

        $calculated = [];
        $resolving = [];

        $resolve =
            function (
                int $componentId
            ) use (
                &$resolve,
                &$calculated,
                &$resolving,
                $componentsById,
                $submittedByComponent
            ): float {
                if (
                    array_key_exists(
                        $componentId,
                        $calculated
                    )
                ) {
                    return $calculated[
                        $componentId
                    ];
                }

                if (
                    isset(
                        $resolving[
                            $componentId
                        ]
                    )
                ) {
                    throw ValidationException::withMessages([
                        'items' => 'A circular percentage dependency exists between salary components.',
                    ]);
                }

                /** @var SalaryComponent|null $component */
                $component =
                    $componentsById->get(
                        $componentId
                    );

                $submitted =
                    $submittedByComponent->get(
                        $componentId
                    );

                if (
                    ! $component ||
                    ! $submitted
                ) {
                    throw ValidationException::withMessages([
                        'items' => 'An invalid salary component was submitted.',
                    ]);
                }

                $resolving[
                    $componentId
                ] = true;

                if (
                    $component
                        ->calculation_type ===
                    'percentage'
                ) {
                    $baseId =
                        (int)
                        $component
                            ->percentage_of_component_id;

                    if (
                        ! $submittedByComponent
                            ->has(
                                $baseId
                            )
                    ) {
                        throw ValidationException::withMessages([
                            'items' => "{$component->name} requires its base salary component to be included in this structure.",
                        ]);
                    }

                    $baseAmount =
                        $resolve(
                            $baseId
                        );

                    $percentage =
                        (float)
                        (
                            $submitted[
                                'percentage'
                            ]
                            ??
                            $component
                                ->default_percentage
                        );

                    $value =
                        round(
                            $baseAmount *
                            (
                                $percentage /
                                100
                            ),
                            2
                        );
                } else {
                    $value =
                        round(
                            (float)
                            (
                                $submitted[
                                    'amount'
                                ]
                                ??
                                $component
                                    ->default_amount
                            ),
                            2
                        );
                }

                unset(
                    $resolving[
                        $componentId
                    ]
                );

                $calculated[
                    $componentId
                ] = $value;

                return $value;
            };

        foreach (
            $submittedByComponent as $componentId => $submitted
        ) {
            $resolve(
                (int)
                $componentId
            );
        }

        $items = [];

        $totalEarnings = 0;
        $totalDeductions = 0;
        $grossSalary = 0;

        foreach (
            $submittedByComponent as $componentId => $submitted
        ) {
            /** @var SalaryComponent $component */
            $component =
                $componentsById->get(
                    (int)
                    $componentId
                );

            $calculatedAmount =
                $calculated[
                    (int)
                    $componentId
                ];

            if (
                $component
                    ->component_type ===
                'earning'
            ) {
                $totalEarnings +=
                    $calculatedAmount;

                if (
                    $component
                        ->affects_gross_salary
                ) {
                    $grossSalary +=
                        $calculatedAmount;
                }
            } else {
                $totalDeductions +=
                    $calculatedAmount;
            }

            $items[] = [
                'salary_component_id' => $component->id,

                'component_name' => $component->name,

                'component_code' => $component->code,

                'component_type' => $component
                    ->component_type,

                'calculation_type' => $component
                    ->calculation_type,

                'percentage_of_component_id' => $component
                    ->percentage_of_component_id,

                'amount' => $component
                    ->calculation_type ===
                        'fixed'
                    ? (
                        $submitted[
                            'amount'
                        ]
                        ??
                        $component
                            ->default_amount
                    )
                            : 0,

                'percentage' => $component
                    ->calculation_type ===
                        'percentage'
                    ? (
                        $submitted[
                            'percentage'
                        ]
                        ??
                        $component
                            ->default_percentage
                    )
                            : 0,

                'calculated_amount' => $calculatedAmount,

                'is_taxable' => $component
                    ->is_taxable,

                'affects_gross_salary' => $component
                    ->affects_gross_salary,

                'is_recurring' => $component
                    ->is_recurring,

                'sort_order' => $component
                    ->sort_order,
            ];
        }

        return [
            'items' => $items,

            'total_earnings' => round(
                $totalEarnings,
                2
            ),

            'total_deductions' => round(
                $totalDeductions,
                2
            ),

            'gross_salary' => round(
                $grossSalary,
                2
            ),

            'net_salary' => round(
                $totalEarnings -
                $totalDeductions,
                2
            ),
        ];
    }
}

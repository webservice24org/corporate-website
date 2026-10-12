<?php

namespace App\Http\Controllers\ERP\HRM;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\LeaveType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LeaveTypeController extends Controller
{
    public function index(
        Request $request
    ): Response {
        $leaveTypes =
            LeaveType::query()
                ->with(
                    'company:id,name'
                )
                ->when(
                    $request->filled(
                        'company_id'
                    ),
                    fn ($query) => $query->where(
                        'company_id',
                        $request->company_id
                    )
                )
                ->when(
                    $request->filled(
                        'search'
                    ),
                    function ($query) use (
                        $request
                    ) {
                        $search =
                            trim(
                                $request->search
                            );

                        $query->where(
                            function (
                                $query
                            ) use (
                                $search
                            ) {
                                $query
                                    ->where(
                                        'name',
                                        'like',
                                        "%{$search}%"
                                    )
                                    ->orWhere(
                                        'code',
                                        'like',
                                        "%{$search}%"
                                    );
                            }
                        );
                    }
                )
                ->orderBy(
                    'company_id'
                )
                ->orderBy('name')
                ->paginate(20)
                ->withQueryString();

        return Inertia::render(
            'ERP/HRM/Leave/Types/Index',
            [
                'leaveTypes' => $leaveTypes,

                'companies' => Company::query()
                    ->where(
                        'status',
                        'active'
                    )
                    ->orderBy(
                        'name'
                    )
                    ->get([
                        'id',
                        'name',
                    ]),

                'filters' => [
                    'search' => $request->search,

                    'company_id' => $request
                        ->company_id,
                ],
            ]
        );
    }

    public function create(): Response
    {
        return Inertia::render(
            'ERP/HRM/Leave/Types/Create',
            [
                'companies' => Company::query()
                    ->where(
                        'status',
                        'active'
                    )
                    ->orderBy(
                        'name'
                    )
                    ->get([
                        'id',
                        'name',
                    ]),
            ]
        );
    }

    public function store(
        Request $request
    ): RedirectResponse {
        $data =
            $this->validatedData(
                $request
            );

        LeaveType::create(
            $data
        );

        return redirect()
            ->route(
                'erp.leave-types.index'
            )
            ->with(
                'success',
                'Leave type created successfully.'
            );
    }

    public function edit(
        LeaveType $leaveType
    ): Response {
        return Inertia::render(
            'ERP/HRM/Leave/Types/Edit',
            [
                'leaveType' => $leaveType,

                'companies' => Company::query()
                    ->where(
                        function (
                            $query
                        ) use (
                            $leaveType
                        ) {
                            $query
                                ->where(
                                    'status',
                                    'active'
                                )
                                ->orWhere(
                                    'id',
                                    $leaveType
                                        ->company_id
                                );
                        }
                    )
                    ->orderBy(
                        'name'
                    )
                    ->get([
                        'id',
                        'name',
                    ]),
            ]
        );
    }

    public function update(
        Request $request,
        LeaveType $leaveType
    ): RedirectResponse {
        $data =
            $this->validatedData(
                $request,
                $leaveType
            );

        $leaveType->update(
            $data
        );

        return redirect()
            ->route(
                'erp.leave-types.index'
            )
            ->with(
                'success',
                'Leave type updated successfully.'
            );
    }

    public function destroy(
        LeaveType $leaveType
    ): RedirectResponse {
        if (
            $leaveType
                ->requests()
                ->exists()
        ) {
            return back()->with(
                'error',
                'This leave type cannot be deleted because leave requests already use it.'
            );
        }

        if (
            $leaveType
                ->balances()
                ->exists()
        ) {
            return back()->with(
                'error',
                'This leave type cannot be deleted because employee leave balances already use it. Set it to inactive instead.'
            );
        }

        $leaveType->delete();

        return back()->with(
            'success',
            'Leave type deleted successfully.'
        );
    }

    private function validatedData(
        Request $request,
        ?LeaveType $leaveType = null
    ): array {
        return $request->validate([
            'company_id' => [
                'required',
                'integer',
                'exists:companies,id',
            ],

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'code' => [
                'required',
                'string',
                'max:50',

                Rule::unique(
                    'leave_types',
                    'code'
                )
                    ->where(
                        fn ($query) => $query->where(
                            'company_id',
                            $request
                                ->company_id
                        )
                    )
                    ->ignore(
                        $leaveType?->id
                    ),
            ],

            'annual_allowance' => [
                'required',
                'numeric',
                'min:0',
            ],

            'is_paid' => [
                'required',
                'boolean',
            ],

            'allow_half_day' => [
                'required',
                'boolean',
            ],

            'carry_forward' => [
                'required',
                'boolean',
            ],

            'max_carry_forward' => [
                'required',
                'numeric',
                'min:0',
            ],

            'requires_attachment' => [
                'required',
                'boolean',
            ],

            'exclude_weekly_off' => [
                'required',
                'boolean',
            ],

            'exclude_holidays' => [
                'required',
                'boolean',
            ],

            'status' => [
                'required',
                Rule::in([
                    'active',
                    'inactive',
                ]),
            ],

            'description' => [
                'nullable',
                'string',
                'max:5000',
            ],
        ]);
    }
}

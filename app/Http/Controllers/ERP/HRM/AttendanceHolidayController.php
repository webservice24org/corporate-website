<?php

namespace App\Http\Controllers\ERP\HRM;

use App\Http\Controllers\Controller;
use App\Models\AttendanceHoliday;
use App\Models\Branch;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceHolidayController extends Controller
{
    public function index(
        Request $request
    ): Response {
        $filters = $request->validate([
            'search' => [
                'nullable',
                'string',
                'max:255',
            ],

            'company_id' => [
                'nullable',
                'integer',
            ],

            'branch_id' => [
                'nullable',
                'integer',
            ],

            'status' => [
                'nullable',
                'string',
                'max:20',
            ],

            'date_from' => [
                'nullable',
                'date',
            ],

            'date_to' => [
                'nullable',
                'date',
            ],
        ]);

        $holidays =
            AttendanceHoliday::query()
                ->with([
                    'company:id,name',
                    'branch:id,name',
                ])

                ->when(
                    $filters['search']
                        ?? null,
                    fn ($query, $search) => $query->where(
                        'name',
                        'like',
                        "%{$search}%"
                    )
                )

                ->when(
                    $filters['company_id']
                        ?? null,
                    fn ($query, $companyId) => $query->where(
                        'company_id',
                        $companyId
                    )
                )

                ->when(
                    $filters['branch_id']
                        ?? null,
                    fn ($query, $branchId) => $query->where(
                        'branch_id',
                        $branchId
                    )
                )

                ->when(
                    $filters['status']
                        ?? null,
                    fn ($query, $status) => $query->where(
                        'status',
                        $status
                    )
                )

                ->when(
                    $filters['date_from']
                        ?? null,
                    fn ($query, $date) => $query->whereDate(
                        'holiday_date',
                        '>=',
                        $date
                    )
                )

                ->when(
                    $filters['date_to']
                        ?? null,
                    fn ($query, $date) => $query->whereDate(
                        'holiday_date',
                        '<=',
                        $date
                    )
                )

                ->orderByDesc(
                    'holiday_date'
                )
                ->orderBy('name')
                ->paginate(20)
                ->withQueryString();

        return Inertia::render(
            'ERP/HRM/Attendance/Holidays/Index',
            [
                'holidays' => $holidays,

                'filters' => [
                    'search' => $filters['search']
                        ?? '',

                    'company_id' => $filters['company_id']
                        ?? '',

                    'branch_id' => $filters['branch_id']
                        ?? '',

                    'status' => $filters['status']
                        ?? '',

                    'date_from' => $filters['date_from']
                        ?? '',

                    'date_to' => $filters['date_to']
                        ?? '',
                ],

                'companies' => Company::query()
                    ->where(
                        'status',
                        'active'
                    )
                    ->orderBy('name')
                    ->get([
                        'id',
                        'name',
                    ]),

                'branches' => Branch::query()
                    ->where(
                        'status',
                        'active'
                    )
                    ->orderBy('name')
                    ->get([
                        'id',
                        'company_id',
                        'name',
                    ]),
            ]
        );
    }

    public function create(): Response
    {
        return Inertia::render(
            'ERP/HRM/Attendance/Holidays/Create',
            [
                'companies' => Company::query()
                    ->where(
                        'status',
                        'active'
                    )
                    ->orderBy('name')
                    ->get([
                        'id',
                        'name',
                    ]),

                'branches' => Branch::query()
                    ->where(
                        'status',
                        'active'
                    )
                    ->orderBy('name')
                    ->get([
                        'id',
                        'company_id',
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

        $this->ensureScopeIsUnique(
            companyId: (int) $data['company_id'],

            branchId: $data['branch_id']
                    ? (int) $data['branch_id']
                    : null,

            holidayDate: $data['holiday_date']
        );

        AttendanceHoliday::create(
            $data
        );

        return redirect()
            ->route(
                'erp.attendance-holidays.index'
            )
            ->with(
                'success',
                'Holiday created successfully.'
            );
    }

    public function edit(
        AttendanceHoliday $attendanceHoliday
    ): Response {
        return Inertia::render(
            'ERP/HRM/Attendance/Holidays/Edit',
            [
                'holiday' => $attendanceHoliday,

                'companies' => Company::query()
                    ->where(
                        'status',
                        'active'
                    )
                    ->orWhere(
                        'id',
                        $attendanceHoliday
                            ->company_id
                    )
                    ->orderBy('name')
                    ->get([
                        'id',
                        'name',
                    ]),

                'branches' => Branch::query()
                    ->where(
                        'status',
                        'active'
                    )
                    ->orWhere(
                        'id',
                        $attendanceHoliday
                            ->branch_id
                    )
                    ->orderBy('name')
                    ->get([
                        'id',
                        'company_id',
                        'name',
                    ]),
            ]
        );
    }

    public function update(
        Request $request,
        AttendanceHoliday $attendanceHoliday
    ): RedirectResponse {
        $data =
            $this->validatedData(
                $request
            );

        $this->ensureScopeIsUnique(
            companyId: (int) $data['company_id'],

            branchId: $data['branch_id']
                    ? (int) $data['branch_id']
                    : null,

            holidayDate: $data['holiday_date'],

            ignoreId: $attendanceHoliday->id
        );

        $attendanceHoliday->update(
            $data
        );

        return redirect()
            ->route(
                'erp.attendance-holidays.index'
            )
            ->with(
                'success',
                'Holiday updated successfully.'
            );
    }

    public function destroy(
        AttendanceHoliday $attendanceHoliday
    ): RedirectResponse {
        $attendanceHoliday->delete();

        return back()->with(
            'success',
            'Holiday deleted successfully.'
        );
    }

    private function validatedData(
        Request $request
    ): array {
        $data =
            $request->validate([
                'company_id' => [
                    'required',
                    'integer',
                    'exists:companies,id',
                ],

                'branch_id' => [
                    'nullable',
                    'integer',
                    'exists:branches,id',
                ],

                'name' => [
                    'required',
                    'string',
                    'max:255',
                ],

                'holiday_date' => [
                    'required',
                    'date',
                ],

                'holiday_type' => [
                    'required',
                    'in:public,company,optional',
                ],

                'is_paid' => [
                    'required',
                    'boolean',
                ],

                'status' => [
                    'required',
                    'in:active,inactive',
                ],

                'notes' => [
                    'nullable',
                    'string',
                ],
            ]);

        if (
            ! empty($data['branch_id'])
        ) {
            $validBranch =
                Branch::query()
                    ->where(
                        'id',
                        $data['branch_id']
                    )
                    ->where(
                        'company_id',
                        $data['company_id']
                    )
                    ->exists();

            if (! $validBranch) {
                throw ValidationException::withMessages([
                    'branch_id' => 'The selected branch does not belong to the selected company.',
                ]);
            }
        }

        return $data;
    }

    private function ensureScopeIsUnique(
        int $companyId,
        ?int $branchId,
        string $holidayDate,
        ?int $ignoreId = null
    ): void {
        $query =
            AttendanceHoliday::query()
                ->where(
                    'company_id',
                    $companyId
                )
                ->whereDate(
                    'holiday_date',
                    $holidayDate
                );

        if ($branchId) {
            $query->where(
                'branch_id',
                $branchId
            );
        } else {
            $query->whereNull(
                'branch_id'
            );
        }

        if ($ignoreId) {
            $query->where(
                'id',
                '!=',
                $ignoreId
            );
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'holiday_date' => 'A holiday already exists for this company/branch scope on this date.',
            ]);
        }
    }
}

<?php

namespace App\Http\Controllers\ERP\HRM;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDevice;
use App\Models\Branch;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceDeviceController extends Controller
{
    public function index(
        Request $request
    ): Response {
        $query =
            AttendanceDevice::query()
                ->with([
                    'company:id,name,code',
                    'branch:id,company_id,name,code',
                ])
                ->withCount('users');

        if (
            $request->filled(
                'company_id'
            )
        ) {
            $query->where(
                'company_id',
                $request->integer(
                    'company_id'
                )
            );
        }

        if (
            $request->filled(
                'branch_id'
            )
        ) {
            $query->where(
                'branch_id',
                $request->integer(
                    'branch_id'
                )
            );
        }

        if (
            $request->filled(
                'provider'
            )
        ) {
            $query->where(
                'provider',
                $request->input(
                    'provider'
                )
            );
        }

        if (
            $request->filled(
                'status'
            )
        ) {
            $query->where(
                'status',
                $request->input(
                    'status'
                )
            );
        }

        if (
            $request->filled(
                'search'
            )
        ) {
            $search =
                trim(
                    (string) $request->input(
                        'search'
                    )
                );

            $query->where(
                function (
                    Builder $builder
                ) use ($search) {
                    $builder
                        ->where(
                            'name',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'code',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'serial_number',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'model',
                            'like',
                            "%{$search}%"
                        );
                }
            );
        }

        return Inertia::render(
            'ERP/HRM/Attendance/Devices/Index',
            [
                'devices' =>
                    $query
                        ->orderBy('company_id')
                        ->orderBy('name')
                        ->paginate(15)
                        ->withQueryString(),

                'companies' =>
                    $this->companies(),

                'branches' =>
                    $this->branches(),

                'filters' => [
                    'search' =>
                        $request->input(
                            'search',
                            ''
                        ),

                    'company_id' =>
                        $request->input(
                            'company_id',
                            ''
                        ),

                    'branch_id' =>
                        $request->input(
                            'branch_id',
                            ''
                        ),

                    'provider' =>
                        $request->input(
                            'provider',
                            ''
                        ),

                    'status' =>
                        $request->input(
                            'status',
                            ''
                        ),
                ],
            ]
        );
    }

    public function create(): Response
    {
        return Inertia::render(
            'ERP/HRM/Attendance/Devices/Create',
            [
                'companies' =>
                    $this->companies(),

                'branches' =>
                    $this->branches(),
            ]
        );
    }

    public function store(
        Request $request
    ): RedirectResponse {
        $validated =
            $this->validateDevice(
                $request
            );

        $this->ensureBranchBelongsToCompany(
            $validated
        );

        $validated['code'] =
            strtoupper(
                $validated['code']
            );

        AttendanceDevice::create(
            $validated
        );

        return to_route(
            'erp.attendance-devices.index'
        )->with(
            'success',
            'Attendance device created successfully.'
        );
    }

    public function edit(
        AttendanceDevice $attendanceDevice
    ): Response {
        return Inertia::render(
            'ERP/HRM/Attendance/Devices/Edit',
            [
                'device' => [
                    'id' =>
                        $attendanceDevice->id,

                    'company_id' =>
                        $attendanceDevice->company_id,

                    'branch_id' =>
                        $attendanceDevice->branch_id,

                    'name' =>
                        $attendanceDevice->name,

                    'code' =>
                        $attendanceDevice->code,

                    'provider' =>
                        $attendanceDevice->provider,

                    'model' =>
                        $attendanceDevice->model,

                    'serial_number' =>
                        $attendanceDevice->serial_number,

                    'integration_mode' =>
                        $attendanceDevice->integration_mode,

                    'ip_address' =>
                        $attendanceDevice->ip_address,

                    'port' =>
                        $attendanceDevice->port,

                    'base_url' =>
                        $attendanceDevice->base_url,

                    'timezone' =>
                        $attendanceDevice->timezone,

                    'status' =>
                        $attendanceDevice->status,

                    'notes' =>
                        $attendanceDevice->notes,
                ],

                'companies' =>
                    $this->companies(
                        $attendanceDevice
                            ->company_id
                    ),

                'branches' =>
                    $this->branches(
                        $attendanceDevice
                            ->branch_id
                    ),
            ]
        );
    }

    public function update(
        Request $request,
        AttendanceDevice $attendanceDevice
    ): RedirectResponse {
        $validated =
            $this->validateDevice(
                $request,
                $attendanceDevice
            );

        $this->ensureBranchBelongsToCompany(
            $validated
        );

        $validated['code'] =
            strtoupper(
                $validated['code']
            );

        $attendanceDevice->update(
            $validated
        );

        return to_route(
            'erp.attendance-devices.index'
        )->with(
            'success',
            'Attendance device updated successfully.'
        );
    }

    public function destroy(
        AttendanceDevice $attendanceDevice
    ): RedirectResponse {
        if (
            $attendanceDevice
                ->users()
                ->exists()
        ) {
            return back()->with(
                'error',
                'This device cannot be deleted because employee mappings exist.'
            );
        }

        $attendanceDevice->delete();

        return to_route(
            'erp.attendance-devices.index'
        )->with(
            'success',
            'Attendance device deleted successfully.'
        );
    }

    private function validateDevice(
        Request $request,
        ?AttendanceDevice $device = null
    ): array {
        $companyId =
            $request->integer(
                'company_id'
            );

        $uniqueCode =
            Rule::unique(
                'attendance_devices',
                'code'
            )->where(
                fn ($query) =>
                    $query->where(
                        'company_id',
                        $companyId
                    )
            );

        if ($device) {
            $uniqueCode->ignore(
                $device->id
            );
        }

        return $request->validate([
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

            'code' => [
                'required',
                'string',
                'max:50',
                $uniqueCode,
            ],

            'provider' => [
                'required',

                Rule::in([
                    'generic',
                    'zkteco',
                    'hikvision',
                    'suprema',
                    'anviz',
                    'other',
                ]),
            ],

            'model' => [
                'nullable',
                'string',
                'max:255',
            ],

            'serial_number' => [
                'nullable',
                'string',
                'max:255',
            ],

            'integration_mode' => [
                'required',

                Rule::in([
                    'push_adms',
                    'wdms_api',
                    'pull_sdk',
                    'manual_import',
                    'other',
                ]),
            ],

            'ip_address' => [
                'nullable',
                'string',
                'max:100',
            ],

            'port' => [
                'nullable',
                'integer',
                'min:1',
                'max:65535',
            ],

            'base_url' => [
                'nullable',
                'url',
                'max:2048',
            ],

            'timezone' => [
                'nullable',
                'timezone',
            ],

            'status' => [
                'required',

                Rule::in([
                    'active',
                    'inactive',
                ]),
            ],

            'notes' => [
                'nullable',
                'string',
            ],
        ]);
    }

    private function ensureBranchBelongsToCompany(
        array $validated
    ): void {
        if (
            empty(
                $validated[
                    'branch_id'
                ]
            )
        ) {
            return;
        }

        $valid =
            Branch::query()
                ->where(
                    'id',
                    $validated[
                        'branch_id'
                    ]
                )
                ->where(
                    'company_id',
                    $validated[
                        'company_id'
                    ]
                )
                ->exists();

        if (!$valid) {
            throw ValidationException::withMessages([
                'branch_id' =>
                    'The selected branch does not belong to the selected company.',
            ]);
        }
    }

    private function companies(
        ?int $includeId = null
    ) {
        return Company::query()
            ->where(
                function (
                    Builder $query
                ) use ($includeId) {
                    $query->where(
                        'status',
                        'active'
                    );

                    if ($includeId) {
                        $query->orWhere(
                            'id',
                            $includeId
                        );
                    }
                }
            )
            ->orderByDesc(
                'is_default'
            )
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'code',
                'timezone',
            ]);
    }

    private function branches(
        ?int $includeId = null
    ) {
        return Branch::query()
            ->where(
                function (
                    Builder $query
                ) use ($includeId) {
                    $query->where(
                        'status',
                        'active'
                    );

                    if ($includeId) {
                        $query->orWhere(
                            'id',
                            $includeId
                        );
                    }
                }
            )
            ->orderBy('name')
            ->get([
                'id',
                'company_id',
                'name',
                'code',
            ]);
    }
}
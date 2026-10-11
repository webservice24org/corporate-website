<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDeviceUser;
use App\Models\AttendanceRawPunch;
use Illuminate\Support\Facades\DB;

class AttendancePunchResolver
{
    public function resolveForMapping(
        AttendanceDeviceUser $mapping
    ): int {
        if ($mapping->status !== 'active') {
            return 0;
        }

        return DB::transaction(
            function () use ($mapping): int {
                $punchIds =
                    AttendanceRawPunch::query()
                        ->where(
                            'device_id',
                            $mapping->device_id
                        )
                        ->where(
                            'external_user_id',
                            $mapping->external_user_id
                        )
                        ->whereNull(
                            'employee_id'
                        )
                        ->where(
                            'processing_status',
                            'unmapped'
                        )
                        ->lockForUpdate()
                        ->pluck('id');

                if ($punchIds->isEmpty()) {
                    return 0;
                }

                return AttendanceRawPunch::query()
                    ->whereIn(
                        'id',
                        $punchIds
                    )
                    ->update([
                        'employee_id' =>
                            $mapping->employee_id,

                        'mapped_at' =>
                            now(),

                        'processing_status' =>
                            'pending',

                        'processing_error' =>
                            null,

                        'processed_at' =>
                            null,

                        'updated_at' =>
                            now(),
                    ]);
            }
        );
    }

    public function unresolvedCountForMapping(
        AttendanceDeviceUser $mapping
    ): int {
        return AttendanceRawPunch::query()
            ->where(
                'device_id',
                $mapping->device_id
            )
            ->where(
                'external_user_id',
                $mapping->external_user_id
            )
            ->whereNull(
                'employee_id'
            )
            ->where(
                'processing_status',
                'unmapped'
            )
            ->count();
    }

    public function resolveAll(): int
    {
        $total = 0;

        AttendanceDeviceUser::query()
            ->where(
                'status',
                'active'
            )
            ->orderBy('id')
            ->lazyById(100)
            ->each(
                function (
                    AttendanceDeviceUser $mapping
                ) use (&$total): void {
                    $total +=
                        $this->resolveForMapping(
                            $mapping
                        );
                }
            );

        return $total;
    }
}
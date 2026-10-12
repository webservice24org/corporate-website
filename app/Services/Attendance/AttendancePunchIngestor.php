<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceUser;
use App\Models\AttendanceRawPunch;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;

class AttendancePunchIngestor
{
    public function ingest(
        AttendanceDevice $device,
        string $externalUserId,
        CarbonInterface $punchedAtUtc,
        array $metadata = []
    ): AttendanceRawPunch {
        $source =
            (string) Arr::get(
                $metadata,
                'source',
                'device'
            );

        $sourceRecordId =
            Arr::get(
                $metadata,
                'source_record_id'
            );

        /*
        |--------------------------------------------------------------------------
        | Prefer provider record ID for true idempotency
        |--------------------------------------------------------------------------
        */

        if ($sourceRecordId) {
            $existing =
                AttendanceRawPunch::query()
                    ->where(
                        'device_id',
                        $device->id
                    )
                    ->where(
                        'source',
                        $source
                    )
                    ->where(
                        'source_record_id',
                        $sourceRecordId
                    )
                    ->first();

            if ($existing) {
                return $existing;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve device user -> employee
        |--------------------------------------------------------------------------
        */

        $mapping =
            AttendanceDeviceUser::query()
                ->where(
                    'device_id',
                    $device->id
                )
                ->where(
                    'external_user_id',
                    $externalUserId
                )
                ->where(
                    'status',
                    'active'
                )
                ->first();

        /*
        |--------------------------------------------------------------------------
        | Raw metadata
        |--------------------------------------------------------------------------
        */

        $rawPayload =
            Arr::get(
                $metadata,
                'raw_payload'
            );

        $devicePunchedAt =
            Arr::get(
                $metadata,
                'device_punched_at'
            );

        $timezone =
            Arr::get(
                $metadata,
                'source_timezone',
                $device->timezone
            );

        /*
        |--------------------------------------------------------------------------
        | Stable ingestion key
        |--------------------------------------------------------------------------
        |
        | This prevents an identical network retry from creating a second row.
        |
        | This is NOT our business-level duplicate punch detection.
        | That happens later using Attendance Settings.
        |
        */

        $ingestionKey =
            $this->makeIngestionKey(
                deviceId: $device->id,

                source: $source,

                externalUserId: $externalUserId,

                punchedAtUtc: $punchedAtUtc,

                sourceRecordId: $sourceRecordId,

                punchState: Arr::get(
                    $metadata,
                    'punch_state'
                ),

                verificationMethod: Arr::get(
                    $metadata,
                    'verification_method'
                ),

                rawPayload: $rawPayload
            );

        $existing =
            AttendanceRawPunch::query()
                ->where(
                    'ingestion_key',
                    $ingestionKey
                )
                ->first();

        if ($existing) {
            return $existing;
        }

        return AttendanceRawPunch::create([
            'company_id' => $device->company_id,

            'device_id' => $device->id,

            'employee_id' => $mapping?->employee_id,

            'external_user_id' => $externalUserId,

            'device_punched_at' => $devicePunchedAt,

            'punched_at' => CarbonImmutable::instance(
                $punchedAtUtc
            )->utc(),

            'source_timezone' => $timezone,

            'punch_state' => Arr::get(
                $metadata,
                'punch_state'
            ),

            'verification_method' => Arr::get(
                $metadata,
                'verification_method'
            ),

            'source' => $source,

            'source_record_id' => $sourceRecordId,

            'ingestion_key' => $ingestionKey,

            'raw_payload' => $rawPayload,

            'mapped_at' => $mapping
                    ? now()
                    : null,

            'processing_status' => $mapping
                    ? 'pending'
                    : 'unmapped',

            'processing_error' => null,

            'processed_at' => null,

            'received_at' => now(),
        ]);
    }

    private function makeIngestionKey(
        int $deviceId,
        string $source,
        string $externalUserId,
        CarbonInterface $punchedAtUtc,
        ?string $sourceRecordId,
        ?string $punchState,
        ?string $verificationMethod,
        mixed $rawPayload
    ): string {
        $payload = [
            'device_id' => $deviceId,

            'source' => $source,

            'external_user_id' => $externalUserId,

            'punched_at' => CarbonImmutable::instance(
                $punchedAtUtc
            )
                ->utc()
                ->format(
                    'Y-m-d H:i:s.u'
                ),

            'source_record_id' => $sourceRecordId,

            'punch_state' => $punchState,

            'verification_method' => $verificationMethod,

            'raw_payload' => $rawPayload,
        ];

        return hash(
            'sha256',
            json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_PRESERVE_ZERO_FRACTION
            )
        );
    }
}

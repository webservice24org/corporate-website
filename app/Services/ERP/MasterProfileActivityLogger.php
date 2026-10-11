<?php

namespace App\Services\ERP;

use App\Models\Company;
use App\Models\Depot;
use App\Models\District;
use App\Models\Division;
use App\Models\MasterProfileActivity;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class MasterProfileActivityLogger
{
    public function record(
        Model $subject,
        ?int $actorId,
        string $action,
        string $source = 'admin',
        ?string $description = null,
        array $changes = [],
        array $meta = [],
        ?Request $request = null
    ): MasterProfileActivity {
        return MasterProfileActivity::create([
            'subject_type' =>
                $subject->getMorphClass(),

            'subject_id' =>
                $subject->getKey(),

            'actor_id' =>
                $actorId,

            'source' =>
                $source,

            'action' =>
                $action,

            'description' =>
                $description,

            'changes' =>
                $changes ?: null,

            'meta' =>
                $meta ?: null,

            'ip_address' =>
                $request?->ip(),

            'user_agent' =>
                $request?->userAgent(),
        ]);
    }

    public function recordProfileUpdate(
        Model $subject,
        ?int $actorId,
        array $before,
        array $after,
        array $fields,
        string $source = 'admin',
        ?Request $request = null,
        ?string $description = null
    ): ?MasterProfileActivity {
        $changes = [];

        foreach ($fields as $field) {
            $oldValue =
                Arr::get(
                    $before,
                    $field
                );

            $newValue =
                Arr::get(
                    $after,
                    $field
                );

            if (
                $this->comparable(
                    $oldValue
                ) ===
                $this->comparable(
                    $newValue
                )
            ) {
                continue;
            }

            $changes[$field] = [
                'old' =>
                    $this->displayValue(
                        $field,
                        $oldValue
                    ),

                'new' =>
                    $this->displayValue(
                        $field,
                        $newValue
                    ),
            ];
        }

        if (!$changes) {
            return null;
        }

        return $this->record(
            subject:
                $subject,

            actorId:
                $actorId,

            action:
                'profile.updated',

            source:
                $source,

            description:
                $description ??
                'Master profile updated.',

            changes:
                $changes,

            request:
                $request
        );
    }

    private function comparable(
        mixed $value
    ): string {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value
                ? '1'
                : '0';
        }

        return trim(
            (string) $value
        );
    }

    private function displayValue(
        string $field,
        mixed $value
    ): mixed {
        if (
            $field ===
            'proprietor_photo'
        ) {
            return $value
                ? 'Picture available'
                : 'No picture';
        }

        if (
            $field ===
            'is_active'
        ) {
            return (bool) $value
                ? 'Active'
                : 'Inactive';
        }

        if ($value === null || $value === '') {
            return null;
        }

        return match ($field) {
            'company_id' =>
                Company::query()
                    ->whereKey($value)
                    ->value('name') ??
                $value,

            'division_id' =>
                Division::query()
                    ->whereKey($value)
                    ->value('name') ??
                $value,

            'district_id' =>
                District::query()
                    ->whereKey($value)
                    ->value('name') ??
                $value,

            'depot_id' =>
                Depot::query()
                    ->whereKey($value)
                    ->value('name') ??
                $value,

            'upazila_id' =>
                Upazila::query()
                    ->whereKey($value)
                    ->value('name') ??
                $value,

            'manager_user_id' =>
                User::query()
                    ->whereKey($value)
                    ->value('name') ??
                $value,

            default =>
                $value,
        };
    }
}

<?php

namespace App\Services\ERP\CRM;

use App\Models\Depot;
use App\Models\DepotApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DepotApplicationApprovalService
{
    public function approve(
        DepotApplication $application,
        User $user,
        array $data = []
    ): Depot {
        return DB::transaction(
            function () use ($application, $user, $data) {
                $application =
                    DepotApplication::query()
                        ->lockForUpdate()
                        ->findOrFail(
                            $application->id
                        );

                if (
                    $application->status ===
                    'approved'
                ) {
                    $existingDepot = $application->depot;
                    if ($existingDepot instanceof Depot) {
                        return $existingDepot;
                    }

                    throw ValidationException::withMessages([
                        'application' => 'This application has already been approved.',
                    ]);
                }

                if (
                    $application->status ===
                    'rejected'
                ) {
                    throw ValidationException::withMessages([
                        'application' => 'A rejected application cannot be approved directly.',
                    ]);
                }

                $depot =
                    Depot::create([
                        'company_id' => $application
                            ->company_id,

                        'division_id' => $application
                            ->division_id,

                        'district_id' => $application
                            ->district_id,

                        /*
                         * Leave code null until
                         * DocumentSequence exists.
                         */
                        'depot_code' => null,

                        'name' => $application
                            ->business_name,

                        'proprietor_name' => $application
                            ->proprietor_name,

                        'proprietor_photo' => $application
                            ->proprietor_photo,

                        'phone' => $application
                            ->phone,

                        'alternate_phone' => $application
                            ->alternate_phone,

                        'email' => $application
                            ->email,

                        'trade_license_number' => $application
                            ->trade_license_number,

                        'nid_number' => $application
                            ->nid_number,

                        'tin_number' => $application
                            ->tin_number,

                        'vat_number' => $application
                            ->vat_number,

                        'address' => $application
                            ->business_address,

                        'postal_code' => $application
                            ->postal_code,

                        'credit_limit' => $data['credit_limit']
                            ?? $application
                                ->requested_credit_limit
                            ?? 0,

                        'payment_terms_days' => $data['payment_terms_days']
                            ?? 0,

                        'manager_user_id' => $data['manager_user_id']
                            ?? null,

                        'is_active' => true,

                        'notes' => $data['notes']
                            ?? null,
                    ]);

                $application->update([
                    'depot_id' => $depot->id,

                    'status' => 'approved',

                    'reviewed_by_user_id' => $user->id,

                    'reviewed_at' => now(),

                    'approved_by_user_id' => $user->id,

                    'approved_at' => now(),

                    'rejected_by_user_id' => null,

                    'rejected_at' => null,

                    'rejection_reason' => null,

                    'admin_notes' => $data['admin_notes']
                        ?? $application
                            ->admin_notes,
                ]);

                return $depot;
            }
        );
    }

    public function reject(
        DepotApplication $application,
        User $user,
        string $reason,
        ?string $notes = null
    ): void {
        DB::transaction(
            function () use ($application, $user, $reason, $notes) {
                $application =
                    DepotApplication::query()
                        ->lockForUpdate()
                        ->findOrFail(
                            $application->id
                        );

                if (
                    $application->status ===
                    'approved'
                ) {
                    throw ValidationException::withMessages([
                        'application' => 'An approved application cannot be rejected.',
                    ]);
                }

                $application->update([
                    'status' => 'rejected',

                    'reviewed_by_user_id' => $user->id,

                    'reviewed_at' => now(),

                    'rejected_by_user_id' => $user->id,

                    'rejected_at' => now(),

                    'rejection_reason' => $reason,

                    'admin_notes' => $notes,
                ]);
            }
        );
    }
}

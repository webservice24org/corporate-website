<?php

namespace App\Services\ERP\CRM;

use App\Models\Dealer;
use App\Models\DealerApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DealerApplicationApprovalService
{
    public function approve(
        DealerApplication $application,
        User $user,
        array $data = []
    ): Dealer {
        return DB::transaction(
            function () use (
                $application,
                $user,
                $data
            ) {
                $application =
                    DealerApplication::query()
                        ->with([
                            'documents',
                            'depot',
                            'upazila.district',
                        ])
                        ->lockForUpdate()
                        ->findOrFail(
                            $application->id
                        );

                if (
                    $application->status ===
                    'approved'
                ) {
                    throw ValidationException::withMessages([
                        'application' =>
                            'This application is already approved.',
                    ]);
                }

                if (
                    $application->status ===
                    'rejected'
                ) {
                    throw ValidationException::withMessages([
                        'application' =>
                            'A rejected application cannot be approved.',
                    ]);
                }

                if (
                    !$application->depot ||
                    !$application->depot->is_active
                ) {
                    throw ValidationException::withMessages([
                        'depot' =>
                            'The selected Depot is not active.',
                    ]);
                }

                if (
                    !$application->upazila ||
                    !$application->upazila->district ||
                    $application->upazila->district_id !==
                        $application->depot->district_id
                ) {
                    throw ValidationException::withMessages([
                        'upazila' =>
                            'Dealer Upazila does not belong to the Depot district.',
                    ]);
                }

                $requiredTypes = [
                    'bank_check',
                    'trade_license',
                    'nid',
                ];

                foreach (
                    $requiredTypes as $type
                ) {
                    $document =
                        $application
                            ->documents
                            ->firstWhere(
                                'document_type',
                                $type
                            );

                    if (!$document) {
                        throw ValidationException::withMessages([
                            'documents' =>
                                "Required document '{$type}' is missing.",
                        ]);
                    }

                    if (
                        !$document->verified_at
                    ) {
                        throw ValidationException::withMessages([
                            'documents' =>
                                "Required document '{$type}' must be verified before approval.",
                        ]);
                    }
                }

                /*
                 * dealer_code intentionally remains NULL.
                 * DocumentSequence will generate it later.
                 */
                $dealer =
                    Dealer::create([
                        'company_id' =>
                            $application->company_id,

                        'depot_id' =>
                            $application->depot_id,

                        'upazila_id' =>
                            $application->upazila_id,

                        'dealer_code' =>
                            null,

                        'business_name' =>
                            $application->business_name,

                        'proprietor_name' =>
                            $application->proprietor_name,

                        'proprietor_photo' =>
                            $application->proprietor_photo,

                        'phone' =>
                            $application->phone,

                        'alternate_phone' =>
                            $application->alternate_phone,

                        'email' =>
                            $application->email,

                        'trade_license_number' =>
                            $application->trade_license_number,

                        'nid_number' =>
                            $application->nid_number,

                        'tin_number' =>
                            $application->tin_number,

                        'vat_number' =>
                            $application->vat_number,

                        'address' =>
                            $application->business_address,

                        'postal_code' =>
                            $application->postal_code,

                        'credit_limit' =>
                            $data['credit_limit'] ??
                            $application->requested_credit_limit ??
                            0,

                        'payment_terms_days' =>
                            $data['payment_terms_days'] ??
                            0,

                        'manager_user_id' =>
                            $data['manager_user_id'] ??
                            null,

                        'is_active' =>
                            true,

                        'notes' =>
                            $data['admin_notes'] ??
                            null,
                    ]);

                $application->update([
                    'dealer_id' =>
                        $dealer->id,

                    'status' =>
                        'approved',

                    'reviewed_by_user_id' =>
                        $application->reviewed_by_user_id ??
                        $user->id,

                    'reviewed_at' =>
                        $application->reviewed_at ??
                        now(),

                    'approved_by_user_id' =>
                        $user->id,

                    'approved_at' =>
                        now(),

                    'rejected_by_user_id' =>
                        null,

                    'rejected_at' =>
                        null,

                    'rejection_reason' =>
                        null,

                    'admin_notes' =>
                        $data['admin_notes'] ??
                        $application->admin_notes,
                ]);

                return $dealer;
            }
        );
    }

    public function reject(
        DealerApplication $application,
        User $user,
        string $reason,
        ?string $notes = null
    ): DealerApplication {
        return DB::transaction(
            function () use (
                $application,
                $user,
                $reason,
                $notes
            ) {
                $application =
                    DealerApplication::query()
                        ->lockForUpdate()
                        ->findOrFail(
                            $application->id
                        );

                if (
                    $application->status ===
                    'approved'
                ) {
                    throw ValidationException::withMessages([
                        'application' =>
                            'An approved application cannot be rejected.',
                    ]);
                }

                $application->update([
                    'status' =>
                        'rejected',

                    'reviewed_by_user_id' =>
                        $application->reviewed_by_user_id ??
                        $user->id,

                    'reviewed_at' =>
                        $application->reviewed_at ??
                        now(),

                    'rejected_by_user_id' =>
                        $user->id,

                    'rejected_at' =>
                        now(),

                    'rejection_reason' =>
                        $reason,

                    'admin_notes' =>
                        $notes,
                ]);

                return $application;
            }
        );
    }
}
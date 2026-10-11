<?php

namespace App\Http\Controllers\ERP\CRM;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Depot;
use App\Models\District;
use App\Models\Division;
use App\Models\User;
use App\Services\ERP\MasterProfileActivityLogger;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DepotAdminEditController extends Controller
{
    public function edit(Depot $depot): Response
    {
        $depot->load([
            'company:id,name',
            'division:id,name',
            'district:id,division_id,name',
            'manager:id,name,email',
            'currentDocuments' => fn ($query) =>
                $query
                    ->with([
                        'verifier:id,name',
                        'uploader:id,name',
                    ])
                    ->orderBy('document_type'),
        ]);

        return Inertia::render(
            'ERP/CRM/Depots/Edit',
            [
                'depot' => $depot,

                'companies' => Company::query()
                    ->orderBy('name')
                    ->get([
                        'id',
                        'name',
                    ]),

                'divisions' => Division::query()
                    ->orderBy('name')
                    ->get([
                        'id',
                        'name',
                    ]),

                'districts' => District::query()
                    ->orderBy('name')
                    ->get([
                        'id',
                        'division_id',
                        'name',
                    ]),

                'managers' => User::query()
                    ->orderBy('name')
                    ->get([
                        'id',
                        'name',
                        'email',
                    ]),
            ]
        );
    }

    public function update(
        Request $request,
        Depot $depot
    ): RedirectResponse {
        $validated = $request->validate([
            'company_id' => [
                'required',
                'integer',
                'exists:companies,id',
            ],

            'division_id' => [
                'required',
                'integer',
                'exists:divisions,id',
            ],

            'district_id' => [
                'required',
                'integer',
                'exists:districts,id',
            ],

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'proprietor_name' => [
                'required',
                'string',
                'max:255',
            ],

            'proprietor_photo' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],

            'phone' => [
                'required',
                'string',
                'max:50',
            ],

            'alternate_phone' => [
                'nullable',
                'string',
                'max:50',
            ],

            'email' => [
                'nullable',
                'email',
                'max:255',
            ],

            'trade_license_number' => [
                'nullable',
                'string',
                'max:255',
            ],

            'nid_number' => [
                'nullable',
                'string',
                'max:255',
            ],

            'tin_number' => [
                'nullable',
                'string',
                'max:255',
            ],

            'vat_number' => [
                'nullable',
                'string',
                'max:255',
            ],

            'address' => [
                'required',
                'string',
                'max:5000',
            ],

            'postal_code' => [
                'nullable',
                'string',
                'max:50',
            ],

            'credit_limit' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'payment_terms_days' => [
                'nullable',
                'integer',
                'min:0',
                'max:3650',
            ],

            'manager_user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
            ],

            'is_active' => [
                'required',
                'boolean',
            ],

            'notes' => [
                'nullable',
                'string',
                'max:5000',
            ],
        ]);

        $before =
            $depot->getAttributes();

        $districtBelongsToDivision =
            District::query()
                ->whereKey(
                    $validated['district_id']
                )
                ->where(
                    'division_id',
                    $validated['division_id']
                )
                ->exists();

        if (!$districtBelongsToDivision) {
            return back()
                ->withErrors([
                    'district_id' =>
                        'The selected district does not belong to the selected division.',
                ])
                ->withInput();
        }

        if (
            $request->hasFile(
                'proprietor_photo'
            )
        ) {
            if (
                $depot->proprietor_photo &&
                Storage::disk('local')->exists(
                    $depot->proprietor_photo
                )
            ) {
                Storage::disk('local')->delete(
                    $depot->proprietor_photo
                );
            }

            $validated['proprietor_photo'] =
                $request
                    ->file('proprietor_photo')
                    ->store(
                        'erp/depots/proprietors',
                        'local'
                    );
        } else {
            unset(
                $validated['proprietor_photo']
            );
        }

        /*
         * depot_code is intentionally untouched.
         * Centralized DocumentSequence owns it later.
         */
        $depot->update(
            $validated
        );

        $depot->refresh();

        app(
            MasterProfileActivityLogger::class
        )->recordProfileUpdate(
            subject:
                $depot,

            actorId:
                $request->user()?->id,

            before:
                $before,

            after:
                $depot->getAttributes(),

            fields: [
                'company_id',
                'division_id',
                'district_id',
                'name',
                'proprietor_name',
                'proprietor_photo',
                'phone',
                'alternate_phone',
                'email',
                'trade_license_number',
                'nid_number',
                'tin_number',
                'vat_number',
                'address',
                'postal_code',
                'credit_limit',
                'payment_terms_days',
                'manager_user_id',
                'is_active',
                'notes',
            ],

            source:
                'admin',

            request:
                $request,

            description:
                'Depot master profile updated by administrator.'
        );

        return redirect()
            ->route(
                'erp.depots.show',
                $depot
            )
            ->with(
                'success',
                'Depot updated successfully.'
            );
    }

    public function proprietorPhoto(
        Depot $depot
    ): StreamedResponse {
        abort_unless(
            $depot->proprietor_photo,
            404
        );

        /** @var FilesystemAdapter $disk */
        $disk =
            Storage::disk(
                'local'
            );

        abort_unless(
            $disk->exists(
                $depot->proprietor_photo
            ),
            404
        );

        return $disk->response(
            $depot->proprietor_photo
        );
    }
}

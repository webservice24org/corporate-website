<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\DealerApplication;
use App\Models\Depot;
use App\Models\Upazila;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DealerRegistrationController extends Controller
{
    public function create(): Response
    {
        $depots = Depot::query()
            ->where(
                'is_active',
                true
            )
            ->with([
                'company:id,name',
                'district:id,division_id,name,name_bn',
                'district.division:id,name,name_bn',
            ])
            ->orderBy('name')
            ->get([
                'id',
                'company_id',
                'district_id',
                'name',
                'depot_code',
            ]);

        $upazilas = Upazila::query()
            ->where(
                'is_active',
                true
            )
            ->whereHas(
                'district',
                fn ($query) => $query->where(
                    'is_active',
                    true
                )
            )
            ->with([
                'district:id,division_id,name,name_bn',
            ])
            ->orderBy('name')
            ->get([
                'id',
                'district_id',
                'name',
                'name_bn',
                'code',
            ]);

        return Inertia::render(
            'Frontend/DealerRegistration/Create',
            [
                'depots' => $depots,
                'upazilas' => $upazilas,
            ]
        );
    }

    public function store(
        Request $request
    ): RedirectResponse {
        $validated = $request->validate([
            'depot_id' => [
                'required',
                'integer',
                Rule::exists(
                    'depots',
                    'id'
                )->where(
                    fn ($query) => $query->where(
                        'is_active',
                        true
                    )
                        ->whereNull(
                            'deleted_at'
                        )
                ),
            ],

            'upazila_id' => [
                'required',
                'integer',
                Rule::exists(
                    'upazilas',
                    'id'
                )->where(
                    'is_active',
                    true
                ),
            ],

            'business_name' => [
                'required',
                'string',
                'max:255',
            ],

            'proprietor_name' => [
                'required',
                'string',
                'max:255',
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

            'business_address' => [
                'required',
                'string',
                'max:5000',
            ],

            'postal_code' => [
                'nullable',
                'string',
                'max:30',
            ],

            'years_in_business' => [
                'nullable',
                'integer',
                'min:0',
                'max:200',
            ],

            'requested_credit_limit' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'business_description' => [
                'nullable',
                'string',
                'max:10000',
            ],

            'bank_check' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:5120',
            ],

            'trade_license_document' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:5120',
            ],

            'nid_document' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:5120',
            ],

            'tin_document' => [
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:5120',
            ],

            'vat_document' => [
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:5120',
            ],

            'proprietor_photo' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],
        ]);

        $depot = Depot::query()
            ->whereKey(
                $validated['depot_id']
            )
            ->where(
                'is_active',
                true
            )
            ->firstOrFail();

        $upazila = Upazila::query()
            ->with('district')
            ->whereKey(
                $validated['upazila_id']
            )
            ->where(
                'is_active',
                true
            )
            ->firstOrFail();

        if (
            ! $upazila->district ||
            $upazila->district_id !==
                $depot->district_id
        ) {
            return back()
                ->withErrors([
                    'upazila_id' => 'The selected Upazila does not belong to the Depot district.',
                ])
                ->withInput();
        }

        $directory = null;

        try {
            DB::transaction(
                function () use (
                    $request,
                    $validated,
                    $depot,
                    &$directory
                ) {
                    $application =
                        DealerApplication::create([
                            'company_id' => $depot->company_id,

                            'depot_id' => $depot->id,

                            'upazila_id' => $validated['upazila_id'],

                            'business_name' => $validated['business_name'],

                            'proprietor_name' => $validated['proprietor_name'],

                            'phone' => $validated['phone'],

                            'alternate_phone' => $validated['alternate_phone'] ??
                                null,

                            'email' => $validated['email'] ??
                                null,

                            'trade_license_number' => $validated['trade_license_number'] ??
                                null,

                            'nid_number' => $validated['nid_number'] ??
                                null,

                            'tin_number' => $validated['tin_number'] ??
                                null,

                            'vat_number' => $validated['vat_number'] ??
                                null,

                            'business_address' => $validated['business_address'],

                            'postal_code' => $validated['postal_code'] ??
                                null,

                            'years_in_business' => $validated['years_in_business'] ??
                                null,

                            'requested_credit_limit' => $validated['requested_credit_limit'] ??
                                null,

                            'business_description' => $validated['business_description'] ??
                                null,

                            'status' => 'pending',
                        ]);

                    $directory =
                        "erp/dealer-applications/{$application->id}";

                    if ($request->hasFile('proprietor_photo')) {
                        $path = $request
                            ->file('proprietor_photo')
                            ->store(
                                "{$directory}/proprietor",
                                'local'
                            );

                        $application->update([
                            'proprietor_photo' => $path,
                        ]);
                    }

                    $documents = [
                        'bank_check' => 'bank_check',

                        'trade_license_document' => 'trade_license',

                        'nid_document' => 'nid',

                        'tin_document' => 'tin',

                        'vat_document' => 'vat',
                    ];

                    foreach (
                        $documents as $field => $type
                    ) {
                        if (
                            ! $request->hasFile(
                                $field
                            )
                        ) {
                            continue;
                        }

                        $file =
                            $request->file(
                                $field
                            );

                        $path =
                            $file->store(
                                $directory,
                                'local'
                            );

                        $application
                            ->documents()
                            ->create([
                                'document_type' => $type,

                                'disk' => 'local',

                                'path' => $path,

                                'original_name' => $file->getClientOriginalName(),

                                'mime_type' => $file->getMimeType(),

                                'file_size' => $file->getSize(),
                            ]);
                    }
                }
            );
        } catch (Throwable $exception) {
            if ($directory) {
                Storage::disk('local')
                    ->deleteDirectory(
                        $directory
                    );
            }

            throw $exception;
        }

        return back()->with(
            'success',
            'Dealer application submitted successfully. It will be reviewed by our administration team.'
        );
    }
}

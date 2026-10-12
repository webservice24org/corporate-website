<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\DepotApplication;
use App\Models\District;
use App\Models\Division;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DepotRegistrationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render(
            'Frontend/DepotRegistration/Create',
            [
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
            ]
        );
    }

    public function store(
        Request $request
    ): RedirectResponse {
        $validated =
            $request->validate([
                'company_id' => [
                    'required',
                    Rule::exists(
                        'companies',
                        'id'
                    ),
                ],

                'division_id' => [
                    'required',
                    Rule::exists(
                        'divisions',
                        'id'
                    ),
                ],

                'district_id' => [
                    'required',
                    Rule::exists(
                        'districts',
                        'id'
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
                    'max:150',
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

        /*
        * Verify district really belongs
        * to submitted division.
        */
        District::query()
            ->whereKey(
                $validated[
                    'district_id'
                ]
            )
            ->where(
                'division_id',
                $validated[
                    'division_id'
                ]
            )
            ->firstOrFail();

        try {
            DB::transaction(
                function () use (
                    $request,
                    $validated
                ) {
                    $application =
                        DepotApplication::create([
                            'company_id' => $validated['company_id'],

                            'division_id' => $validated['division_id'],

                            'district_id' => $validated['district_id'],

                            'business_name' => $validated['business_name'],

                            'proprietor_name' => $validated['proprietor_name'],

                            'phone' => $validated['phone'],

                            'alternate_phone' => $validated['alternate_phone']
                                ?? null,

                            'email' => $validated['email']
                                ?? null,

                            'trade_license_number' => $validated['trade_license_number']
                                ?? null,

                            'nid_number' => $validated['nid_number']
                                ?? null,

                            'tin_number' => $validated['tin_number']
                                ?? null,

                            'vat_number' => $validated['vat_number']
                                ?? null,

                            'business_address' => $validated['business_address'],

                            'postal_code' => $validated['postal_code']
                                ?? null,

                            'years_in_business' => $validated['years_in_business']
                                ?? null,

                            'requested_credit_limit' => $validated['requested_credit_limit']
                                ?? null,

                            'business_description' => $validated['business_description']
                                ?? null,

                            'status' => 'pending',
                        ]);

                    if ($request->hasFile('proprietor_photo')) {
                        $path = $request
                            ->file('proprietor_photo')
                            ->store(
                                "erp/depot-applications/{$application->id}/proprietor",
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
                                "erp/depot-applications/{$application->id}",
                                'local'
                            );

                        $application
                            ->documents()
                            ->create([
                                'document_type' => $type,

                                'disk' => 'local',

                                'path' => $path,

                                'original_name' => $file
                                    ->getClientOriginalName(),

                                'mime_type' => $file
                                    ->getMimeType(),

                                'file_size' => $file
                                    ->getSize(),
                            ]);
                    }
                }
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->withErrors([
                    'registration' => 'Unable to submit the depot registration. Please try again.',
                ]);
        }

        return back()->with(
            'success',
            'Your depot registration application has been submitted successfully and is awaiting approval.'
        );
    }
}

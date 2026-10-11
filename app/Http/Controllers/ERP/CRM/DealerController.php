<?php

namespace App\Http\Controllers\ERP\CRM;

use App\Http\Controllers\Controller;
use App\Models\Dealer;
use App\Models\Depot;
use App\Models\Upazila;
use App\Models\User;
use App\Services\ERP\MasterProfileActivityLogger;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DealerController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'depot_id' => ['nullable', 'integer', 'exists:depots,id'],
            'upazila_id' => ['nullable', 'integer', 'exists:upazilas,id'],
        ]);

        $dealers = Dealer::query()
            ->with([
                'company:id,name',
                'depot:id,name,depot_code,district_id',
                'upazila:id,district_id,name,name_bn',
            ])
            ->when(
                $filters['search'] ?? null,
                function ($query, $search) {
                    $query->where(function ($query) use ($search) {
                        $query
                            ->where('business_name', 'like', "%{$search}%")
                            ->orWhere('proprietor_name', 'like', "%{$search}%")
                            ->orWhere('dealer_code', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
                }
            )
            ->when(
                $filters['depot_id'] ?? null,
                fn ($query, $depotId) =>
                    $query->where('depot_id', $depotId)
            )
            ->when(
                $filters['upazila_id'] ?? null,
                fn ($query, $upazilaId) =>
                    $query->where('upazila_id', $upazilaId)
            )
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('ERP/CRM/Dealers/Index', [
            'dealers' => $dealers,
            'filters' => $filters,
        ]);
    }

    public function show(Dealer $dealer): Response
    {
        $dealer->load([
            'company:id,name',
            'depot:id,name,depot_code,district_id',
            'depot.district:id,division_id,name,name_bn',
            'depot.district.division:id,name,name_bn',
            'upazila:id,district_id,name,name_bn',
            'manager:id,name,email',
            'applications' => fn ($query) => $query->latest(),
            'currentDocuments' => fn ($query) =>
                $query
                    ->with([
                        'verifier:id,name',
                        'uploader:id,name',
                    ])
                    ->orderBy('document_type'),
        ]);

        return Inertia::render('ERP/CRM/Dealers/Show', [
            'dealer' => $dealer,
        ]);
    }

    public function edit(Dealer $dealer): Response
    {
        $dealer->load([
            'company:id,name',
            'depot:id,company_id,district_id,name,depot_code',
            'depot.company:id,name',
            'depot.district:id,division_id,name,name_bn',
            'depot.district.division:id,name,name_bn',
            'upazila:id,district_id,name,name_bn',
            'manager:id,name,email',
            'currentDocuments' => fn ($query) =>
                $query
                    ->with([
                        'verifier:id,name',
                        'uploader:id,name',
                    ])
                    ->orderBy('document_type'),
        ]);

        $depots = Depot::query()
            ->where(function ($query) use ($dealer) {
                $query
                    ->where('is_active', true)
                    ->orWhere('id', $dealer->depot_id);
            })
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
                'is_active',
            ]);

        $districtIds = $depots
            ->pluck('district_id')
            ->filter()
            ->unique()
            ->values();

        $upazilas = Upazila::query()
            ->whereIn('district_id', $districtIds)
            ->where(function ($query) use ($dealer) {
                $query
                    ->where('is_active', true)
                    ->orWhere('id', $dealer->upazila_id);
            })
            ->orderBy('name')
            ->get([
                'id',
                'district_id',
                'name',
                'name_bn',
                'code',
                'is_active',
            ]);

        $managers = User::query()
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'email',
            ]);

        return Inertia::render('ERP/CRM/Dealers/Edit', [
            'dealer' => $dealer,
            'depots' => $depots,
            'upazilas' => $upazilas,
            'managers' => $managers,
        ]);
    }

    public function update(
        Request $request,
        Dealer $dealer
    ): RedirectResponse {
        $validated = $request->validate([
            'depot_id' => [
                'required',
                'integer',
                Rule::exists('depots', 'id')->where(
                    fn ($query) => $query->whereNull('deleted_at')
                ),
            ],

            'upazila_id' => [
                'required',
                'integer',
                'exists:upazilas,id',
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
                'max:30',
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
            $dealer->getAttributes();

        $depot = Depot::query()
            ->whereKey($validated['depot_id'])
            ->whereNull('deleted_at')
            ->firstOrFail();

        $upazila = Upazila::query()
            ->whereKey($validated['upazila_id'])
            ->firstOrFail();

        if (
            $upazila->district_id !==
                $depot->district_id
        ) {
            return back()
                ->withErrors([
                    'upazila_id' =>
                        'The selected Upazila does not belong to the selected Depot district.',
                ])
                ->withInput();
        }

        /*
         * Never trust company_id from the browser.
         * Company ownership comes from the selected Depot.
         */
        $validated['company_id'] =
            $depot->company_id;

        if (
            $request->hasFile('proprietor_photo')
        ) {
            /** @var FilesystemAdapter $disk */
            $disk = Storage::disk('local');

            $oldPath =
                $dealer->proprietor_photo;

            $newPath = $request
                ->file('proprietor_photo')
                ->store(
                    'erp/dealers/proprietors',
                    'local'
                );

            $validated['proprietor_photo'] =
                $newPath;

            if (
                $oldPath &&
                $disk->exists($oldPath)
            ) {
                $disk->delete($oldPath);
            }
        } else {
            unset($validated['proprietor_photo']);
        }

        /*
         * dealer_code is intentionally preserved.
         * It is not part of this edit form.
         */
        $dealer->update($validated);

        $dealer->refresh();

        app(
            MasterProfileActivityLogger::class
        )->recordProfileUpdate(
            subject:
                $dealer,

            actorId:
                $request->user()?->id,

            before:
                $before,

            after:
                $dealer->getAttributes(),

            fields: [
                'company_id',
                'depot_id',
                'upazila_id',
                'business_name',
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
                'Dealer master profile updated by administrator.'
        );

        return redirect()
            ->route(
                'erp.dealers.show',
                $dealer
            )
            ->with(
                'success',
                'Dealer updated successfully.'
            );
    }

    public function proprietorPhoto(
        Dealer $dealer
    ): StreamedResponse {
        abort_unless(
            $dealer->proprietor_photo,
            404
        );

        /** @var FilesystemAdapter $disk */
        $disk =
            Storage::disk('local');

        abort_unless(
            $disk->exists(
                $dealer->proprietor_photo
            ),
            404
        );

        return $disk->response(
            $dealer->proprietor_photo
        );
    }
}

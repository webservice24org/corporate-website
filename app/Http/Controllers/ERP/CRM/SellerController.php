<?php

namespace App\Http\Controllers\ERP\CRM;

use App\Http\Controllers\Controller;
use App\Models\Dealer;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SellerController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'dealer_id' => ['nullable', 'integer'],
            'status' => [
                'nullable',
                Rule::in([
                    'active',
                    'inactive',
                ]),
            ],
        ]);

        $sellers = Seller::query()
            ->with([
                'company:id,name',
                'dealer:id,company_id,depot_id,upazila_id,business_name,dealer_code',
                'dealer.depot:id,name,district_id',
                'dealer.depot.district:id,division_id,name',
                'dealer.depot.district.division:id,name',
                'dealer.upazila:id,district_id,name,name_bn',
                'manager:id,name,email',
            ])
            ->when(
                $filters['search'] ?? null,
                function (Builder $query, string $search) {
                    $query->where(function (Builder $query) use ($search) {
                        $query
                            ->where(
                                'business_name',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'proprietor_name',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'seller_code',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'phone',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'email',
                                'like',
                                "%{$search}%"
                            );
                    });
                }
            )
            ->when(
                $filters['dealer_id'] ?? null,
                fn(
                Builder $query,
                int $dealerId
            ) => $query->where(
                        'dealer_id',
                        $dealerId
                    )
            )
            ->when(
                ($filters['status'] ?? null)
                === 'active',
                fn(Builder $query) => $query->where(
                    'is_active',
                    true
                )
            )
            ->when(
                ($filters['status'] ?? null)
                === 'inactive',
                fn(Builder $query) => $query->where(
                    'is_active',
                    false
                )
            )
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $dealers = Dealer::query()
            ->with([
                'depot:id,name',
                'upazila:id,name',
            ])
            ->where('is_active', true)
            ->orderBy('business_name')
            ->get([
                'id',
                'company_id',
                'depot_id',
                'upazila_id',
                'dealer_code',
                'business_name',
            ]);

        return Inertia::render(
            'ERP/CRM/Sellers/Index',
            [
                'sellers' => $sellers,
                'dealers' => $dealers,
                'filters' => [
                    'search' => $filters['search'] ?? '',
                    'dealer_id' => $filters['dealer_id'] ?? '',
                    'status' => $filters['status'] ?? '',
                ],
            ]
        );
    }

    public function create(): Response
    {
        return Inertia::render(
            'ERP/CRM/Sellers/Create',
            $this->formOptions()
        );
    }

    public function store(
        Request $request
    ): RedirectResponse {
        $validated =
            $this->validateSeller(
                $request
            );

        $dealer = Dealer::query()
            ->whereKey(
                $validated['dealer_id']
            )
            ->where('is_active', true)
            ->firstOrFail();

        /*
         * Company must always come from Dealer.
         * Do not trust a company ID from the frontend.
         */
        $validated['company_id'] =
            $dealer->company_id;

        /*
         * Centralized document numbering
         * will generate this later.
         */
        $validated['seller_code'] =
            null;

        if ($request->hasFile('proprietor_photo')) {
            $validated['proprietor_photo'] =
                $request
                    ->file('proprietor_photo')
                    ->store(
                        'erp/sellers/proprietors',
                        'local'
                    );
        }

        $seller = Seller::create(
            $validated
        );

        return redirect()
            ->route(
                'erp.sellers.show',
                $seller
            )
            ->with(
                'success',
                'Seller created successfully.'
            );
    }

    public function show(
        Seller $seller
    ): Response {
        $seller->load([
            'company:id,name',
            'dealer:id,company_id,depot_id,upazila_id,business_name,dealer_code,proprietor_name,phone',
            'dealer.depot:id,name,depot_code,district_id',
            'dealer.depot.district:id,division_id,name',
            'dealer.depot.district.division:id,name',
            'dealer.upazila:id,district_id,name,name_bn',
            'manager:id,name,email',
        ]);

        return Inertia::render(
            'ERP/CRM/Sellers/Show',
            [
                'seller' => $seller,
            ]
        );
    }

    public function edit(
        Seller $seller
    ): Response {
        $seller->load([
            'dealer:id,company_id,depot_id,upazila_id,business_name,dealer_code',
        ]);

        return Inertia::render(
            'ERP/CRM/Sellers/Edit',
            [
                'seller' => $seller,
                ...$this->formOptions(
                    $seller
                ),
            ]
        );
    }

    public function update(
        Request $request,
        Seller $seller
    ): RedirectResponse {
        $validated =
            $this->validateSeller(
                $request
            );

        $dealer = Dealer::query()
            ->whereKey(
                $validated['dealer_id']
            )
            ->where('is_active', true)
            ->firstOrFail();

        $validated['company_id'] =
            $dealer->company_id;

        /*
         * Preserve seller_code.
         * It will later be managed by
         * centralized DocumentSequence.
         */
        unset(
            $validated['seller_code']
        );

        if ($request->hasFile('proprietor_photo')) {
            if (
                $seller->proprietor_photo &&
                Storage::disk('local')->exists(
                    $seller->proprietor_photo
                )
            ) {
                Storage::disk('local')->delete(
                    $seller->proprietor_photo
                );
            }

            $validated['proprietor_photo'] =
                $request
                    ->file('proprietor_photo')
                    ->store(
                        'erp/sellers/proprietors',
                        'local'
                    );
        } else {
            /*
             * Validation includes proprietor_photo as nullable,
             * so the validated array may contain null.
             * Remove it when no replacement file was submitted
             * to preserve the seller's existing picture.
             */
            unset(
                $validated['proprietor_photo']
            );
        }

        $seller->update(
            $validated
        );

        return redirect()
            ->route(
                'erp.sellers.show',
                $seller
            )
            ->with(
                'success',
                'Seller updated successfully.'
            );
    }

    public function destroy(
        Seller $seller
    ): RedirectResponse {
        /*
         * Once Sales/Inventory references Sellers,
         * we will strengthen this with dependency
         * checks instead of relying only on soft delete.
         */
        $seller->delete();

        return redirect()
            ->route(
                'erp.sellers.index'
            )
            ->with(
                'success',
                'Seller deleted successfully.'
            );
    }

    private function validateSeller(
        Request $request
    ): array {
        return $request->validate([
            'dealer_id' => [
                'required',
                'integer',
                Rule::exists(
                    'dealers',
                    'id'
                )->where(
                        fn($query) => $query
                            ->where(
                                'is_active',
                                true
                            )
                            ->whereNull(
                                'deleted_at'
                            )
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

            'proprietor_photo' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],

            'seller_type' => [
                'required',
                Rule::in([
                    'retailer',
                    'general_seller',
                ]),
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
    }

    private function formOptions(
        ?Seller $seller = null
    ): array {
        $dealers = Dealer::query()
            ->with([
                'company:id,name',
                'depot:id,name,depot_code,district_id',
                'depot.district:id,name',
                'upazila:id,name,name_bn',
            ])
            ->where(function (Builder $query) use ($seller) {
                $query->where(
                    'is_active',
                    true
                );

                if ($seller) {
                    $query->orWhere(
                        'id',
                        $seller->dealer_id
                    );
                }
            })
            ->orderBy(
                'business_name'
            )
            ->get([
                'id',
                'company_id',
                'depot_id',
                'upazila_id',
                'dealer_code',
                'business_name',
                'is_active',
            ]);

        $managers = User::query()
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'email',
            ]);

        return [
            'dealers' => $dealers,
            'managers' => $managers,
        ];
    }

    public function proprietorPhoto(
        Seller $seller
    ): StreamedResponse {
        abort_unless(
            $seller->proprietor_photo !== null
            && $seller->proprietor_photo !== '',
            404
        );

        /** @var FilesystemAdapter $disk */
        $disk =
            Storage::disk(
                'local'
            );

        abort_unless(
            $disk->exists(
                $seller->proprietor_photo
            ),
            404
        );

        return $disk->response(
            $seller->proprietor_photo
        );
    }
}

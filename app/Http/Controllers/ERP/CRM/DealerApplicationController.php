<?php

namespace App\Http\Controllers\ERP\CRM;

use App\Http\Controllers\Controller;
use App\Models\DealerApplication;
use App\Models\DealerApplicationDocument;
use App\Services\ERP\CRM\DealerApplicationApprovalService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DealerApplicationController extends Controller
{
    public function index(
        Request $request
    ): Response {
        $filters = $request->validate([
            'search' => [
                'nullable',
                'string',
                'max:255',
            ],

            'status' => [
                'nullable',
                Rule::in([
                    'pending',
                    'under_review',
                    'approved',
                    'rejected',
                ]),
            ],

            'depot_id' => [
                'nullable',
                'integer',
                'exists:depots,id',
            ],
        ]);

        $applications =
            DealerApplication::query()
                ->with([
                    'company:id,name',

                    'depot:id,name,depot_code,district_id',

                    'upazila:id,district_id,name,name_bn',
                ])

                ->when(
                    $filters['search'] ?? null,
                    function (
                        $query,
                        $search
                    ) {
                        $query->where(
                            function ($query) use ($search) {
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
                                        'phone',
                                        'like',
                                        "%{$search}%"
                                    )
                                    ->orWhere(
                                        'email',
                                        'like',
                                        "%{$search}%"
                                    );
                            }
                        );
                    }
                )

                ->when(
                    $filters['status'] ?? null,
                    fn ($query, $status) => $query->where(
                        'status',
                        $status
                    )
                )

                ->when(
                    $filters['depot_id'] ?? null,
                    fn ($query, $depotId) => $query->where(
                        'depot_id',
                        $depotId
                    )
                )

                ->latest()
                ->paginate(20)
                ->withQueryString();

        return Inertia::render(
            'ERP/CRM/DealerApplications/Index',
            [
                'applications' => $applications,

                'filters' => $filters,
            ]
        );
    }

    public function show(
        DealerApplication $dealerApplication
    ): Response {
        $dealerApplication->load([
            'company:id,name',

            'depot:id,name,depot_code,district_id',

            'depot.district:id,division_id,name,name_bn',

            'depot.district.division:id,name,name_bn',

            'upazila:id,district_id,name,name_bn',

            'documents.verifiedBy:id,name,email',

            'reviewedBy:id,name,email',

            'approvedBy:id,name,email',

            'rejectedBy:id,name,email',

            'dealer:id,business_name,dealer_code',
        ]);

        return Inertia::render(
            'ERP/CRM/DealerApplications/Show',
            [
                'application' => $dealerApplication,
            ]
        );
    }

    public function markUnderReview(
        Request $request,
        DealerApplication $dealerApplication
    ): RedirectResponse {
        if (
            $dealerApplication->status !==
            'pending'
        ) {
            return back()->withErrors([
                'application' => 'Only pending applications can be moved to Under Review.',
            ]);
        }

        $dealerApplication->update([
            'status' => 'under_review',

            'reviewed_by_user_id' => $request->user()->id,

            'reviewed_at' => now(),
        ]);

        return back()->with(
            'success',
            'Dealer application marked as under review.'
        );
    }

    public function approve(
        Request $request,
        DealerApplication $dealerApplication,
        DealerApplicationApprovalService $service
    ): RedirectResponse {
        $validated = $request->validate([
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

            'admin_notes' => [
                'nullable',
                'string',
                'max:10000',
            ],
        ]);

        $dealer = $service->approve(
            $dealerApplication,
            $request->user(),
            $validated
        );

        return redirect()
            ->route(
                'erp.dealers.show',
                $dealer
            )
            ->with(
                'success',
                'Dealer application approved successfully.'
            );
    }

    public function reject(
        Request $request,
        DealerApplication $dealerApplication,
        DealerApplicationApprovalService $service
    ): RedirectResponse {
        $validated = $request->validate([
            'rejection_reason' => [
                'required',
                'string',
                'max:5000',
            ],

            'admin_notes' => [
                'nullable',
                'string',
                'max:10000',
            ],
        ]);

        $service->reject(
            $dealerApplication,
            $request->user(),
            $validated['rejection_reason'],
            $validated['admin_notes'] ??
                null
        );

        return back()->with(
            'success',
            'Dealer application rejected.'
        );
    }

    public function verifyDocument(
        Request $request,
        DealerApplication $dealerApplication,
        DealerApplicationDocument $document
    ): RedirectResponse {
        abort_unless(
            $document->dealer_application_id ===
                $dealerApplication->id,
            404
        );

        $document->update([
            'verified_by_user_id' => $request->user()->id,

            'verified_at' => now(),
        ]);

        return back()->with(
            'success',
            'Document verified successfully.'
        );
    }

    public function downloadDocument(
        DealerApplication $dealerApplication,
        DealerApplicationDocument $document
    ): StreamedResponse {
        abort_unless(
            $document->dealer_application_id === $dealerApplication->id,
            404
        );

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($document->disk);

        abort_unless(
            $disk->exists($document->path),
            404
        );

        return $disk->download(
            $document->path,
            $document->original_name
        );
    }
}

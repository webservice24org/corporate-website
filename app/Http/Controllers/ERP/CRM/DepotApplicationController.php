<?php

namespace App\Http\Controllers\ERP\CRM;

use App\Http\Controllers\Controller;
use App\Models\DepotApplication;
use App\Models\DepotApplicationDocument;
use App\Services\ERP\CRM\DepotApplicationApprovalService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DepotApplicationController extends Controller
{
    public function index(
        Request $request
    ): Response {
        $filters =
            $request->validate([
                'status' => [
                    'nullable',
                    Rule::in([
                        'pending',
                        'under_review',
                        'approved',
                        'rejected',
                    ]),
                ],

                'search' => [
                    'nullable',
                    'string',
                    'max:255',
                ],
            ]);

        $applications =
            DepotApplication::query()
                ->with([
                    'company:id,name',
                    'division:id,name',
                    'district:id,name',
                    'depot:id,name,depot_code',
                ])

                ->when(
                    $filters['status']
                    ?? null,
                    fn ($query, $status) => $query->where(
                        'status',
                        $status
                    )
                )

                ->when(
                    $filters['search']
                    ?? null,
                    function (
                        $query,
                        $search
                    ) {
                        $query->where(
                            function (
                                $query
                            ) use (
                                $search
                            ) {
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

                ->latest()
                ->paginate(20)
                ->withQueryString();

        return Inertia::render(
            'ERP/CRM/DepotApplications/Index',
            [
                'applications' => $applications,

                'filters' => $filters,
            ]
        );
    }

    public function show(
        DepotApplication $depotApplication
    ): Response {
        $depotApplication->load([
            'company:id,name',
            'division:id,name',
            'district:id,name',
            'depot:id,name,depot_code',
            'documents.verifiedBy:id,name',
            'reviewedBy:id,name',
            'approvedBy:id,name',
            'rejectedBy:id,name',
        ]);

        return Inertia::render(
            'ERP/CRM/DepotApplications/Show',
            [
                'application' => $depotApplication,
            ]
        );
    }

    public function markUnderReview(
        Request $request,
        DepotApplication $depotApplication
    ): RedirectResponse {
        if (
            $depotApplication->status !==
            'pending'
        ) {
            return back()->withErrors([
                'application' => 'Only pending applications can be marked under review.',
            ]);
        }

        $depotApplication->update([
            'status' => 'under_review',

            'reviewed_by_user_id' => $request->user()->id,

            'reviewed_at' => now(),
        ]);

        return back()->with(
            'success',
            'Depot application marked as under review.'
        );
    }

    public function approve(
        Request $request,
        DepotApplication $depotApplication,
        DepotApplicationApprovalService $service
    ): RedirectResponse {
        $validated =
            $request->validate([
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
                    Rule::exists(
                        'users',
                        'id'
                    ),
                ],

                'admin_notes' => [
                    'nullable',
                    'string',
                    'max:10000',
                ],
            ]);

        $depot =
            $service->approve(
                $depotApplication,
                $request->user(),
                $validated
            );

        return redirect()
            ->route(
                'erp.depots.show',
                $depot
            )
            ->with(
                'success',
                'Depot application approved and Depot created successfully.'
            );
    }

    public function reject(
        Request $request,
        DepotApplication $depotApplication,
        DepotApplicationApprovalService $service
    ): RedirectResponse {
        $validated =
            $request->validate([
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
            $depotApplication,
            $request->user(),
            $validated[
                'rejection_reason'
            ],
            $validated[
                'admin_notes'
            ] ?? null
        );

        return back()->with(
            'success',
            'Depot application rejected.'
        );
    }

    public function downloadDocument(
        DepotApplication $depotApplication,
        DepotApplicationDocument $document
    ): StreamedResponse {
        abort_unless(
            $document->depot_application_id === $depotApplication->id,
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

    public function verifyDocument(
        Request $request,
        DepotApplication $depotApplication,
        DepotApplicationDocument $document
    ): RedirectResponse {
        abort_unless(
            $document->depot_application_id ===
                $depotApplication->id,
            404
        );

        $document->update([
            'verified_by_user_id' => $request->user()->id,

            'verified_at' => now(),
        ]);

        return back()->with(
            'success',
            'Document verified.'
        );
    }
}

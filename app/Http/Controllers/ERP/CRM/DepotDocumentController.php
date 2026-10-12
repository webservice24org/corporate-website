<?php

namespace App\Http\Controllers\ERP\CRM;

use App\Http\Controllers\Controller;
use App\Models\Depot;
use App\Models\DepotDocument;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DepotDocumentController extends Controller
{
    private const DOCUMENT_TYPES = [
        'bank_check',
        'trade_license',
        'nid',
        'tin',
        'vat',
        'bank_statement',
        'other',
    ];

    public function index(
        Depot $depot
    ): Response {
        $depot->load([
            'company:id,name',
            'division:id,name',
            'district:id,name',
        ]);

        $documents =
            $depot->documents()
                ->with([
                    'verifier:id,name',
                    'uploader:id,name',
                ])
                ->orderBy('document_type')
                ->orderByDesc('version')
                ->get();

        return Inertia::render(
            'ERP/CRM/Depots/Documents/Index',
            [
                'depot' => $depot,
                'documents' => $documents,
                'documentTypes' => self::DOCUMENT_TYPES,
            ]
        );
    }

    public function store(
        Request $request,
        Depot $depot
    ): RedirectResponse {
        $validated =
            $request->validate([
                'document_type' => [
                    'required',
                    'string',
                    Rule::in(
                        self::DOCUMENT_TYPES
                    ),
                ],

                'title' => [
                    'nullable',
                    'string',
                    'max:255',
                ],

                'file' => [
                    'required',
                    'file',
                    'mimes:pdf,jpg,jpeg,png,webp',
                    'max:5120',
                ],

                'notes' => [
                    'nullable',
                    'string',
                    'max:5000',
                ],
            ]);

        $file =
            $request->file('file');

        DB::transaction(
            function () use (
                $depot,
                $validated,
                $file,
                $request
            ) {
                $type =
                    $validated[
                        'document_type'
                    ];

                $latestVersion =
                    DepotDocument::query()
                        ->where(
                            'depot_id',
                            $depot->id
                        )
                        ->where(
                            'document_type',
                            $type
                        )
                        ->lockForUpdate()
                        ->max('version');

                $nextVersion =
                    ((int) $latestVersion) +
                    1;

                DepotDocument::query()
                    ->where(
                        'depot_id',
                        $depot->id
                    )
                    ->where(
                        'document_type',
                        $type
                    )
                    ->where(
                        'is_current',
                        true
                    )
                    ->update([
                        'is_current' => false,
                    ]);

                $path =
                    $file->store(
                        "erp/depots/{$depot->id}/documents/{$type}",
                        'local'
                    );

                DepotDocument::create([
                    'depot_id' => $depot->id,

                    'document_type' => $type,

                    'title' => $validated[
                            'title'
                        ] ?? null,

                    'disk' => 'local',

                    'file_path' => $path,

                    'original_name' => $file
                        ->getClientOriginalName(),

                    'mime_type' => $file
                        ->getClientMimeType(),

                    'file_size' => $file->getSize(),

                    'version' => $nextVersion,

                    'is_current' => true,

                    /*
                     * A replacement must be
                     * verified again.
                     */
                    'verified_at' => null,

                    'verified_by' => null,

                    'uploaded_by' => $request
                        ->user()
                        ?->id,

                    'notes' => $validated[
                            'notes'
                        ] ?? null,
                ]);
            }
        );

        return back()->with(
            'success',
            'Depot document uploaded successfully.'
        );
    }

    public function verify(
        Request $request,
        Depot $depot,
        DepotDocument $document
    ): RedirectResponse {
        $this->ensureBelongsToDepot(
            $depot,
            $document
        );

        $document->update([
            'verified_at' => now(),

            'verified_by' => $request
                ->user()
                ?->id,
        ]);

        return back()->with(
            'success',
            'Depot document verified.'
        );
    }

    public function unverify(
        Depot $depot,
        DepotDocument $document
    ): RedirectResponse {
        $this->ensureBelongsToDepot(
            $depot,
            $document
        );

        $document->update([
            'verified_at' => null,

            'verified_by' => null,
        ]);

        return back()->with(
            'success',
            'Depot document verification removed.'
        );
    }

    public function download(
        Depot $depot,
        DepotDocument $document
    ): StreamedResponse {
        $this->ensureBelongsToDepot(
            $depot,
            $document
        );

        /** @var FilesystemAdapter $disk */
        $disk =
            Storage::disk(
                $document->disk
                    ?: 'local'
            );

        abort_unless(
            $disk->exists(
                $document->file_path
            ),
            404
        );

        return $disk->download(
            $document->file_path,
            $document->original_name
                ?: basename(
                    $document->file_path
                )
        );
    }

    public function preview(
        Depot $depot,
        DepotDocument $document
    ): StreamedResponse {
        $this->ensureBelongsToDepot(
            $depot,
            $document
        );

        /** @var FilesystemAdapter $disk */
        $disk =
            Storage::disk(
                $document->disk
                    ?: 'local'
            );

        abort_unless(
            $disk->exists(
                $document->file_path
            ),
            404
        );

        return $disk->response(
            $document->file_path
        );
    }

    private function ensureBelongsToDepot(
        Depot $depot,
        DepotDocument $document
    ): void {
        abort_unless(
            $document->depot_id ===
                $depot->id,
            404
        );
    }

    public function remove(
        Depot $depot,
        DepotDocument $document
    ): RedirectResponse {
        $this->ensureBelongsToDepot(
            $depot,
            $document
        );

        DB::transaction(
            function () use (
                $depot,
                $document
            ) {
                $wasCurrent =
                    $document->is_current;

                /*
                * Preserve the document and file as history.
                */
                $document->update([
                    'is_current' => false,
                ]);

                /*
                * If we removed the current version,
                * promote the newest previous version.
                */
                if ($wasCurrent) {
                    $previous =
                        DepotDocument::query()
                            ->where(
                                'depot_id',
                                $depot->id
                            )
                            ->where(
                                'document_type',
                                $document->document_type
                            )
                            ->where(
                                'id',
                                '!=',
                                $document->id
                            )
                            ->orderByDesc(
                                'version'
                            )
                            ->first();

                    if ($previous) {
                        $previous->update([
                            'is_current' => true,
                        ]);
                    }
                }
            }
        );

        return back()->with(
            'success',
            'Depot document removed from current documents. History has been preserved.'
        );
    }
}

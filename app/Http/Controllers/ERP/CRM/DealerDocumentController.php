<?php

namespace App\Http\Controllers\ERP\CRM;

use App\Http\Controllers\Controller;
use App\Models\Dealer;
use App\Models\DealerDocument;
use App\Services\ERP\MasterProfileActivityLogger;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DealerDocumentController extends Controller
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

    public function index(Dealer $dealer): Response
    {
        $dealer->load([
            'company:id,name',
            'depot:id,name,depot_code,district_id',
            'depot.district:id,division_id,name,name_bn',
            'depot.district.division:id,name,name_bn',
            'upazila:id,district_id,name,name_bn',
        ]);

        $documents = $dealer->documents()
            ->with([
                'verifier:id,name',
                'uploader:id,name',
            ])
            ->orderBy('document_type')
            ->orderByDesc('version')
            ->get();

        return Inertia::render(
            'ERP/CRM/Dealers/Documents/Index',
            [
                'dealer' => $dealer,
                'documents' => $documents,
                'documentTypes' => self::DOCUMENT_TYPES,
            ]
        );
    }

    public function store(
        Request $request,
        Dealer $dealer
    ): RedirectResponse {
        $validated = $request->validate([
            'document_type' => [
                'required',
                'string',
                Rule::in(self::DOCUMENT_TYPES),
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

        $file = $request->file('file');

        DB::transaction(function () use (
            $dealer,
            $validated,
            $file,
            $request
        ) {
            $type = $validated['document_type'];

            $latestVersion = DealerDocument::query()
                ->where('dealer_id', $dealer->id)
                ->where('document_type', $type)
                ->lockForUpdate()
                ->max('version');

            $nextVersion = ((int) $latestVersion) + 1;

            DealerDocument::query()
                ->where('dealer_id', $dealer->id)
                ->where('document_type', $type)
                ->where('is_current', true)
                ->update([
                    'is_current' => false,
                ]);

            $path = $file->store(
                "erp/dealers/{$dealer->id}/documents/{$type}",
                'local'
            );

            $document =
                DealerDocument::create([
                    'dealer_id' => $dealer->id,

                    'document_type' => $type,

                    'title' => $validated['title'] ?? null,

                    'disk' => 'local',

                    'file_path' => $path,

                    'original_name' => $file->getClientOriginalName(),

                    'mime_type' => $file->getClientMimeType(),

                    'file_size' => $file->getSize(),

                    'version' => $nextVersion,

                    'is_current' => true,

                    'verified_at' => null,

                    'verified_by' => null,

                    'uploaded_by' => $request->user()?->id,

                    'notes' => $validated['notes'] ?? null,
                ]);

            app(
                MasterProfileActivityLogger::class
            )->record(
                subject: $dealer,

                actorId: $request->user()?->id,

                action: 'document.uploaded',

                source: 'admin',

                description: $nextVersion > 1
                        ? 'Dealer document replaced with a new version.'
                        : 'Dealer document uploaded.',

                meta: [
                    'document_id' => $document->id,

                    'document_type' => $document->document_type,

                    'version' => $document->version,

                    'original_name' => $document->original_name,
                ],

                request: $request
            );
        });

        return back()->with(
            'success',
            'Dealer document uploaded successfully.'
        );
    }

    public function verify(
        Request $request,
        Dealer $dealer,
        DealerDocument $document
    ): RedirectResponse {
        $this->ensureBelongsToDealer(
            $dealer,
            $document
        );

        $document->update([
            'verified_at' => now(),
            'verified_by' => $request->user()?->id,
        ]);

        app(
            MasterProfileActivityLogger::class
        )->record(
            subject: $dealer,

            actorId: $request->user()?->id,

            action: 'document.verified',

            source: 'admin',

            description: 'Dealer document verified.',

            meta: [
                'document_id' => $document->id,

                'document_type' => $document->document_type,

                'version' => $document->version,

                'original_name' => $document->original_name,
            ],

            request: $request
        );

        return back()->with(
            'success',
            'Dealer document verified.'
        );
    }

    public function unverify(
        Request $request,
        Dealer $dealer,
        DealerDocument $document
    ): RedirectResponse {
        $this->ensureBelongsToDealer(
            $dealer,
            $document
        );

        $document->update([
            'verified_at' => null,
            'verified_by' => null,
        ]);

        app(
            MasterProfileActivityLogger::class
        )->record(
            subject: $dealer,

            actorId: $request->user()?->id,

            action: 'document.unverified',

            source: 'admin',

            description: 'Dealer document verification removed.',

            meta: [
                'document_id' => $document->id,

                'document_type' => $document->document_type,

                'version' => $document->version,

                'original_name' => $document->original_name,
            ],

            request: $request
        );

        return back()->with(
            'success',
            'Dealer document verification removed.'
        );
    }

    public function remove(
        Request $request,
        Dealer $dealer,
        DealerDocument $document
    ): RedirectResponse {
        $this->ensureBelongsToDealer(
            $dealer,
            $document
        );

        DB::transaction(function () use (
            $dealer,
            $document
        ) {
            $wasCurrent = $document->is_current;

            $document->update([
                'is_current' => false,
            ]);

            if ($wasCurrent) {
                $previous = DealerDocument::query()
                    ->where('dealer_id', $dealer->id)
                    ->where(
                        'document_type',
                        $document->document_type
                    )
                    ->where('id', '!=', $document->id)
                    ->orderByDesc('version')
                    ->first();

                if ($previous) {
                    DealerDocument::query()
                        ->where('dealer_id', $dealer->id)
                        ->where(
                            'document_type',
                            $document->document_type
                        )
                        ->update([
                            'is_current' => false,
                        ]);

                    $previous->update([
                        'is_current' => true,
                    ]);
                }
            }
        });

        app(
            MasterProfileActivityLogger::class
        )->record(
            subject: $dealer,

            actorId: $request->user()?->id,

            action: 'document.removed',

            source: 'admin',

            description: 'Dealer document removed from current use. Version history was preserved.',

            meta: [
                'document_id' => $document->id,

                'document_type' => $document->document_type,

                'version' => $document->version,

                'original_name' => $document->original_name,
            ],

            request: $request
        );

        return back()->with(
            'success',
            'Dealer document removed from current documents. Version history has been preserved.'
        );
    }

    public function preview(
        Dealer $dealer,
        DealerDocument $document
    ): StreamedResponse {
        $this->ensureBelongsToDealer(
            $dealer,
            $document
        );

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk(
            $document->disk ?: 'local'
        );

        abort_unless(
            $disk->exists($document->file_path),
            404
        );

        return $disk->response(
            $document->file_path
        );
    }

    public function download(
        Dealer $dealer,
        DealerDocument $document
    ): StreamedResponse {
        $this->ensureBelongsToDealer(
            $dealer,
            $document
        );

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk(
            $document->disk ?: 'local'
        );

        abort_unless(
            $disk->exists($document->file_path),
            404
        );

        return $disk->download(
            $document->file_path,
            $document->original_name
                ?: basename($document->file_path)
        );
    }

    private function ensureBelongsToDealer(
        Dealer $dealer,
        DealerDocument $document
    ): void {
        abort_unless(
            $document->dealer_id === $dealer->id,
            404
        );
    }
}

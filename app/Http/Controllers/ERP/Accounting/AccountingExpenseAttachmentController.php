<?php

declare(strict_types=1);

namespace App\Http\Controllers\ERP\Accounting;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ERP\Accounting\Expenses\AccountingExpenseAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AccountingExpenseAttachmentController extends Controller
{
    public function __construct(private readonly AccountingExpenseAttachmentService $service) {}

    public function index(Request $request, int $expense): JsonResponse
    {
        $this->keys($request, []);

        return response()->json($this->service->listing($this->actor($request), $expense));
    }

    public function store(Request $request, int $expense): JsonResponse
    {
        $this->keys($request, ['revision', 'key', 'reason', 'file']);

        $data = Validator::make($request->all(), ['revision' => ['required', 'integer', 'min:1'], 'key' => ['required', 'string'], 'reason' => ['required', 'string', 'max:2000'], 'file' => ['required', 'file', 'max:10240']])->validate();

        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => 'Choose one supporting file.']);
        }

        $uploaded = $this->service->upload($this->actor($request), $expense, (int) $data['revision'], $data['key'], $data['reason'], $file);

        return response()->json(['attachment_id' => (int) $uploaded->getKey(), ...$this->service->listing($this->actor($request), $expense)]);
    }

    public function withdraw(Request $request, int $expense, int $attachment): JsonResponse
    {
        $this->keys($request, ['revision', 'reason']);

        $data = Validator::make($request->all(), ['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000']])->validate();

        $this->service->withdraw($this->actor($request), $expense, $attachment, (int) $data['revision'], $data['reason']);

        return response()->json($this->service->listing($this->actor($request), $expense));
    }

    public function download(Request $request, int $expense, int $attachment): StreamedResponse
    {
        $this->keys($request, []);

        return $this->service->download($this->actor($request), $expense, $attachment);
    }

    /** @param list<string> $allowed */
    private function keys(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw ValidationException::withMessages(['attachment' => 'Only the documented upload/review fields are accepted. Storage paths, URLs and ownership metadata cannot be supplied.']);
        }
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}

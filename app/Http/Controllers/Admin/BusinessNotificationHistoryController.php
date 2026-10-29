<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessNotificationDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class BusinessNotificationHistoryController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'processing', 'submitted', 'blocked', 'failed', 'uncertain', 'skipped', 'cancelled'])],
            'channel' => ['nullable', Rule::in(['email', 'sms'])],
        ]);
        $query = BusinessNotificationDelivery::query()->orderByDesc('id');
        foreach (['status', 'channel'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        $page = $query->paginate(25)->withQueryString();
        $rows = [];
        foreach ($page->items() as $row) {
            try {
                $recipient = (string) $row->getAttribute('recipient');
                $mask = $recipient === '' ? 'Not recorded' : '••••'.mb_substr($recipient, -4);
            } catch (Throwable $exception) {
                $mask = 'Unreadable encrypted contact';
            }
            $rows[] = [
                'id' => (int) $row->getKey(), 'company_id' => (int) $row->getAttribute('company_id'),
                'event' => (string) $row->getAttribute('event'), 'source_id' => (int) $row->getAttribute('source_id'),
                'audience' => (string) $row->getAttribute('audience'), 'channel' => (string) $row->getAttribute('channel'),
                'recipient' => $mask, 'status' => (string) $row->getAttribute('status'), 'reason' => $row->getAttribute('reason'),
                'attempts' => (int) $row->getAttribute('attempts'), 'provider_id' => $row->getAttribute('provider_id'),
                'created_at' => (string) $row->getAttribute('created_at'),
            ];
        }

        return Inertia::render('Admin/NotificationHistory/Index', [
            'records' => $rows, 'filters' => ['status' => $filters['status'] ?? '', 'channel' => $filters['channel'] ?? ''],
            'pagination' => ['current' => $page->currentPage(), 'last' => $page->lastPage(), 'total' => $page->total(),
                'previous' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
            'canManage' => $request->user()?->can('mail-settings.edit') ?? false,
        ]);
    }

    public function action(Request $request, int $delivery): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['retry', 'cancel'])]]);
        DB::transaction(function () use ($request, $delivery, $data): void {
            $row = BusinessNotificationDelivery::query()->lockForUpdate()->findOrFail($delivery);
            $status = (string) $row->getAttribute('status');
            $allowed = $data['action'] === 'retry' ? ['blocked', 'failed'] : ['pending', 'blocked', 'failed'];
            if (! in_array($status, $allowed, true)) {
                throw ValidationException::withMessages(['delivery' => 'This record cannot be retried or cancelled from its current state. Unknown sends must be checked with the provider.']);
            }
            $row->fill(['status' => $data['action'] === 'retry' ? 'pending' : 'cancelled',
                'reason' => $data['action'] === 'retry' ? null : 'cancelled_by_admin', 'claimed_at' => null])->save();
            DB::table('business_notification_actions')->insert([
                'delivery_id' => $delivery, 'actor_id' => $request->user()?->getKey(), 'action' => $data['action'],
                'previous_status' => $status, 'occurred_at' => now(),
            ]);
        });

        return back()->with('success', 'Notification action saved.');
    }
}

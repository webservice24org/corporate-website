<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ContactMessage;
use App\Models\ContactReply;
use App\Services\DynamicMailConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ContactMessageController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => [
                'nullable',
                'string',
                'in:unread,read,replied,archived',
            ],
        ]);

        $status = $validated['status'] ?? null;

        $messages = ContactMessage::query()
            ->when(
                $status,
                fn ($query) =>
                    $query->where('status', $status)
            )
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $counts = [
            'all' => ContactMessage::count(),

            'unread' => ContactMessage::where(
                'status',
                'unread'
            )->count(),

            'read' => ContactMessage::where(
                'status',
                'read'
            )->count(),

            'replied' => ContactMessage::where(
                'status',
                'replied'
            )->count(),

            'archived' => ContactMessage::where(
                'status',
                'archived'
            )->count(),
        ];

        return inertia(
            'Admin/ContactMessages/Index',
            [
                'messages' => $messages,

                'filters' => [
                    'status' => $status,
                ],

                'counts' => $counts,
            ]
        );
    }

    public function show(
        ContactMessage $contactMessage
    ) {
        if ($contactMessage->status === 'unread') {
            $contactMessage->update([
                'status' => 'read',
                'read_at' => now(),
            ]);
        }

        $contactMessage->load([
            'replies.user',
        ]);

        return inertia(
            'Admin/ContactMessages/Show',
            [
                'message' =>
                    $contactMessage,
            ]
        );
    }

    public function reply(
        Request $request,
        ContactMessage $contactMessage,
        DynamicMailConfig $mailConfig
    ) {
        $validated = $request->validate([
            'subject' => [
                'required',
                'string',
                'max:190',
            ],

            'message' => [
                'required',
                'string',
                'max:10000',
            ],
        ]);

        try {
            /*
            * Apply SMTP settings stored in DB.
            */
            $mailSettings = $mailConfig->apply();

            if (! $mailSettings) {
                return back()->with(
                    'error',
                    'No active mail configuration was found.'
                );
            }

            /*
            * Send first.
            *
            * We only save the reply as "sent"
            * after the mailer completes successfully.
            */
            Mail::raw(
                $validated['message'],
                function ($mail) use (
                    $contactMessage,
                    $validated,
                    $mailSettings
                ) {
                    $mail
                        ->to(
                            $contactMessage->email,
                            $contactMessage->name
                        )
                        ->subject(
                            $validated['subject']
                        );

                    /*
                    * Optional configured Reply-To.
                    */
                    if (
                        $mailSettings->reply_to_address
                    ) {
                        $mail->replyTo(
                            $mailSettings->reply_to_address,
                            $mailSettings->reply_to_name
                        );
                    }
                }
            );

            /*
            * Save reply + update parent message together.
            */
            DB::transaction(function () use (
                $request,
                $contactMessage,
                $validated
            ) {
                ContactReply::create([
                    'contact_message_id' =>
                        $contactMessage->id,

                    'user_id' =>
                        $request->user()?->id,

                    'to_email' =>
                        $contactMessage->email,

                    'subject' =>
                        $validated['subject'],

                    'message' =>
                        $validated['message'],

                    'sent_at' =>
                        now(),
                ]);

                $contactMessage->update([
                    'status' =>
                        'replied',

                    'replied_at' =>
                        now(),

                    /*
                    * Useful if somebody somehow replies
                    * before it has been marked read.
                    */
                    'read_at' =>
                        $contactMessage->read_at
                            ?? now(),
                ]);
            });

            return back()->with(
                'success',
                'Reply sent successfully.'
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()->with(
                'error',
                'Reply could not be sent. Please check your mail configuration.'
            );
        }
    }

    public function destroy(
        ContactMessage $contactMessage
    ) {
        $contactMessage->delete();

        return back()->with(
            'success',
            'Contact message deleted successfully.'
        );
    }

}

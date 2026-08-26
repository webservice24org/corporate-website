<?php
namespace App\Http\Controllers;

use App\Models\ContactMessage;
use Illuminate\Http\Request;

class ContactMessageController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:50',
            ],

            'email' => [
                'required',
                'email',
                'max:190',
            ],

            'target' => [
                'nullable',
                'string',
                'max:120',
            ],

            'target_label' => [
                'nullable',
                'string',
                'max:190',
            ],

            'subject' => [
                'required',
                'string',
                'max:190',
            ],

            'message' => [
                'required',
                'string',
                'max:5000',
            ],
        ]);

        ContactMessage::create([
            ...$validated,

            'ip_address' =>
                $request->ip(),

            'status' =>
                'unread',
        ]);

        return back()->with(
            'success',
            'Thank you for contacting us. We will get back to you shortly.',
        );
    }
}
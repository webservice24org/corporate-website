<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GlobalSection;
use Illuminate\Http\Request;

class GlobalFooterController extends Controller
{
    public function edit()
    {
        $footer = GlobalSection::query()
            ->where('type', 'footer')
            ->first();

        return inertia(
            'Admin/Global/Footer/Edit',
            [
                'footer' => $footer,
            ]
        );
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'variant' => [
                'required',
                'string',
                'max:100',
            ],

            'settings' => [
                'required',
                'array',
            ],

            'is_active' => [
                'required',
                'boolean',
            ],
        ]);

        GlobalSection::updateOrCreate(
            [
                'type' => 'footer',
            ],
            [
                'variant' =>
                    $validated['variant'],

                'settings' =>
                    $validated['settings'],

                'is_active' =>
                    $validated['is_active'],
            ]
        );

        return back()->with(
            'success',
            'Global footer saved successfully.'
        );
    }
}
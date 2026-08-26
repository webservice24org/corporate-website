<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GlobalSection;
use Illuminate\Http\Request;

class GlobalFloatingContactController extends Controller
{
    public function edit()
    {
        $section = GlobalSection::query()
            ->where(
                'type',
                'floating-contact'
            )
            ->first();

        return inertia(
            'Admin/Global/FloatingContact/Edit',
            [
                'section' => $section,
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
                'type' =>
                    'floating-contact',
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
            'Floating contact settings saved successfully.'
        );
    }
}
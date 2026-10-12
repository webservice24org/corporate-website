<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GlobalSection;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GlobalHeaderController extends Controller
{
    public function edit()
    {
        $header = GlobalSection::query()
            ->where('type', 'header')
            ->first();

        return inertia('Admin/Global/Header/Edit', [
            'header' => $header,
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'variant' => [
                'required',
                'string',
                Rule::in([
                    'header-01',
                    'header-02',
                    'header-03',
                    'header-04',
                    'header-05',
                ]),
            ],

            'is_active' => [
                'required',
                'boolean',
            ],

            'settings' => [
                'required',
                'array',
            ],
        ]);

        GlobalSection::query()->updateOrCreate(
            [
                'type' => 'header',
            ],
            [
                'variant' => $validated['variant'],

                'settings' => $validated['settings'],

                'is_active' => $validated['is_active'],
            ]
        );

        return back()->with(
            'success',
            'Global header settings updated successfully.'
        );
    }
}

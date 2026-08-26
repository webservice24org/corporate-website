<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use App\Models\GlobalSection;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],

            'globalFooter' => fn () =>
                GlobalSection::query()
                    ->where('type', 'footer')
                    ->where('is_active', true)
                    ->first([
                        'id',
                        'type',
                        'variant',
                        'settings',
                        'is_active',
                    ]),

            'globalHeader' => fn () => GlobalSection::query()
            ->where('type', 'header')
            ->where('is_active', true)
            ->first(),

            'globalFloatingContact' => fn () =>
                GlobalSection::query()
                    ->where(
                        'type',
                        'floating-contact'
                    )
                    ->where(
                        'is_active',
                        true
                    )
                    ->first([
                        'id',
                        'type',
                        'variant',
                        'settings',
                        'is_active',
                    ]),
        ];
    }
}

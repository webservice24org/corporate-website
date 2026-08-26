<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    public function show(string $slug): Response
    {
        $page = Page::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->where(function ($query) {
                $query
                    ->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->with([
                'sections' => fn ($query) =>
                    $query
                        ->where('is_active', true)
                        ->orderBy('sort_order'),
            ])
            ->firstOrFail();

        return Inertia::render('Frontend/Page', [
            'page' => $page,
        ]);
    }
}
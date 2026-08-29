<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function index(): Response
    {
        $page = Page::query()
            ->where('is_home', true)
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
            ->first();

        if (! $page) {
            return Inertia::render('Frontend/Home', [
                'page' => null,
                'sections' => [],
            ]);
        }

        return Inertia::render('Frontend/Home', [
            'page' => [
                'id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,

                'meta_title' => $page->meta_title,
                'meta_description' => $page->meta_description,
            ],

            'sections' => $page->sections
                ->values(),
        ]);
    }
}
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
                'sections' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('sort_order'),
            ])
            ->firstOrFail();

        return Inertia::render('Frontend/Page', [
            'page' => [
                'id' => $page->id,

                'title' => $page->title,
                'slug' => $page->slug,

                /*
                 * Standard page content
                 */
                'description' => $page->description,
                'featured_image' => $page->featured_image,

                /*
                 * Page mode + frontend layout
                 */
                'template' => $page->template,
                'layout' => $page->layout,

                /*
                 * Publishing
                 */
                'status' => $page->status,
                'published_at' => $page->published_at,

                /*
                 * SEO
                 */
                'meta_title' => $page->meta_title,
                'meta_description' => $page->meta_description,

                /*
                 * Page Builder sections
                 *
                 * Standard pages can simply ignore these.
                 * Builder pages will render them through PageRenderer.
                 */
                'sections' => $page->sections
                    ->values(),
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): Response
    {
        $pages = Page::query()
            ->latest()
            ->get([
                'id',
                'title',
                'slug',

                'description',
                'featured_image',
                'layout',

                'status',
                'template',

                'meta_title',
                'meta_description',

                'published_at',
                'is_home',
                'created_at',
            ]);

        return Inertia::render(
            'Admin/Pages/Index',
            [
                'pages' => $pages,
            ]
        );
    }


    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        return Inertia::render(
            'Admin/Pages/Create'
        );
    }


    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => [
                'required',
                'string',
                'max:255',
            ],

            'slug' => [
                'nullable',
                'string',
                'max:255',
            ],

            /*
             * Standard page content
             */
            'description' => [
                'nullable',
                'string',
            ],

            /*
             * Page featured image
             */
            'featured_image' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],

            /*
             * Page layout
             */
            'layout' => [
                'required',
                Rule::in([
                    'full-width',
                    'left-sidebar',
                    'right-sidebar',
                ]),
            ],

            'status' => [
                'required',
                Rule::in([
                    'draft',
                    'published',
                ]),
            ],

            /*
             * Keep existing template
             * validation flexible for now.
             */
            'template' => [
                'required',
                'string',
                'max:100',
            ],

            'meta_title' => [
                'nullable',
                'string',
                'max:255',
            ],

            'meta_description' => [
                'nullable',
                'string',
            ],

            'is_home' => [
                'nullable',
                'boolean',
            ],
        ]);


        /*
         * Generate slug.
         */
        $slug = Str::slug(
            $validated['slug']
                ?: $validated['title']
        );

        if ($slug === '') {
            $slug = 'page';
        }

        $baseSlug = $slug;
        $counter = 1;

        while (
            Page::where(
                'slug',
                $slug
            )->exists()
        ) {
            $slug =
                $baseSlug
                . '-'
                . $counter;

            $counter++;
        }


        /*
         * Upload featured image.
         */
        $featuredImage = null;

        if (
            $request->hasFile(
                'featured_image'
            )
        ) {
            $featuredImage =
                $request
                    ->file(
                        'featured_image'
                    )
                    ->store(
                        'pages',
                        'public'
                    );
        }


        /*
         * Prepare page data.
         */
        $pageData = [
            'title' =>
                $validated['title'],

            'slug' =>
                $slug,

            'description' =>
                $validated['description']
                ?? null,

            'featured_image' =>
                $featuredImage,

            'layout' =>
                $validated['layout'],

            'status' =>
                $validated['status'],

            'template' =>
                $validated['template'],

            'is_home' =>
                !empty(
                    $validated['is_home']
                ),

            'meta_title' =>
                $validated['meta_title']
                ?? null,

            'meta_description' =>
                $validated[
                    'meta_description'
                ]
                ?? null,

            'published_at' =>
                $validated['status']
                    === 'published'
                        ? now()
                        : null,
        ];


        /*
         * Save page.
         *
         * Only one page can be homepage.
         */
        try {
            DB::transaction(
                function () use (
                    $pageData
                ) {
                    if (
                        !empty(
                            $pageData[
                                'is_home'
                            ]
                        )
                    ) {
                        Page::query()
                            ->update([
                                'is_home' =>
                                    false,
                            ]);
                    }

                    Page::create(
                        $pageData
                    );
                }
            );
        } catch (\Throwable $e) {
            /*
             * Avoid orphaned uploaded file
             * if database operation fails.
             */
            if ($featuredImage) {
                Storage::disk(
                    'public'
                )->delete(
                    $featuredImage
                );
            }

            throw $e;
        }


        return redirect()
            ->route(
                'admin.pages.index'
            )
            ->with(
                'success',
                'Page created successfully.'
            );
    }


    /**
     * Show the form for editing the specified resource.
     */
    public function edit(
        Page $page
    ): Response {
        return Inertia::render(
            'Admin/Pages/Edit',
            [
                'page' => $page,
            ]
        );
    }


    /**
     * Update the specified resource in storage.
     */
    public function update(
        Request $request,
        Page $page
    ) {
        $validated = $request->validate([
            'title' => [
                'required',
                'string',
                'max:255',
            ],

            'slug' => [
                'required',
                'string',
                'max:255',
            ],

            /*
             * Standard page content
             */
            'description' => [
                'nullable',
                'string',
            ],

            /*
             * New featured image.
             *
             * Existing image is retained
             * when this is not supplied.
             */
            'featured_image' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],

            /*
             * Allows Edit page to explicitly
             * remove the current image.
             */
            'remove_featured_image' => [
                'nullable',
                'boolean',
            ],

            /*
             * Page layout
             */
            'layout' => [
                'required',
                Rule::in([
                    'full-width',
                    'left-sidebar',
                    'right-sidebar',
                ]),
            ],

            'status' => [
                'required',
                Rule::in([
                    'draft',
                    'published',
                ]),
            ],

            'template' => [
                'required',
                'string',
                'max:100',
            ],

            'meta_title' => [
                'nullable',
                'string',
                'max:255',
            ],

            'meta_description' => [
                'nullable',
                'string',
            ],

            'is_home' => [
                'nullable',
                'boolean',
            ],
        ]);


        /*
         * Generate / normalize slug.
         */
        $slug = Str::slug(
            $validated['slug']
        );

        if ($slug === '') {
            $slug = Str::slug(
                $validated['title']
            );
        }

        if ($slug === '') {
            $slug = 'page';
        }

        $baseSlug = $slug;
        $counter = 1;

        while (
            Page::where(
                'slug',
                $slug
            )
                ->where(
                    'id',
                    '!=',
                    $page->id
                )
                ->exists()
        ) {
            $slug =
                $baseSlug
                . '-'
                . $counter;

            $counter++;
        }


        /*
         * Remember current image.
         *
         * We only delete it after the
         * database update succeeds.
         */
        $oldFeaturedImage =
            $page->featured_image;

        $newFeaturedImage = null;

        $featuredImage =
            $oldFeaturedImage;


        /*
         * Explicit image removal.
         */
        if (
            !empty(
                $validated[
                    'remove_featured_image'
                ]
            )
        ) {
            $featuredImage = null;
        }


        /*
         * New image takes priority.
         */
        if (
            $request->hasFile(
                'featured_image'
            )
        ) {
            $newFeaturedImage =
                $request
                    ->file(
                        'featured_image'
                    )
                    ->store(
                        'pages',
                        'public'
                    );

            $featuredImage =
                $newFeaturedImage;
        }


        /*
         * Prepare update data.
         */
        $updateData = [
            'title' =>
                $validated['title'],

            'slug' =>
                $slug,

            'description' =>
                $validated['description']
                ?? null,

            'featured_image' =>
                $featuredImage,

            'layout' =>
                $validated['layout'],

            'status' =>
                $validated['status'],

            'template' =>
                $validated['template'],

            'is_home' =>
                !empty(
                    $validated['is_home']
                ),

            'meta_title' =>
                $validated['meta_title']
                ?? null,

            'meta_description' =>
                $validated[
                    'meta_description'
                ]
                ?? null,

            'published_at' =>
                $validated['status']
                    === 'published'
                        ? (
                            $page
                                ->published_at
                            ?? now()
                        )
                        : null,
        ];


        try {
            DB::transaction(
                function () use (
                    $page,
                    $updateData
                ) {
                    if (
                        !empty(
                            $updateData[
                                'is_home'
                            ]
                        )
                    ) {
                        Page::query()
                            ->where(
                                'id',
                                '!=',
                                $page->id
                            )
                            ->update([
                                'is_home' =>
                                    false,
                            ]);
                    }

                    $page->update(
                        $updateData
                    );
                }
            );
        } catch (\Throwable $e) {
            /*
             * If database update fails,
             * remove newly uploaded image.
             */
            if (
                $newFeaturedImage
            ) {
                Storage::disk(
                    'public'
                )->delete(
                    $newFeaturedImage
                );
            }

            throw $e;
        }


        /*
         * Delete old image only after
         * successful database update.
         */
        if (
            $oldFeaturedImage &&
            $oldFeaturedImage
                !== $featuredImage
        ) {
            Storage::disk(
                'public'
            )->delete(
                $oldFeaturedImage
            );
        }


        return redirect()
            ->route(
                'admin.pages.index'
            )
            ->with(
                'success',
                'Page updated successfully.'
            );
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(
        Page $page
    ) {
        /*
         * Remember image before deleting
         * the database row.
         */
        $featuredImage =
            $page->featured_image;

        $page->delete();


        /*
         * Clean up page image.
         */
        if ($featuredImage) {
            Storage::disk(
                'public'
            )->delete(
                $featuredImage
            );
        }


        return redirect()
            ->route(
                'admin.pages.index'
            )
            ->with(
                'success',
                'Page deleted successfully.'
            );
    }
}
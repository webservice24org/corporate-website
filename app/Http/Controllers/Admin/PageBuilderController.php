<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageSection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PageBuilderController extends Controller
{
    public function edit(Page $page): Response
    {
        $page->load([
            'sections' => fn($query) => $query->orderBy('sort_order'),
        ]);

        return Inertia::render('Admin/Pages/Builder', [
            'page' => $page,
        ]);
    }

    public function storeSection(Request $request, Page $page)
    {
        $validated = $request->validate([
            'type' => [
                'required',
                'string',
            ],

            'variant' => [
                'required',
                'string',
                'max:100',
            ],

            'settings' => [
                'required',
                'array',
            ],
        ]);

        $nextOrder =
            ($page->sections()->max('sort_order') ?? 0) + 1;

        $section = $page->sections()->create([
            'type' => $validated['type'],
            'name' => ucfirst($validated['type']),
            'variant' => $validated['variant'],
            'settings' => $validated['settings'],
            'sort_order' => $nextOrder,
            'is_active' => true,
        ]);

        if (!$section instanceof PageSection) {
            throw new \LogicException('Expected a PageSection after creation.');
        }

        return back()->with(
            'success',
            "{$section->name} section inserted successfully.",
        );
    }

    public function updateSection(
        Request $request,
        Page $page,
        PageSection $section,
    ) {
        $this->ensureSectionBelongsToPage(
            $page,
            $section,
        );

        $validated = $request->validate([
            'name' => [
                'nullable',
                'string',
                'max:255',
            ],

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

        $section->update($validated);

        return back()->with(
            'success',
            'Section updated successfully.',
        );
    }

    public function duplicateSection(
        Page $page,
        PageSection $section,
    ) {
        $this->ensureSectionBelongsToPage(
            $page,
            $section,
        );

        $sectionsAfter = $page->sections()
            ->where(
                'sort_order',
                '>',
                $section->sort_order,
            )
            ->orderByDesc('sort_order')
            ->get();

        foreach ($sectionsAfter as $item) {
            $item->increment('sort_order');
        }

        $copy = $section->replicate();

        $copy->page_id = $page->id;

        $copy->name = $section->name
            ? $section->name . ' Copy'
            : ucfirst($section->type) . ' Copy';

        $copy->sort_order =
            $section->sort_order + 1;

        $copy->save();

        return back()->with(
            'success',
            'Section duplicated successfully.',
        );
    }

    public function moveSectionUp(
        Page $page,
        PageSection $section,
    ) {
        $this->ensureSectionBelongsToPage(
            $page,
            $section,
        );

        $previous = $page->sections()
            ->where(
                'sort_order',
                '<',
                $section->sort_order,
            )
            ->orderByDesc('sort_order')
            ->first();

        if (!$previous instanceof PageSection) {
            return back();
        }

        $currentOrder = $section->sort_order;

        $section->update([
            'sort_order' => $previous->sort_order,
        ]);

        $previous->update([
            'sort_order' => $currentOrder,
        ]);

        return back()->with(
            'success',
            'Section moved up.',
        );
    }

    public function moveSectionDown(
        Page $page,
        PageSection $section,
    ) {
        $this->ensureSectionBelongsToPage(
            $page,
            $section,
        );

        $next = $page->sections()
            ->where(
                'sort_order',
                '>',
                $section->sort_order,
            )
            ->orderBy('sort_order')
            ->first();

        if (!$next instanceof PageSection) {
            return back();
        }

        $currentOrder = $section->sort_order;

        $section->update([
            'sort_order' => $next->sort_order,
        ]);

        $next->update([
            'sort_order' => $currentOrder,
        ]);

        return back()->with(
            'success',
            'Section moved down.',
        );
    }

    public function destroySection(
        Page $page,
        PageSection $section,
    ) {
        $this->ensureSectionBelongsToPage(
            $page,
            $section,
        );

        $section->delete();

        $this->normalizeSortOrder($page);

        return back()->with(
            'success',
            'Section deleted successfully.',
        );
    }

    private function ensureSectionBelongsToPage(
        Page $page,
        PageSection $section,
    ): void {
        abort_unless(
            $section->page_id === $page->id,
            404,
        );
    }

    public function reorderSections(
        Request $request,
        Page $page,
    ) {
        $validated = $request->validate([
            'section_ids' => [
                'required',
                'array',
            ],

            'section_ids.*' => [
                'required',
                'integer',
                'distinct',
            ],
        ]);

        $sectionIds = collect(
            $validated['section_ids'],
        )->map(fn($id) => (int) $id);

        $pageSectionIds = $page->sections()
            ->pluck('id');

        /*
         * Make sure the request contains exactly
         * the sections belonging to this page.
         */
        abort_unless(
            $sectionIds->count() ===
            $pageSectionIds->count() &&
            $sectionIds->sort()->values()->all() ===
            $pageSectionIds->sort()->values()->all(),
            422,
        );

        foreach (
            $sectionIds->values() as $index => $sectionId
        ) {
            PageSection::where('id', $sectionId)
                ->where('page_id', $page->id)
                ->update([
                    'sort_order' => $index + 1,
                ]);
        }

        return back()->with(
            'success',
            'Section order updated.',
        );
    }

    public function uploadImage(Request $request)
    {
        $validated = $request->validate([
            'image' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
        ]);

        $path = $validated['image']->store(
            'page-builder',
            'public',
        );

        return response()->json([
            'url' => asset('storage/' . $path),
            'path' => $path,
        ]);
    }

    private function normalizeSortOrder(Page $page): void
    {
        $page->sections()
            ->orderBy('sort_order')
            ->get()
            ->each(function ($section, int $index): void {
                if (!$section instanceof PageSection) {
                    throw new \LogicException('Expected a PageSection when normalizing order.');
                }

                $section->update([
                    'sort_order' => $index + 1,
                ]);
            });
    }
}

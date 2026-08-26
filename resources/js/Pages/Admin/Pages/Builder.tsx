import AdminLayout from "@/Layouts/admin-layout";
import { Head, Link, router } from "@inertiajs/react";
import { useEffect, useState } from "react";
import SectionLibrary from "@/PageBuilder/components/SectionLibrary";
import type { BuilderSection } from "@/PageBuilder/types";
import { getSectionDesign } from "@/PageBuilder/registry";
import SectionActions from "@/PageBuilder/components/SectionActions";
import toast from "react-hot-toast";
import SectionEditorModal from "@/PageBuilder/components/SectionEditorModal";

import {
    Plus
} from "lucide-react";

import { Button } from "@/Components/ui/button";
import {
    DndContext,
    PointerSensor,
    KeyboardSensor,
    closestCenter,
    useSensor,
    useSensors,
    type DragEndEvent,
} from "@dnd-kit/core";

import {
    SortableContext,
    arrayMove,
    sortableKeyboardCoordinates,
    verticalListSortingStrategy,
} from "@dnd-kit/sortable";

import SortableSection from "@/PageBuilder/components/SortableSection";


type Page = {
    id: number;
    title: string;
    slug: string;
    status: "draft" | "published";
    sections: BuilderSection[];
};

type Props = {
    page: Page;
};

export default function Builder({ page }: Props) {
    const [
        editingSectionId,
        setEditingSectionId,
    ] = useState<number | null>(null);

     const [sections, setSections] =
    useState<BuilderSection[]>(
        page.sections,
    );

    const editingSection =
    sections.find(
        (section) =>
            section.id === editingSectionId,
    ) ?? null;


    useEffect(() => {
        setSections(page.sections);
    }, [page.sections]);

    const [sectionLibraryOpen, setSectionLibraryOpen] =
        useState(false);

        const sensors = useSensors(
            useSensor(PointerSensor, {
                activationConstraint: {
                    distance: 6,
                },
            }),

            useSensor(KeyboardSensor, {
                coordinateGetter:
                    sortableKeyboardCoordinates,
            }),
        );

        const handleDragEnd = (
            event: DragEndEvent,
        ) => {
            const {
                active,
                over,
            } = event;

            if (
                !over ||
                active.id === over.id
            ) {
                return;
            }

            const oldIndex =
                sections.findIndex(
                    (section) =>
                        section.id ===
                        Number(active.id),
                );

            const newIndex =
                sections.findIndex(
                    (section) =>
                        section.id ===
                        Number(over.id),
                );

            if (
                oldIndex === -1 ||
                newIndex === -1
            ) {
                return;
            }

            const previousSections =
                sections;

            const reorderedSections =
                arrayMove(
                    sections,
                    oldIndex,
                    newIndex,
                );

            setSections(
                reorderedSections,
            );

            router.post(
                `/admin/pages/${page.id}/sections/reorder`,
                {
                    section_ids:
                        reorderedSections.map(
                            (section) =>
                                section.id,
                        ),
                },
                {
                    preserveScroll: true,
                    preserveState: true,

                    onError: () => {
                        /*
                        * Roll back the visual order
                        * if Laravel rejects the update.
                        */
                        setSections(
                            previousSections,
                        );

                        toast.error(
                            "Unable to reorder sections.",
                        );
                    },
                },
            );
        };
       

    return (
        <AdminLayout>
            <Head title={`Builder - ${page.title}`} />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">
                            Page Builder
                        </h1>

                        <p className="mt-1 text-sm text-muted-foreground">
                            {page.title} · /{page.slug}
                        </p>
                    </div>

                    <Link
                        href="/admin/pages"
                        className="text-sm text-muted-foreground hover:text-foreground"
                    >
                        Back to Pages
                    </Link>
                </div>

                <div className="grid gap-5 xl:grid-cols-[220px_minmax(0,1fr)]" >
                    {/* Left Sidebar: Add Section */}
                    <aside className="h-fit rounded-xl border bg-background p-4">
                        <h2 className="font-semibold">
                            Sections
                        </h2>

                        <p className="mt-1 text-sm text-muted-foreground">
                            Add a pre-designed section to this
                            page.
                        </p>

                        <Button
                            className="mt-4 w-full"
                            onPress={() =>
                                setSectionLibraryOpen(true)
                            }
                        >
                            <Plus />
                            Add Section
                        </Button>
                    </aside>

                    {/* Middle Column: Section List */}
                    <DndContext
                        sensors={sensors}
                        collisionDetection={closestCenter}
                        onDragEnd={handleDragEnd}
                    >
                        <SortableContext
                            items={sections.map(
                                (section) =>
                                    section.id,
                            )}
                            strategy={
                                verticalListSortingStrategy
                            }
                        >
                            <div className="space-y-4">
                                {sections.length > 0 ? (
                                    sections.map(
                                        (section, index) => {
                                            const design =
                                                getSectionDesign(
                                                    section.type,
                                                    section.variant,
                                                );

                                            if (!design) {
                                                return (
                                                    <SortableSection
                                                        key={
                                                            section.id
                                                        }
                                                        id={
                                                            section.id
                                                        }
                                                    >
                                                        <div className="rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
                                                            Design not found:{" "}
                                                            {
                                                                section.type
                                                            }{" "}
                                                            /{" "}
                                                            {
                                                                section.variant
                                                            }
                                                        </div>
                                                    </SortableSection>
                                                );
                                            }

                                            const SectionComponent =
                                                design.component;

                                            const isSelected =
                                                editingSectionId ===
                                                section.id;

                                            return (
                                                <SortableSection
                                                    key={
                                                        section.id
                                                    }
                                                    id={
                                                        section.id
                                                    }
                                                >
                                                    <div
                                                        className={`group relative overflow-hidden rounded-xl border bg-background transition ${
                                                            isSelected
                                                                ? "border-primary ring-2 ring-primary/20"
                                                                : "border-border hover:border-primary/50"
                                                        }`}
                                                    >
                                                        {/* Toolbar */}
                                                        <div className="flex items-center justify-between border-b bg-muted/30 py-2 pl-14 pr-3">
                                                            <div className="min-w-0">
                                                                <div className="truncate text-sm font-medium">
                                                                    {section.name ??
                                                                        design.label}
                                                                </div>

                                                                <div className="text-xs text-muted-foreground">
                                                                    {
                                                                        design.label
                                                                    }
                                                                </div>
                                                            </div>

                                                            <div className="flex items-center gap-1">
                                                                {!section.is_active && (
                                                                    <span className="mr-2 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                                                                        Hidden
                                                                    </span>
                                                                )}

                                                                <SectionActions
                                                                    pageId={
                                                                        page.id
                                                                    }
                                                                    section={
                                                                        section
                                                                    }
                                                                    index={
                                                                        index
                                                                    }
                                                                    totalSections={
                                                                        sections.length
                                                                    }
                                                                    onEdit={() =>
                                                                        setEditingSectionId(
                                                                            section.id,
                                                                        )
                                                                    }
                                                                    onDeleted={() => {
                                                                        if (
                                                                            editingSectionId ===
                                                                            section.id
                                                                        ) {
                                                                            setEditingSectionId(
                                                                                null,
                                                                            );
                                                                        }
                                                                    }}
                                                                />
                                                            </div>
                                                        </div>

                                                        {/* Actual section preview */}
                                                        <div className="relative">
                                                            <div
                                                                className={`pointer-events-none ${
                                                                    section.is_active
                                                                        ? ""
                                                                        : "opacity-40 grayscale"
                                                                }`}
                                                            >
                                                                <SectionComponent
                                                                    settings={section.settings}
                                                                    preview
                                                                />
                                                            </div>

                                                            <button
                                                                type="button"
                                                                aria-label={`Edit ${
                                                                    section.name ?? design.label
                                                                }`}
                                                                onClick={() =>
                                                                    setEditingSectionId(section.id)
                                                                }
                                                                className="absolute inset-0 z-10 cursor-pointer"
                                                            >
                                                                <span className="sr-only">
                                                                    Edit {section.name ?? design.label}
                                                                </span>
                                                            </button>
                                                        </div>

                                                        {isSelected && (
                                                            <div className="pointer-events-none absolute inset-0 z-20 border-2 border-primary" />
                                                        )}
                                                    </div>
                                                </SortableSection>
                                            );
                                        },
                                    )
                                ) : (
                                    <div className="rounded-xl border border-dashed p-16 text-center">
                                        <h3 className="font-medium">
                                            Start building your page
                                        </h3>

                                        <p className="mx-auto mt-2 max-w-md text-sm text-muted-foreground">
                                            Add a pre-designed
                                            section from the Section
                                            Library.
                                        </p>

                                        <Button
                                            className="mt-5"
                                            onPress={() =>
                                                setSectionLibraryOpen(
                                                    true,
                                                )
                                            }
                                        >
                                            <Plus />
                                            Add Your First Section
                                        </Button>
                                    </div>
                                )}
                            </div>
                        </SortableContext>
                    </DndContext>

                    
                </div>
            </div>

            <SectionLibrary
                pageId={page.id}
                isOpen={sectionLibraryOpen}
                onClose={() =>
                    setSectionLibraryOpen(false)
                }
            />

            

            {editingSection && (
                <SectionEditorModal
                    key={editingSection.id}
                    pageId={page.id}
                    section={editingSection}
                    onClose={() =>
                        setEditingSectionId(null)
                    }
                />
            )}
        </AdminLayout>
    );
}
import { useMemo, useState } from "react";
import { router } from "@inertiajs/react";
import type { FormDataConvertible } from "@inertiajs/core";
import {
    Check,
    Eye,
    Plus,
    Search,
    X,
} from "lucide-react";

import { Button } from "@/Components/ui/button";
import { sectionRegistry } from "../registry";
import type {
    SectionDesign,
    SectionGroup,
    SectionType,
} from "../types";

type Props = {
    pageId: number;
    isOpen: boolean;
    onClose: () => void;
};

type FilterType = "all" | SectionType;

type FilterButtonProps = {
    active: boolean;
    children: React.ReactNode;
    onPress: () => void;
};

function FilterButton({
    active,
    children,
    onPress,
}: FilterButtonProps) {
    return (
        <Button
            variant={active ? "default" : "outline"}
            size="sm"
            onPress={onPress}
        >
            {children}
        </Button>
    );
}

export default function SectionLibrary({
    pageId,
    isOpen,
    onClose,
}: Props) {
    const [activeFilter, setActiveFilter] = useState<FilterType>("all");
    const [search, setSearch] = useState("");
    const [previewDesign, setPreviewDesign] = useState<{
        group: SectionGroup;
        design: SectionDesign;
    } | null>(null);

    const [insertingId, setInsertingId] = useState<string | null>(null);

    const designs = useMemo(() => {
        return sectionRegistry.flatMap((group) =>
            group.designs
                .filter(() => {
                    if (activeFilter === "all") {
                        return true;
                    }
                    return group.type === activeFilter;
                })
                .filter((design) => {
                    const query = search.trim().toLowerCase();
                    if (!query) {
                        return true;
                    }

                    return (
                        design.label.toLowerCase().includes(query) ||
                        design.description?.toLowerCase().includes(query) ||
                        group.label.toLowerCase().includes(query)
                    );
                })
                .map((design) => ({
                    group,
                    design,
                })),
        );
    }, [activeFilter, search]);

    const insertSection = (
        group: SectionGroup,
        design: SectionDesign,
    ) => {
        setInsertingId(design.id);

        router.post(
            `/admin/pages/${pageId}/sections`,
            {
                type: group.type,
                variant: design.id,
                settings: design.defaultSettings as Record<string, FormDataConvertible>,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setPreviewDesign(null);
                    onClose();
                },
                onFinish: () => {
                    setInsertingId(null);
                },
            },
        );
    };

    if (!isOpen) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4 backdrop-blur-sm md:p-8">
            <div className="w-full max-w-7xl overflow-hidden rounded-2xl border bg-background shadow-2xl">
                {/* Header */}
                <div className="flex items-center justify-between border-b px-5 py-4">
                    <div>
                        <h2 className="text-xl font-semibold">
                            Section Library
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Choose a pre-designed section and insert it into your page.
                        </p>
                    </div>

                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Close section library"
                        onPress={onClose}
                    >
                        <X className="size-4" />
                    </Button>
                </div>

                {/* Toolbar */}
                <div className="space-y-4 border-b p-5">
                    <div className="relative max-w-md">
                        <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search sections..."
                            className="h-10 w-full rounded-lg border border-input bg-background pl-9 pr-3 text-sm outline-none focus:border-ring focus:ring-2 focus:ring-ring/20"
                        />
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <FilterButton
                            active={activeFilter === "all"}
                            onPress={() => setActiveFilter("all")}
                        >
                            All
                        </FilterButton>

                        {sectionRegistry.map((group) => (
                            <FilterButton
                                key={group.type}
                                active={activeFilter === group.type}
                                onPress={() => setActiveFilter(group.type)}
                            >
                                {group.label}
                            </FilterButton>
                        ))}
                    </div>
                </div>

                {/* Designs Grid */}
                <div className="p-5">
                    {designs.length > 0 ? (
                        <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                            {designs.map(({ group, design }) => {
                                const Component = design.component;
                                const inserting = insertingId === design.id;

                                return (
                                    <div
                                        key={`${group.type}-${design.id}`}
                                        className="overflow-hidden rounded-xl border bg-card transition-shadow hover:shadow-md"
                                    >
                                        {/* Live Preview Card */}
                                        <div className="relative h-64 overflow-hidden bg-muted">
                                            <div className="pointer-events-none absolute left-0 top-0 w-[200%] origin-top-left scale-50">
                                                <Component
                                                    settings={design.defaultSettings}
                                                    preview
                                                />
                                            </div>

                                            <div className="absolute inset-x-0 bottom-0 flex justify-end bg-gradient-to-t from-black/60 to-transparent p-3 pt-12">
                                                <Button
                                                    variant="secondary"
                                                    size="sm"
                                                    onPress={() =>
                                                        setPreviewDesign({
                                                            group,
                                                            design,
                                                        })
                                                    }
                                                >
                                                    <Eye className="mr-1 size-4" />
                                                    Preview
                                                </Button>
                                            </div>
                                        </div>

                                        {/* Info & Actions */}
                                        <div className="space-y-4 p-4">
                                            <div>
                                                <div className="flex items-center justify-between gap-3">
                                                    <h3 className="font-semibold">
                                                        {design.label}
                                                    </h3>
                                                    <span className="rounded-full bg-muted px-2.5 py-0.5 text-xs capitalize text-muted-foreground">
                                                        {group.label}
                                                    </span>
                                                </div>

                                                {design.description && (
                                                    <p className="mt-2 text-sm text-muted-foreground">
                                                        {design.description}
                                                    </p>
                                                )}
                                            </div>

                                            <Button
                                                className="w-full"
                                                onPress={() =>
                                                    insertSection(group, design)
                                                }
                                                isDisabled={insertingId !== null}
                                            >
                                                {inserting ? (
                                                    <>
                                                        <Check className="mr-1 size-4" />
                                                        Inserting...
                                                    </>
                                                ) : (
                                                    <>
                                                        <Plus className="mr-1 size-4" />
                                                        Insert Section
                                                    </>
                                                )}
                                            </Button>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    ) : (
                        <div className="rounded-xl border border-dashed py-20 text-center">
                            <h3 className="font-medium">No designs found</h3>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Try another search or section category.
                            </p>
                        </div>
                    )}
                </div>
            </div>

            {/* Full Screen Preview Modal */}
            {previewDesign && (
                <div className="fixed inset-0 z-[60] overflow-y-auto bg-black/70 p-4 backdrop-blur-sm md:p-8">
                    <div className="mx-auto max-w-7xl overflow-hidden rounded-2xl bg-background shadow-2xl">
                        <div className="flex items-center justify-between border-b px-5 py-4">
                            <div>
                                <h3 className="font-semibold">
                                    {previewDesign.design.label}
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    {previewDesign.group.label} section
                                </p>
                            </div>

                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Close preview"
                                onPress={() => setPreviewDesign(null)}
                            >
                                <X className="size-4" />
                            </Button>
                        </div>

                        <div className="overflow-hidden">
                            {(() => {
                                const PreviewComponent =
                                    previewDesign.design.component;

                                return (
                                    <PreviewComponent
                                        settings={
                                            previewDesign.design.defaultSettings
                                        }
                                        preview
                                    />
                                );
                            })()}
                        </div>

                        <div className="flex justify-end gap-2 border-t p-4">
                            <Button
                                variant="outline"
                                onPress={() => setPreviewDesign(null)}
                            >
                                Close
                            </Button>

                            <Button
                                onPress={() =>
                                    insertSection(
                                        previewDesign.group,
                                        previewDesign.design,
                                    )
                                }
                                isDisabled={insertingId !== null}
                            >
                                <Plus className="mr-1 size-4" />
                                {insertingId === previewDesign.design.id
                                    ? "Inserting..."
                                    : "Insert Section"}
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
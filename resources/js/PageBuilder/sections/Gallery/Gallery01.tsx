import {
    useEffect,
    useMemo,
    useState,
} from "react";

import type {
    CSSProperties,
} from "react";

import type {
    SectionSettings,
} from "../../types";

import {
    getContainerClass,
    getCustomClass,
    getHorizontalPadding,
    getSectionId,
    getSectionSpacing,
    getVisibilityClass,
} from "../../utils/sectionStyles";

type GalleryItem = {
    image?: string;
    alt?: string;

    show_caption?: boolean;
    caption?: string;
};

type GalleryCategory = {
    title?: string;
    items?: GalleryItem[];
};

type Props = {
    settings: SectionSettings;
    preview?: boolean;
};

type GalleryViewItem = GalleryItem & {
    categoryIndex: number;
    itemIndex: number;
};

export default function Gallery01({
    settings,
    preview = false,
}: Props) {
    const categories =
        (settings.categories as
            | GalleryCategory[]
            | undefined) ?? [];

    const [activeFilter, setActiveFilter] =
        useState("all");

    /*
     * Section content
     */
    const title = String(
        settings.title ??
            "Photo Gallery",
    );

    const description = String(
        settings.description ??
            "Filter through our collection of photography.",
    );

    const allLabel = String(
        settings.all_label ?? "All",
    );

    /*
     * Gallery options
     */
    const showCaptions = Boolean(
        settings.show_captions ?? true,
    );

    const columns = String(
        settings.columns ?? "3",
    );

    const imageHeight = Math.max(
        180,
        Number(
            settings.image_height ?? 280,
        ),
    );

    const galleryEffect = String(
        settings.gallery_effect ??
            "fade-scale",
    );

    const transitionDuration =
        Math.max(
            0,
            Number(
                settings.transition_duration ??
                    450,
            ),
        );

    /*
     * Style
     */
    const backgroundType = String(
        settings.background_type ??
            "color",
    );

    const backgroundColor = String(
        settings.background_color ??
            "#f8fafc",
    );

    const backgroundImage = String(
        settings.background_image ?? "",
    );

    const titleFontSize = Number(
        settings.title_font_size ?? 40,
    );

    const textFontSize = Number(
        settings.text_font_size ?? 16,
    );

    const titleColor = String(
        settings.title_color ??
            "#111827",
    );

    const textColor = String(
        settings.text_color ??
            "#6b7280",
    );

    const accentColor = String(
        settings.accent_color ??
            "#4f46e5",
    );

    const alignment =
        (settings.text_alignment as
            | "left"
            | "center"
            | "right") ?? "center";

    /*
     * Advanced
     */
    const sectionSpacing =
        getSectionSpacing(settings);

    const containerClass =
        getContainerClass(settings);

    const horizontalPadding =
        getHorizontalPadding(settings);

    const sectionId =
        getSectionId(settings);

    const customClass =
        getCustomClass(settings);

    const visibilityClass = preview
        ? ""
        : getVisibilityClass(settings);

    /*
     * Reset invalid active category
     * if admin removes it.
     */
    useEffect(() => {
        if (activeFilter === "all") {
            return;
        }

        const exists =
            categories.some(
                (_, index) =>
                    String(index) ===
                    activeFilter,
            );

        if (!exists) {
            setActiveFilter("all");
        }
    }, [
        categories.length,
        activeFilter,
    ]);

    const allItems =
        useMemo<GalleryViewItem[]>(() => {
            return categories.flatMap(
                (category, categoryIndex) =>
                    (
                        category.items ?? []
                    ).map(
                        (
                            item,
                            itemIndex,
                        ) => ({
                            ...item,
                            categoryIndex,
                            itemIndex,
                        }),
                    ),
            );
        }, [categories]);

    const visibleItems =
        activeFilter === "all"
            ? allItems
            : allItems.filter(
                  (item) =>
                      String(
                          item.categoryIndex,
                      ) === activeFilter,
              );

    const gridClass =
        columns === "2"
            ? "sm:grid-cols-2"
            : columns === "4"
              ? "sm:grid-cols-2 xl:grid-cols-4"
              : "sm:grid-cols-2 lg:grid-cols-3";

    const headerPosition =
        alignment === "center"
            ? "mx-auto"
            : alignment === "right"
              ? "ml-auto"
              : "";

    const filterJustify =
        alignment === "center"
            ? "justify-center"
            : alignment === "right"
              ? "justify-end"
              : "justify-start";

    const animationName =
        galleryEffect === "fade-up"
            ? "galleryFadeUp"
            : galleryEffect ===
                "fade-scale"
              ? "galleryFadeScale"
              : "none";

    return (
        <section
            id={sectionId || undefined}
            className={`bg-cover bg-center ${visibilityClass} ${customClass}`}
            style={{
                ...sectionSpacing,

                backgroundColor:
                    backgroundType === "color"
                        ? backgroundColor
                        : undefined,

                backgroundImage:
                    backgroundType === "image" &&
                    backgroundImage
                        ? `url("${backgroundImage}")`
                        : undefined,
            }}
        >
            <div
                className={`mx-auto w-full ${containerClass}`}
                style={{
                    paddingLeft:
                        horizontalPadding,

                    paddingRight:
                        horizontalPadding,
                }}
            >
                {/* Header */}
                <div
                    className={`mb-10 max-w-3xl ${headerPosition}`}
                    style={{
                        textAlign:
                            alignment,
                    }}
                >
                    <h2
                        className="font-bold tracking-tight"
                        style={{
                            color:
                                titleColor,

                            fontSize:
                                `${titleFontSize}px`,
                        }}
                    >
                        {title}
                    </h2>

                    {description && (
                        <p
                            className="mt-3 leading-7"
                            style={{
                                color:
                                    textColor,

                                fontSize:
                                    `${textFontSize}px`,
                            }}
                        >
                            {description}
                        </p>
                    )}
                </div>

                {/* Filters */}
                <div
                    className={`mb-8 flex flex-wrap gap-2 ${filterJustify}`}
                >
                    <FilterButton
                        active={
                            activeFilter ===
                            "all"
                        }
                        label={allLabel}
                        accentColor={
                            accentColor
                        }
                        onClick={() =>
                            setActiveFilter(
                                "all",
                            )
                        }
                    />

                    {categories.map(
                        (
                            category,
                            index,
                        ) => (
                            <FilterButton
                                key={
                                    index
                                }
                                active={
                                    activeFilter ===
                                    String(
                                        index,
                                    )
                                }
                                label={String(
                                    category.title ??
                                        `Category ${
                                            index +
                                            1
                                        }`,
                                )}
                                accentColor={
                                    accentColor
                                }
                                onClick={() =>
                                    setActiveFilter(
                                        String(
                                            index,
                                        ),
                                    )
                                }
                            />
                        ),
                    )}
                </div>

                {/* Gallery */}
                {visibleItems.length >
                0 ? (
                    <div
                        key={activeFilter}
                        className={`grid grid-cols-1 gap-4 ${gridClass}`}
                    >
                        {visibleItems.map(
                            (
                                item,
                                index,
                            ) => {
                                const caption =
                                    String(
                                        item.caption ??
                                            "",
                                    );

                                const alt =
                                    String(
                                        item.alt ??
                                            caption ??
                                            "",
                                    );

                                const displayCaption =
                                    showCaptions &&
                                    item.show_caption !==
                                        false &&
                                    Boolean(
                                        caption,
                                    );

                                return (
                                    <div
                                        key={`${item.categoryIndex}-${item.itemIndex}`}
                                        className="group relative overflow-hidden rounded-2xl bg-slate-200 shadow-sm"
                                        style={
                                            {
                                                height:
                                                    `${imageHeight}px`,

                                                animationName,

                                                animationDuration:
                                                    `${transitionDuration}ms`,

                                                animationTimingFunction:
                                                    "cubic-bezier(0.22, 1, 0.36, 1)",

                                                animationFillMode:
                                                    "both",

                                                animationDelay:
                                                    `${Math.min(
                                                        index *
                                                            55,
                                                        330,
                                                    )}ms`,
                                            } as CSSProperties
                                        }
                                    >
                                        {item.image ? (
                                            <img
                                                src={
                                                    item.image
                                                }
                                                alt={
                                                    alt
                                                }
                                                className="h-full w-full object-cover transition-transform duration-700 ease-out group-hover:scale-110"
                                            />
                                        ) : (
                                            <div className="flex h-full items-center justify-center text-sm text-slate-400">
                                                Gallery
                                                Image
                                            </div>
                                        )}

                                        {displayCaption && (
                                            <div className="absolute inset-0 flex items-end bg-gradient-to-t from-black/75 via-black/10 to-transparent opacity-0 transition-opacity duration-300 group-hover:opacity-100">
                                                <div className="w-full p-5">
                                                    <p className="translate-y-3 font-medium text-white opacity-0 transition-all duration-300 group-hover:translate-y-0 group-hover:opacity-100">
                                                        {
                                                            caption
                                                        }
                                                    </p>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                );
                            },
                        )}
                    </div>
                ) : (
                    <div className="rounded-2xl border border-dashed p-12 text-center">
                        <p
                            className="text-sm"
                            style={{
                                color:
                                    textColor,
                            }}
                        >
                            No photos found in
                            this category.
                        </p>
                    </div>
                )}
            </div>
        </section>
    );
}

type FilterButtonProps = {
    active: boolean;
    label: string;
    accentColor: string;
    onClick: () => void;
};

function FilterButton({
    active,
    label,
    accentColor,
    onClick,
}: FilterButtonProps) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="rounded-full border px-5 py-2 text-sm font-semibold shadow-sm transition-all duration-200 hover:-translate-y-0.5"
            style={{
                backgroundColor:
                    active
                        ? accentColor
                        : "#ffffff",

                borderColor:
                    active
                        ? accentColor
                        : "#e5e7eb",

                color:
                    active
                        ? "#ffffff"
                        : "#374151",

                boxShadow:
                    active
                        ? `0 6px 18px ${accentColor}30`
                        : undefined,
            }}
        >
            {label}
        </button>
    );
}
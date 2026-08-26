import type {
    SectionSettings,
} from "../../types";

import {
    getBuilderIcon,
} from "../../utils/icons";

import {
    getContainerClass,
    getCustomClass,
    getHorizontalPadding,
    getSectionId,
    getSectionSpacing,
    getVisibilityClass,
} from "../../utils/sectionStyles";

type ValueItem = {
    media_type?: "icon" | "image";

    icon?: string;
    image?: string;

    title?: string;
    description?: string;
};

type Props = {
    settings: SectionSettings;
    preview?: boolean;
};

export default function Values01({
    settings,
    preview = false,
}: Props) {
    const items =
        (settings.items as
            | ValueItem[]
            | undefined) ?? [];

    /*
     * Content
     */
    const topTitle = String(
        settings.top_title ?? "",
    );

    const title = String(
        settings.title ??
            "Our Mission, Vision & Core Values",
    );

    const description = String(
        settings.description ?? "",
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
            "#059669",
    );

    const alignment =
        (settings.text_alignment as
            | "left"
            | "center"
            | "right") ?? "center";

    const columns = String(
        settings.columns ?? "3",
    );

    const mediaStyle = String(
        settings.media_style ??
            "rounded",
    );

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

    const gridClass =
        columns === "2"
            ? "md:grid-cols-2"
            : columns === "4"
              ? "md:grid-cols-2 xl:grid-cols-4"
              : "md:grid-cols-2 lg:grid-cols-3";

    const headerPosition =
        alignment === "center"
            ? "mx-auto"
            : alignment === "right"
              ? "ml-auto"
              : "";

    const mediaRadius =
        mediaStyle === "circle"
            ? "rounded-full"
            : mediaStyle === "square"
              ? "rounded-none"
              : "rounded-2xl";

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
                    className={`mb-12 max-w-3xl ${headerPosition}`}
                    style={{
                        textAlign:
                            alignment,
                    }}
                >
                    {topTitle && (
                        <p
                            className="mb-3 text-sm font-semibold uppercase tracking-[0.2em]"
                            style={{
                                color:
                                    accentColor,
                            }}
                        >
                            {topTitle}
                        </p>
                    )}

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
                            className="mt-4 leading-7"
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

                {/* Dynamic cards */}
                {items.length > 0 ? (
                    <div
                        className={`grid grid-cols-1 gap-7 ${gridClass}`}
                    >
                        {items.map(
                            (
                                item,
                                index,
                            ) => {
                                const Icon =
                                    getBuilderIcon(
                                        item.icon ??
                                            "target",
                                    );

                                const itemTitle =
                                    String(
                                        item.title ??
                                            `Value ${
                                                index +
                                                1
                                            }`,
                                    );

                                const itemDescription =
                                    String(
                                        item.description ??
                                            "",
                                    );

                                const mediaType =
                                    item.media_type ??
                                    "icon";

                                return (
                                    <article
                                        key={
                                            index
                                        }
                                        className="group relative overflow-hidden rounded-3xl border border-slate-200 bg-white p-7 shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-xl"
                                    >
                                        <div
                                            className="pointer-events-none absolute -right-12 -top-12 size-40 rounded-full opacity-[0.05] transition-transform duration-500 group-hover:scale-125"
                                            style={{
                                                backgroundColor:
                                                    accentColor,
                                            }}
                                        />

                                        <div className="relative z-10">
                                            {mediaType ===
                                            "image" ? (
                                                <div
                                                    className={`mb-6 h-52 overflow-hidden bg-slate-100 ${mediaRadius}`}
                                                >
                                                    {item.image ? (
                                                        <img
                                                            src={
                                                                item.image
                                                            }
                                                            alt={
                                                                itemTitle
                                                            }
                                                            className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
                                                        />
                                                    ) : (
                                                        <div className="flex h-full items-center justify-center text-sm text-slate-400">
                                                            Card
                                                            Image
                                                        </div>
                                                    )}
                                                </div>
                                            ) : (
                                                <div
                                                    className={`mb-6 flex size-16 items-center justify-center ${mediaRadius}`}
                                                    style={{
                                                        color:
                                                            accentColor,

                                                        backgroundColor:
                                                            `${accentColor}15`,
                                                    }}
                                                >
                                                    <Icon className="size-8" />
                                                </div>
                                            )}

                                            <h3
                                                className="text-xl font-bold"
                                                style={{
                                                    color:
                                                        titleColor,
                                                }}
                                            >
                                                {
                                                    itemTitle
                                                }
                                            </h3>

                                            {itemDescription && (
                                                <p
                                                    className="mt-3 leading-7"
                                                    style={{
                                                        color:
                                                            textColor,

                                                        fontSize:
                                                            `${textFontSize}px`,
                                                    }}
                                                >
                                                    {
                                                        itemDescription
                                                    }
                                                </p>
                                            )}
                                        </div>
                                    </article>
                                );
                            },
                        )}
                    </div>
                ) : (
                    <div className="rounded-2xl border border-dashed p-12 text-center text-sm text-muted-foreground">
                        No items added yet.
                    </div>
                )}
            </div>
        </section>
    );
}
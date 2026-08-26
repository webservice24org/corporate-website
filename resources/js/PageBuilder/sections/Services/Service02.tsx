import {
    ArrowRight,
} from "lucide-react";

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

type ServiceItem = {
    image?: string;

    title?: string;

    show_description?: boolean;
    description?: string;

    button_text?: string;
    button_url?: string;
};

type Props = {
    settings: SectionSettings;
    preview?: boolean;
};

export default function Service02({
    settings,
    preview = false,
}: Props) {
    const items =
        (settings.items as
            | ServiceItem[]
            | undefined) ?? [];

    const topTitle = String(
        settings.top_title ?? "",
    );

    const title = String(
        settings.title ??
            "Our Services",
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
            "#0f172a",
    );

    const textColor = String(
        settings.text_color ??
            "#64748b",
    );

    const accentColor = String(
        settings.accent_color ??
            "#2563eb",
    );

    const alignment =
        (settings.text_alignment as
            | "left"
            | "center"
            | "right") ?? "center";

    const columns = String(
        settings.columns ?? "3",
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
                <div
                    className={`mb-14 max-w-3xl ${headerPosition}`}
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
                            className="mt-5 leading-7"
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

                {items.length > 0 ? (
                    <div
                        className={`grid grid-cols-1 gap-7 ${gridClass}`}
                    >
                        {items.map(
                            (
                                item,
                                index,
                            ) => {
                                const itemTitle =
                                    String(
                                        item.title ??
                                            `Service ${
                                                index +
                                                1
                                            }`,
                                    );

                                const itemDescription =
                                    String(
                                        item.description ??
                                            "",
                                    );

                                const showDescription =
                                    item.show_description !==
                                    false;

                                const buttonText =
                                    String(
                                        item.button_text ??
                                            "",
                                    );

                                const buttonUrl =
                                    String(
                                        item.button_url ??
                                            "#",
                                    );

                                return (
                                    <article
                                        key={
                                            index
                                        }
                                        className="group overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-xl"
                                    >
                                        <div className="aspect-[16/10] overflow-hidden bg-slate-100">
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
                                                    Service
                                                    Image
                                                </div>
                                            )}
                                        </div>

                                        <div className="flex h-full flex-col p-6">
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

                                            {showDescription &&
                                                itemDescription && (
                                                    <p
                                                        className="mt-3 leading-6"
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

                                            {buttonText && (
                                                <div className="mt-6">
                                                    <a
                                                        href={
                                                            preview
                                                                ? undefined
                                                                : buttonUrl
                                                        }
                                                        onClick={(
                                                            event,
                                                        ) => {
                                                            if (
                                                                preview
                                                            ) {
                                                                event.preventDefault();
                                                            }
                                                        }}
                                                        className="inline-flex items-center gap-2 text-sm font-semibold transition-opacity hover:opacity-70"
                                                        style={{
                                                            color:
                                                                accentColor,
                                                        }}
                                                    >
                                                        {
                                                            buttonText
                                                        }

                                                        <ArrowRight className="size-4 transition-transform group-hover:translate-x-1" />
                                                    </a>
                                                </div>
                                            )}
                                        </div>
                                    </article>
                                );
                            },
                        )}
                    </div>
                ) : (
                    <div className="rounded-xl border border-dashed p-12 text-center text-sm text-muted-foreground">
                        No services added yet.
                    </div>
                )}
            </div>
        </section>
    );
}
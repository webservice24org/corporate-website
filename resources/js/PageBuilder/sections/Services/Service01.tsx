import type {
    CSSProperties,
} from "react";
import {
    ArrowRight,
} from "lucide-react";

import {
    getBuilderIcon,
} from "../../utils/icons";

import type {
    LucideIcon,
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
    icon?: string;
    title?: string;
    description?: string;

    button_text?: string;
    button_url?: string;
};

type Props = {
    settings: SectionSettings;
    preview?: boolean;
};


export default function Service01({
    settings,
    preview = false,
}: Props) {
    const items =
        (settings.items as
            | ServiceItem[]
            | undefined) ?? [];

    /*
     * Content
     */
    const topTitle = String(
        settings.top_title ?? "",
    );

    const title = String(
        settings.title ??
            "Our Premium Services",
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
            "#ffffff",
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
            "#4b5563",
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

    const justifyClass =
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
                {/*
                 * Header
                 */}
                <div
                    className={`mb-14 max-w-3xl ${justifyClass}`}
                    style={{
                        textAlign: alignment,
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

                    <div
                        className={`mt-4 h-1 w-24 rounded-full ${
                            alignment ===
                            "center"
                                ? "mx-auto"
                                : alignment ===
                                    "right"
                                  ? "ml-auto"
                                  : ""
                        }`}
                        style={{
                            backgroundColor:
                                accentColor,
                        }}
                    />

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

                {/*
                 * Dynamic service grid
                 */}
                {items.length > 0 ? (
                    <div
                        className={`grid grid-cols-1 gap-8 ${gridClass}`}
                    >
                        {items.map(
                            (
                                item,
                                index,
                            ) => {
                                const Icon =
                                    getBuilderIcon(
                                        item.icon ??
                                            "briefcase",
                                    );

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
                                        key={index}
                                        className="service-card group relative flex min-h-full flex-col justify-between overflow-hidden rounded-2xl border border-slate-200 bg-white p-6 shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-2xl"
                                        style={
                                            {
                                                "--service-accent":
                                                    accentColor,
                                            } as CSSProperties
                                        }
                                    >
                                        {/*
                                         * Decorative corner
                                         */}
                                        <div
                                            className="pointer-events-none absolute -right-4 -top-4 size-24 rounded-bl-full opacity-[0.06] transition-transform duration-300 group-hover:scale-110"
                                            style={{
                                                backgroundColor:
                                                    accentColor,
                                            }}
                                        />

                                        <div className="relative z-10">
                                            {/*
                                             * Icon
                                             */}
                                            <div
                                                className="service-icon mb-5 flex size-14 items-center justify-center rounded-xl shadow-sm transition-all duration-300"
                                                style={{
                                                    color:
                                                        accentColor,

                                                    backgroundColor:
                                                        `${accentColor}18`,
                                                }}
                                            >
                                                <Icon className="size-7" />
                                            </div>

                                            <h3
                                                className="mb-2 text-xl font-bold transition-colors"
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
                                                    className="mb-6 text-sm leading-relaxed"
                                                    style={{
                                                        color:
                                                            textColor,
                                                    }}
                                                >
                                                    {
                                                        itemDescription
                                                    }
                                                </p>
                                            )}
                                        </div>

                                        {buttonText && (
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
                                                className="service-button relative z-10 mt-auto inline-flex w-full items-center justify-center gap-2.5 rounded-xl bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700 shadow-inner transition-all duration-200 hover:shadow-md"
                                                
                                            >
                                                <span>
                                                    {
                                                        buttonText
                                                    }
                                                </span>

                                                <ArrowRight className="size-4 transition-transform duration-200 group-hover:translate-x-1" />
                                            </a>
                                        )}
                                    </article>
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
                            No services
                            added yet.
                        </p>
                    </div>
                )}
            </div>
        </section>
    );
}
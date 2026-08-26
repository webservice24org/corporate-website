import {
    ArrowRight,
} from "lucide-react";

import {
    useEffect,
    useState,
} from "react";

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

type ServiceCard = {
    image?: string;
    icon?: string;

    title?: string;
    count_text?: string;

    button_url?: string;
};

type ServiceCategory = {
    title?: string;
    services?: ServiceCard[];
};

type Props = {
    settings: SectionSettings;
    preview?: boolean;
};

export default function Service03({
    settings,
    preview = false,
}: Props) {
    const categories =
        (settings.categories as
            | ServiceCategory[]
            | undefined) ?? [];

    const [activeIndex, setActiveIndex] =
        useState(0);

    useEffect(() => {
        if (!categories.length) {
            setActiveIndex(0);
            return;
        }

        if (
            activeIndex >
            categories.length - 1
        ) {
            setActiveIndex(0);
        }
    }, [
        categories.length,
        activeIndex,
    ]);

    if (!categories.length) {
        return null;
    }

    const safeIndex = Math.min(
        activeIndex,
        categories.length - 1,
    );

    const activeCategory =
        categories[safeIndex];

    const services =
        activeCategory.services ?? [];

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

    const titleFontSize = Number(
        settings.title_font_size ?? 32,
    );

    const textFontSize = Number(
        settings.text_font_size ?? 16,
    );

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
            ? "sm:grid-cols-2"
            : columns === "4"
              ? "sm:grid-cols-2 xl:grid-cols-4"
              : "sm:grid-cols-2 xl:grid-cols-3";

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
                <div className="grid grid-cols-1 gap-8 lg:grid-cols-4">
                    {/* CATEGORY SIDEBAR */}
                    <aside>
                        <nav className="overflow-hidden rounded-xl border bg-white/80 backdrop-blur">
                            {categories.map(
                                (
                                    category,
                                    index,
                                ) => {
                                    const active =
                                        index ===
                                        safeIndex;

                                    return (
                                        <button
                                            key={
                                                index
                                            }
                                            type="button"
                                            onClick={() =>
                                                setActiveIndex(
                                                    index,
                                                )
                                            }
                                            className="block w-full border-b px-6 py-4 text-left text-base transition-colors last:border-b-0"
                                            style={{
                                                backgroundColor:
                                                    active
                                                        ? `${accentColor}12`
                                                        : "transparent",

                                                color:
                                                    active
                                                        ? titleColor
                                                        : textColor,

                                                fontWeight:
                                                    active
                                                        ? 600
                                                        : 400,

                                                borderColor:
                                                    `${accentColor}18`,
                                            }}
                                        >
                                            {String(
                                                category.title ??
                                                    `Category ${
                                                        index +
                                                        1
                                                    }`,
                                            )}
                                        </button>
                                    );
                                },
                            )}
                        </nav>
                    </aside>

                    {/* CATEGORY CONTENT */}
                    <main className="min-w-0 lg:col-span-3">
                        <div className="mb-6 flex items-end justify-between gap-4">
                            <h2
                                className="font-bold tracking-tight"
                                style={{
                                    color:
                                        titleColor,

                                    fontSize:
                                        `${titleFontSize}px`,
                                }}
                            >
                                {String(
                                    activeCategory.title ??
                                        "Services",
                                )}
                            </h2>

                            <span
                                className="text-sm"
                                style={{
                                    color:
                                        textColor,
                                }}
                            >
                                {services.length}{" "}
                                {services.length ===
                                1
                                    ? "Service"
                                    : "Services"}
                            </span>
                        </div>

                        {services.length >
                        0 ? (
                            <div
                                className={`grid grid-cols-1 gap-6 ${gridClass}`}
                            >
                                {services.map(
                                    (
                                        service,
                                        index,
                                    ) => {
                                        const Icon =
                                            getBuilderIcon(
                                                service.icon ??
                                                    "briefcase",
                                            );

                                        const serviceTitle =
                                            String(
                                                service.title ??
                                                    `Service ${
                                                        index +
                                                        1
                                                    }`,
                                            );

                                        const countText =
                                            String(
                                                service.count_text ??
                                                    "",
                                            );

                                        const buttonUrl =
                                            String(
                                                service.button_url ??
                                                    "#",
                                            );

                                        return (
                                            <a
                                                key={
                                                    index
                                                }
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
                                                className="group relative block h-72 overflow-hidden rounded-2xl bg-slate-100 shadow-sm"
                                            >
                                                {service.image ? (
                                                    <img
                                                        src={
                                                            service.image
                                                        }
                                                        alt={
                                                            serviceTitle
                                                        }
                                                        className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
                                                    />
                                                ) : (
                                                    <div className="flex h-full items-center justify-center bg-slate-100 text-sm text-slate-400">
                                                        Service
                                                        Image
                                                    </div>
                                                )}

                                                <div className="absolute inset-0 bg-gradient-to-t from-black/20 via-transparent to-transparent" />

                                                <div className="absolute bottom-4 left-4 right-4 flex items-center gap-3 rounded-xl bg-white p-3 shadow-lg">
                                                    <div
                                                        className="flex size-10 shrink-0 items-center justify-center rounded-lg"
                                                        style={{
                                                            color:
                                                                accentColor,

                                                            backgroundColor:
                                                                `${accentColor}12`,
                                                        }}
                                                    >
                                                        <Icon className="size-5" />
                                                    </div>

                                                    <div className="min-w-0 flex-1">
                                                        <h3
                                                            className="truncate text-sm font-bold"
                                                            style={{
                                                                color:
                                                                    titleColor,
                                                            }}
                                                        >
                                                            {
                                                                serviceTitle
                                                            }
                                                        </h3>

                                                        {countText && (
                                                            <p
                                                                className="mt-0.5 text-xs font-medium"
                                                                style={{
                                                                    color:
                                                                        textColor,

                                                                    fontSize:
                                                                        `${Math.max(
                                                                            11,
                                                                            textFontSize -
                                                                                4,
                                                                        )}px`,
                                                                }}
                                                            >
                                                                {
                                                                    countText
                                                                }
                                                            </p>
                                                        )}
                                                    </div>

                                                    <ArrowRight
                                                        className="size-4 shrink-0 transition-transform duration-200 group-hover:translate-x-1"
                                                        style={{
                                                            color:
                                                                accentColor,
                                                        }}
                                                    />
                                                </div>
                                            </a>
                                        );
                                    },
                                )}
                            </div>
                        ) : (
                            <div className="rounded-xl border border-dashed p-10 text-center">
                                <p
                                    className="text-sm"
                                    style={{
                                        color:
                                            textColor,
                                    }}
                                >
                                    No services
                                    added to this
                                    category.
                                </p>
                            </div>
                        )}
                    </main>
                </div>
            </div>
        </section>
    );
}
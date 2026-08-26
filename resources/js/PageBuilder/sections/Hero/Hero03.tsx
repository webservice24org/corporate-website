import {
    useEffect,
    useState,
} from "react";

import {
    ArrowLeft,
    ArrowRight,
} from "lucide-react";

import type { SectionSettings } from "../../types";

import {
    getContainerClass,
    getCustomClass,
    getHorizontalPadding,
    getSectionId,
    getSectionSpacing,
    getVisibilityClass,
} from "../../utils/sectionStyles";

import type {
    SliderTransition,
} from "../../utils/sliderTransition";

type SplitSlide = {
    top_title?: string;
    title?: string;
    description?: string;

    image?: string;

    button_text?: string;
    button_url?: string;
};

type Props = {
    settings: SectionSettings;
    preview?: boolean;
};

export default function Hero03({
    settings,
    preview = false,
}: Props) {
    const slides =
        (settings.slides as
            | SplitSlide[]
            | undefined) ?? [];

    const autoplay = Boolean(
        settings.autoplay ?? true,
    );

    const interval = Number(
        settings.interval ?? 5000,
    );

    /*
     * Slider transition
     */
    const transitionEffect =
        (settings.transition_effect as SliderTransition) ??
        "slide-left";

    const transitionDuration = Math.max(
        0,
        Number(
            settings.transition_duration ?? 700,
        ),
    );

    const transitionEasing = String(
        settings.transition_easing ??
            "ease-in-out",
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
        settings.title_font_size ?? 64,
    );

    const textFontSize = Number(
        settings.text_font_size ?? 18,
    );

    const titleColor = String(
        settings.title_color ?? "#0f172a",
    );

    const textColor = String(
        settings.text_color ?? "#475569",
    );

    const accentColor = String(
        settings.accent_color ?? "#2563eb",
    );

    const alignment =
        (settings.text_alignment as
            | "left"
            | "center"
            | "right") ?? "left";

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

    const [activeIndex, setActiveIndex] =
        useState(0);

    /*
     * Keep active index valid
     */
    useEffect(() => {
        if (!slides.length) {
            setActiveIndex(0);
            return;
        }

        if (
            activeIndex >
            slides.length - 1
        ) {
            setActiveIndex(0);
        }
    }, [
        slides.length,
        activeIndex,
    ]);

    /*
     * Autoplay
     */
    useEffect(() => {
        if (
            preview ||
            !autoplay ||
            slides.length <= 1
        ) {
            return;
        }

        const timer =
            window.setInterval(() => {
                setActiveIndex(
                    (current) =>
                        current + 1 >=
                        slides.length
                            ? 0
                            : current + 1,
                );
            }, Math.max(2000, interval));

        return () => {
            window.clearInterval(timer);
        };
    }, [
        autoplay,
        interval,
        preview,
        slides.length,
    ]);

    if (!slides.length) {
        return null;
    }

    const safeIndex = Math.min(
        activeIndex,
        slides.length - 1,
    );

    const previous = () => {
        setActiveIndex(
            (current) =>
                current === 0
                    ? slides.length - 1
                    : current - 1,
        );
    };

    const next = () => {
        setActiveIndex(
            (current) =>
                current + 1 >=
                slides.length
                    ? 0
                    : current + 1,
        );
    };

    /*
     * Transition state for every slide.
     */
    const getSlideTransform = (
        index: number,
    ) => {
        const isActive =
            index === safeIndex;

        if (
            preview ||
            transitionEffect === "none"
        ) {
            return {
                opacity:
                    isActive ? 1 : 0,

                transform:
                    "translateX(0)",

                pointerEvents:
                    isActive
                        ? ("auto" as const)
                        : ("none" as const),
            };
        }

        if (
            transitionEffect === "fade"
        ) {
            return {
                opacity:
                    isActive ? 1 : 0,

                transform:
                    "translateX(0)",

                pointerEvents:
                    isActive
                        ? ("auto" as const)
                        : ("none" as const),
            };
        }

        if (
            transitionEffect ===
            "slide-left"
        ) {
            let translateX = "100%";

            if (isActive) {
                translateX = "0%";
            } else if (
                index < safeIndex
            ) {
                translateX = "-100%";
            }

            return {
                opacity: 1,

                transform:
                    `translateX(${translateX})`,

                pointerEvents:
                    isActive
                        ? ("auto" as const)
                        : ("none" as const),
            };
        }

        /*
         * slide-right
         */
        let translateX = "-100%";

        if (isActive) {
            translateX = "0%";
        } else if (
            index < safeIndex
        ) {
            translateX = "100%";
        }

        return {
            opacity: 1,

            transform:
                `translateX(${translateX})`,

            pointerEvents:
                isActive
                    ? ("auto" as const)
                    : ("none" as const),
        };
    };

    return (
        <section
            id={sectionId || undefined}
            className={`relative overflow-hidden bg-cover bg-center ${visibilityClass} ${customClass}`}
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
            {/*
             * Keep every slide mounted.
             */}
            <div className="relative min-h-[650px] overflow-hidden">
                {slides.map(
                    (
                        slide,
                        index,
                    ) => {
                        const slideState =
                            getSlideTransform(
                                index,
                            );

                        return (
                            <div
                                key={index}
                                className="absolute inset-0"
                                style={{
                                    opacity:
                                        slideState.opacity,

                                    transform:
                                        slideState.transform,

                                    pointerEvents:
                                        slideState.pointerEvents,

                                    transitionProperty:
                                        transitionEffect ===
                                        "none"
                                            ? "none"
                                            : "opacity, transform",

                                    transitionDuration:
                                        transitionEffect ===
                                        "none"
                                            ? "0ms"
                                            : `${transitionDuration}ms`,

                                    transitionTimingFunction:
                                        transitionEasing,
                                }}
                            >
                                <div
                                    className={`mx-auto grid min-h-[650px] w-full items-center lg:grid-cols-2 ${containerClass}`}
                                >
                                    {/*
                                     * LEFT CONTENT
                                     */}
                                    <div
                                        className="flex min-h-[650px] items-center"
                                        style={{
                                            paddingLeft:
                                                horizontalPadding,

                                            paddingRight:
                                                horizontalPadding,
                                        }}
                                    >
                                        <div
                                            className="w-full"
                                            style={{
                                                textAlign:
                                                    alignment,
                                            }}
                                        >
                                            {slide.top_title && (
                                                <p
                                                    className="mb-4 text-sm font-semibold uppercase tracking-[0.2em]"
                                                    style={{
                                                        color: accentColor,
                                                    }}
                                                >
                                                    {
                                                        slide.top_title
                                                    }
                                                </p>
                                            )}

                                            <h1
                                                className="font-bold tracking-tight"
                                                style={{
                                                    color: titleColor,

                                                    fontSize:
                                                        `${titleFontSize}px`,
                                                }}
                                            >
                                                {
                                                    slide.title
                                                }
                                            </h1>

                                            {slide.description && (
                                                <p
                                                    className="mt-6 max-w-xl leading-7"
                                                    style={{
                                                        color: textColor,

                                                        fontSize:
                                                            `${textFontSize}px`,

                                                        marginLeft:
                                                            alignment ===
                                                            "center"
                                                                ? "auto"
                                                                : alignment ===
                                                                    "right"
                                                                  ? "auto"
                                                                  : undefined,

                                                        marginRight:
                                                            alignment ===
                                                            "center"
                                                                ? "auto"
                                                                : undefined,
                                                    }}
                                                >
                                                    {
                                                        slide.description
                                                    }
                                                </p>
                                            )}

                                            {slide.button_text && (
                                                <div
                                                    className={`mt-8 flex ${
                                                        alignment ===
                                                        "center"
                                                            ? "justify-center"
                                                            : alignment ===
                                                                "right"
                                                              ? "justify-end"
                                                              : ""
                                                    }`}
                                                >
                                                    <a
                                                        href={
                                                            preview
                                                                ? undefined
                                                                : slide.button_url
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
                                                        className="inline-flex rounded-lg px-5 py-3 text-sm font-semibold text-white"
                                                        style={{
                                                            backgroundColor:
                                                                accentColor,
                                                        }}
                                                    >
                                                        {
                                                            slide.button_text
                                                        }
                                                    </a>
                                                </div>
                                            )}

                                            {/*
                                             * Slider arrows
                                             */}
                                            {!preview &&
                                                slides.length >
                                                    1 && (
                                                    <div
                                                        className={`mt-10 flex gap-2 ${
                                                            alignment ===
                                                            "center"
                                                                ? "justify-center"
                                                                : alignment ===
                                                                    "right"
                                                                  ? "justify-end"
                                                                  : ""
                                                        }`}
                                                    >
                                                        <button
                                                            type="button"
                                                            onClick={
                                                                previous
                                                            }
                                                            aria-label="Previous slide"
                                                            className="flex size-10 items-center justify-center rounded-full border transition"
                                                            style={{
                                                                borderColor:
                                                                    `${accentColor}40`,

                                                                color:
                                                                    accentColor,
                                                            }}
                                                        >
                                                            <ArrowLeft />
                                                        </button>

                                                        <button
                                                            type="button"
                                                            onClick={
                                                                next
                                                            }
                                                            aria-label="Next slide"
                                                            className="flex size-10 items-center justify-center rounded-full text-white transition"
                                                            style={{
                                                                backgroundColor:
                                                                    accentColor,
                                                            }}
                                                        >
                                                            <ArrowRight />
                                                        </button>
                                                    </div>
                                                )}
                                        </div>
                                    </div>

                                    {/*
                                     * RIGHT IMAGE
                                     */}
                                    <div className="min-h-[650px] overflow-hidden bg-slate-100">
                                        {slide.image ? (
                                            <img
                                                src={
                                                    slide.image
                                                }
                                                alt=""
                                                className="h-full min-h-[650px] w-full object-cover"
                                            />
                                        ) : (
                                            <div className="flex min-h-[650px] items-center justify-center text-sm text-slate-400">
                                                Hero
                                                Image
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        );
                    },
                )}

                {/*
                 * Dots stay outside individual slides,
                 * so they don't move with the transition.
                 */}
                {!preview &&
                    slides.length >
                        1 && (
                        <div className="absolute bottom-6 left-1/2 z-30 flex -translate-x-1/2 gap-2">
                            {slides.map(
                                (
                                    _,
                                    index,
                                ) => (
                                    <button
                                        key={
                                            index
                                        }
                                        type="button"
                                        aria-label={`Go to slide ${
                                            index +
                                            1
                                        }`}
                                        onClick={() =>
                                            setActiveIndex(
                                                index,
                                            )
                                        }
                                        className="size-2.5 rounded-full transition-all"
                                        style={{
                                            backgroundColor:
                                                index ===
                                                safeIndex
                                                    ? accentColor
                                                    : `${accentColor}55`,

                                            transform:
                                                index ===
                                                safeIndex
                                                    ? "scale(1.2)"
                                                    : "scale(1)",
                                        }}
                                    />
                                ),
                            )}
                        </div>
                    )}
            </div>
        </section>
    );
}
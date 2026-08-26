import {
    useEffect,
    useState,
} from "react";

import {
    ChevronLeft,
    ChevronRight,
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

type HeroSlide = {
    top_title?: string;
    title?: string;
    description?: string;

    background_type?:
        | "image"
        | "color";

    background_image?: string;
    background_color?: string;

    button_text?: string;
    button_url?: string;
};

type Props = {
    settings: SectionSettings;
    preview?: boolean;
};

export default function Hero02({
    settings,
    preview = false,
}: Props) {
    const slides =
        (settings.slides as
            | HeroSlide[]
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
        "fade";

    const transitionDuration = Math.max(
        0,
        Number(
            settings.transition_duration ??
                700,
        ),
    );

    const transitionEasing = String(
        settings.transition_easing ??
            "ease-in-out",
    );

    /*
     * Style
     */
    const titleFontSize = Number(
        settings.title_font_size ?? 64,
    );

    const textFontSize = Number(
        settings.text_font_size ?? 18,
    );

    const titleColor = String(
        settings.title_color ?? "#ffffff",
    );

    const textColor = String(
        settings.text_color ?? "#e2e8f0",
    );

    const accentColor = String(
        settings.accent_color ?? "#ffffff",
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
     * Keep active index valid if slides change.
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

        const timer = window.setInterval(
            () => {
                setActiveIndex(
                    (current) =>
                        current + 1 >=
                        slides.length
                            ? 0
                            : current + 1,
                );
            },
            Math.max(
                2000,
                interval,
            ),
        );

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
        setActiveIndex((current) =>
            current === 0
                ? slides.length - 1
                : current - 1,
        );
    };

    const next = () => {
        setActiveIndex((current) =>
            current + 1 >=
            slides.length
                ? 0
                : current + 1,
        );
    };

    /*
     * Calculates where each slide should sit.
     *
     * Fade:
     * inactive slides remain in place but transparent.
     *
     * Slide Left:
     * previous slides sit to the left,
     * upcoming slides sit to the right.
     *
     * Slide Right:
     * opposite direction.
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
            className={`relative overflow-hidden ${visibilityClass} ${customClass}`}
            style={{
                ...sectionSpacing,
            }}
        >
            {/*
             * All slides stay mounted.
             * CSS moves/fades them when activeIndex changes.
             */}
            <div className="relative min-h-[620px] overflow-hidden">
                {slides.map(
                    (
                        slide,
                        index,
                    ) => {
                        const hasImage =
                            slide.background_type ===
                                "image" &&
                            Boolean(
                                slide.background_image,
                            );

                        const slideState =
                            getSlideTransform(
                                index,
                            );

                        return (
                            <div
                                key={index}
                                className="absolute inset-0 bg-cover bg-center"
                                style={{
                                    backgroundColor:
                                        slide.background_color ||
                                        "#0f172a",

                                    backgroundImage:
                                        hasImage
                                            ? `url("${slide.background_image}")`
                                            : undefined,

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
                                {/*
                                 * Overlay
                                 */}
                                <div className="absolute inset-0 bg-black/45" />

                                {/*
                                 * Slide content
                                 */}
                                <div
                                    className={`relative z-10 mx-auto flex min-h-[620px] w-full items-center ${containerClass}`}
                                    style={{
                                        paddingLeft:
                                            horizontalPadding,

                                        paddingRight:
                                            horizontalPadding,
                                    }}
                                >
                                    <div
                                        className="w-full max-w-3xl"
                                        style={{
                                            textAlign:
                                                alignment,

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
                                                className="mt-6 max-w-2xl leading-7"
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
                                                    className="inline-flex rounded-lg px-5 py-3 text-sm font-semibold"
                                                    style={{
                                                        backgroundColor:
                                                            accentColor,

                                                        color:
                                                            "#020617",
                                                    }}
                                                >
                                                    {
                                                        slide.button_text
                                                    }
                                                </a>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        );
                    },
                )}

                {/*
                 * Controls
                 *
                 * Keep these outside individual slides so they do
                 * not animate along with the slide.
                 */}
                {!preview &&
                    slides.length >
                        1 && (
                        <>
                            <button
                                type="button"
                                aria-label="Previous slide"
                                onClick={
                                    previous
                                }
                                className="absolute left-5 top-1/2 z-30 flex size-10 -translate-y-1/2 items-center justify-center rounded-full bg-black/30 text-white backdrop-blur transition hover:bg-black/50"
                            >
                                <ChevronLeft />
                            </button>

                            <button
                                type="button"
                                aria-label="Next slide"
                                onClick={
                                    next
                                }
                                className="absolute right-5 top-1/2 z-30 flex size-10 -translate-y-1/2 items-center justify-center rounded-full bg-black/30 text-white backdrop-blur transition hover:bg-black/50"
                            >
                                <ChevronRight />
                            </button>

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
                                                        : `${accentColor}66`,

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
                        </>
                    )}
            </div>
        </section>
    );
}
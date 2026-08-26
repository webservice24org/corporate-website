import {
    useEffect,
    useState,
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


type Props = {
    settings: SectionSettings;
    preview?: boolean;
};

export default function PageBanner01({
    settings,
    preview = false,
}: Props) {
    const badge = String(
        settings.badge ?? "Who We Are",
    );

    const title = String(
        settings.title ??
            "Engineering Excellence & Unmatched Quality",
    );

    const typingEffect = Boolean(
        settings.typing_effect ?? true,
    );

    const typingSpeed = Math.max(
        20,
        Number(
            settings.typing_speed ?? 70,
        ),
    );
    const [typedTitle, setTypedTitle] =
    useState(
        preview || !typingEffect
            ? title
            : "",
    );

    const description = String(
        settings.description ??
            "Empowering modern infrastructure with advanced chemical admixtures, durable construction materials, and innovative industrial engineering supplies.",
    );

    /*
     * Style
     */
    const backgroundType = String(
        settings.background_type ?? "color",
    );

    const backgroundColor = String(
        settings.background_color ?? "#030712",
    );

    const backgroundImage = String(
        settings.background_image ?? "",
    );

    const overlayOpacity = Math.min(
        1,
        Math.max(
            0,
            Number(
                settings.overlay_opacity ?? 0.55,
            ),
        ),
    );

    const titleFontSize = Number(
        settings.title_font_size ?? 56,
    );

    const textFontSize = Number(
        settings.text_font_size ?? 18,
    );

    const titleColor = String(
        settings.title_color ?? "#ffffff",
    );

    const textColor = String(
        settings.text_color ?? "#d1d5db",
    );

    const accentColor = String(
        settings.accent_color ?? "#10b981",
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

    const contentPosition =
        alignment === "center"
            ? "mx-auto"
            : alignment === "right"
              ? "ml-auto"
              : "";

    useEffect(() => {
        if (
            preview ||
            !typingEffect
        ) {
            setTypedTitle(title);
            return;
        }

        const reduceMotion =
            window.matchMedia(
                "(prefers-reduced-motion: reduce)",
            ).matches;

        if (reduceMotion) {
            setTypedTitle(title);
            return;
        }

        setTypedTitle("");

        let index = 0;

        const timer =
            window.setInterval(() => {
                index += 1;

                setTypedTitle(
                    title.slice(
                        0,
                        index,
                    ),
                );

                if (
                    index >=
                    title.length
                ) {
                    window.clearInterval(
                        timer,
                    );
                }
            }, typingSpeed);

        return () => {
            window.clearInterval(
                timer,
            );
        };
    }, [
        title,
        typingEffect,
        typingSpeed,
        preview,
    ]);

    return (
        <section
            id={sectionId || undefined}
            className={`relative overflow-hidden border-b bg-cover bg-center ${visibilityClass} ${customClass}`}
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

                borderColor:
                    `${titleColor}18`,
            }}
        >
            {backgroundType === "image" &&
                backgroundImage && (
                    <div
                        className="absolute inset-0"
                        style={{
                            backgroundColor:
                                `rgba(0,0,0,${overlayOpacity})`,
                        }}
                    />
                )}

            <div
                className={`relative z-10 mx-auto w-full ${containerClass}`}
                style={{
                    paddingLeft:
                        horizontalPadding,

                    paddingRight:
                        horizontalPadding,
                }}
            >
                <div
                    className={`max-w-4xl ${contentPosition}`}
                    style={{
                        textAlign:
                            alignment,
                    }}
                >
                    {badge && (
                        <span
                            className="inline-flex rounded-full border px-4 py-1.5 text-sm font-bold uppercase tracking-widest"
                            style={{
                                color:
                                    accentColor,

                                backgroundColor:
                                    `${accentColor}18`,

                                borderColor:
                                    `${accentColor}35`,
                            }}
                        >
                            {badge}
                        </span>
                    )}

                    <h1
                        className="mt-6 font-extrabold tracking-tight leading-tight"
                        style={{
                            color:
                                titleColor,

                            fontSize:
                                `${titleFontSize}px`,
                        }}
                    >
                        {typedTitle}

                        {typingEffect &&
                            !preview && (
                                <span
                                    aria-hidden="true"
                                    className="ml-1 inline-block animate-pulse"
                                    style={{
                                        color:
                                            accentColor,
                                    }}
                                >
                                    |
                                </span>
                            )}
                    </h1>

                    {description && (
                        <p
                            className={`mt-5 max-w-3xl font-light leading-relaxed ${contentPosition}`}
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
            </div>
        </section>
    );
}
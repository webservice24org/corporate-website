import { useEffect, useState } from "react";

import type { SectionSettings } from "../../types";

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

export default function Hero01({
    settings,
    preview = false,
}: Props) {
    const topTitle = String(
        settings.top_title ?? "Welcome",
    );

    const title = String(
        settings.title ??
            "We build better digital businesses",
    );

    const description = String(
        settings.description ??
            "Modern corporate solutions designed to help organizations grow.",
    );

    const primaryButtonText = String(
        settings.primary_button_text ?? "Get Started",
    );

    const primaryButtonUrl = String(
        settings.primary_button_url ?? "#",
    );

    const secondaryButtonText = String(
        settings.secondary_button_text ?? "Learn More",
    );

    const secondaryButtonUrl = String(
        settings.secondary_button_url ?? "#",
    );

    const typingSpeed = Number(
        settings.typing_speed ?? 70,
    );

    // Style
    const backgroundType = String(
        settings.background_type ?? "color",
    );

    const backgroundColor = String(
        settings.background_color ?? "#020617",
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
        settings.title_color ?? "#ffffff",
    );

    const textColor = String(
        settings.text_color ?? "#cbd5e1",
    );

    const accentColor = String(
        settings.accent_color ?? "#ffffff",
    );

    const alignment =
        (settings.text_alignment as
            | "left"
            | "center"
            | "right") ?? "left";

    // Advanced
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

    const [typedTitle, setTypedTitle] =
        useState(preview ? title : "");

    useEffect(() => {
        if (preview) {
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
                    title.slice(0, index),
                );

                if (
                    index >= title.length
                ) {
                    window.clearInterval(
                        timer,
                    );
                }
            }, Math.max(20, typingSpeed));

        return () => {
            window.clearInterval(timer);
        };
    }, [
        title,
        typingSpeed,
        preview,
    ]);

    return (
        <section
            id={sectionId || undefined}
            className={`overflow-hidden bg-cover bg-center ${visibilityClass} ${customClass}`}
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
                    className="max-w-4xl"
                    style={{
                        textAlign: alignment,
                        marginLeft:
                            alignment === "center"
                                ? "auto"
                                : undefined,
                        marginRight:
                            alignment === "center"
                                ? "auto"
                                : undefined,
                    }}
                >
                    {topTitle && (
                        <p
                            className="mb-4 text-sm font-semibold uppercase tracking-[0.2em]"
                            style={{
                                color: accentColor,
                            }}
                        >
                            {topTitle}
                        </p>
                    )}

                    <h1
                        className="font-bold tracking-tight"
                        style={{
                            color: titleColor,
                            fontSize: `${titleFontSize}px`,
                            minHeight:
                                preview
                                    ? undefined
                                    : "1.2em",
                        }}
                    >
                        {typedTitle}

                        {!preview && (
                            <span
                                aria-hidden="true"
                                className="ml-1 inline-block animate-pulse"
                            >
                                |
                            </span>
                        )}
                    </h1>

                    {description && (
                        <p
                            className="mt-6 max-w-2xl leading-7"
                            style={{
                                color: textColor,
                                fontSize: `${textFontSize}px`,
                                marginLeft:
                                    alignment ===
                                    "center"
                                        ? "auto"
                                        : undefined,
                                marginRight:
                                    alignment ===
                                    "center"
                                        ? "auto"
                                        : undefined,
                            }}
                        >
                            {description}
                        </p>
                    )}

                    <div
                        className={`mt-8 flex flex-wrap gap-3 ${
                            alignment ===
                            "center"
                                ? "justify-center"
                                : alignment ===
                                    "right"
                                  ? "justify-end"
                                  : ""
                        }`}
                    >
                        {primaryButtonText && (
                            <a
                                href={
                                    preview
                                        ? undefined
                                        : primaryButtonUrl
                                }
                                onClick={(e) => {
                                    if (
                                        preview
                                    ) {
                                        e.preventDefault();
                                    }
                                }}
                                className="inline-flex rounded-lg px-5 py-3 text-sm font-semibold"
                                style={{
                                    backgroundColor:
                                        accentColor,
                                    color:
                                        backgroundType ===
                                            "color" &&
                                        backgroundColor ===
                                            "#ffffff"
                                            ? "#ffffff"
                                            : "#020617",
                                }}
                            >
                                {
                                    primaryButtonText
                                }
                            </a>
                        )}

                        {secondaryButtonText && (
                            <a
                                href={
                                    preview
                                        ? undefined
                                        : secondaryButtonUrl
                                }
                                onClick={(e) => {
                                    if (
                                        preview
                                    ) {
                                        e.preventDefault();
                                    }
                                }}
                                className="inline-flex rounded-lg border px-5 py-3 text-sm font-semibold"
                                style={{
                                    color: titleColor,
                                    borderColor:
                                        `${titleColor}40`,
                                }}
                            >
                                {
                                    secondaryButtonText
                                }
                            </a>
                        )}
                    </div>
                </div>
            </div>
        </section>
    );
}
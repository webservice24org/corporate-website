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

export default function Map01({
    settings,
    preview = false,
}: Props) {
    /*
     * Content
     */
    const topTitle = String(
        settings.top_title ?? "",
    );

    const title = String(
        settings.title ??
            "Find Us",
    );

    const description = String(
        settings.description ?? "",
    );

    const mapUrl = normalizeGoogleMapUrl(
        String(
            settings.map_url ?? "",
        ),
    );

    /*
     * Map options
     */
    const mapHeight = Math.max(
        200,
        Number(
            settings.map_height ?? 450,
        ),
    );

    const borderRadius = Number(
        settings.map_radius ?? 24,
    );

    const fullWidth = Boolean(
        settings.full_width ?? false,
    );

    const showHeader = Boolean(
        settings.show_header ?? true,
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

    const headerPosition =
        alignment === "center"
            ? "mx-auto"
            : alignment === "right"
              ? "ml-auto"
              : "";

    const content = (
        <>
            {showHeader && (
                <div
                    className={`mb-10 max-w-3xl ${headerPosition}`}
                    style={{
                        textAlign:
                            alignment,
                    }}
                >
                    {topTitle && (
                        <span
                            className="inline-flex rounded-full border px-3 py-1 text-xs font-semibold uppercase tracking-wider"
                            style={{
                                color:
                                    accentColor,

                                backgroundColor:
                                    `${accentColor}0d`,

                                borderColor:
                                    `${accentColor}20`,
                            }}
                        >
                            {topTitle}
                        </span>
                    )}

                    {title && (
                        <h2
                            className="mt-3 font-bold tracking-tight"
                            style={{
                                color:
                                    titleColor,

                                fontSize:
                                    `${titleFontSize}px`,
                            }}
                        >
                            {title}
                        </h2>
                    )}

                    {description && (
                        <p
                            className="mt-3 leading-relaxed"
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
            )}

            <div
                className="relative overflow-hidden border bg-slate-100 shadow-sm"
                style={{
                    height:
                        `${mapHeight}px`,

                    borderRadius:
                        `${borderRadius}px`,

                    borderColor:
                        `${accentColor}18`,
                }}
            >
                {mapUrl ? (
                    <iframe
                        src={mapUrl}
                        title={
                            title ||
                            "Google Map"
                        }
                        className="h-full w-full border-0"
                        loading="lazy"
                        referrerPolicy="no-referrer-when-downgrade"
                        allowFullScreen
                    />
                ) : (
                    <div className="flex h-full items-center justify-center p-8 text-center">
                        <div>
                            <div
                                className="text-sm font-semibold"
                                style={{
                                    color:
                                        titleColor,
                                }}
                            >
                                Google Map
                            </div>

                            <p
                                className="mt-2 max-w-sm text-sm"
                                style={{
                                    color:
                                        textColor,
                                }}
                            >
                                Add a Google
                                Maps embed URL
                                from the section
                                editor.
                            </p>
                        </div>
                    </div>
                )}
            </div>
        </>
    );

    return (
        <section
            id={sectionId || undefined}
            className={`bg-cover bg-center ${visibilityClass} ${customClass}`}
            style={{
                ...sectionSpacing,

                backgroundColor:
                    backgroundType ===
                    "color"
                        ? backgroundColor
                        : undefined,

                backgroundImage:
                    backgroundType ===
                        "image" &&
                    backgroundImage
                        ? `url("${backgroundImage}")`
                        : undefined,
            }}
        >
            {fullWidth ? (
                <div>
                    {showHeader && (
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
                             * Header is handled
                             * separately in
                             * full-width mode.
                             */}
                            <MapHeader
                                topTitle={
                                    topTitle
                                }
                                title={
                                    title
                                }
                                description={
                                    description
                                }
                                alignment={
                                    alignment
                                }
                                titleFontSize={
                                    titleFontSize
                                }
                                textFontSize={
                                    textFontSize
                                }
                                titleColor={
                                    titleColor
                                }
                                textColor={
                                    textColor
                                }
                                accentColor={
                                    accentColor
                                }
                            />
                        </div>
                    )}

                    <div
                        className="relative overflow-hidden bg-slate-100"
                        style={{
                            height:
                                `${mapHeight}px`,
                        }}
                    >
                        {mapUrl ? (
                            <iframe
                                src={
                                    mapUrl
                                }
                                title={
                                    title ||
                                    "Google Map"
                                }
                                className="h-full w-full border-0"
                                loading="lazy"
                                referrerPolicy="no-referrer-when-downgrade"
                                allowFullScreen
                            />
                        ) : (
                            <div className="flex h-full items-center justify-center text-sm text-muted-foreground">
                                Add Google
                                Maps URL
                            </div>
                        )}
                    </div>
                </div>
            ) : (
                <div
                    className={`mx-auto w-full ${containerClass}`}
                    style={{
                        paddingLeft:
                            horizontalPadding,

                        paddingRight:
                            horizontalPadding,
                    }}
                >
                    {content}
                </div>
            )}
        </section>
    );
}

type MapHeaderProps = {
    topTitle: string;
    title: string;
    description: string;

    alignment:
        | "left"
        | "center"
        | "right";

    titleFontSize: number;
    textFontSize: number;

    titleColor: string;
    textColor: string;
    accentColor: string;
};

function MapHeader({
    topTitle,
    title,
    description,
    alignment,
    titleFontSize,
    textFontSize,
    titleColor,
    textColor,
    accentColor,
}: MapHeaderProps) {
    const position =
        alignment === "center"
            ? "mx-auto"
            : alignment === "right"
              ? "ml-auto"
              : "";

    return (
        <div
            className={`mb-10 max-w-3xl ${position}`}
            style={{
                textAlign:
                    alignment,
            }}
        >
            {topTitle && (
                <span
                    className="inline-flex rounded-full border px-3 py-1 text-xs font-semibold uppercase tracking-wider"
                    style={{
                        color:
                            accentColor,

                        backgroundColor:
                            `${accentColor}0d`,

                        borderColor:
                            `${accentColor}20`,
                    }}
                >
                    {topTitle}
                </span>
            )}

            {title && (
                <h2
                    className="mt-3 font-bold tracking-tight"
                    style={{
                        color:
                            titleColor,

                        fontSize:
                            `${titleFontSize}px`,
                    }}
                >
                    {title}
                </h2>
            )}

            {description && (
                <p
                    className="mt-3 leading-relaxed"
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
    );
}

/*
 * Accept either:
 *
 * 1. Google Maps embed URL
 *
 * 2. iframe code copied from
 *    Google Maps → Share → Embed map
 *
 * If iframe HTML is pasted,
 * extract src automatically.
 */
function normalizeGoogleMapUrl(
    value: string,
): string {
    const input =
        value.trim();

    if (!input) {
        return "";
    }

    /*
     * User pasted complete iframe.
     */
    if (
        input.includes(
            "<iframe",
        )
    ) {
        const match =
            input.match(
                /src=["']([^"']+)["']/i,
            );

        return (
            match?.[1] ?? ""
        );
    }

    /*
     * Only allow Google Maps
     * HTTPS embeds.
     */
    try {
        const url =
            new URL(input);

        const allowedHosts = [
            "www.google.com",
            "google.com",
            "maps.google.com",
        ];

        if (
            url.protocol !==
            "https:"
        ) {
            return "";
        }

        if (
            !allowedHosts.includes(
                url.hostname,
            )
        ) {
            return "";
        }

        return input;
    } catch {
        return "";
    }
}
import {
    MessageCircle,
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

type Props = {
    settings: SectionSettings;
    preview?: boolean;
};

export default function CTA02({
    settings,
    preview = false,
}: Props) {
    const badge = String(
        settings.badge ?? "",
    );

    const title = String(
        settings.title ?? "",
    );

    const description = String(
        settings.description ?? "",
    );

    const whatsappLabel = String(
        settings.whatsapp_label ??
            "Instant WhatsApp",
    );

    const whatsappNumber = String(
        settings.whatsapp_number ?? "",
    );

    const whatsappUrl = String(
        settings.whatsapp_url ?? "#",
    );

    const backgroundType = String(
        settings.background_type ?? "color",
    );

    const backgroundColor = String(
        settings.background_color ?? "#064e3b",
    );

    const backgroundImage = String(
        settings.background_image ?? "",
    );

    const titleColor = String(
        settings.title_color ?? "#ffffff",
    );

    const textColor = String(
        settings.text_color ?? "#d1fae5",
    );

    const accentColor = String(
        settings.accent_color ?? "#16a34a",
    );

    const titleFontSize = Number(
        settings.title_font_size ?? 40,
    );

    const textFontSize = Number(
        settings.text_font_size ?? 16,
    );

    const sectionSpacing =
        getSectionSpacing(settings);

    const containerClass =
        getContainerClass(settings);

    const horizontalPadding =
        getHorizontalPadding(settings);

    const visibilityClass = preview
        ? ""
        : getVisibilityClass(settings);

    return (
        <section
            id={
                getSectionId(settings) ||
                undefined
            }
            className={`bg-cover bg-center ${visibilityClass} ${getCustomClass(
                settings,
            )}`}
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
                    className="flex flex-col items-center justify-between gap-8 rounded-3xl border p-8 shadow-2xl lg:flex-row lg:p-12"
                    style={{
                        backgroundColor:
                            `${backgroundColor}dd`,

                        borderColor:
                            `${accentColor}35`,
                    }}
                >
                    <div className="max-w-xl text-center lg:text-left">
                        {badge && (
                            <span
                                className="inline-block rounded-full border px-3 py-1 text-xs font-semibold uppercase tracking-wide"
                                style={{
                                    color:
                                        textColor,

                                    backgroundColor:
                                        `${accentColor}22`,

                                    borderColor:
                                        `${accentColor}44`,
                                }}
                            >
                                {badge}
                            </span>
                        )}

                        <h2
                            className="mt-4 font-extrabold tracking-tight"
                            style={{
                                color:
                                    titleColor,

                                fontSize:
                                    `${titleFontSize}px`,
                            }}
                        >
                            {title}
                        </h2>

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
                    </div>

                    <a
                        href={
                            preview
                                ? undefined
                                : whatsappUrl
                        }
                        target={
                            preview
                                ? undefined
                                : "_blank"
                        }
                        rel="noreferrer"
                        onClick={(event) => {
                            if (preview) {
                                event.preventDefault();
                            }
                        }}
                        className="flex w-full items-center justify-center gap-3 rounded-xl border px-6 py-4 font-bold text-white shadow-lg transition hover:scale-105 sm:w-auto"
                        style={{
                            backgroundColor:
                                accentColor,

                            borderColor:
                                `${accentColor}aa`,
                        }}
                    >
                        <span className="flex size-10 items-center justify-center rounded-full bg-black/10">
                            <MessageCircle className="size-5" />
                        </span>

                        <span className="text-left">
                            <span className="block text-xs uppercase tracking-wider opacity-80">
                                {
                                    whatsappLabel
                                }
                            </span>

                            <span>
                                {
                                    whatsappNumber
                                }
                            </span>
                        </span>
                    </a>
                </div>
            </div>
        </section>
    );
}
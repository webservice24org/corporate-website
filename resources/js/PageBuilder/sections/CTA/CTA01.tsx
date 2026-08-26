import { Phone } from "lucide-react";

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

export default function CTA01({
    settings,
    preview = false,
}: Props) {
    const title = String(
        settings.title ?? "",
    );

    const description = String(
        settings.description ?? "",
    );

    const phoneLabel = String(
        settings.phone_label ?? "",
    );

    const phoneNumber = String(
        settings.phone_number ?? "",
    );

    const note = String(
        settings.note ?? "",
    );

    const showNote = Boolean(
        settings.show_note ?? true,
    );

    const backgroundType = String(
        settings.background_type ?? "color",
    );

    const backgroundColor = String(
        settings.background_color ?? "#d4530c",
    );

    const backgroundImage = String(
        settings.background_image ?? "",
    );

    const titleColor = String(
        settings.title_color ?? "#ffffff",
    );

    const textColor = String(
        settings.text_color ?? "#e5e7eb",
    );

    const accentColor = String(
        settings.accent_color ?? "#f26616",
    );

    const titleFontSize = Number(
        settings.title_font_size ?? 48,
    );

    const textFontSize = Number(
        settings.text_font_size ?? 18,
    );

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
            <div
                className="pointer-events-none absolute -left-24 -top-24 size-96 rounded-full opacity-30 blur-3xl"
                style={{
                    backgroundColor:
                        accentColor,
                }}
            />

            <div
                className="pointer-events-none absolute -bottom-24 -right-24 size-96 rounded-full opacity-20 blur-3xl"
                style={{
                    backgroundColor:
                        accentColor,
                }}
            />

            <div
                className={`relative z-10 mx-auto w-full text-center ${containerClass}`}
                style={{
                    paddingLeft:
                        horizontalPadding,
                    paddingRight:
                        horizontalPadding,
                }}
            >
                <h2
                    className="font-extrabold leading-tight tracking-tight"
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
                        className="mx-auto mt-6 max-w-2xl leading-relaxed"
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

                {phoneLabel && (
                    <div className="mt-8">
                        <a
                            href={
                                preview
                                    ? undefined
                                    : `tel:${phoneNumber}`
                            }
                            onClick={(event) => {
                                if (preview) {
                                    event.preventDefault();
                                }
                            }}
                            className="inline-flex items-center justify-center gap-3 rounded-2xl border border-white/20 bg-white/10 px-8 py-4 text-2xl font-bold text-white shadow-xl backdrop-blur-md transition-all duration-300 hover:scale-105 hover:bg-white/20 sm:text-3xl"
                        >
                            <Phone className="size-7" />

                            {phoneLabel}
                        </a>
                    </div>
                )}

                {showNote && note && (
                    <p
                        className="mt-8 text-sm sm:text-base"
                        style={{
                            color:
                                titleColor,
                        }}
                    >
                        {note}
                    </p>
                )}
            </div>
        </section>
    );
}
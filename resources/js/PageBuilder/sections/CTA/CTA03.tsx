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

export default function CTA03({
    settings,
    preview = false,
}: Props) {
    const title = String(
        settings.title ?? "",
    );

    const description = String(
        settings.description ?? "",
    );

    const primaryText = String(
        settings.primary_text ?? "",
    );

    const primaryUrl = String(
        settings.primary_url ?? "#",
    );

    const secondaryText = String(
        settings.secondary_text ?? "",
    );

    const secondaryUrl = String(
        settings.secondary_url ?? "#",
    );

    const backgroundType = String(
        settings.background_type ?? "color",
    );

    const backgroundColor = String(
        settings.background_color ?? "#f0fdf4",
    );

    const backgroundImage = String(
        settings.background_image ?? "",
    );

    const titleColor = String(
        settings.title_color ?? "#111827",
    );

    const textColor = String(
        settings.text_color ?? "#4b5563",
    );

    const accentColor = String(
        settings.accent_color ?? "#16a34a",
    );

    const titleFontSize = Number(
        settings.title_font_size ?? 36,
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
                <div className="rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-xl sm:p-12">
                    <h2
                        className="font-bold"
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
                            className="mx-auto mt-4 max-w-2xl leading-relaxed"
                            style={{
                                color:
                                    textColor,

                                fontSize:
                                    `${textFontSize}px`,
                            }}
                        >
                            {
                                description
                            }
                        </p>
                    )}

                    <div className="mt-8 flex flex-wrap justify-center gap-4">
                        {primaryText && (
                            <a
                                href={
                                    preview
                                        ? undefined
                                        : primaryUrl
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
                                className="rounded-lg px-8 py-3.5 font-semibold text-white shadow-lg transition hover:-translate-y-0.5"
                                style={{
                                    backgroundColor:
                                        accentColor,
                                }}
                            >
                                {
                                    primaryText
                                }
                            </a>
                        )}

                        {secondaryText && (
                            <a
                                href={
                                    preview
                                        ? undefined
                                        : secondaryUrl
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
                                className="rounded-lg bg-slate-100 px-8 py-3.5 font-semibold text-slate-800 transition hover:bg-slate-200"
                            >
                                {
                                    secondaryText
                                }
                            </a>
                        )}
                    </div>
                </div>
            </div>
        </section>
    );
}
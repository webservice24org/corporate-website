import type { SectionSettings } from "../../types";
import {
    getContainerClass,
    getCustomClass,
    getHorizontalPadding,
    getSectionId,
    getSectionSpacing,
    getVisibilityClass,
} from "../../utils/sectionStyles";

type ValueItem = {
    number?: string;
    title?: string;
    description?: string;
};

type Props = {
    settings: SectionSettings;
    preview?: boolean;
};

export default function About03({
    settings,
    preview = false,
}: Props) {
    const values =
        (settings.values as ValueItem[] | undefined) ??
        [];

    const backgroundType = String(
        settings.background_type ?? "color",
    );

    const backgroundImage = String(
        settings.background_image ?? "",
    );

    const backgroundColor = String(
        settings.background_color ?? "#ffffff",
    );

    const titleColor = String(
        settings.title_color ?? "#0f172a",
    );

    const textColor = String(
        settings.text_color ?? "#475569",
    );

    const accentColor = String(
        settings.accent_color ?? "#059669",
    );

    const alignment =
        (settings.text_alignment as
            | "left"
            | "center"
            | "right") ?? "center";

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
            className={`bg-cover bg-center ${visibilityClass} ${customClass}`}
            style={{
                ...sectionSpacing,

                backgroundColor,

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
                    className="mx-auto max-w-3xl"
                    style={{
                        textAlign: alignment,
                    }}
                >
                    <span
                        className="inline-flex rounded-full px-3 py-1 text-xs font-semibold text-white"
                        style={{
                            backgroundColor:
                                accentColor,
                        }}
                    >
                        {String(
                            settings.badge ??
                                "Our Culture",
                        )}
                    </span>

                    <h2
                        className="mt-4 font-extrabold tracking-tight"
                        style={{
                            color: titleColor,
                            fontSize: `${Number(
                                settings.title_font_size ??
                                    40,
                            )}px`,
                        }}
                    >
                        {String(
                            settings.title ?? "",
                        )}
                    </h2>

                    <p
                        className="mt-4"
                        style={{
                            color: textColor,
                            fontSize: `${Number(
                                settings.text_font_size ??
                                    16,
                            )}px`,
                        }}
                    >
                        {String(
                            settings.description ?? "",
                        )}
                    </p>
                </div>

                <div className="mt-16 grid gap-8 md:grid-cols-3">
                    {values.map(
                        (item, index) => (
                            <div
                                key={index}
                                className="rounded-2xl border bg-white/90 p-8 shadow-sm backdrop-blur"
                            >
                                <div
                                    className="flex size-12 items-center justify-center rounded-xl text-lg font-bold text-white"
                                    style={{
                                        backgroundColor:
                                            accentColor,
                                    }}
                                >
                                    {item.number ??
                                        String(
                                            index + 1,
                                        ).padStart(
                                            2,
                                            "0",
                                        )}
                                </div>

                                <h3
                                    className="mt-6 text-xl font-bold"
                                    style={{
                                        color: titleColor,
                                    }}
                                >
                                    {item.title}
                                </h3>

                                <p
                                    className="mt-3 text-sm leading-relaxed"
                                    style={{
                                        color: textColor,
                                    }}
                                >
                                    {
                                        item.description
                                    }
                                </p>
                            </div>
                        ),
                    )}
                </div>
            </div>
        </section>
    );
}
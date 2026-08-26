import type { SectionSettings } from "../../types";
import {
    getContainerClass,
    getCustomClass,
    getHorizontalPadding,
    getSectionId,
    getSectionSpacing,
    getVisibilityClass,
} from "../../utils/sectionStyles";

type Stat = {
    value?: string;
    title?: string;
    description?: string;
};

type Props = {
    settings: SectionSettings;
    preview?: boolean;
};

export default function About02({
    settings,
    preview = false,
}: Props) {

    const stats =
        (settings.stats as Stat[] | undefined) ?? [];

    const backgroundType = String(
        settings.background_type ?? "color",
    );

    const backgroundImage = String(
        settings.background_image ?? "",
    );

    const backgroundColor = String(
        settings.background_color ?? "#f8fafc",
    );

    const titleColor = String(
        settings.title_color ?? "#0f172a",
    );

    const textColor = String(
        settings.text_color ?? "#475569",
    );

    const accentColor = String(
        settings.accent_color ?? "#4f46e5",
    );

    const buttonText = String(
        settings.button_text ?? "",
    );

    const buttonUrl = String(
        settings.button_url ?? "#",
    );

    const alignment =
        (settings.text_alignment as
            | "left"
            | "center"
            | "right") ?? "left";

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
                <div className="grid items-center gap-12 lg:grid-cols-2 lg:gap-16">
                    <div
                        style={{
                            textAlign: alignment,
                        }}
                    >
                        <span
                            className="text-xs font-bold uppercase tracking-widest"
                            style={{
                                color: accentColor,
                            }}
                        >
                            {String(
                                settings.top_title ??
                                    "Who We Are",
                            )}
                        </span>

                        <h2
                            className="mt-3 font-extrabold tracking-tight"
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
                            className="mt-6 leading-relaxed"
                            style={{
                                color: textColor,
                                fontSize: `${Number(
                                    settings.text_font_size ??
                                        16,
                                )}px`,
                            }}
                        >
                            {String(
                                settings.description_one ??
                                    "",
                            )}
                        </p>

                        <p
                            className="mt-4 leading-relaxed"
                            style={{
                                color: textColor,
                                fontSize: `${Number(
                                    settings.text_font_size ??
                                        16,
                                )}px`,
                            }}
                        >
                            {String(
                                settings.description_two ??
                                    "",
                            )}
                        </p>

                        {buttonText && (
                            <a
                                href={preview ? undefined : buttonUrl}
                                onClick={(e) => {
                                    if (preview) {
                                        e.preventDefault();
                                    }
                                }}
                                className="mt-8 inline-flex rounded-lg px-6 py-3 text-sm font-semibold text-white"
                                style={{
                                    backgroundColor: accentColor,
                                }}
                            >
                                {buttonText}
                            </a>
                        )}
                    </div>

                    <div className="grid grid-cols-2 gap-4 sm:gap-6">
                        {stats.map(
                            (stat, index) => (
                                <div
                                    key={index}
                                    className="rounded-2xl border bg-white/95 p-6 shadow-sm"
                                >
                                    <p
                                        className="text-3xl font-black sm:text-4xl"
                                        style={{
                                            color: accentColor,
                                        }}
                                    >
                                        {stat.value}
                                    </p>

                                    <p
                                        className="mt-2 text-sm font-semibold"
                                        style={{
                                            color: titleColor,
                                        }}
                                    >
                                        {stat.title}
                                    </p>

                                    <p
                                        className="mt-1 text-xs"
                                        style={{
                                            color: textColor,
                                        }}
                                    >
                                        {
                                            stat.description
                                        }
                                    </p>
                                </div>
                            ),
                        )}
                    </div>
                </div>
            </div>
        </section>
    );
}
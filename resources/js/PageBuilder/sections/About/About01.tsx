import type { SectionSettings } from "../../types";

import {
    getContainerClass,
    getCustomClass,
    getHorizontalPadding,
    getSectionId,
    getSectionSpacing,
    getVisibilityClass,
} from "../../utils/sectionStyles";

type Pillar = {
    title?: string;
    description?: string;
};

type Counter = {
    value?: string;
    label?: string;
};

type Props = {
    settings: SectionSettings;
    preview?: boolean;
};

export default function About01({
    settings,
    preview = false,
}: Props) {
    const pillars =
        (settings.pillars as Pillar[] | undefined) ?? [];

    const counters =
        (settings.counters as Counter[] | undefined) ?? [];

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
        settings.title_color ?? "#111827",
    );

    const textColor = String(
        settings.text_color ?? "#4b5563",
    );

    const accentColor = String(
        settings.accent_color ?? "#2563eb",
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
                    <div className="relative">
                        <div className="relative overflow-hidden rounded-2xl shadow-2xl">
                            {settings.image ? (
                                <img
                                    src={String(
                                        settings.image,
                                    )}
                                    alt=""
                                    className="h-[480px] w-full object-cover"
                                />
                            ) : (
                                <div className="flex h-[480px] items-center justify-center bg-slate-100 text-slate-400">
                                    About Image
                                </div>
                            )}

                            <div className="absolute inset-0 bg-gradient-to-t from-black/50 via-transparent to-transparent" />
                        </div>

                        <div
                            className="absolute bottom-6 right-6 flex items-center gap-4 rounded-2xl p-5 text-white shadow-xl"
                            style={{
                                backgroundColor:
                                    accentColor,
                            }}
                        >
                            <div className="text-4xl font-extrabold">
                                {String(
                                    settings.experience_value ??
                                        "12+",
                                )}
                            </div>

                            <div className="whitespace-pre-line text-xs font-semibold uppercase tracking-wider">
                                {String(
                                    settings.experience_label ??
                                        "Years of\nIndustry\nLeadership",
                                )}
                            </div>
                        </div>
                    </div>

                    <div
                        className="space-y-6"
                        style={{
                            textAlign: alignment,
                        }}
                    >
                        <div
                            className="text-sm font-bold uppercase tracking-widest"
                            style={{
                                color: accentColor,
                            }}
                        >
                            {String(
                                settings.top_title ??
                                    "About Our Company",
                            )}
                        </div>

                        <h2
                            className="font-extrabold leading-tight"
                            style={{
                                color: titleColor,
                                fontSize: `${Number(
                                    settings.title_font_size ??
                                        40,
                                )}px`,
                            }}
                        >
                            {String(
                                settings.title ??
                                    "Pioneering Sustainable Solutions",
                            )}
                        </h2>

                        <p
                            className="leading-relaxed"
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
                            className="leading-relaxed"
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

                        <div className="grid gap-4 border-t pt-5 sm:grid-cols-2">
                            {pillars.map(
                                (pillar, index) => (
                                    <div
                                        key={index}
                                        className="flex items-start gap-3 text-left"
                                    >
                                        <div
                                            className="flex size-8 shrink-0 items-center justify-center rounded-lg text-sm font-bold text-white"
                                            style={{
                                                backgroundColor:
                                                    accentColor,
                                            }}
                                        >
                                            ✓
                                        </div>

                                        <div>
                                            <h3
                                                className="text-sm font-bold"
                                                style={{
                                                    color: titleColor,
                                                }}
                                            >
                                                {
                                                    pillar.title
                                                }
                                            </h3>

                                            <p
                                                className="mt-1 text-xs"
                                                style={{
                                                    color: textColor,
                                                }}
                                            >
                                                {
                                                    pillar.description
                                                }
                                            </p>
                                        </div>
                                    </div>
                                ),
                            )}
                        </div>
                    </div>
                </div>

                <div className="mt-20 grid grid-cols-2 gap-6 lg:grid-cols-4">
                    {counters.map(
                        (counter, index) => (
                            <div
                                key={index}
                                className="rounded-2xl border bg-white/90 p-7 text-center shadow-sm backdrop-blur"
                            >
                                <div
                                    className="text-3xl font-extrabold sm:text-4xl"
                                    style={{
                                        color: accentColor,
                                    }}
                                >
                                    {counter.value}
                                </div>

                                <p
                                    className="mt-2 text-xs font-semibold uppercase tracking-wider"
                                    style={{
                                        color: textColor,
                                    }}
                                >
                                    {counter.label}
                                </p>
                            </div>
                        ),
                    )}
                </div>
            </div>
        </section>
    );
}
import {
    ArrowRight,
    Quote,
} from "lucide-react";

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

type StatItem = {
    value?: string;
    label?: string;
};

type MessageParagraph = {
    text?: string;
};

type Props = {
    settings: SectionSettings;
    preview?: boolean;
};

export default function Message01({
    settings,
    preview = false,
}: Props) {
    const personImage = String(
        settings.person_image ?? "",
    );

    const personName = String(
        settings.person_name ??
            "MD. Monir Hossen",
    );

    const personRole = String(
        settings.person_role ??
            "Founder & Chairman",
    );

    const showStats = Boolean(
        settings.show_stats ?? true,
    );

    const stats =
        (settings.stats as
            | StatItem[]
            | undefined) ?? [];

    const showQuote = Boolean(
        settings.show_quote ?? true,
    );

    const quote = String(
        settings.quote ?? "",
    );

    const topTitle = String(
        settings.top_title ??
            "Official Address",
    );

    const title = String(
        settings.title ??
            "Building Sustainable Futures Together",
    );

    const greeting = String(
        settings.greeting ??
            "Welcome to our organization,",
    );

    const paragraphs =
        (settings.paragraphs as
            | MessageParagraph[]
            | undefined) ?? [];

    const showSignature = Boolean(
        settings.show_signature ?? true,
    );

    const signatureImage = String(
        settings.signature_image ?? "",
    );

    const signatureText = String(
        settings.signature_text ?? "",
    );

    const signatureName = String(
        settings.signature_name ??
            personName,
    );

    const signatureRole = String(
        settings.signature_role ??
            personRole,
    );

    const signatureOrganization = String(
        settings.signature_organization ??
            "",
    );

    const showButton = Boolean(
        settings.show_button ?? true,
    );

    const buttonText = String(
        settings.button_text ??
            "Schedule A Meeting",
    );

    const buttonUrl = String(
        settings.button_url ?? "#",
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
            "#f8fafc",
    );

    const backgroundImage = String(
        settings.background_image ?? "",
    );

    const titleFontSize = Number(
        settings.title_font_size ?? 36,
    );

    const textFontSize = Number(
        settings.text_font_size ?? 17,
    );

    const titleColor = String(
        settings.title_color ??
            "#111827",
    );

    const textColor = String(
        settings.text_color ??
            "#374151",
    );

    const accentColor = String(
        settings.accent_color ??
            "#059669",
    );

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

    return (
        <section
            id={sectionId || undefined}
            className={`bg-cover bg-center ${visibilityClass} ${customClass}`}
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
                <div className="grid grid-cols-1 items-start gap-12 lg:grid-cols-12">
                    {/* LEFT PERSON PANEL */}
                    <div className="space-y-6 lg:sticky lg:top-28 lg:col-span-5">
                        <div className="relative overflow-hidden rounded-3xl border border-slate-200 bg-white p-4 shadow-xl">
                            <div className="group relative h-[450px] overflow-hidden rounded-2xl bg-slate-100">
                                {personImage ? (
                                    <img
                                        src={
                                            personImage
                                        }
                                        alt={
                                            personName
                                        }
                                        className="h-full w-full object-cover object-top transition duration-500 group-hover:scale-105"
                                    />
                                ) : (
                                    <div className="flex h-full items-center justify-center text-sm text-slate-400">
                                        Person Image
                                    </div>
                                )}

                                <div className="absolute inset-0 bg-gradient-to-t from-black/75 via-black/20 to-transparent" />

                                <div className="absolute bottom-6 left-6 right-6 text-white">
                                    <h3 className="text-2xl font-bold">
                                        {personName}
                                    </h3>

                                    <p
                                        className="mt-1 text-sm font-medium"
                                        style={{
                                            color:
                                                `${accentColor}`,
                                        }}
                                    >
                                        {personRole}
                                    </p>
                                </div>
                            </div>

                            {showStats &&
                                stats.length > 0 && (
                                    <div
                                        className={`grid gap-4 pt-4 ${
                                            stats.length ===
                                            1
                                                ? "grid-cols-1"
                                                : "grid-cols-2"
                                        }`}
                                    >
                                        {stats.map(
                                            (
                                                stat,
                                                index,
                                            ) => (
                                                <div
                                                    key={
                                                        index
                                                    }
                                                    className="rounded-xl border p-3 text-center"
                                                    style={{
                                                        backgroundColor:
                                                            `${accentColor}0d`,

                                                        borderColor:
                                                            `${accentColor}20`,
                                                    }}
                                                >
                                                    <div
                                                        className="text-2xl font-bold"
                                                        style={{
                                                            color:
                                                                accentColor,
                                                        }}
                                                    >
                                                        {String(
                                                            stat.value ??
                                                                "",
                                                        )}
                                                    </div>

                                                    <div
                                                        className="mt-1 text-xs font-medium"
                                                        style={{
                                                            color:
                                                                textColor,
                                                        }}
                                                    >
                                                        {String(
                                                            stat.label ??
                                                                "",
                                                        )}
                                                    </div>
                                                </div>
                                            ),
                                        )}
                                    </div>
                                )}
                        </div>

                        {showQuote &&
                            quote && (
                                <div
                                    className="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg"
                                    style={{
                                        backgroundColor:
                                            accentColor,
                                    }}
                                >
                                    <Quote className="absolute left-4 top-4 size-10 opacity-20" />

                                    <p className="relative z-10 pt-2 text-sm italic leading-relaxed">
                                        “{quote}”
                                    </p>
                                </div>
                            )}
                    </div>

                    {/* RIGHT MESSAGE */}
                    <div className="space-y-6 rounded-3xl border border-slate-200 bg-white p-8 shadow-xl sm:p-12 lg:col-span-7">
                        <div className="border-b border-slate-100 pb-6">
                            {topTitle && (
                                <span
                                    className="text-xs font-bold uppercase tracking-widest"
                                    style={{
                                        color:
                                            accentColor,
                                    }}
                                >
                                    {topTitle}
                                </span>
                            )}

                            <h2
                                className="mt-1 font-extrabold"
                                style={{
                                    color:
                                        titleColor,

                                    fontSize:
                                        `${titleFontSize}px`,
                                }}
                            >
                                {title}
                            </h2>
                        </div>

                        <div
                            className="space-y-5 leading-relaxed"
                            style={{
                                color:
                                    textColor,

                                fontSize:
                                    `${textFontSize}px`,
                            }}
                        >
                            {greeting && (
                                <p
                                    className="font-medium"
                                    style={{
                                        color:
                                            titleColor,
                                    }}
                                >
                                    {greeting}
                                </p>
                            )}

                            {paragraphs.map(
                                (
                                    paragraph,
                                    index,
                                ) =>
                                    paragraph.text ? (
                                        <p
                                            key={
                                                index
                                            }
                                        >
                                            {
                                                paragraph.text
                                            }
                                        </p>
                                    ) : null,
                            )}
                        </div>

                        {(showSignature ||
                            showButton) && (
                            <div className="flex flex-col items-start justify-between gap-6 border-t border-slate-100 pt-8 sm:flex-row sm:items-center">
                                {showSignature && (
                                    <div>
                                        {signatureImage ? (
                                            <img
                                                src={
                                                    signatureImage
                                                }
                                                alt="Signature"
                                                className="mb-2 max-h-16 w-auto object-contain"
                                            />
                                        ) : signatureText ? (
                                            <div
                                                className="mb-1 font-serif text-2xl font-bold italic tracking-wide"
                                                style={{
                                                    color:
                                                        accentColor,
                                                }}
                                            >
                                                {
                                                    signatureText
                                                }
                                            </div>
                                        ) : null}

                                        {signatureName && (
                                            <h4
                                                className="text-lg font-bold"
                                                style={{
                                                    color:
                                                        titleColor,
                                                }}
                                            >
                                                {
                                                    signatureName
                                                }
                                            </h4>
                                        )}

                                        {signatureRole && (
                                            <p className="text-sm text-slate-500">
                                                {
                                                    signatureRole
                                                }
                                            </p>
                                        )}

                                        {signatureOrganization && (
                                            <p
                                                className="text-xs font-semibold"
                                                style={{
                                                    color:
                                                        accentColor,
                                                }}
                                            >
                                                {
                                                    signatureOrganization
                                                }
                                            </p>
                                        )}
                                    </div>
                                )}

                                {showButton &&
                                    buttonText && (
                                        <a
                                            href={
                                                preview
                                                    ? undefined
                                                    : buttonUrl
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
                                            className="inline-flex items-center gap-2 rounded-xl px-6 py-3 text-sm font-semibold text-white shadow-md transition hover:-translate-y-0.5"
                                            style={{
                                                backgroundColor:
                                                    accentColor,
                                            }}
                                        >
                                            {
                                                buttonText
                                            }

                                            <ArrowRight className="size-4" />
                                        </a>
                                    )}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </section>
    );
}
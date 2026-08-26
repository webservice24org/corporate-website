import type { SectionSettings } from "../../types";
import {
    getContainerClass,
    getCustomClass,
    getHorizontalPadding,
    getSectionId,
    getSectionSpacing,
    getVisibilityClass,
} from "../../utils/sectionStyles";

type Member = {
    name?: string;
    role?: string;
    description?: string;
    image?: string;
    initials?: string;
};

type Props = {
    settings: SectionSettings;
    preview?: boolean;
};

export default function About04({
    settings,
    preview = false,
}: Props) {
    const members =
        (settings.members as Member[] | undefined) ??
        [];

    const backgroundType = String(
        settings.background_type ?? "color",
    );

    const backgroundImage = String(
        settings.background_image ?? "",
    );

    const backgroundColor = String(
        settings.background_color ?? "#020617",
    );

    const titleColor = String(
        settings.title_color ?? "#ffffff",
    );

    const textColor = String(
        settings.text_color ?? "#94a3b8",
    );

    const accentColor = String(
        settings.accent_color ?? "#22d3ee",
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
                <div
                    className="max-w-3xl"
                    style={{
                        textAlign: alignment,
                    }}
                >
                    <h2
                        className="text-xs font-semibold uppercase tracking-widest"
                        style={{
                            color: accentColor,
                        }}
                    >
                        {String(
                            settings.top_title ??
                                "About the Team",
                        )}
                    </h2>

                    <p
                        className="mt-4 font-extrabold tracking-tight"
                        style={{
                            color: titleColor,
                            fontSize: `${Number(
                                settings.title_font_size ??
                                    48,
                            )}px`,
                        }}
                    >
                        {String(
                            settings.title ?? "",
                        )}
                    </p>

                    <p
                        className="mt-6 leading-relaxed"
                        style={{
                            color: textColor,
                            fontSize: `${Number(
                                settings.text_font_size ??
                                    18,
                            )}px`,
                        }}
                    >
                        {String(
                            settings.description ?? "",
                        )}
                    </p>
                </div>

                <div className="mt-16 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    {members.map(
                        (member, index) => (
                            <div
                                key={index}
                                className="rounded-xl border border-white/10 bg-white/5 p-6 backdrop-blur"
                            >
                                {member.image ? (
                                    <img
                                        src={
                                            member.image
                                        }
                                        alt={
                                            member.name ??
                                            ""
                                        }
                                        className="size-14 rounded-full object-cover"
                                    />
                                ) : (
                                    <div
                                        className="flex size-14 items-center justify-center rounded-full text-lg font-bold text-white"
                                        style={{
                                            backgroundColor:
                                                accentColor,
                                        }}
                                    >
                                        {member.initials}
                                    </div>
                                )}

                                <h3
                                    className="mt-4 text-lg font-bold"
                                    style={{
                                        color: titleColor,
                                    }}
                                >
                                    {member.name}
                                </h3>

                                <p
                                    className="text-xs font-medium"
                                    style={{
                                        color: accentColor,
                                    }}
                                >
                                    {member.role}
                                </p>

                                <p
                                    className="mt-3 text-sm leading-relaxed"
                                    style={{
                                        color: textColor,
                                    }}
                                >
                                    {
                                        member.description
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
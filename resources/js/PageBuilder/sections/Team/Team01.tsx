import {
    ArrowRight,
    UserPlus,
} from "lucide-react";

import type {
    SectionSettings,
} from "../../types";

import type {
    TeamMember,
} from "./teamTypes";

import {
    TeamSocialLinks,
} from "./teamHelpers";

import {
    getContainerClass,
    getCustomClass,
    getHorizontalPadding,
    getSectionId,
    getSectionSpacing,
    getVisibilityClass,
} from "../../utils/sectionStyles";

type Props = {
    settings:
        SectionSettings;

    preview?: boolean;
};

export default function Team01({
    settings,
    preview = false,
}: Props) {
    const members =
        (settings.members as
            | TeamMember[]
            | undefined) ?? [];

    const title = String(
        settings.title ??
            "Meet Our Leadership",
    );

    const description = String(
        settings.description ?? "",
    );

    const accentColor = String(
        settings.accent_color ??
            "#2563eb",
    );

    const titleColor = String(
        settings.title_color ??
            "#111827",
    );

    const textColor = String(
        settings.text_color ??
            "#6b7280",
    );

    const columns = Number(
        settings.columns ?? 4,
    );

    const showHiringCard =
        Boolean(
            settings.show_hiring_card ??
                true,
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

    const visibilityClass =
        preview
            ? ""
            : getVisibilityClass(
                  settings,
              );

    const gridClass =
        columns === 2
            ? "lg:grid-cols-2"
            : columns === 3
              ? "lg:grid-cols-3"
              : "lg:grid-cols-4";

    return (
        <section
            id={
                sectionId ||
                undefined
            }
            className={`${visibilityClass} ${customClass}`}
            style={{
                ...sectionSpacing,

                backgroundColor:
                    String(
                        settings.background_color ??
                            "#f9fafb",
                    ),
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
                <div className="mx-auto max-w-2xl text-center">
                    <h2
                        className="font-bold tracking-tight"
                        style={{
                            color:
                                titleColor,

                            fontSize:
                                `${Number(
                                    settings.title_font_size ??
                                        40,
                                )}px`,
                        }}
                    >
                        {title}
                    </h2>

                    {description && (
                        <p
                            className="mt-4 leading-7"
                            style={{
                                color:
                                    textColor,
                            }}
                        >
                            {description}
                        </p>
                    )}
                </div>

                <div
                    className={`mt-16 grid grid-cols-1 gap-8 sm:grid-cols-2 ${gridClass}`}
                >
                    {members.map(
                        (
                            member,
                            index,
                        ) => (
                            <article
                                key={
                                    index
                                }
                                className="group overflow-hidden rounded-2xl bg-white p-6 shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-xl"
                            >
                                <div className="aspect-square overflow-hidden rounded-xl bg-slate-100">
                                    {member.image ? (
                                        <img
                                            src={
                                                member.image
                                            }
                                            alt={
                                                member.name ??
                                                ""
                                            }
                                            className="h-full w-full object-cover transition duration-500 group-hover:scale-105"
                                        />
                                    ) : (
                                        <div className="flex h-full items-center justify-center text-sm text-slate-400">
                                            Team
                                            Photo
                                        </div>
                                    )}
                                </div>

                                <div className="mt-6 text-center">
                                    <h3
                                        className="text-lg font-bold"
                                        style={{
                                            color:
                                                titleColor,
                                        }}
                                    >
                                        {
                                            member.name
                                        }
                                    </h3>

                                    <p
                                        className="mt-1 text-sm font-medium"
                                        style={{
                                            color:
                                                accentColor,
                                        }}
                                    >
                                        {
                                            member.designation
                                        }
                                    </p>

                                    {member.show_bio !==
                                        false &&
                                        member.bio && (
                                            <p
                                                className="mt-2 text-xs leading-5"
                                                style={{
                                                    color:
                                                        textColor,
                                                }}
                                            >
                                                {
                                                    member.bio
                                                }
                                            </p>
                                        )}
                                </div>

                                {member.show_social !==
                                    false && (
                                    <div className="mt-5 flex justify-center border-t pt-4">
                                        <TeamSocialLinks
                                            member={
                                                member
                                            }
                                            accentColor={
                                                accentColor
                                            }
                                            preview={
                                                preview
                                            }
                                        />
                                    </div>
                                )}
                            </article>
                        ),
                    )}

                    {showHiringCard && (
                        <article
                            className="group flex flex-col justify-between rounded-2xl border-2 border-dashed p-6 text-center transition-all hover:-translate-y-1 hover:shadow-xl"
                            style={{
                                borderColor:
                                    `${accentColor}55`,

                                backgroundColor:
                                    `${accentColor}08`,
                            }}
                        >
                            <div className="my-auto flex flex-col items-center py-4">
                                <div
                                    className="flex size-16 items-center justify-center rounded-full"
                                    style={{
                                        color:
                                            accentColor,

                                        backgroundColor:
                                            `${accentColor}15`,
                                    }}
                                >
                                    <UserPlus className="size-7" />
                                </div>

                                <h3
                                    className="mt-6 text-xl font-bold"
                                    style={{
                                        color:
                                            titleColor,
                                    }}
                                >
                                    {String(
                                        settings.hiring_title ??
                                            "We're Hiring!",
                                    )}
                                </h3>

                                <p
                                    className="mt-2 text-xs leading-5"
                                    style={{
                                        color:
                                            textColor,
                                    }}
                                >
                                    {String(
                                        settings.hiring_description ??
                                            "",
                                    )}
                                </p>
                            </div>

                            <a
                                href={
                                    preview
                                        ? undefined
                                        : String(
                                              settings.hiring_button_url ??
                                                  "#",
                                          )
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
                                className="mt-4 inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-xs font-semibold text-white"
                                style={{
                                    backgroundColor:
                                        accentColor,
                                }}
                            >
                                {String(
                                    settings.hiring_button_text ??
                                        "View Open Roles",
                                )}

                                <ArrowRight className="size-3.5" />
                            </a>
                        </article>
                    )}
                </div>
            </div>
        </section>
    );
}
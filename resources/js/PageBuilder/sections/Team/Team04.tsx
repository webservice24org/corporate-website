import {
    Mail,
    Phone,
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

type Props = {
    settings:
        SectionSettings;

    preview?: boolean;
};

export default function Team04({
    settings,
    preview = false,
}: Props) {
    const members =
        (settings.members as
            | TeamMember[]
            | undefined) ?? [];

    const accentColor = String(
        settings.accent_color ??
            "#0f766e",
    );

    return (
        <section className="bg-slate-50 py-24">
            <div className="mx-auto max-w-7xl px-6">
                <div className="mx-auto mb-16 max-w-3xl text-center">
                    <p
                        className="text-xs font-bold uppercase tracking-[0.2em]"
                        style={{
                            color:
                                accentColor,
                        }}
                    >
                        {String(
                            settings.top_title ??
                                "",
                        )}
                    </p>

                    <h2 className="mt-3 text-4xl font-bold text-slate-950">
                        {String(
                            settings.title ??
                                "",
                        )}
                    </h2>

                    <p className="mt-4 text-slate-600">
                        {String(
                            settings.description ??
                                "",
                        )}
                    </p>
                </div>

                <div className="grid grid-cols-1 gap-8 md:grid-cols-2 xl:grid-cols-3">
                    {members.map(
                        (
                            member,
                            index,
                        ) => (
                            <article
                                key={
                                    index
                                }
                                className="group overflow-hidden rounded-3xl border bg-white shadow-sm transition duration-300 hover:-translate-y-1 hover:shadow-xl"
                            >
                                <div className="h-80 overflow-hidden bg-slate-100">
                                    {member.image ? (
                                        <img
                                            src={
                                                member.image
                                            }
                                            alt={
                                                member.name ??
                                                ""
                                            }
                                            className="h-full w-full object-cover object-top transition duration-500 group-hover:scale-105"
                                        />
                                    ) : (
                                        <div className="flex h-full items-center justify-center text-slate-400">
                                            Executive
                                            Photo
                                        </div>
                                    )}
                                </div>

                                <div className="p-6">
                                    <h3 className="text-xl font-bold text-slate-900">
                                        {
                                            member.name
                                        }
                                    </h3>

                                    <p
                                        className="mt-1 text-sm font-semibold"
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
                                            <p className="mt-4 text-sm leading-6 text-slate-600">
                                                {
                                                    member.bio
                                                }
                                            </p>
                                        )}

                                    {member.show_contact &&
                                        (member.email ||
                                            member.phone) && (
                                            <div className="mt-5 space-y-2 border-t pt-4 text-sm text-slate-600">
                                                {member.email && (
                                                    <a
                                                        href={
                                                            preview
                                                                ? undefined
                                                                : `mailto:${member.email}`
                                                        }
                                                        className="flex items-center gap-2"
                                                    >
                                                        <Mail className="size-4" />

                                                        {
                                                            member.email
                                                        }
                                                    </a>
                                                )}

                                                {member.phone && (
                                                    <a
                                                        href={
                                                            preview
                                                                ? undefined
                                                                : `tel:${member.phone}`
                                                        }
                                                        className="flex items-center gap-2"
                                                    >
                                                        <Phone className="size-4" />

                                                        {
                                                            member.phone
                                                        }
                                                    </a>
                                                )}
                                            </div>
                                        )}

                                    {member.show_social !==
                                        false && (
                                        <div className="mt-5">
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
                                </div>
                            </article>
                        ),
                    )}
                </div>
            </div>
        </section>
    );
}
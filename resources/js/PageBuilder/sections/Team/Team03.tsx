import type {
    SectionSettings,
} from "../../types";

import type {
    TeamMember,
} from "./teamTypes";

type Props = {
    settings:
        SectionSettings;

    preview?: boolean;
};

export default function Team03({
    settings,
}: Props) {
    const members =
        (settings.members as
            | TeamMember[]
            | undefined) ?? [];

    const accentColor = String(
        settings.accent_color ??
            "#2563eb",
    );

    return (
        <section className="bg-white py-20">
            <div className="mx-auto max-w-7xl px-6">
                <div className="mb-12 max-w-xl">
                    <h2 className="text-3xl font-bold text-slate-900">
                        {String(
                            settings.title ??
                                "",
                        )}
                    </h2>

                    <p className="mt-2 text-sm text-slate-600">
                        {String(
                            settings.description ??
                                "",
                        )}
                    </p>
                </div>

                <div className="grid grid-cols-1 gap-x-12 gap-y-5 md:grid-cols-2">
                    {members.map(
                        (
                            member,
                            index,
                        ) => (
                            <article
                                key={
                                    index
                                }
                                className="flex items-center gap-4 rounded-xl p-4 transition hover:bg-slate-50"
                            >
                                <div
                                    className="size-16 shrink-0 overflow-hidden rounded-full ring-2"
                                    style={{
                                        boxShadow:
                                            `0 0 0 2px ${accentColor}20`,
                                    }}
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
                                            className="h-full w-full object-cover"
                                        />
                                    ) : (
                                        <div className="flex h-full items-center justify-center bg-slate-100 text-xs text-slate-400">
                                            Photo
                                        </div>
                                    )}
                                </div>

                                <div>
                                    <h3 className="font-bold text-slate-900">
                                        {
                                            member.name
                                        }
                                    </h3>

                                    <p
                                        className="text-xs font-medium"
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
                                            <p className="mt-1 text-xs leading-5 text-slate-500">
                                                {
                                                    member.bio
                                                }
                                            </p>
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
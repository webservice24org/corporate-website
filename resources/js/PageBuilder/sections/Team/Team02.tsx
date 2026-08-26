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

export default function Team02({
    settings,
}: Props) {
    const members =
        (settings.members as
            | TeamMember[]
            | undefined) ?? [];

    const accentColor = String(
        settings.accent_color ??
            "#34d399",
    );

    return (
        <section
            className="bg-slate-950 py-24 text-white"
            style={{
                backgroundColor:
                    String(
                        settings.background_color ??
                            "#020617",
                    ),
            }}
        >
            <div className="mx-auto max-w-7xl px-6">
                <div className="mb-16 flex flex-col justify-between gap-6 md:flex-row md:items-end">
                    <div>
                        <span
                            className="text-xs font-bold uppercase tracking-widest"
                            style={{
                                color:
                                    accentColor,
                            }}
                        >
                            {String(
                                settings.top_title ??
                                    "",
                            )}
                        </span>

                        <h2 className="mt-2 text-3xl font-extrabold sm:text-4xl">
                            {String(
                                settings.title ??
                                    "",
                            )}
                        </h2>
                    </div>

                    <p className="max-w-md text-sm text-slate-400">
                        {String(
                            settings.description ??
                                "",
                        )}
                    </p>
                </div>

                <div className="grid grid-cols-1 gap-8 sm:grid-cols-2 lg:grid-cols-3">
                    {members.map(
                        (
                            member,
                            index,
                        ) => (
                            <article
                                key={
                                    index
                                }
                                className="group rounded-3xl border border-slate-800 bg-slate-900/60 p-4 transition-all duration-300 hover:-translate-y-1 hover:border-white/20"
                            >
                                <div className="relative h-80 overflow-hidden rounded-2xl bg-slate-800">
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
                                        <div className="flex h-full items-center justify-center text-slate-500">
                                            Team
                                            Photo
                                        </div>
                                    )}

                                    <div className="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/20 to-transparent" />

                                    <div className="absolute bottom-4 left-4 right-4">
                                        <h3 className="text-xl font-bold">
                                            {
                                                member.name
                                            }
                                        </h3>

                                        <p
                                            className="mt-1 text-xs font-semibold"
                                            style={{
                                                color:
                                                    accentColor,
                                            }}
                                        >
                                            {
                                                member.designation
                                            }
                                        </p>
                                    </div>
                                </div>
                            </article>
                        ),
                    )}
                </div>
            </div>
        </section>
    );
}
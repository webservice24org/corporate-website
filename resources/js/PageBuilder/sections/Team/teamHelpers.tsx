import {
    getBuilderIcon,
} from "../../utils/icons";

import type {
    TeamMember,
} from "./teamTypes";

export function TeamSocialLinks({
    member,
    accentColor,
    preview,
}: {
    member: TeamMember;

    accentColor: string;

    preview: boolean;
}) {
    if (
        member.show_social ===
            false ||
        !member.social_links
            ?.length
    ) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center gap-3">
            {member.social_links.map(
                (
                    social,
                    index,
                ) => {
                    const Icon =
                        getBuilderIcon(
                            social.icon ??
                                "link",
                        );

                    return (
                        <a
                            key={index}
                            href={
                                preview
                                    ? undefined
                                    : social.url ||
                                      "#"
                            }
                            target={
                                preview
                                    ? undefined
                                    : "_blank"
                            }
                            rel="noreferrer"
                            onClick={(
                                event,
                            ) => {
                                if (
                                    preview
                                ) {
                                    event.preventDefault();
                                }
                            }}
                            className="flex size-9 items-center justify-center rounded-full border transition hover:-translate-y-0.5"
                            style={{
                                color:
                                    accentColor,

                                borderColor:
                                    `${accentColor}30`,
                            }}
                        >
                            <Icon className="size-4" />
                        </a>
                    );
                },
            )}
        </div>
    );
}
import type {
    SectionSettings,
} from "../types";

import Footer01 from "../sections/Footer/Footer01";
import FloatingContact01 from "../sections/Global/FloatingContact01";
import HeaderRenderer from "@/PageBuilder/sections/Global/Header/HeaderRenderer";

type GlobalSection = {
    id: number;

    type: string;
    variant: string;

    settings:
        | SectionSettings
        | null;

    is_active: boolean;
};

type Props = {
    section:
        | GlobalSection
        | null
        | undefined;
};

export default function GlobalSectionRenderer({
    section,
}: Props) {
    if (
        !section ||
        !section.is_active
    ) {
        return null;
    }

    if (
        section.type ===
        "header"
    ) {
        return (
            <HeaderRenderer
                variant={
                    section.variant as
                        | "header-01"
                        | "header-02"
                        | "header-03"
                        | "header-04"
                        | "header-05"
                }
                settings={
                    section.settings ?? {}
                }
            />
        );
    }

    if (
        section.type === "footer" &&
        section.variant === "footer-01"
    ) {
        return (
            <Footer01
                settings={
                    section.settings ?? {}
                }
            />
        );
    }

    if (
        section.type ===
            "floating-contact" &&
        section.variant ===
            "floating-contact-01"
    ) {
        return (
            <FloatingContact01
                settings={
                    section.settings ?? {}
                }
            />
        );
    }

    return null;
}
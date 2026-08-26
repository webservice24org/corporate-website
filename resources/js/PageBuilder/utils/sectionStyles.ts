import type { SectionSettings } from "../types";

function safeNumber(
    value: unknown,
    fallback: number,
    min = 0,
    max = 500,
) {
    const number = Number(value);

    if (!Number.isFinite(number)) {
        return fallback;
    }

    return Math.min(
        max,
        Math.max(min, number),
    );
}

export function getSectionSpacing(
    settings: SectionSettings,
) {
    return {
        marginTop: `${safeNumber(
            settings.margin_top,
            0,
        )}px`,

        marginBottom: `${safeNumber(
            settings.margin_bottom,
            0,
        )}px`,

        paddingTop: `${safeNumber(
            settings.padding_top,
            80,
        )}px`,

        paddingBottom: `${safeNumber(
            settings.padding_bottom,
            80,
        )}px`,
    };
}

export function getHorizontalPadding(
    settings: SectionSettings,
) {
    return `${safeNumber(
        settings.padding_horizontal,
        24,
        0,
        150,
    )}px`;
}

export function getContainerClass(
    settings: SectionSettings,
) {
    const width = String(
        settings.container_width ?? "xl",
    );

    switch (width) {
        case "small":
            return "max-w-3xl";

        case "medium":
            return "max-w-5xl";

        case "large":
            return "max-w-6xl";

        case "full":
            return "max-w-none";

        case "xl":
        default:
            return "max-w-7xl";
    }
}

export function getVisibilityClass(
    settings: SectionSettings,
) {
    const classes: string[] = [];

    if (settings.hide_mobile === true) {
        classes.push("max-md:hidden");
    }

    if (settings.hide_tablet === true) {
        classes.push(
            "md:max-lg:hidden",
        );
    }

    if (settings.hide_desktop === true) {
        classes.push("lg:hidden");
    }

    return classes.join(" ");
}

export function getSectionId(
    settings: SectionSettings,
) {
    const value = String(
        settings.section_id ?? "",
    )
        .trim()
        .toLowerCase();

    return value
        .replace(/\s+/g, "-")
        .replace(/[^a-z0-9-_]/g, "");
}

export function getCustomClass(
    settings: SectionSettings,
) {
    return String(
        settings.custom_class ?? "",
    )
        .trim()
        .replace(/[^a-zA-Z0-9-_ ]/g, "");
}
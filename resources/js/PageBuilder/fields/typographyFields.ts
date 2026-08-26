import type { SectionField } from "../types";

export const typographyFields: SectionField[] = [
    {
        key: "title_font_size",
        label: "Title Font Size",
        type: "number",
        group: "style",
        panel: "typography",
    },

    {
        key: "text_font_size",
        label: "Text Font Size",
        type: "number",
        group: "style",
        panel: "typography",
    },

    {
        key: "title_color",
        label: "Title Color",
        type: "color",
        group: "style",
        panel: "typography",
    },

    {
        key: "text_color",
        label: "Text Color",
        type: "color",
        group: "style",
        panel: "typography",
    },

    {
        key: "accent_color",
        label: "Accent Color",
        type: "color",
        group: "style",
        panel: "typography",
    },
];
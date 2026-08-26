import type { SectionField } from "../types";

export const backgroundFields: SectionField[] = [
    {
        key: "background_type",
        label: "Background Type",
        type: "select",
        group: "style",
        panel: "background",

        options: [
            {
                label: "Color",
                value: "color",
            },
            {
                label: "Image",
                value: "image",
            },
        ],
    },

    {
        key: "background_color",
        label: "Background Color",
        type: "color",
        group: "style",
        panel: "background",

        visibleWhen: {
            field: "background_type",
            equals: "color",
        },
    },

    {
        key: "background_image",
        label: "Background Image",
        type: "image",
        group: "style",
        panel: "background",

        visibleWhen: {
            field: "background_type",
            equals: "image",
        },
    },
];
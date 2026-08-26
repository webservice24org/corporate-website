import type { SectionField } from "../types";

export const alignmentFields: SectionField[] = [
    {
        key: "text_alignment",
        label: "Text Alignment",
        type: "select",
        group: "style",
        panel: "alignment",

        options: [
            {
                label: "Left",
                value: "left",
            },
            {
                label: "Center",
                value: "center",
            },
            {
                label: "Right",
                value: "right",
            },
        ],
    },
];
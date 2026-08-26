import type { SectionField } from "../types";

export const advancedFields: SectionField[] = [
    // -------------------------------------------------
    // Spacing
    // -------------------------------------------------

    {
        key: "margin_top",
        label: "Margin Top",
        type: "number",
        group: "advanced",
        panel: "spacing",
        placeholder: "0",
    },

    {
        key: "margin_bottom",
        label: "Margin Bottom",
        type: "number",
        group: "advanced",
        panel: "spacing",
        placeholder: "0",
    },

    {
        key: "padding_top",
        label: "Padding Top",
        type: "number",
        group: "advanced",
        panel: "spacing",
        placeholder: "80",
    },

    {
        key: "padding_bottom",
        label: "Padding Bottom",
        type: "number",
        group: "advanced",
        panel: "spacing",
        placeholder: "80",
    },

    {
        key: "padding_horizontal",
        label: "Horizontal Padding",
        type: "number",
        group: "advanced",
        panel: "spacing",
        placeholder: "24",
    },

    // -------------------------------------------------
    // Layout
    // -------------------------------------------------

    {
        key: "container_width",
        label: "Container Width",
        type: "select",
        group: "advanced",
        panel: "layout",

        options: [
            {
                label: "Small",
                value: "small",
            },
            {
                label: "Medium",
                value: "medium",
            },
            {
                label: "Large",
                value: "large",
            },
            {
                label: "Extra Large",
                value: "xl",
            },
            {
                label: "Full Width",
                value: "full",
            },
        ],
    },

    // -------------------------------------------------
    // Responsive
    // -------------------------------------------------

    {
        key: "hide_desktop",
        label: "Hide on Desktop",
        type: "checkbox",
        group: "advanced",
        panel: "responsive",
    },

    {
        key: "hide_tablet",
        label: "Hide on Tablet",
        type: "checkbox",
        group: "advanced",
        panel: "responsive",
    },

    {
        key: "hide_mobile",
        label: "Hide on Mobile",
        type: "checkbox",
        group: "advanced",
        panel: "responsive",
    },

    // -------------------------------------------------
    // Developer
    // -------------------------------------------------

    {
        key: "section_id",
        label: "Section ID / Anchor",
        type: "text",
        group: "advanced",
        panel: "developer",
        placeholder: "our-team",
    },

    {
        key: "custom_class",
        label: "Custom CSS Class",
        type: "text",
        group: "advanced",
        panel: "developer",
        placeholder: "special-about-section",
    },
];
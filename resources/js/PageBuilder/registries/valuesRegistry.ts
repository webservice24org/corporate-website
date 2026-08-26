import type {
    SectionGroup,
} from "../types";

import Values01 from "../sections/Values/Values01";

import {
    backgroundFields,
    typographyFields,
    alignmentFields,
    advancedFields,
} from "../fields";

export const valuesRegistry: SectionGroup = {
    type: "values",
    label: "Mission & Values",

    designs: [
        {
            id: "values-01",

            label:
                "Mission Vision & Values",

            description:
                "Dynamic mission, vision and core values cards with selectable icon or image media.",

            component:
                Values01,

            defaultSettings: {
                top_title:
                    "What Guides Us",

                title:
                    "Our Mission, Vision & Core Values",

                description:
                    "The principles that guide our decisions, shape our culture, and define the impact we aim to create.",

                items: [
                    {
                        media_type:
                            "icon",

                        icon:
                            "target",

                        image: "",

                        title:
                            "Our Mission",

                        description:
                            "To deliver innovative, sustainable, and dependable solutions that create long-term value for our clients and communities.",
                    },

                    {
                        media_type:
                            "icon",

                        icon:
                            "eye",

                        image: "",

                        title:
                            "Our Vision",

                        description:
                            "To become a trusted leader recognized for innovation, quality, responsible growth, and positive social impact.",
                    },

                    {
                        media_type:
                            "icon",

                        icon:
                            "shield-check",

                        image: "",

                        title:
                            "Integrity",

                        description:
                            "We act transparently, honor our commitments, and uphold strong ethical standards in everything we do.",
                    },
                ],

                columns: "3",

                media_style:
                    "rounded",

                /*
                 * Style
                 */
                background_type:
                    "color",

                background_color:
                    "#f8fafc",

                background_image:
                    "",

                title_font_size:
                    40,

                text_font_size:
                    16,

                title_color:
                    "#111827",

                text_color:
                    "#6b7280",

                accent_color:
                    "#059669",

                text_alignment:
                    "center",

                /*
                 * Advanced
                 */
                margin_top: 0,
                margin_bottom: 0,

                padding_top: 80,
                padding_bottom: 80,
                padding_horizontal: 24,

                container_width:
                    "xl",

                hide_desktop:
                    false,

                hide_tablet:
                    false,

                hide_mobile:
                    false,

                section_id: "",
                custom_class: "",
            },

            fields: [
                {
                    key:
                        "top_title",

                    label:
                        "Top Title",

                    type:
                        "text",

                    maxLength:
                        80,
                },

                {
                    key:
                        "title",

                    label:
                        "Section Title",

                    type:
                        "text",

                    maxLength:
                        160,
                },

                {
                    key:
                        "description",

                    label:
                        "Section Description",

                    type:
                        "textarea",

                    rows: 4,

                    maxLength:
                        320,
                },

                {
                    key:
                        "items",

                    label:
                        "Mission / Values Items",

                    type:
                        "repeater",

                    itemLabel:
                        "Item",

                    min: 1,
                    max: 12,

                    defaultItem: {
                        media_type:
                            "icon",

                        icon:
                            "star",

                        image: "",

                        title:
                            "New Value",

                        description:
                            "Describe this value, principle, mission, or goal.",
                    },

                    fields: [
                        {
                            key:
                                "media_type",

                            label:
                                "Media Type",

                            type:
                                "select",

                            options: [
                                {
                                    label:
                                        "Icon",

                                    value:
                                        "icon",
                                },

                                {
                                    label:
                                        "Image",

                                    value:
                                        "image",
                                },
                            ],
                        },

                        {
                            key:
                                "icon",

                            label:
                                "Icon",

                            type:
                                "icon",

                            visibleWhen:
                                {
                                    field:
                                        "media_type",

                                    equals:
                                        "icon",
                                },
                        },

                        {
                            key:
                                "image",

                            label:
                                "Image",

                            type:
                                "image",

                            visibleWhen:
                                {
                                    field:
                                        "media_type",

                                    equals:
                                        "image",
                                },
                        },

                        {
                            key:
                                "title",

                            label:
                                "Item Title",

                            type:
                                "text",

                            maxLength:
                                100,
                        },

                        {
                            key:
                                "description",

                            label:
                                "Description",

                            type:
                                "textarea",

                            rows: 5,

                            maxLength:
                                500,
                        },
                    ],
                },

                {
                    key:
                        "columns",

                    label:
                        "Columns",

                    type:
                        "select",

                    group:
                        "style",

                    panel:
                        "alignment",

                    options: [
                        {
                            label:
                                "2 Columns",

                            value:
                                "2",
                        },

                        {
                            label:
                                "3 Columns",

                            value:
                                "3",
                        },

                        {
                            label:
                                "4 Columns",

                            value:
                                "4",
                        },
                    ],
                },

                {
                    key:
                        "media_style",

                    label:
                        "Media Shape",

                    type:
                        "select",

                    group:
                        "style",

                    panel:
                        "alignment",

                    options: [
                        {
                            label:
                                "Rounded",

                            value:
                                "rounded",
                        },

                        {
                            label:
                                "Circle",

                            value:
                                "circle",
                        },

                        {
                            label:
                                "Square",

                            value:
                                "square",
                        },
                    ],
                },

                ...backgroundFields,
                ...typographyFields,
                ...alignmentFields,
                ...advancedFields,
            ],
        },
    ],
};
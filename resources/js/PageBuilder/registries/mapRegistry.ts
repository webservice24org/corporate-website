import type {
    SectionGroup,
} from "../types";

import Map01 from "../sections/Map/Map01";

import {
    backgroundFields,
    typographyFields,
    alignmentFields,
    advancedFields,
} from "../fields";

export const mapRegistry: SectionGroup = {
    type: "map",
    label: "Google Map",

    designs: [
        {
            id: "map-01",

            label:
                "Google Map",

            description:
                "Responsive Google Maps embed with optional heading and full-width layout.",

            component:
                Map01,

            defaultSettings: {
                /*
                 * Content
                 */
                show_header:
                    true,

                top_title:
                    "Our Location",

                title:
                    "Find Us",

                description:
                    "Visit our office or use the map below to get directions.",

                map_url:
                    "",

                /*
                 * Map
                 */
                map_height:
                    450,

                map_radius:
                    24,

                full_width:
                    false,

                /*
                 * Style
                 */
                background_type:
                    "color",

                background_color:
                    "#ffffff",

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

                section_id:
                    "location-map",

                custom_class:
                    "",
            },

            fields: [
                {
                    key:
                        "show_header",

                    label:
                        "Show Heading",

                    type:
                        "checkbox",
                },

                {
                    key:
                        "top_title",

                    label:
                        "Top Title",

                    type:
                        "text",

                    maxLength:
                        80,

                    visibleWhen: {
                        field:
                            "show_header",

                        equals:
                            true,
                    },
                },

                {
                    key:
                        "title",

                    label:
                        "Title",

                    type:
                        "text",

                    maxLength:
                        120,

                    visibleWhen: {
                        field:
                            "show_header",

                        equals:
                            true,
                    },
                },

                {
                    key:
                        "description",

                    label:
                        "Description",

                    type:
                        "textarea",

                    rows: 3,

                    maxLength:
                        300,

                    visibleWhen: {
                        field:
                            "show_header",

                        equals:
                            true,
                    },
                },

                {
                    key:
                        "map_url",

                    label:
                        "Google Maps Embed",

                    type:
                        "textarea",

                    rows: 5,

                    placeholder:
                        "Paste Google Maps embed URL or iframe code",
                },

                {
                    key:
                        "full_width",

                    label:
                        "Full Width Map",

                    type:
                        "checkbox",

                    group:
                        "style",

                    panel:
                        "alignment",
                },

                {
                    key:
                        "map_height",

                    label:
                        "Map Height (px)",

                    type:
                        "number",

                    group:
                        "style",

                    panel:
                        "alignment",
                },

                {
                    key:
                        "map_radius",

                    label:
                        "Corner Radius (px)",

                    type:
                        "number",

                    group:
                        "style",

                    panel:
                        "alignment",

                    visibleWhen: {
                        field:
                            "full_width",

                        equals:
                            false,
                    },
                },

                ...backgroundFields,
                ...typographyFields,
                ...alignmentFields,
                ...advancedFields,
            ],
        },
    ],
};
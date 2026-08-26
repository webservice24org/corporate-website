import type {
    SectionGroup,
} from "../types";

import PageBanner01 from "../sections/PageBanner/PageBanner01";

import {
    backgroundFields,
    typographyFields,
    alignmentFields,
    advancedFields,
} from "../fields";

export const pageBannerRegistry: SectionGroup = {
    type: "page-banner",
    label: "Page Banner",

    designs: [
        {
            id: "page-banner-01",

            label:
                "Corporate Page Banner",

            description:
                "Page banner with badge, large heading, description and color or image background.",

            component:
                PageBanner01,

            defaultSettings: {
                badge:
                    "Who We Are",

                title:
                    "Engineering Excellence & Unmatched Quality",

                description:
                    "Empowering modern infrastructure with advanced chemical admixtures, durable construction materials, and innovative industrial engineering supplies.",

                background_type:
                    "color",

                background_color:
                    "#030712",

                background_image:
                    "",

                overlay_opacity:
                    0.55,

                title_font_size:
                    56,

                text_font_size:
                    18,

                title_color:
                    "#ffffff",

                text_color:
                    "#d1d5db",

                accent_color:
                    "#10b981",

                text_alignment:
                    "center",

                margin_top: 0,
                margin_bottom: 0,

                padding_top: 96,
                padding_bottom: 96,
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

                typing_effect: true,
                typing_speed: 70,
            },

            fields: [
                {
                    key:
                        "badge",

                    label:
                        "Badge",

                    type:
                        "text",

                    maxLength:
                        60,
                },

                {
                    key:
                        "title",

                    label:
                        "Page Title",

                    type:
                        "text",

                    maxLength:
                        140,
                },

                {
                    key: "typing_effect",
                    label: "Enable Typing Effect",
                    type: "checkbox",
                },

                {
                    key: "typing_speed",
                    label: "Typing Speed (ms)",
                    type: "number",

                    visibleWhen: {
                        field: "typing_effect",
                        equals: true,
                    },
                },

                {
                    key:
                        "description",

                    label:
                        "Description",

                    type:
                        "textarea",

                    rows: 4,

                    maxLength:
                        300,
                },

                {
                    key:
                        "overlay_opacity",

                    label:
                        "Image Overlay Opacity",

                    type:
                        "number",

                    group:
                        "style",

                    panel:
                        "background",

                    visibleWhen: {
                        field:
                            "background_type",

                        equals:
                            "image",
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
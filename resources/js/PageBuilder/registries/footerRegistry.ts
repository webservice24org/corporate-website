import type {
    SectionGroup,
} from "../types";

import Footer01 from "../sections/Footer/Footer01";

import {
    advancedFields,
} from "../fields";

export const footerRegistry: SectionGroup = {
    type: "footer",
    label: "Footer",

    designs: [
        {
            id: "footer-01",

            label:
                "Corporate Footer",

            description:
                "Dynamic corporate footer with company info, links, contact details, newsletter and bottom links.",

            component:
                Footer01,

            defaultSettings: {
                /*
                 * Company
                 */
                brand_short: "AG",
                brand_text:
                    "Arab Group",

                about_text:
                    "A diversified business conglomerate dedicated to sustainable growth, technical innovation, and societal advancement.",

                /*
                 * Quick Links
                 */
                link_columns: [
                    {
                        title:
                            "Key Subsidiaries",

                        links: [
                            {
                                label:
                                    "Arab Global Ltd",

                                url: "#",
                            },

                            {
                                label:
                                    "Arab Green Agro Ltd",

                                url: "#",
                            },

                            {
                                label:
                                    "Arab IT",

                                url: "#",
                            },

                            {
                                label:
                                    "Anusandhan News",

                                url: "#",
                            },
                        ],
                    },
                ],

                /*
                 * Contact
                 */
                contact_title:
                    "Headquarters",

                contact_items: [
                    {
                        icon:
                            "map-pin",

                        text:
                            "46/6, Haque Tower, M block main road, Dhaka 1219",

                        url: "",
                    },

                    {
                        icon:
                            "mail",

                        text:
                            "info@arabgroupbd.com",

                        url:
                            "mailto:info@arabgroupbd.com",
                    },

                    {
                        icon:
                            "phone",

                        text:
                            "+880 1826-065530",

                        url:
                            "tel:+8801826065530",
                    },
                ],

                /*
                 * Newsletter
                 */
                newsletter_title:
                    "Stay Updated",

                newsletter_text:
                    "Subscribe for corporate announcements and insights.",

                newsletter_placeholder:
                    "Enter your email",

                newsletter_button_text:
                    "Subscribe",

                /*
                 * Bottom
                 */
                copyright_text:
                    "© 2026 Arab Group. All rights reserved.",

                bottom_links: [
                    {
                        label:
                            "Privacy Policy",

                        url: "#",
                    },

                    {
                        label:
                            "Terms of Service",

                        url: "#",
                    },

                    {
                        label:
                            "Sitemap",

                        url: "#",
                    },
                ],

                developer_prefix:
                    "Designed & Developed by",

                developer_name:
                    "MicroWeb Technology",

                developer_url:
                    "https://webservicebd.org",

                /*
                 * Style
                 */
                background_color:
                    "#111827",

                title_color:
                    "#ffffff",

                text_color:
                    "#9ca3af",

                accent_color:
                    "#059669",

                /*
                 * Advanced
                 */
                margin_top: 0,
                margin_bottom: 0,

                padding_top: 0,
                padding_bottom: 0,
                padding_horizontal: 24,

                container_width:
                    "xl",

                hide_desktop: false,
                hide_tablet: false,
                hide_mobile: false,

                section_id:
                    "footer",

                custom_class: "",
            },

            fields: [
                {
                    key:
                        "brand_short",

                    label:
                        "Brand Short",

                    type:
                        "text",

                    maxLength:
                        4,
                },

                {
                    key:
                        "brand_text",

                    label:
                        "Brand Name",

                    type:
                        "text",

                    maxLength:
                        100,
                },

                {
                    key:
                        "about_text",

                    label:
                        "About Text",

                    type:
                        "textarea",

                    rows: 4,

                    maxLength:
                        350,
                },

                /*
                 * Link columns
                 */
                {
                    key:
                        "link_columns",

                    label:
                        "Link Columns",

                    type:
                        "repeater",

                    itemLabel:
                        "Column",

                    min: 1,
                    max: 3,

                    defaultItem: {
                        title:
                            "Links",

                        links: [
                            {
                                label:
                                    "New Link",

                                url:
                                    "#",
                            },
                        ],
                    },

                    fields: [
                        {
                            key:
                                "title",

                            label:
                                "Column Title",

                            type:
                                "text",

                            maxLength:
                                80,
                        },

                        {
                            key:
                                "links",

                            label:
                                "Links",

                            type:
                                "repeater",

                            itemLabel:
                                "Link",

                            min: 1,
                            max: 20,

                            defaultItem: {
                                label:
                                    "New Link",

                                url:
                                    "#",
                            },

                            fields: [
                                {
                                    key:
                                        "label",

                                    label:
                                        "Label",

                                    type:
                                        "text",
                                },

                                {
                                    key:
                                        "url",

                                    label:
                                        "URL",

                                    type:
                                        "url",
                                },
                            ],
                        },
                    ],
                },

                /*
                 * Contact
                 */
                {
                    key:
                        "contact_title",

                    label:
                        "Contact Column Title",

                    type:
                        "text",
                },

                {
                    key:
                        "contact_items",

                    label:
                        "Contact Items",

                    type:
                        "repeater",

                    itemLabel:
                        "Contact",

                    min: 1,
                    max: 10,

                    defaultItem: {
                        icon:
                            "map-pin",

                        text:
                            "Contact information",

                        url:
                            "",
                    },

                    fields: [
                        {
                            key:
                                "icon",

                            label:
                                "Icon",

                            type:
                                "icon",
                        },

                        {
                            key:
                                "text",

                            label:
                                "Text",

                            type:
                                "textarea",

                            rows: 2,
                        },

                        {
                            key:
                                "url",

                            label:
                                "Optional URL",

                            type:
                                "url",
                        },
                    ],
                },

                /*
                 * Newsletter
                 */
                {
                    key:
                        "newsletter_title",

                    label:
                        "Newsletter Title",

                    type:
                        "text",
                },

                {
                    key:
                        "newsletter_text",

                    label:
                        "Newsletter Description",

                    type:
                        "textarea",

                    rows: 3,
                },

                {
                    key:
                        "newsletter_placeholder",

                    label:
                        "Email Placeholder",

                    type:
                        "text",
                },

                {
                    key:
                        "newsletter_button_text",

                    label:
                        "Subscribe Button",

                    type:
                        "text",
                },

                /*
                 * Bottom
                 */
                {
                    key:
                        "copyright_text",

                    label:
                        "Copyright Text",

                    type:
                        "text",
                },

                {
                    key:
                        "bottom_links",

                    label:
                        "Bottom Links",

                    type:
                        "repeater",

                    itemLabel:
                        "Link",

                    min: 0,
                    max: 10,

                    defaultItem: {
                        label:
                            "New Link",

                        url:
                            "#",
                    },

                    fields: [
                        {
                            key:
                                "label",

                            label:
                                "Label",

                            type:
                                "text",
                        },

                        {
                            key:
                                "url",

                            label:
                                "URL",

                            type:
                                "url",
                        },
                    ],
                },

                {
                    key:
                        "developer_prefix",

                    label:
                        "Developer Prefix",

                    type:
                        "text",
                },

                {
                    key:
                        "developer_name",

                    label:
                        "Developer Name",

                    type:
                        "text",
                },

                {
                    key:
                        "developer_url",

                    label:
                        "Developer URL",

                    type:
                        "url",
                },

                /*
                 * Colors
                 */
                {
                    key:
                        "background_color",

                    label:
                        "Background Color",

                    type:
                        "color",

                    group:
                        "style",

                    panel:
                        "background",
                },

                {
                    key:
                        "title_color",

                    label:
                        "Heading Color",

                    type:
                        "color",

                    group:
                        "style",

                    panel:
                        "typography",
                },

                {
                    key:
                        "text_color",

                    label:
                        "Text Color",

                    type:
                        "color",

                    group:
                        "style",

                    panel:
                        "typography",
                },

                {
                    key:
                        "accent_color",

                    label:
                        "Accent Color",

                    type:
                        "color",

                    group:
                        "style",

                    panel:
                        "typography",
                },

                ...advancedFields,
            ],
        },
    ],
};
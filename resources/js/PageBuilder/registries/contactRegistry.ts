import type {
    SectionGroup,
} from "../types";

import Contact01 from "../sections/Contact/Contact01";

import {
    backgroundFields,
    typographyFields,
    alignmentFields,
    advancedFields,
} from "../fields";

export const contactRegistry: SectionGroup = {
    type: "contact",
    label: "Contact",

    designs: [
        {
            id: "contact-01",

            label:
                "Corporate Contact Form",

            description:
                "Dynamic contact information, social links, inquiry targets and a real contact form.",

            component:
                Contact01,

            defaultSettings: {
                /*
                 * Header
                 */
                badge:
                    "Get In Touch",

                title:
                    "Contact Us",

                description:
                    "Have an inquiry or looking to collaborate? Send us a message and our team will get back to you shortly.",

                /*
                 * Sidebar
                 */
                sidebar_title:
                    "Corporate Headquarters",

                sidebar_description:
                    "Reach out directly to our corporate office or visit us at our main location.",

                contact_items: [
                    {
                        icon:
                            "map-pin",

                        title:
                            "Office Address",

                        content:
                            "46/6, Haque Tower, M block main road, Dhaka 1219, Dhaka, Bangladesh",
                    },

                    {
                        icon:
                            "headphones",

                        title:
                            "Phone Support",

                        content:
                            "+880 1826-065530 (Main Office)\n+880 1826065530 (Hotline & WhatsApp)",
                    },

                    {
                        icon:
                            "mail",

                        title:
                            "Email Address",

                        content:
                            "info@example.com\nsupport@example.com",
                    },
                ],

                /*
                 * Social channels
                 */
                social_title:
                    "Follow Our Social Channels",

                social_links: [
                    {
                        icon:
                            "globe",

                        url:
                            "#",
                    },

                    {
                        icon:
                            "link",

                        url:
                            "#",
                    },

                    {
                        icon:
                            "message-circle",

                        url:
                            "#",
                    },
                ],

                /*
                 * Form target selector
                 */
                target_options: [
                    {
                        label:
                            "General Inquiry",

                        value:
                            "general",
                    },

                    {
                        label:
                            "Corporate Office",

                        value:
                            "corporate",
                    },

                    {
                        label:
                            "Sales Department",

                        value:
                            "sales",
                    },

                    {
                        label:
                            "Support Department",

                        value:
                            "support",
                    },
                ],

                submit_text:
                    "Send Message",

                success_message:
                    "Thank you for contacting us. We will get back to you shortly.",

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
                    "contact-section",

                custom_class:
                    "",
            },

            fields: [
                /*
                 * Heading
                 */
                {
                    key: "badge",
                    label: "Badge",
                    type: "text",
                    maxLength: 60,
                },

                {
                    key: "title",
                    label: "Section Title",
                    type: "text",
                    maxLength: 120,
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
                        300,
                },

                /*
                 * Sidebar
                 */
                {
                    key:
                        "sidebar_title",

                    label:
                        "Sidebar Title",

                    type:
                        "text",

                    maxLength:
                        100,
                },

                {
                    key:
                        "sidebar_description",

                    label:
                        "Sidebar Description",

                    type:
                        "textarea",

                    rows: 3,

                    maxLength:
                        300,
                },

                /*
                 * Contact info repeater
                 */
                {
                    key:
                        "contact_items",

                    label:
                        "Contact Info",

                    type:
                        "repeater",

                    itemLabel:
                        "Contact Item",

                    min: 1,
                    max: 12,

                    defaultItem: {
                        icon:
                            "map-pin",

                        title:
                            "New Contact Item",

                        content:
                            "Add contact information here.",
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
                                "title",

                            label:
                                "Title",

                            type:
                                "text",

                            maxLength:
                                80,
                        },

                        {
                            key:
                                "content",

                            label:
                                "Content",

                            type:
                                "textarea",

                            rows: 4,

                            maxLength:
                                500,
                        },
                    ],
                },

                /*
                 * Social channels
                 */
                {
                    key:
                        "social_title",

                    label:
                        "Social Section Title",

                    type:
                        "text",

                    maxLength:
                        80,
                },

                {
                    key:
                        "social_links",

                    label:
                        "Social Channels",

                    type:
                        "repeater",

                    itemLabel:
                        "Social Link",

                    min: 0,
                    max: 12,

                    defaultItem: {
                        icon:
                            "globe",

                        url:
                            "#",
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
                                "url",

                            label:
                                "URL",

                            type:
                                "url",
                        },
                    ],
                },

                /*
                 * Form target options
                 */
                {
                    key:
                        "target_options",

                    label:
                        "Target Options",

                    type:
                        "repeater",

                    itemLabel:
                        "Target",

                    min: 0,
                    max: 30,

                    defaultItem: {
                        label:
                            "New Department",

                        value:
                            "new-department",
                    },

                    fields: [
                        {
                            key:
                                "label",

                            label:
                                "Label",

                            type:
                                "text",

                            maxLength:
                                100,
                        },

                        {
                            key:
                                "value",

                            label:
                                "Value",

                            type:
                                "text",

                            maxLength:
                                100,
                        },
                    ],
                },

                /*
                 * Form messages
                 */
                {
                    key:
                        "submit_text",

                    label:
                        "Submit Button Text",

                    type:
                        "text",

                    maxLength:
                        50,
                },

                {
                    key:
                        "success_message",

                    label:
                        "Success Message",

                    type:
                        "textarea",

                    rows: 3,

                    maxLength:
                        250,
                },

                /*
                 * Shared Style
                 */
                ...backgroundFields,
                ...typographyFields,
                ...alignmentFields,
                ...advancedFields,
            ],
        },
    ],
};
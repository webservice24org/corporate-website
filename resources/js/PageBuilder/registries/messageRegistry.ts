import type {
    SectionGroup,
} from "../types";

import Message01 from "../sections/Message/Message01";

import {
    backgroundFields,
    typographyFields,
    advancedFields,
} from "../fields";

export const messageRegistry: SectionGroup = {
    type: "message",
    label: "Message",

    designs: [
        {
            id: "message-01",

            label:
                "Executive Message",

            description:
                "Reusable executive message layout with person image, stats, quote, long-form message, signature and CTA.",

            component:
                Message01,

            defaultSettings: {
                person_image: "",

                person_name:
                    "MD. Monir Hossen",

                person_role:
                    "Founder & Chairman",

                show_stats:
                    true,

                stats: [
                    {
                        value: "15+",
                        label:
                            "Years of Leadership",
                    },

                    {
                        value: "10",
                        label:
                            "Subsidiaries Built",
                    },
                ],

                show_quote:
                    true,

                quote:
                    "Our primary objective has never been just building businesses, but creating economic opportunities and sustainable infrastructure that empowers future generations.",

                top_title:
                    "Official Address",

                title:
                    "Building Sustainable Futures Together",

                greeting:
                    "Welcome to our organization,",

                paragraphs: [
                    {
                        text:
                            "Since our inception, we have operated with a clear vision: to deliver transformative growth, modern innovation, and sustainable solutions across the sectors we serve.",
                    },

                    {
                        text:
                            "Our journey has been shaped by an unwavering commitment to quality, responsible leadership, and long-term partnerships with our clients, employees, and stakeholders.",
                    },

                    {
                        text:
                            "As we look toward the future, we remain committed to embracing innovation, strengthening international relationships, and creating meaningful opportunities for the next generation.",
                    },
                ],

                show_signature:
                    true,

                signature_image:
                    "",

                signature_text:
                    "[Signature]",

                signature_name:
                    "MD. Monir Hossen",

                signature_role:
                    "Chairman & Managing Director",

                signature_organization:
                    "Corporate Council",

                show_button:
                    true,

                button_text:
                    "Schedule A Meeting",

                button_url:
                    "#contact",

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
                    36,

                text_font_size:
                    17,

                title_color:
                    "#111827",

                text_color:
                    "#374151",

                accent_color:
                    "#059669",

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
                        "person_image",

                    label:
                        "Person Image",

                    type:
                        "image",
                },

                {
                    key:
                        "person_name",

                    label:
                        "Person Name",

                    type:
                        "text",

                    maxLength:
                        100,
                },

                {
                    key:
                        "person_role",

                    label:
                        "Designation",

                    type:
                        "text",

                    maxLength:
                        140,
                },

                {
                    key:
                        "show_stats",

                    label:
                        "Show Statistics",

                    type:
                        "checkbox",
                },

                {
                    key:
                        "stats",

                    label:
                        "Statistics",

                    type:
                        "repeater",

                    itemLabel:
                        "Statistic",

                    min: 1,
                    max: 4,

                    visibleWhen: {
                        field:
                            "show_stats",

                        equals:
                            true,
                    },

                    defaultItem: {
                        value: "10+",

                        label:
                            "Years Experience",
                    },

                    fields: [
                        {
                            key:
                                "value",

                            label:
                                "Value",

                            type:
                                "text",

                            maxLength:
                                20,
                        },

                        {
                            key:
                                "label",

                            label:
                                "Label",

                            type:
                                "text",

                            maxLength:
                                80,
                        },
                    ],
                },

                {
                    key:
                        "show_quote",

                    label:
                        "Show Quote",

                    type:
                        "checkbox",
                },

                {
                    key:
                        "quote",

                    label:
                        "Highlighted Quote",

                    type:
                        "textarea",

                    rows: 4,

                    maxLength:
                        350,

                    visibleWhen: {
                        field:
                            "show_quote",

                        equals:
                            true,
                    },
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
                },

                {
                    key:
                        "title",

                    label:
                        "Message Title",

                    type:
                        "text",

                    maxLength:
                        180,
                },

                {
                    key:
                        "greeting",

                    label:
                        "Greeting",

                    type:
                        "text",

                    maxLength:
                        160,
                },

                {
                    key:
                        "paragraphs",

                    label:
                        "Message Paragraphs",

                    type:
                        "repeater",

                    itemLabel:
                        "Paragraph",

                    min: 1,
                    max: 20,

                    defaultItem: {
                        text:
                            "Write the next paragraph here.",
                    },

                    fields: [
                        {
                            key:
                                "text",

                            label:
                                "Paragraph",

                            type:
                                "textarea",

                            rows: 6,

                            maxLength:
                                1500,
                        },
                    ],
                },

                {
                    key:
                        "show_signature",

                    label:
                        "Show Signature",

                    type:
                        "checkbox",
                },

                {
                    key:
                        "signature_image",

                    label:
                        "Signature Image",

                    type:
                        "image",

                    visibleWhen: {
                        field:
                            "show_signature",

                        equals:
                            true,
                    },
                },

                {
                    key:
                        "signature_text",

                    label:
                        "Signature Text / Fallback",

                    type:
                        "text",

                    visibleWhen: {
                        field:
                            "show_signature",

                        equals:
                            true,
                    },
                },

                {
                    key:
                        "signature_name",

                    label:
                        "Signature Name",

                    type:
                        "text",

                    visibleWhen: {
                        field:
                            "show_signature",

                        equals:
                            true,
                    },
                },

                {
                    key:
                        "signature_role",

                    label:
                        "Signature Designation",

                    type:
                        "text",

                    visibleWhen: {
                        field:
                            "show_signature",

                        equals:
                            true,
                    },
                },

                {
                    key:
                        "signature_organization",

                    label:
                        "Organization / Council",

                    type:
                        "text",

                    visibleWhen: {
                        field:
                            "show_signature",

                        equals:
                            true,
                    },
                },

                {
                    key:
                        "show_button",

                    label:
                        "Show Action Button",

                    type:
                        "checkbox",
                },

                {
                    key:
                        "button_text",

                    label:
                        "Button Text",

                    type:
                        "text",

                    maxLength:
                        60,

                    visibleWhen: {
                        field:
                            "show_button",

                        equals:
                            true,
                    },
                },

                {
                    key:
                        "button_url",

                    label:
                        "Button URL",

                    type:
                        "url",

                    visibleWhen: {
                        field:
                            "show_button",

                        equals:
                            true,
                    },
                },

                ...backgroundFields,
                ...typographyFields,
                ...advancedFields,
            ],
        },
    ],
};
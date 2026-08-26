import type { SectionGroup } from "../types";

import FloatingContact01 from "../sections/Global/FloatingContact01";

export const floatingContactRegistry: SectionGroup = {
    type:
        "floating-contact",

    label:
        "Floating Contact",

    designs: [
        {
            id:
                "floating-contact-01",

            label:
                "Call & WhatsApp",

            description:
                "Global floating Call and WhatsApp action buttons.",

            component:
                FloatingContact01,

            defaultSettings: {
                show_phone:
                    true,

                phone_number:
                    "+8801700000000",

                phone_label:
                    "Call Corporate",

                phone_color:
                    "#059669",

                show_whatsapp:
                    true,

                whatsapp_number:
                    "8801800000000",

                whatsapp_label:
                    "Chat on WhatsApp",

                whatsapp_color:
                    "#22c55e",

                whatsapp_pulse:
                    true,

                position:
                    "bottom-right",

                bottom_offset:
                    24,

                side_offset:
                    24,

                button_size:
                    56,
            },

            fields: [
                {
                    key:
                        "show_phone",

                    label:
                        "Show Phone Button",

                    type:
                        "checkbox",
                },

                {
                    key:
                        "phone_number",

                    label:
                        "Phone Number",

                    type:
                        "text",

                    visibleWhen: {
                        field:
                            "show_phone",

                        equals:
                            true,
                    },
                },

                {
                    key:
                        "phone_label",

                    label:
                        "Phone Tooltip",

                    type:
                        "text",

                    visibleWhen: {
                        field:
                            "show_phone",

                        equals:
                            true,
                    },
                },

                {
                    key:
                        "phone_color",

                    label:
                        "Phone Button Color",

                    type:
                        "color",

                    visibleWhen: {
                        field:
                            "show_phone",

                        equals:
                            true,
                    },
                },

                {
                    key:
                        "show_whatsapp",

                    label:
                        "Show WhatsApp Button",

                    type:
                        "checkbox",
                },

                {
                    key:
                        "whatsapp_number",

                    label:
                        "WhatsApp Number",

                    type:
                        "text",

                    visibleWhen: {
                        field:
                            "show_whatsapp",

                        equals:
                            true,
                    },
                },

                {
                    key:
                        "whatsapp_label",

                    label:
                        "WhatsApp Tooltip",

                    type:
                        "text",

                    visibleWhen: {
                        field:
                            "show_whatsapp",

                        equals:
                            true,
                    },
                },

                {
                    key:
                        "whatsapp_color",

                    label:
                        "WhatsApp Button Color",

                    type:
                        "color",

                    visibleWhen: {
                        field:
                            "show_whatsapp",

                        equals:
                            true,
                    },
                },

                {
                    key:
                        "whatsapp_pulse",

                    label:
                        "WhatsApp Pulse Effect",

                    type:
                        "checkbox",

                    visibleWhen: {
                        field:
                            "show_whatsapp",

                        equals:
                            true,
                    },
                },

                {
                    key:
                        "position",

                    label:
                        "Position",

                    type:
                        "select",

                    options: [
                        {
                            label:
                                "Bottom Right",

                            value:
                                "bottom-right",
                        },

                        {
                            label:
                                "Bottom Left",

                            value:
                                "bottom-left",
                        },
                    ],
                },

                {
                    key:
                        "button_size",

                    label:
                        "Button Size (px)",

                    type:
                        "number",
                },

                {
                    key:
                        "bottom_offset",

                    label:
                        "Bottom Offset (px)",

                    type:
                        "number",
                },

                {
                    key:
                        "side_offset",

                    label:
                        "Side Offset (px)",

                    type:
                        "number",
                },
            ],
        },
    ],
};
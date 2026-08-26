import type { SectionGroup } from "../types";

import CTA01 from "../sections/CTA/CTA01";
import CTA02 from "../sections/CTA/CTA02";
import CTA03 from "../sections/CTA/CTA03";

import {
    backgroundFields,
    typographyFields,
    alignmentFields,
    advancedFields,
} from "../fields";

export const ctaRegistry: SectionGroup = {
    type: "cta",
    label: "Call To Action",

    designs: [
        {
            id: "cta-01",
            label: "Phone CTA",
            description:
                "Large centered call-to-action with phone number and support note.",

            component: CTA01,

            defaultSettings: {
                title:
                    "Ready to Elevate Your Business with Sigma Plus?",

                description:
                    "Have questions or need immediate assistance? Speak directly with our expert team today.",

                phone_label:
                    "+880 1531352693",

                phone_number:
                    "+8801531352693",

                show_note: true,

                note:
                    "✓ Available Saturday – Thursday, 9:00 AM – 9:00 PM BD   |   ✓ Instant Support",

                background_type: "color",
                background_color: "#d4530c",
                background_image: "",

                title_font_size: 48,
                text_font_size: 18,

                title_color: "#ffffff",
                text_color: "#e5e7eb",
                accent_color: "#f26616",

                text_alignment: "center",

                margin_top: 0,
                margin_bottom: 0,

                padding_top: 80,
                padding_bottom: 80,
                padding_horizontal: 24,

                container_width: "large",

                hide_desktop: false,
                hide_tablet: false,
                hide_mobile: false,

                section_id: "",
                custom_class: "",
            },

            fields: [
                {
                    key: "title",
                    label: "Headline",
                    type: "text",
                    maxLength: 160,
                },

                {
                    key: "description",
                    label: "Description",
                    type: "textarea",
                    rows: 4,
                    maxLength: 300,
                },

                {
                    key: "phone_label",
                    label: "Phone Display",
                    type: "text",
                    maxLength: 40,
                },

                {
                    key: "phone_number",
                    label: "Phone Number",
                    type: "text",
                    placeholder: "+8801531352693",
                },

                {
                    key: "show_note",
                    label: "Show Support Note",
                    type: "checkbox",
                },

                {
                    key: "note",
                    label: "Support Note",
                    type: "textarea",
                    rows: 2,
                    maxLength: 180,

                    visibleWhen: {
                        field: "show_note",
                        equals: true,
                    },
                },

                ...backgroundFields,
                ...typographyFields,
                ...alignmentFields,
                ...advancedFields,
            ],
        },

        {
            id: "cta-02",
            label: "WhatsApp CTA",
            description:
                "Horizontal corporate CTA with badge, description and WhatsApp action.",

            component: CTA02,

            defaultSettings: {
                badge:
                    "Direct Communication",

                title:
                    "Ready to Partner with Arab Group?",

                description:
                    "Have inquiries regarding investment, services, or partnerships? Speak directly with our corporate relations team today.",

                whatsapp_label:
                    "Instant WhatsApp",

                whatsapp_number:
                    "+880 1826-065530",

                whatsapp_url:
                    "https://wa.me/8801826065530",

                background_type: "color",
                background_color: "#064e3b",
                background_image: "",

                title_font_size: 40,
                text_font_size: 16,

                title_color: "#ffffff",
                text_color: "#d1fae5",
                accent_color: "#16a34a",

                text_alignment: "left",

                margin_top: 48,
                margin_bottom: 48,

                padding_top: 64,
                padding_bottom: 64,
                padding_horizontal: 24,

                container_width: "large",

                hide_desktop: false,
                hide_tablet: false,
                hide_mobile: false,

                section_id: "",
                custom_class: "",
            },

            fields: [
                {
                    key: "badge",
                    label: "Badge",
                    type: "text",
                    maxLength: 60,
                },

                {
                    key: "title",
                    label: "Headline",
                    type: "text",
                    maxLength: 160,
                },

                {
                    key: "description",
                    label: "Description",
                    type: "textarea",
                    rows: 4,
                    maxLength: 300,
                },

                {
                    key: "whatsapp_label",
                    label: "WhatsApp Label",
                    type: "text",
                    maxLength: 50,
                },

                {
                    key: "whatsapp_number",
                    label: "WhatsApp Display Number",
                    type: "text",
                },

                {
                    key: "whatsapp_url",
                    label: "WhatsApp URL",
                    type: "url",
                },

                ...backgroundFields,
                ...typographyFields,
                ...alignmentFields,
                ...advancedFields,
            ],
        },

        {
            id: "cta-03",
            label: "Contact Buttons CTA",
            description:
                "Centered card CTA with email and phone action buttons.",

            component: CTA03,

            defaultSettings: {
                title:
                    "Partner with Arab Group",

                description:
                    "Interested in strategic investment opportunities, green partnerships, or learning more about our corporate business units?",

                primary_text:
                    "contact@arabgroupbd.com",

                primary_url:
                    "mailto:contact@arabgroupbd.com",

                secondary_text:
                    "01826065530",

                secondary_url:
                    "tel:01826065530",

                background_type: "color",
                background_color: "#f0fdf4",
                background_image: "",

                title_font_size: 36,
                text_font_size: 16,

                title_color: "#111827",
                text_color: "#4b5563",
                accent_color: "#16a34a",

                text_alignment: "center",

                margin_top: 0,
                margin_bottom: 0,

                padding_top: 80,
                padding_bottom: 80,
                padding_horizontal: 24,

                container_width: "medium",

                hide_desktop: false,
                hide_tablet: false,
                hide_mobile: false,

                section_id: "",
                custom_class: "",
            },

            fields: [
                {
                    key: "title",
                    label: "Headline",
                    type: "text",
                    maxLength: 140,
                },

                {
                    key: "description",
                    label: "Description",
                    type: "textarea",
                    rows: 4,
                    maxLength: 280,
                },

                {
                    key: "primary_text",
                    label: "Primary Button Text",
                    type: "text",
                },

                {
                    key: "primary_url",
                    label: "Primary Button URL",
                    type: "url",
                },

                {
                    key: "secondary_text",
                    label: "Secondary Button Text",
                    type: "text",
                },

                {
                    key: "secondary_url",
                    label: "Secondary Button URL",
                    type: "url",
                },

                ...backgroundFields,
                ...typographyFields,
                ...alignmentFields,
                ...advancedFields,
            ],
        },
    ],
};
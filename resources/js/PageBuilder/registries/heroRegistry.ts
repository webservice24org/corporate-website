import type { SectionGroup } from "../types";

import Hero01 from "../sections/Hero/Hero01";
import Hero02 from "../sections/Hero/Hero02";
import Hero03 from "../sections/Hero/Hero03";

import {
    backgroundFields,
    typographyFields,
    alignmentFields,
    advancedFields,
} from "../fields";

export const heroRegistry: SectionGroup = {
    type: "hero",
    label: "Hero",

    designs: [
        {
            id: "hero-01",
            label: "Typing Hero",
            description:
                "Corporate hero with animated writing effect.",

            component: Hero01,

            defaultSettings: {
                top_title: "Welcome to Our Company",

                title:
                    "We build better digital businesses",

                description:
                    "Modern corporate solutions designed to help organizations grow and succeed.",

                primary_button_text:
                    "Get Started",

                primary_button_url:
                    "/contact",

                secondary_button_text:
                    "Learn More",

                secondary_button_url:
                    "/about",

                typing_speed: 70,

                // Style
                background_type: "color",
                background_color: "#020617",
                background_image: "",

                title_font_size: 64,
                text_font_size: 18,

                title_color: "#ffffff",
                text_color: "#cbd5e1",
                accent_color: "#ffffff",

                text_alignment: "left",

                // Advanced
                margin_top: 0,
                margin_bottom: 0,

                padding_top: 120,
                padding_bottom: 120,
                padding_horizontal: 24,

                container_width: "xl",

                hide_desktop: false,
                hide_tablet: false,
                hide_mobile: false,

                section_id: "",
                custom_class: "",
            },

            fields: [
                {
                    key: "top_title",
                    label: "Top Title",
                    type: "text",
                },

                {
                    key: "title",
                    label: "Main Title",
                    type: "text",
                },

                {
                    key: "description",
                    label: "Description",
                    type: "textarea",
                    rows: 4,
                },

                {
                    key: "primary_button_text",
                    label: "Primary Button Text",
                    type: "text",
                },

                {
                    key: "primary_button_url",
                    label: "Primary Button URL",
                    type: "url",
                },

                {
                    key: "secondary_button_text",
                    label: "Secondary Button Text",
                    type: "text",
                },

                {
                    key: "secondary_button_url",
                    label: "Secondary Button URL",
                    type: "url",
                },

                {
                    key: "typing_speed",
                    label: "Typing Speed",
                    type: "number",
                },

                ...backgroundFields,
                ...typographyFields,
                ...alignmentFields,
                ...advancedFields,
            ],
        },

        {
            id: "hero-02",
            label: "Background Slider",
            description:
                "Three-slide hero with image or color backgrounds.",

            component: Hero02,

            defaultSettings: {
                autoplay: true,
                interval: 5000,

                slides: [
                    {
                        top_title:
                            "Corporate Solutions",

                        title:
                            "Build a stronger business",

                        description:
                            "Solutions designed for modern organizations.",

                        background_type:
                            "color",

                        background_color:
                            "#0f172a",

                        background_image: "",

                        button_text:
                            "Learn More",

                        button_url:
                            "/about",
                    },

                    {
                        top_title:
                            "Business Growth",

                        title:
                            "Turn strategy into results",

                        description:
                            "Build sustainable growth with smarter solutions.",

                        background_type:
                            "color",

                        background_color:
                            "#172554",

                        background_image: "",

                        button_text:
                            "Our Services",

                        button_url:
                            "/services",
                    },

                    {
                        top_title:
                            "Work With Us",

                        title:
                            "Let's build something remarkable",

                        description:
                            "Partner with a team focused on meaningful results.",

                        background_type:
                            "color",

                        background_color:
                            "#1e293b",

                        background_image: "",

                        button_text:
                            "Contact Us",

                        button_url:
                            "/contact",
                    },
                ],

                // Style
                title_font_size: 64,
                text_font_size: 18,

                title_color: "#ffffff",
                text_color: "#e2e8f0",
                accent_color: "#ffffff",

                text_alignment: "left",

                // Advanced
                margin_top: 0,
                margin_bottom: 0,

                padding_top: 0,
                padding_bottom: 0,
                padding_horizontal: 24,

                container_width: "xl",

                hide_desktop: false,
                hide_tablet: false,
                hide_mobile: false,

                section_id: "",
                custom_class: "",

                transition_effect: "fade",
                transition_duration: 700,
                transition_easing: "ease-in-out",
            },

            fields: [
                {
                    key: "autoplay",
                    label: "Autoplay Slider",
                    type: "checkbox",
                },

                {
                    key: "interval",
                    label:
                        "Autoplay Interval (milliseconds)",
                    type: "number",
                },

                {
                    key: "transition_effect",
                    label: "Transition Effect",
                    type: "select",
                    group: "advanced",
                    panel: "layout",

                    options: [
                        {
                            label: "Fade",
                            value: "fade",
                        },
                        {
                            label: "Slide Right to Left",
                            value: "slide-left",
                        },
                        {
                            label: "Slide Left to Right",
                            value: "slide-right",
                        },
                        {
                            label: "None",
                            value: "none",
                        },
                    ],
                },

                {
                    key: "transition_easing",
                    label: "Transition Easing",
                    type: "select",
                    group: "advanced",
                    panel: "layout",

                    options: [
                        {
                            label: "Smooth",
                            value: "ease-in-out",
                        },
                        {
                            label: "Ease In",
                            value: "ease-in",
                        },
                        {
                            label: "Ease Out",
                            value: "ease-out",
                        },
                        {
                            label: "Linear",
                            value: "linear",
                        },
                    ],
                },


                {
                    key: "transition_duration",
                    label: "Transition Duration (ms)",
                    type: "number",
                    group: "advanced",
                    panel: "layout",
                },



                {
                    key: "slides",
                    label: "Slides",
                    type: "repeater",
                    min: 3,
                    max: 3,

                    fields: [
                        {
                            key: "top_title",
                            label: "Top Title",
                            type: "text",
                        },

                        {
                            key: "title",
                            label: "Main Title",
                            type: "text",
                        },

                        {
                            key: "description",
                            label: "Description",
                            type: "textarea",
                            rows: 3,
                        },

                        {
                            key: "background_type",
                            label: "Background Type",
                            type: "select",

                            options: [
                                {
                                    label: "Color",
                                    value: "color",
                                },

                                {
                                    label: "Image",
                                    value: "image",
                                },
                            ],
                        },

                        {
                            key: "background_color",
                            label: "Background Color",
                            type: "color",

                            visibleWhen: {
                                field: "background_type",
                                equals: "color",
                            },
                        },

                        {
                            key: "background_image",
                            label: "Background Image",
                            type: "image",

                            visibleWhen: {
                                field: "background_type",
                                equals: "image",
                            },
                        },

                        {
                            key: "button_text",
                            label: "Button Text",
                            type: "text",
                        },

                        {
                            key: "button_url",
                            label: "Button URL",
                            type: "url",
                        },
                    ],
                },

                ...typographyFields,
                ...alignmentFields,
                ...advancedFields,
            ],
        },

        {
            id: "hero-03",
            label: "Split Slider",
            description:
                "Three-slide split hero with content and image.",

            component: Hero03,

            defaultSettings: {
                autoplay: true,
                interval: 5000,

                slides: [
                    {
                        top_title:
                            "Corporate Strategy",

                        title:
                            "Ideas that move businesses forward",

                        description:
                            "Thoughtful strategy combined with practical execution.",

                        image: "",

                        button_text:
                            "Discover More",

                        button_url:
                            "/about",
                    },

                    {
                        top_title:
                            "Our Services",

                        title:
                            "Solutions built around your goals",

                        description:
                            "Flexible services for ambitious companies.",

                        image: "",

                        button_text:
                            "View Services",

                        button_url:
                            "/services",
                    },

                    {
                        top_title:
                            "Let's Talk",

                        title:
                            "Your next chapter starts here",

                        description:
                            "Start a conversation with our team today.",

                        image: "",

                        button_text:
                            "Contact Us",

                        button_url:
                            "/contact",
                    },
                ],

                // Style
                background_type: "color",
                background_color: "#ffffff",
                background_image: "",

                title_font_size: 64,
                text_font_size: 18,

                title_color: "#0f172a",
                text_color: "#475569",
                accent_color: "#2563eb",

                text_alignment: "left",

                // Advanced
                margin_top: 0,
                margin_bottom: 0,

                padding_top: 0,
                padding_bottom: 0,
                padding_horizontal: 24,

                container_width: "xl",

                hide_desktop: false,
                hide_tablet: false,
                hide_mobile: false,

                section_id: "",
                custom_class: "",

                transition_effect: "slide-left",
                transition_duration: 700,
                transition_easing: "ease-in-out",
            },

            fields: [
                {
                    key: "autoplay",
                    label: "Autoplay Slider",
                    type: "checkbox",
                },
                

                {
                    key: "interval",
                    label:
                        "Autoplay Interval (milliseconds)",
                    type: "number",
                },

                {
                    key: "transition_effect",
                    label: "Transition Effect",
                    type: "select",
                    group: "advanced",
                    panel: "layout",

                    options: [
                        {
                            label: "Fade",
                            value: "fade",
                        },
                        {
                            label: "Slide Right to Left",
                            value: "slide-left",
                        },
                        {
                            label: "Slide Left to Right",
                            value: "slide-right",
                        },
                        {
                            label: "None",
                            value: "none",
                        },
                    ],
                },

                {
                    key: "transition_duration",
                    label: "Transition Duration (ms)",
                    type: "number",
                    group: "advanced",
                    panel: "layout",
                },

                {
                    key: "transition_easing",
                    label: "Transition Easing",
                    type: "select",
                    group: "advanced",
                    panel: "layout",

                    options: [
                        {
                            label: "Smooth",
                            value: "ease-in-out",
                        },
                        {
                            label: "Ease In",
                            value: "ease-in",
                        },
                        {
                            label: "Ease Out",
                            value: "ease-out",
                        },
                        {
                            label: "Linear",
                            value: "linear",
                        },
                    ],
                },
                {
                    key: "slides",
                    label: "Slides",
                    type: "repeater",
                    min: 3,
                    max: 3,

                    fields: [
                        {
                            key: "top_title",
                            label: "Top Title",
                            type: "text",
                        },

                        {
                            key: "title",
                            label: "Main Title",
                            type: "text",
                        },

                        {
                            key: "description",
                            label: "Description",
                            type: "textarea",
                            rows: 3,
                        },

                        {
                            key: "image",
                            label: "Slide Image",
                            type: "image",
                        },

                        {
                            key: "button_text",
                            label: "Button Text",
                            type: "text",
                        },

                        {
                            key: "button_url",
                            label: "Button URL",
                            type: "url",
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
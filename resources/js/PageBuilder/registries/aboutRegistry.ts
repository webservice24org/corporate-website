import type { SectionGroup } from "../types";

import About01 from "../sections/About/About01";
import About02 from "../sections/About/About02";
import About03 from "../sections/About/About03";
import About04 from "../sections/About/About04";

/**
 * Shared style controls used by all About presets.
 *
 * Each design spreads this array into its own fields list.
 */
import {
    backgroundFields,
    typographyFields,
    alignmentFields,
    advancedFields,
} from "../fields";

export const aboutRegistry: SectionGroup = {
    type: "about",
    label: "About",

    designs: [
        /**
         * ---------------------------------------------------------
         * ABOUT 01
         * Image + Experience Badge + Story + Pillars + Counters
         * ---------------------------------------------------------
         */
        {
            id: "about-01",
            label: "Image Story & Counters",
            description:
                "About section with image showcase, experience badge, story content, key pillars and statistics.",

            component: About01,

            defaultSettings: {
                background_type: "color",
                background_color: "#ffffff",
                background_image: "",

                image: "",

                experience_value: "12+",
                experience_label:
                    "Years of\nIndustry\nLeadership",

                top_title:
                    "About Sigma Plus Private LTD",

                title:
                    "Pioneering Sustainable Construction Chemical Technology",

                description_one:
                    "Established with a passion for quality and technical innovation, Sigma Plus PVT LTD delivers state-of-the-art concrete admixtures, anti-salinity compounds, waterproofing solutions, structural cement, steel, and high-durability coatings.",

                description_two:
                    "Our team of certified chemical engineers, structural consultants, and field technicians partner with developers, contractors, and infrastructure agencies to create solutions that maximize structural lifespan while reducing long-term maintenance costs.",

                pillars: [
                    {
                        title:
                            "ISO Standard Certified",
                        description:
                            "Rigorous multi-stage laboratory quality assurance.",
                    },
                    {
                        title:
                            "Eco-Conscious Formulas",
                        description:
                            "Sustainable, non-toxic, and energy-efficient materials.",
                    },
                ],

                counters: [
                    {
                        value: "500+",
                        label:
                            "Completed Projects",
                    },
                    {
                        value: "150+",
                        label:
                            "Corporate Partners",
                    },
                    {
                        value: "45+",
                        label:
                            "Specialized Products",
                    },
                    {
                        value: "99%",
                        label:
                            "Client Satisfaction",
                    },
                ],

                title_font_size: 40,
                text_font_size: 16,

                title_color: "#111827",
                text_color: "#4b5563",
                accent_color: "#2563eb",

                text_alignment: "left",

                // Advanced
                padding_top: 80,
                padding_bottom: 80,
                padding_horizontal: 24,
                container_width: "xl",

                hide_desktop: false,
                hide_tablet: false,
                hide_mobile: false,

                margin_top: 0,
                margin_bottom: 0,
                section_id: "",
                custom_class: "",
            },

            fields: [
                {
                    key: "image",
                    label: "About Image",
                    type: "image",
                },

                {
                    key: "experience_value",
                    label: "Experience Value",
                    type: "text",
                    placeholder: "12+",
                },

                {
                    key: "experience_label",
                    label: "Experience Label",
                    type: "textarea",
                    rows: 3,
                    placeholder:
                        "Years of Industry Leadership",
                },

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
                    key: "description_one",
                    label: "Description One",
                    type: "textarea",
                    rows: 5,
                },

                {
                    key: "description_two",
                    label: "Description Two",
                    type: "textarea",
                    rows: 5,
                },

                {
                    key: "pillars",
                    label: "Key Pillars",
                    type: "repeater",
                    min: 2,
                    max: 4,

                    fields: [
                        {
                            key: "title",
                            label: "Pillar Title",
                            type: "text",
                        },
                        {
                            key: "description",
                            label:
                                "Pillar Description",
                            type: "textarea",
                            rows: 3,
                        },
                    ],
                },

                {
                    key: "counters",
                    label: "Counters",
                    type: "repeater",
                    min: 2,
                    max: 4,

                    fields: [
                        {
                            key: "value",
                            label: "Value",
                            type: "text",
                            placeholder: "500+",
                        },
                        {
                            key: "label",
                            label: "Label",
                            type: "text",
                        },
                    ],
                },

                ...backgroundFields,
                ...typographyFields,
                ...alignmentFields,
                ...advancedFields,
            ],
        },

        /**
         * ---------------------------------------------------------
         * ABOUT 02
         * Narrative + 2x2 Statistics Grid
         * ---------------------------------------------------------
         */
        {
            id: "about-02",
            label: "Narrative & Stats",
            description:
                "Two-column About section with company story on the left and a four-card stats grid on the right.",

            component: About02,

            defaultSettings: {
                background_type: "color",
                background_color: "#f8fafc",
                background_image: "",

                top_title: "Who We Are",

                title:
                    "We’re building the infrastructure for the next generation of creators.",

                description_one:
                    "Founded with a clear mission: remove technical hurdles so teams can focus entirely on craft. We build tools that scale quietly in the background, giving teams more control over their work.",

                description_two:
                    "From early-stage startups to global enterprises, organizations rely on our ecosystem to power critical products and services.",

                button_text:
                    "Read Our Full Story",

                button_url: "/about",

                stats: [
                    {
                        value: "99.99%",
                        title: "Platform Uptime",
                        description:
                            "Engineered for high-availability production workloads.",
                    },
                    {
                        value: "10M+",
                        title: "Active Users",
                        description:
                            "Trusted across more than 120 countries worldwide.",
                    },
                    {
                        value: "24/7",
                        title: "Expert Support",
                        description:
                            "Dedicated experts available around the clock.",
                    },
                    {
                        value: "4.9★",
                        title:
                            "Customer Rating",
                        description:
                            "Based on thousands of independent product reviews.",
                    },
                ],

                title_font_size: 40,
                text_font_size: 16,

                title_color: "#0f172a",
                text_color: "#475569",
                accent_color: "#4f46e5",

                text_alignment: "left",

                 // Advanced
                padding_top: 80,
                padding_bottom: 80,
                padding_horizontal: 24,
                container_width: "xl",

                hide_desktop: false,
                hide_tablet: false,
                hide_mobile: false,

                margin_top: 0,
                margin_bottom: 0,
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
                    key: "description_one",
                    label: "Description One",
                    type: "textarea",
                    rows: 5,
                },

                {
                    key: "description_two",
                    label: "Description Two",
                    type: "textarea",
                    rows: 5,
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

                {
                    key: "stats",
                    label: "Statistics",
                    type: "repeater",
                    min: 4,
                    max: 4,

                    fields: [
                        {
                            key: "value",
                            label: "Value",
                            type: "text",
                        },
                        {
                            key: "title",
                            label: "Title",
                            type: "text",
                        },
                        {
                            key: "description",
                            label:
                                "Description",
                            type: "textarea",
                            rows: 3,
                        },
                    ],
                },

                ...backgroundFields,
                ...typographyFields,
                ...alignmentFields,
                ...advancedFields,
            ],
        },

        /**
         * ---------------------------------------------------------
         * ABOUT 03
         * Culture + Values Cards
         * ---------------------------------------------------------
         */
        {
            id: "about-03",
            label: "Culture & Values",
            description:
                "Centered About header with editable culture badge and three value cards.",

            component: About03,

            defaultSettings: {
                background_type: "color",
                background_color: "#ffffff",
                background_image: "",

                badge: "Our Culture",

                title:
                    "Driven by clarity, speed, and continuous learning.",

                description:
                    "We believe great work comes from small, highly autonomous teams working with transparency, responsibility, and a willingness to learn.",

                values: [
                    {
                        number: "01",
                        title:
                            "Radical Simplicity",
                        description:
                            "We default to the simplest solution. Complexity is easy; clarity requires deliberate thought and prioritization.",
                    },
                    {
                        number: "02",
                        title:
                            "Customer Obsession",
                        description:
                            "We build things that solve real problems. Listening to users guides how we design and improve.",
                    },
                    {
                        number: "03",
                        title:
                            "Continuous Growth",
                        description:
                            "We encourage experimentation, learning, and thoughtful iteration as part of everyday work.",
                    },
                ],

                title_font_size: 40,
                text_font_size: 16,

                title_color: "#0f172a",
                text_color: "#475569",
                accent_color: "#059669",

                text_alignment: "center",

                 // Advanced
                // Advanced
                padding_top: 80,
                padding_bottom: 80,
                padding_horizontal: 24,
                container_width: "xl",

                hide_desktop: false,
                hide_tablet: false,
                hide_mobile: false,

                margin_top: 0,
                margin_bottom: 0,
                section_id: "",
                custom_class: "",
            },

            fields: [
                {
                    key: "badge",
                    label: "Badge",
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
                    key: "values",
                    label: "Values",
                    type: "repeater",
                    min: 3,
                    max: 6,

                    fields: [
                        {
                            key: "number",
                            label: "Number",
                            type: "text",
                            placeholder: "01",
                        },
                        {
                            key: "title",
                            label:
                                "Value Title",
                            type: "text",
                        },
                        {
                            key: "description",
                            label:
                                "Value Description",
                            type: "textarea",
                            rows: 4,
                        },
                    ],
                },

                ...backgroundFields,
                ...typographyFields,
                ...alignmentFields,
                ...advancedFields,
            ],
        },

        /**
         * ---------------------------------------------------------
         * ABOUT 04
         * Dark Team / Company People
         * ---------------------------------------------------------
         */
        {
            id: "about-04",
            label: "Team Story",
            description:
                "Dark About section with company introduction and editable team member cards.",

            component: About04,

            defaultSettings: {
                background_type: "color",
                background_color: "#020617",
                background_image: "",

                top_title:
                    "About the Team",

                title:
                    "Built by engineers, designed for creators.",

                description:
                    "We are a distributed group of developers, designers, and strategists working across multiple time zones. Our mission is to make high-performance digital experiences easier to build and maintain.",

                members: [
                    {
                        name:
                            "Alex Sterling",
                        role:
                            "Co-Founder & CEO",
                        description:
                            "Experienced technology leader focused on scalable infrastructure and long-term product strategy.",
                        image: "",
                        initials: "AS",
                    },
                    {
                        name:
                            "Maya Rivera",
                        role:
                            "Head of Product Design",
                        description:
                            "Focused on accessible interfaces, thoughtful interaction design, and scalable design systems.",
                        image: "",
                        initials: "MR",
                    },
                    {
                        name:
                            "David Chen",
                        role:
                            "VP of Engineering",
                        description:
                            "Engineering leader focused on platform performance, reliability, and scalable architecture.",
                        image: "",
                        initials: "DC",
                    },
                ],

                title_font_size: 48,
                text_font_size: 18,

                title_color: "#ffffff",
                text_color: "#94a3b8",
                accent_color: "#22d3ee",

                text_alignment: "left",

                 // Advanced
                padding_top: 80,
                padding_bottom: 80,
                padding_horizontal: 24,
                container_width: "xl",

                hide_desktop: false,
                hide_tablet: false,
                hide_mobile: false,

                margin_top: 0,
                margin_bottom: 0,
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
                    rows: 5,
                },

                {
                    key: "members",
                    label: "Team Members",
                    type: "repeater",
                    min: 3,
                    max: 6,

                    fields: [
                        {
                            key: "name",
                            label: "Name",
                            type: "text",
                        },

                        {
                            key: "role",
                            label: "Role",
                            type: "text",
                        },

                        {
                            key: "description",
                            label:
                                "Description",
                            type: "textarea",
                            rows: 4,
                        },

                        {
                            key: "image",
                            label:
                                "Member Image",
                            type: "image",
                        },

                        {
                            key: "initials",
                            label:
                                "Fallback Initials",
                            type: "text",
                            placeholder: "AS",
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
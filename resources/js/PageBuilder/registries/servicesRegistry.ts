import type {
    SectionGroup,
} from "../types";

import Service01 from "../sections/Services/Service01";
import Service02 from "../sections/Services/Service02";
import Service03 from "../sections/Services/Service03";

import {
    backgroundFields,
    typographyFields,
    alignmentFields,
    advancedFields,
} from "../fields";

export const servicesRegistry: SectionGroup = {
    type: "services",
    label: "Services",

    designs: [
        {
            id: "services-01",

            label:
                "Service Cards Grid",

            description:
                "Dynamic services grid with icon, title, description and action button.",

            component:
                Service01,

            defaultSettings: {
                top_title: "",

                title:
                    "Our Premium Services",

                description:
                    "Discover our comprehensive range of services designed for durability and excellence.",

                items: [
                    {
                        icon:
                            "droplets",

                        title:
                            "Waterproofing Solutions",

                        description:
                            "Advanced solutions designed for durability and long-term structural protection.",

                        button_text:
                            "Explore Service",

                        button_url:
                            "#",
                    },

                    {
                        icon:
                            "zap",

                        title:
                            "Concrete Solutions",

                        description:
                            "High-performance solutions for modern construction requirements.",

                        button_text:
                            "Explore Service",

                        button_url:
                            "#",
                    },

                    {
                        icon:
                            "shield",

                        title:
                            "Protection Systems",

                        description:
                            "Reliable systems designed to protect structures and improve service life.",

                        button_text:
                            "Explore Service",

                        button_url:
                            "#",
                    },
                ],

                columns: "3",

                // Style
                background_type:
                    "color",

                background_color:
                    "#ffffff",

                background_image:
                    "",

                title_font_size: 40,

                text_font_size: 16,

                title_color:
                    "#111827",

                text_color:
                    "#4b5563",

                accent_color:
                    "#2563eb",

                text_alignment:
                    "center",

                // Advanced
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
                    "",

                custom_class:
                    "",
            },

            fields: [
                {
                    key:
                        "top_title",

                    label:
                        "Top Title",

                    type:
                        "text",
                },

                {
                    key:
                        "title",

                    label:
                        "Section Title",

                    type:
                        "text",
                },

                {
                    key:
                        "description",

                    label:
                        "Section Description",

                    type:
                        "textarea",

                    rows: 4,
                },

                {
                    key:
                        "items",

                    label:
                        "Services",

                    type:
                        "repeater",

                    itemLabel:
                        "Service",

                    min: 1,

                    max: 12,

                    defaultItem: {
                        icon:
                            "briefcase",

                        title:
                            "New Service",

                        description:
                            "Add your service description here.",

                        button_text:
                            "Learn More",

                        button_url:
                            "#",
                    },

                    fields: [
                        {
                            key: "icon",
                            label: "Icon",
                            type: "icon",
                        },

                        {
                            key:
                                "title",

                            label:
                                "Service Title",

                            type:
                                "text",
                        },

                        {
                            key:
                                "description",

                            label:
                                "Description",

                            type:
                                "textarea",

                            rows: 4,
                        },

                        {
                            key:
                                "button_text",

                            label:
                                "Button Text",

                            type:
                                "text",
                        },

                        {
                            key:
                                "button_url",

                            label:
                                "Button URL",

                            type:
                                "url",
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

                ...backgroundFields,
                ...typographyFields,
                ...alignmentFields,
                ...advancedFields,
            ],
        },

        {
            id: "services-02",

            label:
                "Image Service Cards",

            description:
                "Dynamic service cards with image, title, optional short description and action button.",

            component:
                Service02,

            defaultSettings: {
                top_title:
                    "What We Do",

                title:
                    "Services Built Around Your Goals",

                description:
                    "Explore our professional services and solutions.",

                items: [
                    {
                        image: "",

                        title:
                            "Business Consulting",

                        show_description:
                            true,

                        description:
                            "Practical strategic guidance designed to help organizations improve performance and achieve sustainable growth.",

                        button_text:
                            "Learn More",

                        button_url:
                            "#",
                    },

                    {
                        image: "",

                        title:
                            "Project Solutions",

                        show_description:
                            true,

                        description:
                            "Flexible project solutions tailored to your operational, technical, and commercial requirements.",

                        button_text:
                            "Learn More",

                        button_url:
                            "#",
                    },

                    {
                        image: "",

                        title:
                            "Technical Support",

                        show_description:
                            true,

                        description:
                            "Reliable technical assistance from experienced professionals focused on long-term results.",

                        button_text:
                            "Learn More",

                        button_url:
                            "#",
                    },
                ],

                columns: "3",

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
                    "#0f172a",

                text_color:
                    "#64748b",

                accent_color:
                    "#2563eb",

                text_alignment:
                    "center",

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
                    "",

                custom_class:
                    "",
            },

            fields: [
                {
                    key:
                        "top_title",

                    label:
                        "Top Title",

                    type:
                        "text",
                },

                {
                    key:
                        "title",

                    label:
                        "Section Title",

                    type:
                        "text",
                },

                {
                    key:
                        "description",

                    label:
                        "Section Description",

                    type:
                        "textarea",

                    rows: 4,
                },

                {
                    key:
                        "items",

                    label:
                        "Services",

                    type:
                        "repeater",

                    itemLabel:
                        "Service",

                    min: 1,
                    max: 12,

                    defaultItem: {
                        image: "",

                        title:
                            "New Service",

                        show_description:
                            true,

                        description:
                            "Write a short description for this service.",

                        button_text:
                            "Learn More",

                        button_url:
                            "#",
                    },

                    fields: [
                        {
                            key:
                                "image",

                            label:
                                "Service Image",

                            type:
                                "image",
                        },

                        {
                            key:
                                "title",

                            label:
                                "Service Title",

                            type:
                                "text",

                            maxLength:
                                80,
                        },

                        {
                            key:
                                "show_description",

                            label:
                                "Show Description",

                            type:
                                "checkbox",
                        },

                        {
                            key:
                                "description",

                            label:
                                "Short Description",

                            type:
                                "textarea",

                            rows: 4,

                            maxLength:
                                180,

                            visibleWhen: {
                                field:
                                    "show_description",

                                equals:
                                    true,
                            },
                        },

                        {
                            key:
                                "button_text",

                            label:
                                "Button Text",

                            type:
                                "text",

                            maxLength:
                                40,
                        },

                        {
                            key:
                                "button_url",

                            label:
                                "Button URL",

                            type:
                                "url",
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

                ...backgroundFields,
                ...typographyFields,
                ...alignmentFields,
                ...advancedFields,
            ],
        },

        {
            id: "services-03",

            label:
                "Category Service Grid",

            description:
                "Dynamic category navigation with image-based service cards.",

            component:
                Service03,

            defaultSettings: {
                categories: [
                    {
                        title:
                            "AC Repair Services",

                        services: [
                            {
                                image: "",
                                icon:
                                    "settings",

                                title:
                                    "AC Installation",

                                count_text:
                                    "15 Services",

                                button_url:
                                    "#",
                            },

                            {
                                image: "",
                                icon:
                                    "settings",

                                title:
                                    "AC Maintenance",

                                count_text:
                                    "8 Services",

                                button_url:
                                    "#",
                            },

                            {
                                image: "",
                                icon:
                                    "wrench",

                                title:
                                    "AC Repair",

                                count_text:
                                    "12 Services",

                                button_url:
                                    "#",
                            },
                        ],
                    },

                    {
                        title:
                            "Cleaning Solution",

                        services: [
                            {
                                image: "",
                                icon:
                                    "sparkles",

                                title:
                                    "Home Cleaning",

                                count_text:
                                    "10 Services",

                                button_url:
                                    "#",
                            },

                            {
                                image: "",
                                icon:
                                    "droplets",

                                title:
                                    "Deep Cleaning",

                                count_text:
                                    "6 Services",

                                button_url:
                                    "#",
                            },
                        ],
                    },

                    {
                        title:
                            "Beauty & Wellness",

                        services: [
                            {
                                image: "",
                                icon:
                                    "heart",

                                title:
                                    "Beauty Services",

                                count_text:
                                    "15 Services",

                                button_url:
                                    "#",
                            },
                        ],
                    },
                ],

                columns: "3",

                background_type:
                    "color",

                background_color:
                    "#ffffff",

                background_image:
                    "",

                title_font_size:
                    32,

                text_font_size:
                    16,

                title_color:
                    "#111827",

                text_color:
                    "#6b7280",

                accent_color:
                    "#059669",

                text_alignment:
                    "left",

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
                    "",

                custom_class:
                    "",
            },

            fields: [
                {
                    key:
                        "categories",

                    label:
                        "Service Categories",

                    type:
                        "repeater",

                    itemLabel:
                        "Category",

                    min: 1,
                    max: 12,

                    defaultItem: {
                        title:
                            "New Category",

                        services: [
                            {
                                image: "",

                                icon:
                                    "briefcase",

                                title:
                                    "New Service",

                                count_text:
                                    "1 Service",

                                button_url:
                                    "#",
                            },
                        ],
                    },

                    fields: [
                        {
                            key:
                                "title",

                            label:
                                "Category Title",

                            type:
                                "text",

                            maxLength:
                                80,
                        },

                        {
                            key:
                                "services",

                            label:
                                "Category Services",

                            type:
                                "repeater",

                            itemLabel:
                                "Service",

                            min: 1,
                            max: 20,

                            defaultItem: {
                                image: "",

                                icon:
                                    "briefcase",

                                title:
                                    "New Service",

                                count_text:
                                    "1 Service",

                                button_url:
                                    "#",
                            },

                            fields: [
                                {
                                    key:
                                        "image",

                                    label:
                                        "Service Image",

                                    type:
                                        "image",
                                },

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
                                        "Service Title",

                                    type:
                                        "text",

                                    maxLength:
                                        80,
                                },

                                {
                                    key:
                                        "count_text",

                                    label:
                                        "Count / Short Text",

                                    type:
                                        "text",

                                    maxLength:
                                        40,

                                    placeholder:
                                        "15 Services",
                                },

                                {
                                    key:
                                        "button_url",

                                    label:
                                        "Service URL",

                                    type:
                                        "url",
                                },
                            ],
                        },
                    ],
                },

                {
                    key:
                        "columns",

                    label:
                        "Service Columns",

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

                ...backgroundFields,
                ...typographyFields,
                ...alignmentFields,
                ...advancedFields,
            ],
        },


    ],
};
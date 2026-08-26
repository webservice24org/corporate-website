import type {
    SectionGroup,
} from "../types";

import Team01 from "../sections/Team/Team01";
import Team02 from "../sections/Team/Team02";
import Team03 from "../sections/Team/Team03";
import Team04 from "../sections/Team/Team04";

import {
    backgroundFields,
    typographyFields,
    alignmentFields,
    advancedFields,
} from "../fields";

const teamMemberFields: any[] = [
    {
        key: "image",
        label: "Photo",
        type: "image",
    },

    {
        key: "name",
        label: "Name",
        type: "text",
        maxLength: 100,
    },

    {
        key: "designation",
        label: "Designation",
        type: "text",
        maxLength: 120,
    },

    {
        key: "show_bio",
        label: "Show Short Bio",
        type: "checkbox",
    },

    {
        key: "bio",
        label: "Short Bio",
        type: "textarea",
        rows: 3,
        maxLength: 300,

        visibleWhen: {
            field: "show_bio",
            equals: true,
        },
    },

    {
        key: "show_contact",
        label: "Show Contact Info",
        type: "checkbox",
    },

    {
        key: "email",
        label: "Email",
        type: "text",

        visibleWhen: {
            field: "show_contact",
            equals: true,
        },
    },

    {
        key: "phone",
        label: "Phone",
        type: "text",

        visibleWhen: {
            field: "show_contact",
            equals: true,
        },
    },

    {
        key: "show_social",
        label: "Show Social Links",
        type: "checkbox",
    },

    {
        key: "social_links",
        label: "Social Links",
        type: "repeater",

        itemLabel: "Social Link",

        min: 0,
        max: 10,

        visibleWhen: {
            field: "show_social",
            equals: true,
        },

        defaultItem: {
            icon: "linkedin",
            url: "#",
        },

        fields: [
            {
                key: "icon",
                label: "Icon",
                type: "icon",
            },

            {
                key: "url",
                label: "URL",
                type: "url",
            },
        ],
    },
];

const defaultMembers = [
    {
        image: "",
        name: "Sarah Jenkins",
        designation: "Founder & CEO",

        show_bio: true,
        bio:
            "Experienced business leader focused on sustainable growth and innovation.",

        show_contact: false,
        email: "",
        phone: "",

        show_social: true,

        social_links: [
            {
                icon: "linkedin",
                url: "#",
            },

            {
                icon: "mail",
                url: "mailto:info@example.com",
            },
        ],
    },

    {
        image: "",
        name: "David Chen",
        designation:
            "Chief Technology Officer",

        show_bio: true,
        bio:
            "Technology leader specializing in enterprise systems and digital transformation.",

        show_contact: false,
        email: "",
        phone: "",

        show_social: true,

        social_links: [
            {
                icon: "linkedin",
                url: "#",
            },
        ],
    },

    {
        image: "",
        name: "Elena Rostova",
        designation:
            "Head of Product Design",

        show_bio: true,
        bio:
            "Focused on building intuitive experiences and scalable design systems.",

        show_contact: false,
        email: "",
        phone: "",

        show_social: true,

        social_links: [
            {
                icon: "linkedin",
                url: "#",
            },
        ],
    },
];

const sharedDefaults = {
    top_title:
        "Our Team",

    title:
        "Meet Our Leadership",

    description:
        "Meet the experienced professionals driving our organization forward.",

    members:
        defaultMembers,

    columns:
        3,

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
        "#2563eb",

    text_alignment:
        "center",

    margin_top:
        0,

    margin_bottom:
        0,

    padding_top:
        80,

    padding_bottom:
        80,

    padding_horizontal:
        24,

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
};

const sharedFields: any[] = [
    {
        key: "top_title",
        label: "Top Title",
        type: "text",
    },

    {
        key: "title",
        label: "Section Title",
        type: "text",
    },

    {
        key: "description",
        label: "Description",
        type: "textarea",
        rows: 3,
    },

    {
        key: "members",
        label: "Team Members",
        type: "repeater",

        itemLabel: "Team Member",

        min: 1,
        max: 30,

        defaultItem: {
            image: "",
            name: "New Member",
            designation: "Designation",

            show_bio: true,
            bio: "",

            show_contact: false,
            email: "",
            phone: "",

            show_social: false,
            social_links: [],
        },

        fields:
            teamMemberFields,
    },

    {
        key: "columns",
        label: "Columns",

        type: "select",

        options: [
            {
                label: "2 Columns",
                value: "2",
            },

            {
                label: "3 Columns",
                value: "3",
            },

            {
                label: "4 Columns",
                value: "4",
            },
        ],

        group: "style",
        panel: "layout",
    },

    ...backgroundFields,
    ...typographyFields,
    ...alignmentFields,
    ...advancedFields,
];

export const teamRegistry: SectionGroup = {
    type: "team",

    label: "Team",

    designs: [
        {
            id: "team-01",

            label:
                "Clean Team Cards",

            description:
                "Clean team cards with member photo, biography and social links.",

            component:
                Team01,

            defaultSettings: {
                ...sharedDefaults,

                background_color:
                    "#f9fafb",

                columns: 4,

                show_hiring_card:
                    true,

                hiring_title:
                    "We're Hiring!",

                hiring_description:
                    "Want to build the future with us? Explore our current opportunities.",

                hiring_button_text:
                    "View Open Roles",

                hiring_button_url:
                    "/careers",
            },

            fields: [
                ...sharedFields,

                {
                    key:
                        "show_hiring_card",

                    label:
                        "Show Hiring Card",

                    type:
                        "checkbox",
                },

                {
                    key:
                        "hiring_title",

                    label:
                        "Hiring Card Title",

                    type:
                        "text",

                    visibleWhen: {
                        field:
                            "show_hiring_card",

                        equals:
                            true,
                    },
                },

                {
                    key:
                        "hiring_description",

                    label:
                        "Hiring Description",

                    type:
                        "textarea",

                    rows: 3,

                    visibleWhen: {
                        field:
                            "show_hiring_card",

                        equals:
                            true,
                    },
                },

                {
                    key:
                        "hiring_button_text",

                    label:
                        "Hiring Button",

                    type:
                        "text",

                    visibleWhen: {
                        field:
                            "show_hiring_card",

                        equals:
                            true,
                    },
                },

                {
                    key:
                        "hiring_button_url",

                    label:
                        "Hiring Button URL",

                    type:
                        "url",

                    visibleWhen: {
                        field:
                            "show_hiring_card",

                        equals:
                            true,
                    },
                },
            ],
        },

        {
            id: "team-02",

            label:
                "Dark Overlay Team",

            description:
                "Dark glassmorphism team cards with image overlay.",

            component:
                Team02,

            defaultSettings: {
                ...sharedDefaults,

                top_title:
                    "The Minds Behind Our Success",

                title:
                    "Engineers & Visionaries",

                background_color:
                    "#020617",

                title_color:
                    "#ffffff",

                text_color:
                    "#94a3b8",

                accent_color:
                    "#34d399",

                columns:
                    3,
            },

            fields:
                sharedFields,
        },

        {
            id: "team-03",

            label:
                "Compact Team Rows",

            description:
                "Compact horizontal avatar cards for larger teams and contributors.",

            component:
                Team03,

            defaultSettings: {
                ...sharedDefaults,

                title:
                    "Key Contributors",

                description:
                    "The talented individuals behind our products and services.",

                columns:
                    2,
            },

            fields:
                sharedFields,
        },

        {
            id: "team-04",

            label:
                "Executive Profiles",

            description:
                "Premium executive cards for directors, chairmen and senior management.",

            component:
                Team04,

            defaultSettings: {
                ...sharedDefaults,

                top_title:
                    "Leadership",

                title:
                    "Executive Management",

                description:
                    "Meet the leaders responsible for shaping our long-term vision and strategic direction.",

                columns:
                    3,

                accent_color:
                    "#0f766e",
            },

            fields:
                sharedFields,
        },
    ],
};
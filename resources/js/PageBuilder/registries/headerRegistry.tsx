import HeaderRenderer from "../sections/Global/Header/HeaderRenderer";

const Header01 = (props: any) => (
    <HeaderRenderer
        {...props}
        variant="header-01"
    />
);

const Header02 = (props: any) => (
    <HeaderRenderer
        {...props}
        variant="header-02"
    />
);

const Header03 = (props: any) => (
    <HeaderRenderer
        {...props}
        variant="header-03"
    />
);

const Header04 = (props: any) => (
    <HeaderRenderer
        {...props}
        variant="header-04"
    />
);

const Header05 = (props: any) => (
    <HeaderRenderer
        {...props}
        variant="header-05"
    />
);

const sharedDefaults = {
    logo_type: "text",

    logo_image: "",

    brand_short: "AG",

    brand_name: "Arab Group",

    brand_subtitle: "",

    brand_url: "/",

    menu_items: [
        {
            label: "Home",
            url: "/",

            show_icon: false,
            icon: "house",

            children: [],
        },

        {
            label: "About Us",
            url: "/about",

            show_icon: false,
            icon: "building",

            children: [
                {
                    label:
                        "Company Profile",

                    url:
                        "/about",

                    show_icon:
                        true,

                    icon:
                        "building",

                    description:
                        "Learn more about our organization.",
                },

                {
                    label:
                        "Chairman's Message",

                    url:
                        "/chairman-message",

                    show_icon:
                        true,

                    icon:
                        "message-circle",

                    description:
                        "Message from our leadership.",
                },
            ],
        },

        {
            label: "Services",
            url: "/services",

            show_icon: false,
            icon: "briefcase",

            children: [],
        },

        {
            label: "Contact",
            url: "/contact",

            show_icon: false,
            icon: "mail",

            children: [],
        },
    ],

    show_cta: true,

    cta_text:
        "Get Started",

    cta_url:
        "/contact",

    show_phone: false,

    phone_label:
        "+880 1826-065530",

    phone_number:
        "+8801826065530",

    sticky: true,

    background_color:
        "#ffffff",

    text_color:
        "#334155",

    accent_color:
        "#2563eb",

    submenu_background:
        "#ffffff",

    submenu_text_color:
        "#334155",
};

const sharedFields: any[] = [
    {
        key: "logo_type",
        label: "Logo Type",
        type: "select",

        options: [
            {
                label: "Text Logo",
                value: "text",
            },
            {
                label: "Image Logo",
                value: "image",
            },
        ],
    },

    {
        key: "logo_image",
        label: "Logo Image",
        type: "image",

        visibleWhen: {
            field: "logo_type",
            equals: "image",
        },
    },

    {
        key: "brand_short",
        label: "Logo Initials",
        type: "text",

        visibleWhen: {
            field: "logo_type",
            equals: "text",
        },
    },

    {
        key: "brand_name",
        label: "Brand Name",
        type: "text",
    },

    {
        key: "brand_subtitle",
        label: "Brand Subtitle",
        type: "text",
    },

    {
        key: "brand_url",
        label: "Brand URL",
        type: "url",
    },

    {
        key: "menu_items",
        label: "Navigation Menu",
        type: "repeater",

        itemLabel: "Menu",

        min: 1,
        max: 15,

        defaultItem: {
            label: "New Menu",
            url: "#",

            show_icon: false,
            icon: "circle",

            children: [],
        },

        fields: [
            {
                key: "label",
                label: "Menu Label",
                type: "text",
            },

            {
                key: "url",
                label: "Menu URL",
                type: "url",
            },

            {
                key: "show_icon",
                label: "Show Menu Icon",
                type: "checkbox",
            },

            {
                key: "icon",
                label: "Menu Icon",
                type: "icon",

                visibleWhen: {
                    field: "show_icon",
                    equals: true,
                },
            },

            {
                key: "children",
                label: "Submenu Items",
                type: "repeater",

                itemLabel: "Submenu",

                min: 0,
                max: 20,

                defaultItem: {
                    label:
                        "New Submenu",

                    url: "#",

                    show_icon:
                        false,

                    icon:
                        "circle",

                    description:
                        "",
                },

                fields: [
                    {
                        key: "label",
                        label: "Label",
                        type: "text",
                    },

                    {
                        key: "url",
                        label: "URL",
                        type: "url",
                    },

                    {
                        key:
                            "show_icon",

                        label:
                            "Show Submenu Icon",

                        type:
                            "checkbox",
                    },

                    {
                        key: "icon",
                        label: "Submenu Icon",
                        type: "icon",

                        visibleWhen: {
                            field:
                                "show_icon",

                            equals:
                                true,
                        },
                    },

                    {
                        key:
                            "description",

                        label:
                            "Description",

                        type:
                            "textarea",

                        rows: 2,
                    },
                ],
            },
        ],
    },

    {
        key: "show_cta",
        label: "Show CTA Button",
        type: "checkbox",
    },

    {
        key: "cta_text",
        label: "CTA Text",
        type: "text",

        visibleWhen: {
            field: "show_cta",
            equals: true,
        },
    },

    {
        key: "cta_url",
        label: "CTA URL",
        type: "url",

        visibleWhen: {
            field: "show_cta",
            equals: true,
        },
    },

    {
        key: "show_phone",
        label: "Show Phone Button",
        type: "checkbox",
    },

    {
        key: "phone_label",
        label: "Phone Display",
        type: "text",

        visibleWhen: {
            field: "show_phone",
            equals: true,
        },
    },

    {
        key: "phone_number",
        label: "Phone Number",
        type: "text",

        visibleWhen: {
            field: "show_phone",
            equals: true,
        },
    },

    {
        key: "sticky",
        label: "Sticky Header",
        type: "checkbox",

        group: "advanced",
        panel: "layout",
    },

    {
        key: "background_color",
        label: "Background Color",
        type: "color",

        group: "style",
        panel: "background",
    },

    {
        key: "text_color",
        label: "Menu Text Color",
        type: "color",

        group: "style",
        panel: "typography",
    },

    {
        key: "accent_color",
        label: "Accent Color",
        type: "color",

        group: "style",
        panel: "typography",
    },

    {
        key:
            "submenu_background",

        label:
            "Submenu Background",

        type: "color",

        group: "style",
        panel: "background",
    },

    {
        key:
            "submenu_text_color",

        label:
            "Submenu Text Color",

        type: "color",

        group: "style",
        panel: "typography",
    },
];

export const headerRegistry = {
    type: "header",

    label: "Global Header",

    designs: [
        {
            id: "header-01",

            label:
                "Corporate Top Bar",

            description:
                "Clean corporate header with contact top bar, navigation and CTA.",

            component: Header01,

            defaultSettings: {
                ...sharedDefaults,

                show_top_bar: true,

                top_phone:
                    "+1 (800) 555-0199",

                top_email:
                    "contact@corporate.com",

                top_links: [
                    {
                        label: "Careers",
                        url: "#",
                    },

                    {
                        label:
                            "Investor Relations",
                        url: "#",
                    },

                    {
                        label: "Support",
                        url: "#",
                    },
                ],
            },

            fields: [
                ...sharedFields,

                {
                    key:
                        "show_top_bar",

                    label:
                        "Show Top Bar",

                    type:
                        "checkbox",
                },

                {
                    key:
                        "top_phone",

                    label:
                        "Top Phone",

                    type: "text",

                    visibleWhen: {
                        field:
                            "show_top_bar",

                        equals: true,
                    },
                },

                {
                    key:
                        "top_email",

                    label:
                        "Top Email",

                    type: "text",

                    visibleWhen: {
                        field:
                            "show_top_bar",

                        equals: true,
                    },
                },

                {
                    key:
                        "top_links",

                    label:
                        "Top Links",

                    type:
                        "repeater",

                    itemLabel:
                        "Link",

                    min: 0,
                    max: 8,

                    visibleWhen: {
                        field:
                            "show_top_bar",

                        equals: true,
                    },

                    defaultItem: {
                        label:
                            "New Link",

                        url: "#",
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
                            key: "url",
                            label: "URL",
                            type: "url",
                        },
                    ],
                },
            ],
        },

        {
            id: "header-02",

            label:
                "Modern SaaS Pill",

            description:
                "Dark technology header with floating rounded navigation bar.",

            component: Header02,

            defaultSettings: {
                ...sharedDefaults,

                brand_short: "N",

                brand_name:
                    "Nexus.ai",

                background_color:
                    "#030712",

                text_color:
                    "#d1d5db",

                accent_color:
                    "#4f46e5",

                cta_text:
                    "Request Access",
            },

            fields:
                sharedFields,
        },

        {
            id: "header-03",

            label:
                "Ecommerce Search",

            description:
                "Retail header with logo, search, utilities and category navigation.",

            component: Header03,

            defaultSettings: {
                ...sharedDefaults,

                brand_name:
                    "URBANSTORE",

                accent_color:
                    "#059669",

                search_placeholder:
                    "Search for products, brands...",

                category_links: [
                    {
                        label:
                            "🔥 All Deals",
                        url: "#",
                    },

                    {
                        label:
                            "New Arrivals",
                        url: "#",
                    },

                    {
                        label:
                            "Electronics",
                        url: "#",
                    },

                    {
                        label:
                            "Fashion",
                        url: "#",
                    },
                ],
            },

            fields: [
                ...sharedFields,

                {
                    key:
                        "search_placeholder",

                    label:
                        "Search Placeholder",

                    type: "text",
                },

                {
                    key:
                        "category_links",

                    label:
                        "Category Links",

                    type:
                        "repeater",

                    itemLabel:
                        "Category",

                    defaultItem: {
                        label:
                            "New Category",

                        url: "#",
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
                            key: "url",
                            label: "URL",
                            type: "url",
                        },
                    ],
                },
            ],
        },

        {
            id: "header-04",

            label:
                "Colorful Corporate",

            description:
                "Gradient corporate header with dropdown menus, call CTA and mobile drawer.",

            component: Header04,

            defaultSettings: {
                ...sharedDefaults,

                brand_short: "AG",

                brand_name:
                    "Arab Group",

                background_color:
                    "#14532d",

                text_color:
                    "#d1fae5",

                accent_color:
                    "#34d399",

                show_phone: true,
            },

            fields:
                sharedFields,
        },

        {
            id: "header-05",

            label:
                "Enterprise Mega Menu",

            description:
                "Enterprise corporate header with utility toolbar and rich submenu cards.",

            component: Header05,

            defaultSettings: {
                ...sharedDefaults,

                brand_short: "F",

                brand_name:
                    "FORTRESS",

                brand_subtitle:
                    "Holdings Ltd.",

                background_color:
                    "#ffffff",

                text_color:
                    "#334155",

                accent_color:
                    "#2563eb",

                show_top_bar: true,

                top_left_text:
                    "Global HQ: New York, NY",

                top_support_text:
                    "24/7 Enterprise Support",

                top_links: [
                    {
                        label:
                            "Client Portal",
                        url: "#",
                    },

                    {
                        label:
                            "Investor Relations",
                        url: "#",
                    },
                ],

                cta_text:
                    "Schedule Consultation",
            },

            fields: [
                ...sharedFields,

                {
                    key:
                        "show_top_bar",

                    label:
                        "Show Utility Bar",

                    type:
                        "checkbox",
                },

                {
                    key:
                        "top_left_text",

                    label:
                        "Top Left Text",

                    type:
                        "text",

                    visibleWhen: {
                        field:
                            "show_top_bar",

                        equals: true,
                    },
                },

                {
                    key:
                        "top_support_text",

                    label:
                        "Support Text",

                    type:
                        "text",

                    visibleWhen: {
                        field:
                            "show_top_bar",

                        equals: true,
                    },
                },

                {
                    key:
                        "top_links",

                    label:
                        "Utility Links",

                    type:
                        "repeater",

                    itemLabel:
                        "Link",

                    visibleWhen: {
                        field:
                            "show_top_bar",

                        equals: true,
                    },

                    defaultItem: {
                        label:
                            "New Link",

                        url: "#",
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
                            key: "url",
                            label: "URL",
                            type: "url",
                        },
                    ],
                },
            ],
        },
    ],
};
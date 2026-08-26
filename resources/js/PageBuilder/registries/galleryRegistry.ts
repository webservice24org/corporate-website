import type {
    SectionGroup,
} from "../types";

import Gallery01 from "../sections/Gallery/Gallery01";

import {
    backgroundFields,
    typographyFields,
    alignmentFields,
    advancedFields,
} from "../fields";

export const galleryRegistry: SectionGroup = {
    type: "gallery",
    label: "Gallery",

    designs: [
        {
            id: "gallery-01",

            label:
                "Isotope Filter Gallery",

            description:
                "Dynamic filtered photo gallery with categories, captions and smooth transition effects.",

            component:
                Gallery01,

            defaultSettings: {
                title:
                    "Photo Gallery",

                description:
                    "Filter through our collection of photography.",

                all_label:
                    "All",

                show_captions:
                    true,

                categories: [
                    {
                        title:
                            "Nature",

                        items: [
                            {
                                image: "",

                                alt:
                                    "Misty Forest",

                                show_caption:
                                    true,

                                caption:
                                    "Misty Forest",
                            },

                            {
                                image: "",

                                alt:
                                    "Mountain Pass",

                                show_caption:
                                    true,

                                caption:
                                    "Mountain Pass",
                            },
                        ],
                    },

                    {
                        title:
                            "Architecture",

                        items: [
                            {
                                image: "",

                                alt:
                                    "Modern Skyscraper",

                                show_caption:
                                    true,

                                caption:
                                    "Modern Skyscraper",
                            },

                            {
                                image: "",

                                alt:
                                    "Interior Design",

                                show_caption:
                                    true,

                                caption:
                                    "Interior Design",
                            },
                        ],
                    },

                    {
                        title:
                            "People",

                        items: [
                            {
                                image: "",

                                alt:
                                    "Portrait",

                                show_caption:
                                    true,

                                caption:
                                    "Portrait",
                            },

                            {
                                image: "",

                                alt:
                                    "Street Photography",

                                show_caption:
                                    true,

                                caption:
                                    "Street Photography",
                            },
                        ],
                    },
                ],

                columns: "3",

                image_height: 280,

                gallery_effect:
                    "fade-scale",

                transition_duration:
                    450,

                // Style
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
                    "#111827",

                text_color:
                    "#6b7280",

                accent_color:
                    "#4f46e5",

                text_alignment:
                    "center",

                // Advanced
                margin_top: 0,
                margin_bottom: 0,

                padding_top: 80,
                padding_bottom: 80,

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
            },

            fields: [
                {
                    key: "title",
                    label:
                        "Gallery Title",
                    type: "text",
                    maxLength: 100,
                },

                {
                    key: "description",
                    label:
                        "Description",
                    type: "textarea",
                    rows: 3,
                    maxLength: 220,
                },

                {
                    key: "all_label",
                    label:
                        '"All" Filter Label',
                    type: "text",
                    maxLength: 30,
                },

                {
                    key: "show_captions",
                    label:
                        "Show Captions",
                    type: "checkbox",
                },

                {
                    key:
                        "categories",

                    label:
                        "Gallery Categories",

                    type:
                        "repeater",

                    itemLabel:
                        "Category",

                    min: 1,
                    max: 20,

                    defaultItem: {
                        title:
                            "New Category",

                        items: [
                            {
                                image: "",

                                alt:
                                    "Gallery Image",

                                show_caption:
                                    true,

                                caption:
                                    "Gallery Image",
                            },
                        ],
                    },

                    fields: [
                        {
                            key: "title",

                            label:
                                "Category Name",

                            type: "text",

                            maxLength:
                                50,
                        },

                        {
                            key: "items",

                            label:
                                "Photos",

                            type:
                                "repeater",

                            itemLabel:
                                "Photo",

                            min: 1,

                            max: 50,

                            defaultItem: {
                                image: "",

                                alt:
                                    "Gallery Image",

                                show_caption:
                                    true,

                                caption:
                                    "Gallery Image",
                            },

                            fields: [
                                {
                                    key:
                                        "image",

                                    label:
                                        "Photo",

                                    type:
                                        "image",
                                },

                                {
                                    key:
                                        "alt",

                                    label:
                                        "Image Alt Text",

                                    type:
                                        "text",

                                    maxLength:
                                        120,
                                },

                                {
                                    key:
                                        "show_caption",

                                    label:
                                        "Show Caption",

                                    type:
                                        "checkbox",
                                },

                                {
                                    key:
                                        "caption",

                                    label:
                                        "Caption",

                                    type:
                                        "text",

                                    maxLength:
                                        120,

                                    visibleWhen:
                                        {
                                            field:
                                                "show_caption",

                                            equals:
                                                true,
                                        },
                                },
                            ],
                        },
                    ],
                },

                {
                    key: "columns",

                    label:
                        "Gallery Columns",

                    type: "select",

                    group: "style",

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

                {
                    key:
                        "image_height",

                    label:
                        "Image Height (px)",

                    type:
                        "number",

                    group:
                        "style",

                    panel:
                        "alignment",
                },

                {
                    key:
                        "gallery_effect",

                    label:
                        "Filter Animation",

                    type:
                        "select",

                    group:
                        "advanced",

                    panel:
                        "layout",

                    options: [
                        {
                            label:
                                "Fade + Scale",

                            value:
                                "fade-scale",
                        },

                        {
                            label:
                                "Fade Up",

                            value:
                                "fade-up",
                        },

                        {
                            label:
                                "None",

                            value:
                                "none",
                        },
                    ],
                },

                {
                    key:
                        "transition_duration",

                    label:
                        "Animation Duration (ms)",

                    type:
                        "number",

                    group:
                        "advanced",

                    panel:
                        "layout",
                },

                ...backgroundFields,
                ...typographyFields,
                ...alignmentFields,
                ...advancedFields,
            ],
        },
    ],
};
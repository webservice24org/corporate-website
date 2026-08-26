import type {
    SectionGroup,
} from "../types";

import VideoGallery01 from "../sections/VideoGallery/VideoGallery01";

import {
    backgroundFields,
    typographyFields,
    alignmentFields,
    advancedFields,
} from "../fields";

export const videoGalleryRegistry: SectionGroup = {
    type: "video-gallery",
    label: "Video Gallery",

    designs: [
        {
            id:
                "video-gallery-01",

            label:
                "YouTube Video Grid",

            description:
                "Dynamic YouTube video gallery with thumbnails and modal playback.",

            component:
                VideoGallery01,

            defaultSettings: {
                title:
                    "Video Gallery",

                description:
                    "Watch our latest videos and featured stories.",

                videos: [
                    {
                        youtube_url:
                            "",

                        title:
                            "Company Introduction",

                        show_title:
                            true,

                        thumbnail_type:
                            "youtube",

                        custom_thumbnail:
                            "",
                    },

                    {
                        youtube_url:
                            "",

                        title:
                            "Our Services",

                        show_title:
                            true,

                        thumbnail_type:
                            "youtube",

                        custom_thumbnail:
                            "",
                    },

                    {
                        youtube_url:
                            "",

                        title:
                            "Project Showcase",

                        show_title:
                            true,

                        thumbnail_type:
                            "youtube",

                        custom_thumbnail:
                            "",
                    },
                ],

                columns: "3",

                card_height: 280,

                // Style
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
                    "#dc2626",

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

                section_id: "",
                custom_class: "",
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
                    key:
                        "description",

                    label:
                        "Description",

                    type:
                        "textarea",

                    rows: 3,

                    maxLength: 220,
                },

                {
                    key: "videos",

                    label: "Videos",

                    type:
                        "repeater",

                    itemLabel:
                        "Video",

                    min: 1,
                    max: 24,

                    defaultItem: {
                        youtube_url:
                            "",

                        title:
                            "New Video",

                        show_title:
                            true,

                        thumbnail_type:
                            "youtube",

                        custom_thumbnail:
                            "",
                    },

                    fields: [
                        {
                            key:
                                "youtube_url",

                            label:
                                "YouTube URL",

                            type:
                                "url",

                            placeholder:
                                "https://www.youtube.com/watch?v=...",
                        },

                        {
                            key:
                                "title",

                            label:
                                "Video Title",

                            type:
                                "text",

                            maxLength:
                                100,
                        },

                        {
                            key:
                                "show_title",

                            label:
                                "Show Title",

                            type:
                                "checkbox",
                        },

                        {
                            key:
                                "thumbnail_type",

                            label:
                                "Thumbnail",

                            type:
                                "select",

                            options: [
                                {
                                    label:
                                        "YouTube Thumbnail",

                                    value:
                                        "youtube",
                                },

                                {
                                    label:
                                        "Custom Image",

                                    value:
                                        "custom",
                                },
                            ],
                        },

                        {
                            key:
                                "custom_thumbnail",

                            label:
                                "Custom Thumbnail",

                            type:
                                "image",

                            visibleWhen:
                                {
                                    field:
                                        "thumbnail_type",

                                    equals:
                                        "custom",
                                },
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
                            value: "2",
                        },

                        {
                            label:
                                "3 Columns",
                            value: "3",
                        },

                        {
                            label:
                                "4 Columns",
                            value: "4",
                        },
                    ],
                },

                {
                    key:
                        "card_height",

                    label:
                        "Video Card Height (px)",

                    type:
                        "number",

                    group:
                        "style",

                    panel:
                        "alignment",
                },

                ...backgroundFields,
                ...typographyFields,
                ...alignmentFields,
                ...advancedFields,
            ],
        },
    ],
};
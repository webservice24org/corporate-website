import {
    Play,
    X,
} from "lucide-react";

import {
    useEffect,
    useState,
} from "react";

import type {
    SectionSettings,
} from "../../types";

import {
    getContainerClass,
    getCustomClass,
    getHorizontalPadding,
    getSectionId,
    getSectionSpacing,
    getVisibilityClass,
} from "../../utils/sectionStyles";

import {
    getYouTubeEmbedUrl,
    getYouTubeThumbnail,
} from "../../utils/youtube";

type VideoItem = {
    youtube_url?: string;

    title?: string;

    show_title?: boolean;

    thumbnail_type?:
        | "youtube"
        | "custom";

    custom_thumbnail?: string;
};

type Props = {
    settings: SectionSettings;
    preview?: boolean;
};

export default function VideoGallery01({
    settings,
    preview = false,
}: Props) {
    const videos =
        (settings.videos as
            | VideoItem[]
            | undefined) ?? [];

    const [
        activeVideo,
        setActiveVideo,
    ] = useState<VideoItem | null>(
        null,
    );

    /*
     * Content
     */
    const title = String(
        settings.title ??
            "Video Gallery",
    );

    const description = String(
        settings.description ?? "",
    );

    /*
     * Style
     */
    const backgroundType = String(
        settings.background_type ??
            "color",
    );

    const backgroundColor = String(
        settings.background_color ??
            "#ffffff",
    );

    const backgroundImage = String(
        settings.background_image ?? "",
    );

    const titleFontSize = Number(
        settings.title_font_size ?? 40,
    );

    const textFontSize = Number(
        settings.text_font_size ?? 16,
    );

    const titleColor = String(
        settings.title_color ??
            "#111827",
    );

    const textColor = String(
        settings.text_color ??
            "#6b7280",
    );

    const accentColor = String(
        settings.accent_color ??
            "#dc2626",
    );

    const alignment =
        (settings.text_alignment as
            | "left"
            | "center"
            | "right") ?? "center";

    const columns = String(
        settings.columns ?? "3",
    );

    const cardHeight = Math.max(
        180,
        Number(
            settings.card_height ?? 280,
        ),
    );

    /*
     * Advanced
     */
    const sectionSpacing =
        getSectionSpacing(settings);

    const containerClass =
        getContainerClass(settings);

    const horizontalPadding =
        getHorizontalPadding(settings);

    const sectionId =
        getSectionId(settings);

    const customClass =
        getCustomClass(settings);

    const visibilityClass = preview
        ? ""
        : getVisibilityClass(settings);

    const gridClass =
        columns === "2"
            ? "md:grid-cols-2"
            : columns === "4"
              ? "md:grid-cols-2 xl:grid-cols-4"
              : "md:grid-cols-2 lg:grid-cols-3";

    const headerPosition =
        alignment === "center"
            ? "mx-auto"
            : alignment === "right"
              ? "ml-auto"
              : "";

    /*
     * ESC closes video modal.
     */
    useEffect(() => {
        if (!activeVideo) {
            return;
        }

        const handleKeyDown = (
            event: KeyboardEvent,
        ) => {
            if (
                event.key === "Escape"
            ) {
                setActiveVideo(null);
            }
        };

        window.addEventListener(
            "keydown",
            handleKeyDown,
        );

        return () => {
            window.removeEventListener(
                "keydown",
                handleKeyDown,
            );
        };
    }, [activeVideo]);

    /*
     * Prevent background scrolling
     * while video is open.
     */
    useEffect(() => {
        if (!activeVideo) {
            return;
        }

        const previous =
            document.body.style.overflow;

        document.body.style.overflow =
            "hidden";

        return () => {
            document.body.style.overflow =
                previous;
        };
    }, [activeVideo]);

    return (
        <>
            <section
                id={sectionId || undefined}
                className={`bg-cover bg-center ${visibilityClass} ${customClass}`}
                style={{
                    ...sectionSpacing,

                    backgroundColor:
                        backgroundType ===
                        "color"
                            ? backgroundColor
                            : undefined,

                    backgroundImage:
                        backgroundType ===
                            "image" &&
                        backgroundImage
                            ? `url("${backgroundImage}")`
                            : undefined,
                }}
            >
                <div
                    className={`mx-auto w-full ${containerClass}`}
                    style={{
                        paddingLeft:
                            horizontalPadding,

                        paddingRight:
                            horizontalPadding,
                    }}
                >
                    <div
                        className={`mb-10 max-w-3xl ${headerPosition}`}
                        style={{
                            textAlign:
                                alignment,
                        }}
                    >
                        <h2
                            className="font-bold tracking-tight"
                            style={{
                                color:
                                    titleColor,

                                fontSize:
                                    `${titleFontSize}px`,
                            }}
                        >
                            {title}
                        </h2>

                        {description && (
                            <p
                                className="mt-3 leading-7"
                                style={{
                                    color:
                                        textColor,

                                    fontSize:
                                        `${textFontSize}px`,
                                }}
                            >
                                {
                                    description
                                }
                            </p>
                        )}
                    </div>

                    {videos.length > 0 ? (
                        <div
                            className={`grid grid-cols-1 gap-6 ${gridClass}`}
                        >
                            {videos.map(
                                (
                                    video,
                                    index,
                                ) => {
                                    const videoTitle =
                                        String(
                                            video.title ??
                                                `Video ${
                                                    index +
                                                    1
                                                }`,
                                        );

                                    const youtubeThumbnail =
                                        getYouTubeThumbnail(
                                            video.youtube_url,
                                        );

                                    const thumbnail =
                                        video.thumbnail_type ===
                                            "custom" &&
                                        video.custom_thumbnail
                                            ? video.custom_thumbnail
                                            : youtubeThumbnail;

                                    const validVideo =
                                        Boolean(
                                            getYouTubeEmbedUrl(
                                                video.youtube_url,
                                            ),
                                        );

                                    return (
                                        <button
                                            key={
                                                index
                                            }
                                            type="button"
                                            onClick={() => {
                                                if (
                                                    validVideo
                                                ) {
                                                    setActiveVideo(
                                                        video,
                                                    );
                                                }
                                            }}
                                            className="group text-left"
                                        >
                                            <div
                                                className="relative overflow-hidden rounded-2xl bg-slate-200 shadow-sm transition-all duration-300 group-hover:-translate-y-1 group-hover:shadow-xl"
                                                style={{
                                                    height:
                                                        `${cardHeight}px`,
                                                }}
                                            >
                                                {thumbnail ? (
                                                    <img
                                                        src={
                                                            thumbnail
                                                        }
                                                        alt={
                                                            videoTitle
                                                        }
                                                        className="h-full w-full object-cover transition-transform duration-700 group-hover:scale-105"
                                                    />
                                                ) : (
                                                    <div className="flex h-full items-center justify-center text-sm text-slate-400">
                                                        YouTube
                                                        Video
                                                    </div>
                                                )}

                                                <div className="absolute inset-0 bg-black/20 transition-colors duration-300 group-hover:bg-black/35" />

                                                <div className="absolute inset-0 flex items-center justify-center">
                                                    <span
                                                        className="flex size-16 items-center justify-center rounded-full text-white shadow-xl transition-all duration-300 group-hover:scale-110"
                                                        style={{
                                                            backgroundColor:
                                                                accentColor,
                                                        }}
                                                    >
                                                        <Play
                                                            className="size-7 translate-x-0.5"
                                                            fill="currentColor"
                                                        />
                                                    </span>
                                                </div>

                                                {!validVideo && (
                                                    <div className="absolute bottom-3 left-3 rounded-md bg-black/70 px-2 py-1 text-xs text-white">
                                                        Invalid
                                                        YouTube
                                                        URL
                                                    </div>
                                                )}
                                            </div>

                                            {video.show_title !==
                                                false && (
                                                <h3
                                                    className="mt-3 text-base font-semibold"
                                                    style={{
                                                        color:
                                                            titleColor,
                                                    }}
                                                >
                                                    {
                                                        videoTitle
                                                    }
                                                </h3>
                                            )}
                                        </button>
                                    );
                                },
                            )}
                        </div>
                    ) : (
                        <div className="rounded-2xl border border-dashed p-12 text-center text-sm text-muted-foreground">
                            No videos added
                            yet.
                        </div>
                    )}
                </div>
            </section>

            {activeVideo && (
                <VideoModal
                    video={
                        activeVideo
                    }
                    accentColor={
                        accentColor
                    }
                    onClose={() =>
                        setActiveVideo(
                            null,
                        )
                    }
                />
            )}
        </>
    );
}

type VideoModalProps = {
    video: VideoItem;
    accentColor: string;
    onClose: () => void;
};

function VideoModal({
    video,
    accentColor,
    onClose,
}: VideoModalProps) {
    const embedUrl =
        getYouTubeEmbedUrl(
            video.youtube_url,
        );

    if (!embedUrl) {
        return null;
    }

    const title = String(
        video.title ?? "Video",
    );

    return (
        <div
            className="fixed inset-0 z-[200] flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
            aria-label={title}
            onMouseDown={(
                event,
            ) => {
                /*
                 * Modal backdrop is intentionally
                 * dismissible because video data
                 * isn't being edited here.
                 */
                if (
                    event.target ===
                    event.currentTarget
                ) {
                    onClose();
                }
            }}
        >
            <div className="w-full max-w-5xl">
                <div className="mb-3 flex items-center justify-between gap-4">
                    <h3 className="truncate font-semibold text-white">
                        {title}
                    </h3>

                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close video"
                        className="flex size-10 shrink-0 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20"
                    >
                        <X className="size-5" />
                    </button>
                </div>

                <div className="aspect-video overflow-hidden rounded-2xl bg-black shadow-2xl">
                    <iframe
                        src={embedUrl}
                        title={title}
                        className="h-full w-full"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                        allowFullScreen
                    />
                </div>

                <div
                    className="mx-auto mt-4 h-1 w-20 rounded-full"
                    style={{
                        backgroundColor:
                            accentColor,
                    }}
                />
            </div>
        </div>
    );
}
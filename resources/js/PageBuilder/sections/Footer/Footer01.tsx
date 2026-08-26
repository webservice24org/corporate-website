import type {
    FormEvent,
} from "react";
import {
    useState,
} from "react";
import {
    Mail,
    MapPin,
    Phone,
} from "lucide-react";

import type {
    SectionSettings,
} from "../../types";

import {
    getBuilderIcon,
} from "../../utils/icons";

import {
    getContainerClass,
    getCustomClass,
    getHorizontalPadding,
    getSectionId,
    getSectionSpacing,
    getVisibilityClass,
} from "../../utils/sectionStyles";

import {
    useForm,
} from "@inertiajs/react";

import {
    CheckCircle2,
} from "lucide-react";

type FooterLink = {
    label?: string;
    url?: string;
};

type FooterColumn = {
    title?: string;
    links?: FooterLink[];
};

type ContactItem = {
    icon?: string;
    text?: string;
    url?: string;
};

type BottomLink = {
    label?: string;
    url?: string;
};

type Props = {
    settings: SectionSettings;
    preview?: boolean;
};

export default function Footer01({
    settings,
    preview = false,
}: Props) {
    const brandText = String(
        settings.brand_text ??
            "Arab Group",
    );

    const brandShort = String(
        settings.brand_short ??
            "AG",
    );

    const aboutText = String(
        settings.about_text ??
            "",
    );

    const linkColumns =
        (settings.link_columns as
            | FooterColumn[]
            | undefined) ?? [];

    const contactTitle = String(
        settings.contact_title ??
            "Headquarters",
    );

    const contactItems =
        (settings.contact_items as
            | ContactItem[]
            | undefined) ?? [];

    const newsletterTitle = String(
        settings.newsletter_title ??
            "Stay Updated",
    );

    const newsletterText = String(
        settings.newsletter_text ??
            "",
    );

    const newsletterPlaceholder = String(
        settings.newsletter_placeholder ??
            "Enter your email",
    );

    const newsletterButtonText = String(
        settings.newsletter_button_text ??
            "Subscribe",
    );

    const copyrightText = String(
        settings.copyright_text ??
            "© 2026 Arab Group. All rights reserved.",
    );

    const bottomLinks =
        (settings.bottom_links as
            | BottomLink[]
            | undefined) ?? [];

    const developerPrefix = String(
        settings.developer_prefix ??
            "Designed & Developed by",
    );

    const developerName = String(
        settings.developer_name ??
            "MicroWeb Technology",
    );

    const developerUrl = String(
        settings.developer_url ??
            "https://webservicebd.org",
    );

    /*
     * Style
     */
    const backgroundColor = String(
        settings.background_color ??
            "#111827",
    );

    const titleColor = String(
        settings.title_color ??
            "#ffffff",
    );

    const textColor = String(
        settings.text_color ??
            "#9ca3af",
    );

    const accentColor = String(
        settings.accent_color ??
            "#059669",
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

    
        const {
            data,
            setData,
            post,
            processing,
            errors,
            reset,
        } = useForm({
            email: "",
        });

        const [
            subscribed,
            setSubscribed,
        ] = useState(false);


        const subscribe = (
            event: FormEvent,
        ) => {
            event.preventDefault();

            if (preview) {
                return;
            }

            setSubscribed(false);

            post(
                "/newsletter/subscribe",
                {
                    preserveScroll: true,

                    onSuccess: () => {
                        reset();

                        setSubscribed(true);
                    },
                },
            );
        };

    return (
        <footer
            id={sectionId || undefined}
            className={`${visibilityClass} ${customClass}`}
            style={{
                ...sectionSpacing,
                backgroundColor,
                color: textColor,
            }}
        >
            {/* TOP */}
            <div
                className={`mx-auto w-full ${containerClass}`}
                style={{
                    paddingLeft:
                        horizontalPadding,
                    paddingRight:
                        horizontalPadding,
                }}
            >
                <div className="border-b border-white/10 py-16">
                    <div className="grid grid-cols-1 gap-12 md:grid-cols-2 lg:grid-cols-4">
                        {/* About */}
                        <div className="space-y-4">
                            <div className="flex items-center gap-3">
                                <div
                                    className="flex size-8 items-center justify-center rounded text-sm font-bold text-white"
                                    style={{
                                        backgroundColor:
                                            accentColor,
                                    }}
                                >
                                    {
                                        brandShort
                                    }
                                </div>

                                <span
                                    className="text-xl font-bold"
                                    style={{
                                        color:
                                            titleColor,
                                    }}
                                >
                                    {
                                        brandText
                                    }
                                </span>
                            </div>

                            {aboutText && (
                                <p className="text-sm leading-relaxed">
                                    {
                                        aboutText
                                    }
                                </p>
                            )}
                        </div>

                        {/* Dynamic link column */}
                        {linkColumns
                            .slice(0, 1)
                            .map(
                                (
                                    column,
                                    index,
                                ) => (
                                    <div
                                        key={
                                            index
                                        }
                                    >
                                        <h4
                                            className="mb-4 text-sm font-semibold uppercase tracking-wider"
                                            style={{
                                                color:
                                                    titleColor,
                                            }}
                                        >
                                            {String(
                                                column.title ??
                                                    "",
                                            )}
                                        </h4>

                                        <ul className="space-y-2 text-sm">
                                            {(
                                                column.links ??
                                                []
                                            ).map(
                                                (
                                                    link,
                                                    linkIndex,
                                                ) => (
                                                    <li
                                                        key={
                                                            linkIndex
                                                        }
                                                    >
                                                        <a
                                                            href={
                                                                preview
                                                                    ? undefined
                                                                    : String(
                                                                          link.url ??
                                                                              "#",
                                                                      )
                                                            }
                                                            onClick={(
                                                                event,
                                                            ) => {
                                                                if (
                                                                    preview
                                                                ) {
                                                                    event.preventDefault();
                                                                }
                                                            }}
                                                            className="transition-opacity hover:opacity-70"
                                                            style={{
                                                                color:
                                                                    textColor,
                                                            }}
                                                        >
                                                            {String(
                                                                link.label ??
                                                                    "",
                                                            )}
                                                        </a>
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    </div>
                                ),
                            )}

                        {/* Contact */}
                        <div>
                            <h4
                                className="mb-4 text-sm font-semibold uppercase tracking-wider"
                                style={{
                                    color:
                                        titleColor,
                                }}
                            >
                                {
                                    contactTitle
                                }
                            </h4>

                            <ul className="space-y-3 text-sm">
                                {contactItems.map(
                                    (
                                        item,
                                        index,
                                    ) => {
                                        const Icon =
                                            getBuilderIcon(
                                                item.icon ??
                                                    "map-pin",
                                            );

                                        const content = (
                                            <>
                                                <Icon
                                                    className="mt-0.5 size-4 shrink-0"
                                                    style={{
                                                        color:
                                                            accentColor,
                                                    }}
                                                />

                                                <span>
                                                    {String(
                                                        item.text ??
                                                            "",
                                                    )}
                                                </span>
                                            </>
                                        );

                                        return (
                                            <li
                                                key={
                                                    index
                                                }
                                            >
                                                {item.url ? (
                                                    <a
                                                        href={
                                                            preview
                                                                ? undefined
                                                                : item.url
                                                        }
                                                        onClick={(
                                                            event,
                                                        ) => {
                                                            if (
                                                                preview
                                                            ) {
                                                                event.preventDefault();
                                                            }
                                                        }}
                                                        className="flex items-start gap-3 transition hover:text-white"
                                                    >
                                                        {
                                                            content
                                                        }
                                                    </a>
                                                ) : (
                                                    <div className="flex items-start gap-3">
                                                        {
                                                            content
                                                        }
                                                    </div>
                                                )}
                                            </li>
                                        );
                                    },
                                )}
                            </ul>
                        </div>

                        {/* Newsletter */}
                        <div>
                            <h4
                                className="mb-4 text-sm font-semibold uppercase tracking-wider"
                                style={{
                                    color:
                                        titleColor,
                                }}
                            >
                                {
                                    newsletterTitle
                                }
                            </h4>

                            {newsletterText && (
                                <p className="mb-4 text-sm">
                                    {
                                        newsletterText
                                    }
                                </p>
                            )}

                            <form
                                onSubmit={subscribe}
                                className="flex flex-col gap-2"
                            >
                                <input
                                    type="email"
                                    value={data.email}
                                    onChange={(event) =>
                                        setData(
                                            "email",
                                            event.target.value,
                                        )
                                    }
                                    disabled={preview}
                                    placeholder={
                                        newsletterPlaceholder
                                    }
                                    className="rounded-lg border border-white/10 bg-white/5 px-4 py-2.5 text-sm text-white outline-none transition placeholder:text-gray-500 focus:border-white/30"
                                />

                                {errors.email && (
                                    <p className="text-xs text-red-400">
                                        {errors.email}
                                    </p>
                                )}

                                <button
                                    type="submit"
                                    disabled={
                                        preview ||
                                        processing
                                    }
                                    className="rounded-lg px-4 py-2.5 text-sm font-medium text-white transition hover:opacity-90 disabled:pointer-events-none disabled:opacity-50"
                                    style={{
                                        backgroundColor:
                                            accentColor,
                                    }}
                                >
                                    {processing
                                        ? "Subscribing..."
                                        : newsletterButtonText}
                                </button>

                                {subscribed && (
                                    <div className="mt-1 flex items-center gap-2 text-xs text-emerald-400">
                                        <CheckCircle2 className="size-4" />

                                        Successfully subscribed.
                                    </div>
                                )}
                            </form>
                        </div>
                    </div>
                </div>

                {/* BOTTOM */}
                <div className="py-6">
                    <div className="flex flex-col items-center justify-between gap-4 text-xs md:flex-row">
                        <div className="text-center md:text-left">
                            {
                                copyrightText
                            }
                        </div>

                        <div className="flex flex-wrap justify-center gap-6">
                            {bottomLinks.map(
                                (
                                    link,
                                    index,
                                ) => (
                                    <a
                                        key={
                                            index
                                        }
                                        href={
                                            preview
                                                ? undefined
                                                : String(
                                                      link.url ??
                                                          "#",
                                                  )
                                        }
                                        onClick={(
                                            event,
                                        ) => {
                                            if (
                                                preview
                                            ) {
                                                event.preventDefault();
                                            }
                                        }}
                                        className="transition hover:text-white"
                                    >
                                        {String(
                                            link.label ??
                                                "",
                                        )}
                                    </a>
                                ),
                            )}
                        </div>

                        <div className="text-center md:text-right">
                            {
                                developerPrefix
                            }{" "}

                            <a
                                href={
                                    preview
                                        ? undefined
                                        : developerUrl
                                }
                                target={
                                    preview
                                        ? undefined
                                        : "_blank"
                                }
                                rel="noreferrer"
                                onClick={(
                                    event,
                                ) => {
                                    if (
                                        preview
                                    ) {
                                        event.preventDefault();
                                    }
                                }}
                                className="font-medium hover:underline"
                                style={{
                                    color:
                                        accentColor,
                                }}
                            >
                                {
                                    developerName
                                }
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </footer>
    );
}
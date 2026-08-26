import {
    CheckCircle2,
    Send,
} from "lucide-react";

import {
    useEffect,
    useState,
} from "react";

import {
    useForm,
} from "@inertiajs/react";

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
import type {
    FormEvent,
    ReactNode,
} from "react";
type ContactInfoItem = {
    icon?: string;
    title?: string;
    content?: string;
};

type SocialItem = {
    icon?: string;
    url?: string;
};

type TargetOption = {
    label?: string;
    value?: string;
};

type Props = {
    settings: SectionSettings;
    preview?: boolean;
};

type ContactForm = {
    name: string;
    phone: string;
    email: string;

    target: string;
    target_label: string;

    subject: string;
    message: string;
};

export default function Contact01({
    settings,
    preview = false,
}: Props) {
    /*
     * Builder content
     */
    const badge = String(
        settings.badge ??
            "Get In Touch",
    );

    const title = String(
        settings.title ??
            "Contact Us",
    );

    const description = String(
        settings.description ??
            "Have an inquiry or looking to collaborate? Send us a message and our team will get back to you shortly.",
    );

    const sidebarTitle = String(
        settings.sidebar_title ??
            "Corporate Headquarters",
    );

    const sidebarDescription =
        String(
            settings.sidebar_description ??
                "Reach out directly to our corporate office or visit us at our main location.",
        );

    const socialTitle = String(
        settings.social_title ??
            "Follow Our Social Channels",
    );

    const submitText = String(
        settings.submit_text ??
            "Send Message",
    );

    const successMessage = String(
        settings.success_message ??
            "Thank you for contacting us. We will get back to you shortly.",
    );

    /*
     * Dynamic items
     */
    const contactItems =
        (settings.contact_items as
            | ContactInfoItem[]
            | undefined) ?? [];

    const socialLinks =
        (settings.social_links as
            | SocialItem[]
            | undefined) ?? [];

    const targetOptions =
        (settings.target_options as
            | TargetOption[]
            | undefined) ?? [];

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
            "#059669",
    );

    const alignment =
        (settings.text_alignment as
            | "left"
            | "center"
            | "right") ?? "center";

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

    /*
     * Default target
     */
    const firstTarget =
        targetOptions[0];

    const {
        data,
        setData,
        post,
        processing,
        errors,
        reset,
        clearErrors,
    } = useForm<ContactForm>({
        name: "",
        phone: "",
        email: "",

        target:
            firstTarget?.value ?? "",

        target_label:
            firstTarget?.label ?? "",

        subject: "",
        message: "",
    });

    const [
        submitted,
        setSubmitted,
    ] = useState(false);

    /*
     * If builder target options change
     * while previewing, keep form target valid.
     */
    useEffect(() => {
        if (
            targetOptions.length === 0
        ) {
            return;
        }

        const exists =
            targetOptions.some(
                (option) =>
                    option.value ===
                    data.target,
            );

        if (!exists) {
            setData(
                "target",
                targetOptions[0]
                    ?.value ?? "",
            );

            setData(
                "target_label",
                targetOptions[0]
                    ?.label ?? "",
            );
        }
    }, [
        targetOptions,
        data.target,
    ]);

    const submit = (
        event: FormEvent,
    ) => {
        event.preventDefault();

        /*
         * Never submit from builder preview.
         */
        if (preview) {
            return;
        }

        setSubmitted(false);
        clearErrors();

        post(
            "/contact/messages",
            {
                preserveScroll: true,

                onSuccess: () => {
                    const currentTarget =
                        data.target;

                    const currentLabel =
                        data.target_label;

                    reset();

                    setData(
                        "target",
                        currentTarget,
                    );

                    setData(
                        "target_label",
                        currentLabel,
                    );

                    setSubmitted(true);
                },
            },
        );
    };

    const headerPosition =
        alignment === "center"
            ? "mx-auto"
            : alignment === "right"
              ? "ml-auto"
              : "";

    return (
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
                {/* Section heading */}
                <div
                    className={`mb-16 max-w-2xl ${headerPosition}`}
                    style={{
                        textAlign:
                            alignment,
                    }}
                >
                    {badge && (
                        <span
                            className="inline-flex rounded-full border px-3 py-1 text-xs font-semibold uppercase tracking-wider"
                            style={{
                                color:
                                    accentColor,

                                backgroundColor:
                                    `${accentColor}0d`,

                                borderColor:
                                    `${accentColor}20`,
                            }}
                        >
                            {badge}
                        </span>
                    )}

                    <h2
                        className="mt-3 font-bold tracking-tight"
                        style={{
                            color:
                                titleColor,

                            fontSize:
                                `${titleFontSize}px`,
                        }}
                    >
                        {title}
                    </h2>

                    <div
                        className={`my-4 h-1 w-16 rounded-full ${
                            alignment ===
                            "center"
                                ? "mx-auto"
                                : alignment ===
                                    "right"
                                  ? "ml-auto"
                                  : ""
                        }`}
                        style={{
                            backgroundColor:
                                accentColor,
                        }}
                    />

                    {description && (
                        <p
                            className="leading-relaxed"
                            style={{
                                color:
                                    textColor,

                                fontSize:
                                    `${textFontSize}px`,
                            }}
                        >
                            {description}
                        </p>
                    )}
                </div>

                <div className="grid grid-cols-1 items-start gap-12 lg:grid-cols-12">
                    {/* LEFT — Contact information */}
                    <aside
                        className="space-y-8 rounded-3xl border p-8 sm:p-10 lg:col-span-5"
                        style={{
                            backgroundColor:
                                `${accentColor}08`,

                            borderColor:
                                `${accentColor}18`,
                        }}
                    >
                        <div>
                            <h3
                                className="text-2xl font-bold"
                                style={{
                                    color:
                                        titleColor,
                                }}
                            >
                                {
                                    sidebarTitle
                                }
                            </h3>

                            {sidebarDescription && (
                                <p
                                    className="mt-2 text-sm leading-relaxed"
                                    style={{
                                        color:
                                            textColor,
                                    }}
                                >
                                    {
                                        sidebarDescription
                                    }
                                </p>
                            )}
                        </div>

                        {/* Dynamic contact items */}
                        {contactItems.length >
                            0 && (
                            <div className="space-y-6">
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

                                        return (
                                            <div
                                                key={
                                                    index
                                                }
                                                className="flex items-start gap-4"
                                            >
                                                <div
                                                    className="flex size-12 shrink-0 items-center justify-center rounded-xl text-white shadow-md"
                                                    style={{
                                                        backgroundColor:
                                                            accentColor,
                                                    }}
                                                >
                                                    <Icon className="size-5" />
                                                </div>

                                                <div className="min-w-0">
                                                    <h4
                                                        className="font-bold"
                                                        style={{
                                                            color:
                                                                titleColor,
                                                        }}
                                                    >
                                                        {String(
                                                            item.title ??
                                                                "",
                                                        )}
                                                    </h4>

                                                    <p
                                                        className="mt-1 whitespace-pre-line break-words text-sm leading-relaxed"
                                                        style={{
                                                            color:
                                                                textColor,
                                                        }}
                                                    >
                                                        {String(
                                                            item.content ??
                                                                "",
                                                        )}
                                                    </p>
                                                </div>
                                            </div>
                                        );
                                    },
                                )}
                            </div>
                        )}

                        {/* Social links */}
                        {socialLinks.length >
                            0 && (
                            <div
                                className="border-t pt-5"
                                style={{
                                    borderColor:
                                        `${accentColor}22`,
                                }}
                            >
                                {socialTitle && (
                                    <h4
                                        className="mb-3 text-sm font-bold"
                                        style={{
                                            color:
                                                titleColor,
                                        }}
                                    >
                                        {
                                            socialTitle
                                        }
                                    </h4>
                                )}

                                <div className="flex flex-wrap gap-3">
                                    {socialLinks.map(
                                        (
                                            social,
                                            index,
                                        ) => {
                                            const Icon =
                                                getBuilderIcon(
                                                    social.icon ??
                                                        "globe",
                                                );

                                            return (
                                                <a
                                                    key={
                                                        index
                                                    }
                                                    href={
                                                        preview
                                                            ? undefined
                                                            : String(
                                                                  social.url ??
                                                                      "#",
                                                              )
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
                                                    className="flex size-10 items-center justify-center rounded-full border bg-white transition-all duration-200 hover:-translate-y-0.5"
                                                    style={{
                                                        color:
                                                            accentColor,

                                                        borderColor:
                                                            `${accentColor}20`,
                                                    }}
                                                >
                                                    <Icon className="size-4" />
                                                </a>
                                            );
                                        },
                                    )}
                                </div>
                            </div>
                        )}
                    </aside>

                    {/* RIGHT — Real contact form */}
                    <div className="rounded-3xl border border-slate-100 bg-white p-8 shadow-xl sm:p-10 lg:col-span-7">
                        {submitted && (
                            <div className="mb-6 flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800">
                                <CheckCircle2 className="mt-0.5 size-5 shrink-0" />

                                <p className="text-sm font-medium">
                                    {
                                        successMessage
                                    }
                                </p>
                            </div>
                        )}

                        <form
                            onSubmit={
                                submit
                            }
                            className="space-y-6"
                        >
                            <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                <FormField
                                    label="Full Name"
                                    required
                                    error={
                                        errors.name
                                    }
                                >
                                    <input
                                        type="text"
                                        value={
                                            data.name
                                        }
                                        onChange={(
                                            event,
                                        ) =>
                                            setData(
                                                "name",
                                                event
                                                    .target
                                                    .value,
                                            )
                                        }
                                        placeholder="John Doe"
                                        disabled={
                                            preview
                                        }
                                        className={inputClass}
                                    />
                                </FormField>

                                <FormField
                                    label="Phone Number"
                                    error={
                                        errors.phone
                                    }
                                >
                                    <input
                                        type="tel"
                                        value={
                                            data.phone
                                        }
                                        onChange={(
                                            event,
                                        ) =>
                                            setData(
                                                "phone",
                                                event
                                                    .target
                                                    .value,
                                            )
                                        }
                                        placeholder="+880 17..."
                                        disabled={
                                            preview
                                        }
                                        className={inputClass}
                                    />
                                </FormField>
                            </div>

                            <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                <FormField
                                    label="Email Address"
                                    required
                                    error={
                                        errors.email
                                    }
                                >
                                    <input
                                        type="email"
                                        value={
                                            data.email
                                        }
                                        onChange={(
                                            event,
                                        ) =>
                                            setData(
                                                "email",
                                                event
                                                    .target
                                                    .value,
                                            )
                                        }
                                        placeholder="john@example.com"
                                        disabled={
                                            preview
                                        }
                                        className={inputClass}
                                    />
                                </FormField>

                                {targetOptions.length >
                                    0 && (
                                    <FormField
                                        label="Target Department"
                                        error={
                                            errors.target
                                        }
                                    >
                                        <select
                                            value={
                                                data.target
                                            }
                                            disabled={
                                                preview
                                            }
                                            onChange={(
                                                event,
                                            ) => {
                                                const value =
                                                    event
                                                        .target
                                                        .value;

                                                const option =
                                                    targetOptions.find(
                                                        (
                                                            item,
                                                        ) =>
                                                            item.value ===
                                                            value,
                                                    );

                                                setData(
                                                    "target",
                                                    value,
                                                );

                                                setData(
                                                    "target_label",
                                                    option?.label ??
                                                        "",
                                                );
                                            }}
                                            className={inputClass}
                                        >
                                            {targetOptions.map(
                                                (
                                                    option,
                                                    index,
                                                ) => (
                                                    <option
                                                        key={
                                                            index
                                                        }
                                                        value={String(
                                                            option.value ??
                                                                "",
                                                        )}
                                                    >
                                                        {String(
                                                            option.label ??
                                                                "",
                                                        )}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </FormField>
                                )}
                            </div>

                            <FormField
                                label="Subject"
                                required
                                error={
                                    errors.subject
                                }
                            >
                                <input
                                    type="text"
                                    value={
                                        data.subject
                                    }
                                    onChange={(
                                        event,
                                    ) =>
                                        setData(
                                            "subject",
                                            event
                                                .target
                                                .value,
                                        )
                                    }
                                    placeholder="Partnership Opportunity / Service Inquiry"
                                    disabled={
                                        preview
                                    }
                                    className={inputClass}
                                />
                            </FormField>

                            <FormField
                                label="Your Message"
                                required
                                error={
                                    errors.message
                                }
                            >
                                <textarea
                                    rows={5}
                                    value={
                                        data.message
                                    }
                                    onChange={(
                                        event,
                                    ) =>
                                        setData(
                                            "message",
                                            event
                                                .target
                                                .value,
                                        )
                                    }
                                    placeholder="How can we assist you today?"
                                    disabled={
                                        preview
                                    }
                                    className={`${inputClass} h-auto min-h-[140px] resize-y py-3`}
                                />
                            </FormField>

                            <button
                                type="submit"
                                disabled={
                                    processing ||
                                    preview
                                }
                                className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-8 py-3.5 font-semibold text-white shadow-lg transition-all duration-200 hover:-translate-y-0.5 disabled:pointer-events-none disabled:opacity-50"
                                style={{
                                    backgroundColor:
                                        accentColor,

                                    boxShadow:
                                        `0 10px 25px ${accentColor}30`,
                                }}
                            >
                                <Send className="size-4" />

                                {processing
                                    ? "Sending..."
                                    : submitText}
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </section>
    );
}

type FormFieldProps = {
    label: string;
    required?: boolean;
    error?: string;
    children: ReactNode;
};

function FormField({
    label,
    required = false,
    error,
    children,
}: FormFieldProps) {
    return (
        <div>
            <label className="mb-2 block text-xs font-semibold uppercase tracking-wider text-slate-700">
                {label}

                {required && (
                    <span className="ml-1 text-red-500">
                        *
                    </span>
                )}
            </label>

            {children}

            {error && (
                <p className="mt-1.5 text-xs text-red-600">
                    {error}
                </p>
            )}
        </div>
    );
}

const inputClass =
    "w-full rounded-xl border border-slate-200 bg-slate-50/50 px-4 py-3 text-sm outline-none transition focus:border-transparent focus:bg-white focus:ring-2 focus:ring-emerald-600 disabled:cursor-default disabled:opacity-70";
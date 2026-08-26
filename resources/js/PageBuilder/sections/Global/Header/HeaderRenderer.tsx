import {
    ChevronDown,
    Menu,
    Phone,
    Search,
    ShoppingCart,
    User,
    X,
} from "lucide-react";

import {
    useState,
} from "react";

import {
    getBuilderIcon,
} from "../../../utils/icons";

import type {
    SectionSettings,
} from "../../../types";

type SubmenuItem = {
    label?: string;
    url?: string;

    show_icon?: boolean;
    icon?: string;

    description?: string;
};

type MenuItem = {
    label?: string;
    url?: string;

    show_icon?: boolean;
    icon?: string;

    children?: SubmenuItem[];
};

type SimpleLink = {
    label?: string;
    url?: string;
};

type Props = {
    settings: SectionSettings;

    preview?: boolean;

    variant:
        | "header-01"
        | "header-02"
        | "header-03"
        | "header-04"
        | "header-05";
};

export default function HeaderRenderer({
    settings,
    preview = false,
    variant,
}: Props) {
    const [
        mobileOpen,
        setMobileOpen,
    ] = useState(false);

    const menuItems =
        (settings.menu_items as
            | MenuItem[]
            | undefined) ?? [];

    const brandName = String(
        settings.brand_name ??
            " ",
    );

    const brandShort = String(
        settings.brand_short ??
            "C",
    );

    const brandSubtitle = String(
        settings.brand_subtitle ??
            "",
    );

    const brandUrl = String(
        settings.brand_url ??
            "/",
    );

    const logoType = String(
        settings.logo_type ??
            "text",
    );

    const logoImage = String(
        settings.logo_image ??
            "",
    );

    const backgroundColor =
        String(
            settings.background_color ??
                "#ffffff",
        );

    const textColor = String(
        settings.text_color ??
            "#334155",
    );

    const accentColor = String(
        settings.accent_color ??
            "#2563eb",
    );

    const submenuBackground =
        String(
            settings.submenu_background ??
                "#ffffff",
        );

    const submenuTextColor =
        String(
            settings.submenu_text_color ??
                "#334155",
        );

    const sticky = Boolean(
        settings.sticky ?? true,
    );

    const showCta = Boolean(
        settings.show_cta ?? true,
    );

    const ctaText = String(
        settings.cta_text ??
            "Get Started",
    );

    const ctaUrl = String(
        settings.cta_url ??
            "#",
    );

    const showPhone = Boolean(
        settings.show_phone ??
            false,
    );

    const phoneLabel = String(
        settings.phone_label ??
            "",
    );

    const phoneNumber = String(
        settings.phone_number ??
            "",
    );

    const linkProps = (
        url?: string,
    ) => ({
        href:
            preview
                ? undefined
                : url || "#",

        onClick: preview
            ? (
                  event: React.MouseEvent,
              ) =>
                  event.preventDefault()
            : undefined,
    });

    const brand = (
        <a
            {...linkProps(
                brandUrl,
            )}
            className="flex shrink-0 items-center gap-3"
        >
            {logoType ===
                "image" &&
            logoImage ? (
                <img
                    src={logoImage}
                    alt={brandName}
                    className="h-11 w-auto object-contain"
                />
            ) : (
                <div
                    className="flex size-10 items-center justify-center rounded-xl text-sm font-black text-white"
                    style={{
                        backgroundColor:
                            accentColor,
                    }}
                >
                    {brandShort}
                </div>
            )}

            <div>
                <div className="font-black leading-none">
                    {brandName}
                </div>

                {brandSubtitle && (
                    <div
                        className="mt-1 text-[10px] font-bold uppercase tracking-widest"
                        style={{
                            color:
                                accentColor,
                        }}
                    >
                        {brandSubtitle}
                    </div>
                )}
            </div>
        </a>
    );

    const desktopMenu = (
        <nav className="hidden items-center gap-1 lg:flex">
            {menuItems.map(
                (
                    item,
                    index,
                ) => (
                    <DesktopMenuItem
                        key={index}
                        item={item}
                        preview={
                            preview
                        }
                        accentColor={
                            accentColor
                        }
                        textColor={
                            textColor
                        }
                        submenuBackground={
                            submenuBackground
                        }
                        submenuTextColor={
                            submenuTextColor
                        }
                        mega={
                            variant ===
                            "header-05"
                        }
                    />
                ),
            )}
        </nav>
    );

    /*
     * Variant 1
     */
    if (
        variant ===
        "header-01"
    ) {
        const showTopBar =
            Boolean(
                settings.show_top_bar ??
                    true,
            );

        const topLinks =
            (settings.top_links as
                | SimpleLink[]
                | undefined) ?? [];

        return (
            <header
                className={`z-50 border-b shadow-sm ${
                    sticky
                        ? "sticky top-0"
                        : ""
                }`}
                style={{
                    backgroundColor,
                    color: textColor,
                }}
            >
                {showTopBar && (
                    <div className="bg-slate-900 px-6 py-2 text-xs text-slate-300">
                        <div className="mx-auto flex max-w-7xl justify-between gap-4">
                            <div className="flex gap-6">
                                <span>
                                    {String(
                                        settings.top_phone ??
                                            "",
                                    )}
                                </span>

                                <span className="hidden md:inline">
                                    {String(
                                        settings.top_email ??
                                            "",
                                    )}
                                </span>
                            </div>

                            <div className="hidden gap-4 md:flex">
                                {topLinks.map(
                                    (
                                        item,
                                        index,
                                    ) => (
                                        <a
                                            key={
                                                index
                                            }
                                            {...linkProps(
                                                item.url,
                                            )}
                                            className="hover:text-white"
                                        >
                                            {
                                                item.label
                                            }
                                        </a>
                                    ),
                                )}
                            </div>
                        </div>
                    </div>
                )}

                <div className="mx-auto flex max-w-7xl items-center justify-between gap-8 px-6 py-4">
                    {brand}

                    {desktopMenu}

                    <HeaderActions
                        {...{
                            preview,
                            showCta,
                            ctaText,
                            ctaUrl,
                            showPhone,
                            phoneLabel,
                            phoneNumber,
                            accentColor,
                            mobileOpen,
                            setMobileOpen,
                        }}
                    />
                </div>

                <MobileMenu
                    {...{
                        menuItems,
                        mobileOpen,
                        preview,
                        accentColor,
                    }}
                />
            </header>
        );
    }

    /*
     * Variant 2
     */
    if (
        variant ===
        "header-02"
    ) {
        return (
            <header
                className={`z-50 bg-gray-950 px-4 py-5 ${
                    sticky
                        ? "sticky top-0"
                        : ""
                }`}
            >
                <div className="mx-auto flex max-w-6xl items-center justify-between gap-6 rounded-full border border-white/10 bg-gray-900/90 px-6 py-3 text-gray-300 shadow-2xl backdrop-blur">
                    {brand}

                    {desktopMenu}

                    <HeaderActions
                        {...{
                            preview,
                            showCta,
                            ctaText,
                            ctaUrl,
                            showPhone,
                            phoneLabel,
                            phoneNumber,
                            accentColor,
                            mobileOpen,
                            setMobileOpen,
                        }}
                    />
                </div>

                <MobileMenu
                    {...{
                        menuItems,
                        mobileOpen,
                        preview,
                        accentColor,
                    }}
                    dark
                />
            </header>
        );
    }

    /*
     * Variant 3
     */
    if (
        variant ===
        "header-03"
    ) {
        const categories =
            (settings.category_links as
                | SimpleLink[]
                | undefined) ?? [];

        return (
            <header
                className={`z-50 border-b bg-white ${
                    sticky
                        ? "sticky top-0"
                        : ""
                }`}
            >
                <div className="mx-auto flex max-w-7xl items-center gap-6 px-6 py-4">
                    {brand}

                    <div className="hidden flex-1 sm:block">
                        <div className="relative mx-auto max-w-xl">
                            <input
                                type="search"
                                placeholder={String(
                                    settings.search_placeholder ??
                                        "Search...",
                                )}
                                className="w-full rounded-full border bg-slate-50 py-2.5 pl-4 pr-11 text-sm outline-none focus:bg-white"
                            />

                            <Search className="absolute right-4 top-3 size-4 text-slate-400" />
                        </div>
                    </div>

                    <div className="flex items-center gap-5">
                        <User className="size-5" />

                        <ShoppingCart className="size-5" />

                        <button
                            type="button"
                            className="lg:hidden"
                            onClick={() =>
                                setMobileOpen(
                                    !mobileOpen,
                                )
                            }
                        >
                            <Menu className="size-6" />
                        </button>
                    </div>
                </div>

                <div className="border-t bg-slate-50">
                    <div className="mx-auto flex max-w-7xl gap-8 overflow-x-auto px-6 py-3 text-xs font-semibold">
                        {categories.map(
                            (
                                item,
                                index,
                            ) => (
                                <a
                                    key={
                                        index
                                    }
                                    {...linkProps(
                                        item.url,
                                    )}
                                    className="whitespace-nowrap hover:opacity-70"
                                    style={{
                                        color:
                                            index ===
                                            0
                                                ? accentColor
                                                : textColor,
                                    }}
                                >
                                    {
                                        item.label
                                    }
                                </a>
                            ),
                        )}
                    </div>
                </div>
            </header>
        );
    }

    /*
     * Variant 4
     */
    if (
        variant ===
        "header-04"
    ) {
        return (
            <header
                className={`z-50 border-b shadow-xl ${
                    sticky
                        ? "sticky top-0"
                        : ""
                }`}
                style={{
                    background:
                        `linear-gradient(90deg, ${backgroundColor}, ${accentColor}55, ${backgroundColor})`,

                    color: textColor,
                }}
            >
                <div className="mx-auto flex h-20 max-w-7xl items-center justify-between gap-5 px-6">
                    {brand}

                    {desktopMenu}

                    <HeaderActions
                        {...{
                            preview,
                            showCta,
                            ctaText,
                            ctaUrl,
                            showPhone,
                            phoneLabel,
                            phoneNumber,
                            accentColor,
                            mobileOpen,
                            setMobileOpen,
                        }}
                    />
                </div>

                <MobileMenu
                    {...{
                        menuItems,
                        mobileOpen,
                        preview,
                        accentColor,
                    }}
                    dark
                />
            </header>
        );
    }

    /*
     * Variant 5
     */
    const topLinks =
        (settings.top_links as
            | SimpleLink[]
            | undefined) ?? [];

    return (
        <header
            className={`z-50 border-b bg-white shadow-lg ${
                sticky
                    ? "sticky top-0"
                    : ""
            }`}
        >
            {Boolean(
                settings.show_top_bar ??
                    true,
            ) && (
                <div className="bg-slate-900 px-6 py-2.5 text-xs text-slate-300">
                    <div className="mx-auto flex max-w-7xl items-center justify-between">
                        <div className="flex gap-6">
                            <span>
                                {String(
                                    settings.top_left_text ??
                                        "",
                                )}
                            </span>

                            <span className="hidden md:inline">
                                {String(
                                    settings.top_support_text ??
                                        "",
                                )}
                            </span>
                        </div>

                        <div className="hidden gap-5 md:flex">
                            {topLinks.map(
                                (
                                    item,
                                    index,
                                ) => (
                                    <a
                                        key={
                                            index
                                        }
                                        {...linkProps(
                                            item.url,
                                        )}
                                        className="hover:text-white"
                                    >
                                        {
                                            item.label
                                        }
                                    </a>
                                ),
                            )}
                        </div>
                    </div>
                </div>
            )}

            <div className="mx-auto flex max-w-7xl items-center justify-between gap-6 px-6 py-4">
                {brand}

                {desktopMenu}

                <HeaderActions
                    {...{
                        preview,
                        showCta,
                        ctaText,
                        ctaUrl,
                        showPhone,
                        phoneLabel,
                        phoneNumber,
                        accentColor,
                        mobileOpen,
                        setMobileOpen,
                    }}
                />
            </div>

            <MobileMenu
                {...{
                    menuItems,
                    mobileOpen,
                    preview,
                    accentColor,
                }}
            />
        </header>
    );
}

function DesktopMenuItem({
    item,
    preview,
    accentColor,
    textColor,
    submenuBackground,
    submenuTextColor,
    mega,
}: any) {
    const children =
        item.children ?? [];

    const MainIcon =
        getBuilderIcon(
            item.icon ??
                "circle",
        );

    const showMainIcon =
        Boolean(
            item.show_icon ??
                false,
        );

    if (!children.length) {
        return (
            <a
                href={
                    preview
                        ? undefined
                        : item.url || "#"
                }
                onClick={
                    preview
                        ? (event) =>
                              event.preventDefault()
                        : undefined
                }
                className="rounded-lg px-4 py-2 text-sm font-semibold transition hover:bg-black/5"
                style={{
                    color: textColor,
                }}
            >
                <span className="flex items-center gap-2">
                    {showMainIcon && (
                        <MainIcon className="size-4 shrink-0" />
                    )}

                    <span>
                        {item.label}
                    </span>
                </span>
            </a>
        );
    }

    return (
        <div className="group relative py-3">
            <a
                href={
                    preview
                        ? undefined
                        : item.url || "#"
                }
                onClick={
                    preview
                        ? (event) =>
                            event.preventDefault()
                        : undefined
                }
                className="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold"
                style={{
                    color:
                        textColor,
                }}
            >
                {showMainIcon && (
                    <MainIcon className="size-4 shrink-0" />
                )}

                <span>
                    {item.label}
                </span>

                <ChevronDown className="size-3.5 transition group-hover:rotate-180" />
            </a>

            <div
                className={`absolute left-0 top-full z-50 hidden rounded-2xl border p-3 shadow-2xl group-hover:block ${
                    mega
                        ? "w-[520px]"
                        : "w-64"
                }`}
                style={{
                    backgroundColor:
                        submenuBackground,

                    color:
                        submenuTextColor,
                }}
            >
                <div
                    className={
                        mega
                            ? "grid grid-cols-2 gap-2"
                            : "space-y-1"
                    }
                >
                    {children.map(
                        (
                            child: SubmenuItem,
                            index: number,
                        ) => {
                            const Icon =
                                getBuilderIcon(
                                    child.icon ??
                                        "circle",
                                );

                            const showIcon =
                                Boolean(
                                    child.show_icon ??
                                        false,
                                );

                            return (
                                <a
                                    key={
                                        index
                                    }
                                    href={
                                        preview
                                            ? undefined
                                            : child.url ||
                                              "#"
                                    }
                                    onClick={
                                        preview
                                            ? (
                                                  event,
                                              ) =>
                                                  event.preventDefault()
                                            : undefined
                                    }
                                    className="flex items-start gap-3 rounded-xl p-3 transition hover:bg-black/5"
                                >
                                   {showIcon && (
                                        <div
                                            className="flex size-9 shrink-0 items-center justify-center rounded-lg"
                                            style={{
                                                color:
                                                    accentColor,

                                                backgroundColor:
                                                    `${accentColor}12`,
                                            }}
                                        >
                                            <Icon className="size-4" />
                                        </div>
                                    )}

                                    <div>
                                        <div className="text-sm font-bold">
                                            {
                                                child.label
                                            }
                                        </div>

                                        {mega &&
                                            child.description && (
                                                <div className="mt-0.5 text-xs opacity-60">
                                                    {
                                                        child.description
                                                    }
                                                </div>
                                            )}
                                    </div>
                                </a>
                            );
                        },
                    )}
                </div>
            </div>
        </div>
    );
}

function HeaderActions({
    preview,
    showCta,
    ctaText,
    ctaUrl,
    showPhone,
    phoneLabel,
    phoneNumber,
    accentColor,
    mobileOpen,
    setMobileOpen,
}: any) {
    return (
        <div className="flex items-center gap-3">
            {showPhone && (
                <a
                    href={
                        preview
                            ? undefined
                            : `tel:${phoneNumber}`
                    }
                    onClick={
                        preview
                            ? (event) =>
                                  event.preventDefault()
                            : undefined
                    }
                    className="hidden items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-bold text-white sm:inline-flex"
                    style={{
                        backgroundColor:
                            accentColor,
                    }}
                >
                    <Phone className="size-4" />

                    {phoneLabel}
                </a>
            )}

            {showCta && (
                <a
                    href={
                        preview
                            ? undefined
                            : ctaUrl
                    }
                    onClick={
                        preview
                            ? (event) =>
                                  event.preventDefault()
                            : undefined
                    }
                    className="hidden rounded-xl px-5 py-2.5 text-sm font-bold text-white lg:inline-block"
                    style={{
                        backgroundColor:
                            accentColor,
                    }}
                >
                    {ctaText}
                </a>
            )}

            <button
                type="button"
                className="rounded-lg p-2 lg:hidden"
                onClick={() =>
                    setMobileOpen(
                        !mobileOpen,
                    )
                }
            >
                {mobileOpen ? (
                    <X className="size-6" />
                ) : (
                    <Menu className="size-6" />
                )}
            </button>
        </div>
    );
}

function MobileMenu({
    menuItems,
    mobileOpen,
    preview,
    accentColor,
    dark = false,
}: any) {
    if (!mobileOpen) {
        return null;
    }

    return (
        <div
            className={`border-t px-5 py-4 lg:hidden ${
                dark
                    ? "border-white/10 bg-slate-950 text-white"
                    : "bg-white"
            }`}
        >
            <div className="space-y-2">
                {menuItems.map(
                    (
                        item: MenuItem,
                        index: number,
                    ) => (
                        <MobileItem
                            key={index}
                            item={item}
                            preview={
                                preview
                            }
                            accentColor={
                                accentColor
                            }
                        />
                    ),
                )}
            </div>
        </div>
    );
}

function MobileItem({
    item,
    preview,
    accentColor,
}: any) {
    const [open, setOpen] =
        useState(false);

    const children =
        item.children ?? [];

    const MainIcon =
        getBuilderIcon(
            item.icon ??
                "circle",
        );

    const showMainIcon =
        Boolean(
            item.show_icon ??
                false,
        );

    if (!children.length) {
        return (
            <a
                href={
                    preview
                        ? undefined
                        : item.url || "#"
                }
                onClick={
                    preview
                        ? (event) =>
                              event.preventDefault()
                        : undefined
                }
                className="block rounded-xl px-4 py-3 font-semibold hover:bg-black/5"
            >
                <span className="flex items-center gap-2">
                    {showMainIcon && (
                        <MainIcon className="size-4 shrink-0" />
                    )}

                    <span>
                        {item.label}
                    </span>
                </span>
            </a>
        );
    }

    return (
        <div>
            <button
                type="button"
                onClick={() =>
                    setOpen(!open)
                }
                className="flex w-full items-center justify-between rounded-xl px-4 py-3 font-semibold"
            >
                <span className="flex items-center gap-2">
                    {showMainIcon && (
                        <MainIcon className="size-4 shrink-0" />
                    )}

                    <span>
                        {item.label}
                    </span>
                </span>

                <ChevronDown
                    className={`size-4 transition ${
                        open
                            ? "rotate-180"
                            : ""
                    }`}
                />
            </button>

            {open && (
                <div className="ml-5 space-y-1 border-l pl-3">
                    {children.map(
                        (
                            child: SubmenuItem,
                            index: number,
                        ) => {
                            const ChildIcon =
                                getBuilderIcon(
                                    child.icon ??
                                        "circle",
                                );

                            const showChildIcon =
                                Boolean(
                                    child.show_icon ??
                                        false,
                                );

                            return (
                                <a
                                    key={index}
                                    href={
                                        preview
                                            ? undefined
                                            : child.url ||
                                              "#"
                                    }
                                    onClick={
                                        preview
                                            ? (
                                                  event,
                                              ) =>
                                                  event.preventDefault()
                                            : undefined
                                    }
                                    className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm"
                                    style={{
                                        color:
                                            accentColor,
                                    }}
                                >
                                    {showChildIcon && (
                                        <ChildIcon className="size-4 shrink-0" />
                                    )}

                                    <span>
                                        {
                                            child.label
                                        }
                                    </span>
                                </a>
                            );
                        },
                    )}
                </div>
            )}
        </div>
    );
}
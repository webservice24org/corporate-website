import {
    Link,
    usePage,
} from "@inertiajs/react";

import {
    useAuthorization,
} from "@/hooks/useAuthorization";

import {
    ChevronDown,
    FileText,
    LayoutDashboard,
    LogOut,
    Mail,
    Mailbox,
    PanelBottom,
    PanelTop,
    Settings,
    Users,
    X,
    MessageSquareText,
    PhoneCall,
    ShieldCheck,
} from "lucide-react";

import {
    useEffect,
    useState,
} from "react";

type SidebarProps = {
    mobileOpen?: boolean;

    onMobileClose?: () => void;
};

export default function AdminSidebar({
    mobileOpen = false,
    onMobileClose,
}: SidebarProps) {
    const {
        url,
    } = usePage();

    /*
     * Authorization
     */
    const {
        can,
        canAny,
    } = useAuthorization();

    /*
     * Does this user have access to
     * at least one Settings item?
     */
    const canSeeSettings =
        canAny([
            "mail-settings.view",
            "global-header.view",
            "global-footer.view",
            "floating-contact.view",
        ]);

    /*
     * Settings is considered active only
     * if the user actually has permission
     * to see the Settings group.
     */
    const settingsActive =
        canSeeSettings &&
        (
            url.startsWith(
                "/admin/mail-settings",
            ) ||
            url.startsWith(
                "/admin/global/header",
            ) ||
            url.startsWith(
                "/admin/global/footer",
            ) ||
            url.startsWith(
                "/admin/global/floating-contact",
            )
        );

    const [
        settingsOpen,
        setSettingsOpen,
    ] = useState(
        settingsActive,
    );

    /*
     * Automatically open Settings
     * when visiting one of its pages.
     */
    useEffect(() => {
        if (
            settingsActive
        ) {
            setSettingsOpen(
                true,
            );
        }
    }, [
        settingsActive,
    ]);

    /*
     * If permissions change and the
     * user no longer has Settings access,
     * close the submenu.
     */
    useEffect(() => {
        if (
            !canSeeSettings
        ) {
            setSettingsOpen(
                false,
            );
        }
    }, [
        canSeeSettings,
    ]);

    const closeMobile = () => {
        onMobileClose?.();
    };

    return (
        <>
            {/* Mobile backdrop */}
            {mobileOpen && (
                <button
                    type="button"
                    aria-label="Close sidebar"
                    onClick={
                        closeMobile
                    }
                    className="fixed inset-0 z-40 bg-black/50 lg:hidden"
                />
            )}

            <aside
                className={`
                    fixed inset-y-0 left-0 z-50
                    flex w-72 flex-col
                    border-r border-slate-800
                    bg-slate-950 text-slate-300
                    transition-transform duration-300
                    lg:static lg:translate-x-0
                    ${
                        mobileOpen
                            ? "translate-x-0"
                            : "-translate-x-full"
                    }
                `}
            >
                {/* Logo / Admin Brand */}
                <div className="flex h-16 items-center justify-between border-b border-slate-800 px-5">
                    <Link
                        href="/admin/dashboard"
                        onClick={
                            closeMobile
                        }
                        className="flex items-center gap-3"
                    >
                        <div className="flex size-9 items-center justify-center rounded-lg bg-emerald-600 font-bold text-white shadow-lg shadow-emerald-600/20">
                            C
                        </div>

                        <div>
                            <div className="font-bold leading-none text-white">
                                Corporate
                            </div>

                            <div className="mt-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-500">
                                Administration
                            </div>
                        </div>
                    </Link>

                    <button
                        type="button"
                        aria-label="Close sidebar"
                        onClick={
                            closeMobile
                        }
                        className="rounded-md p-2 text-slate-400 hover:bg-slate-800 hover:text-white lg:hidden"
                    >
                        <X className="size-5" />
                    </button>
                </div>

                {/* Navigation */}
                <div className="flex-1 overflow-y-auto px-3 py-5">
                    <div className="mb-3 px-3 text-[10px] font-bold uppercase tracking-[0.2em] text-slate-600">
                        Main
                    </div>

                    <nav className="space-y-1">
                        {/* Dashboard */}
                        {can(
                            "dashboard.view",
                        ) && (
                            <SidebarLink
                                href="/admin/dashboard"
                                label="Dashboard"
                                icon={
                                    LayoutDashboard
                                }
                                currentUrl={
                                    url
                                }
                                exact
                                onClick={
                                    closeMobile
                                }
                            />
                        )}

                        {/* Pages */}
                        {can(
                            "pages.view",
                        ) && (
                            <SidebarLink
                                href="/admin/pages"
                                label="Pages"
                                icon={
                                    FileText
                                }
                                currentUrl={
                                    url
                                }
                                onClick={
                                    closeMobile
                                }
                            />
                        )}

                        {/* Contact Messages */}
                        {can(
                            "contact-messages.view",
                        ) && (
                            <SidebarLink
                                href="/admin/contact-messages"
                                label="Contact Messages"
                                icon={
                                    MessageSquareText
                                }
                                currentUrl={
                                    url
                                }
                                onClick={
                                    closeMobile
                                }
                            />
                        )}

                        {/* Newsletter */}
                        {can(
                            "newsletter.view",
                        ) && (
                            <SidebarLink
                                href="/admin/newsletter-subscribers"
                                label="Newsletter"
                                icon={
                                    Mailbox
                                }
                                currentUrl={
                                    url
                                }
                                onClick={
                                    closeMobile
                                }
                            />
                        )}

                        {/* Users */}
                        {can(
                            "users.view",
                        ) && (
                            <SidebarLink
                                href="/admin/users"
                                label="Users"
                                icon={
                                    Users
                                }
                                currentUrl={
                                    url
                                }
                                onClick={
                                    closeMobile
                                }
                            />
                        )}

                        {/* Roles & Permissions */}
                        {can(
                            "roles.view",
                        ) && (
                            <SidebarLink
                                href="/admin/roles"
                                label="Roles & Permissions"
                                icon={
                                    ShieldCheck
                                }
                                currentUrl={
                                    url
                                }
                                onClick={
                                    closeMobile
                                }
                            />
                        )}
                    </nav>

                    {/* Configuration */}
                    {canSeeSettings && (
                        <>
                            <div className="mb-3 mt-7 px-3 text-[10px] font-bold uppercase tracking-[0.2em] text-slate-600">
                                Configuration
                            </div>

                            {/* Settings group */}
                            <div>
                                <button
                                    type="button"
                                    onClick={() =>
                                        setSettingsOpen(
                                            (
                                                current,
                                            ) =>
                                                !current,
                                        )
                                    }
                                    className={`
                                        flex w-full items-center gap-3
                                        rounded-lg px-3 py-2.5
                                        text-sm font-medium
                                        transition
                                        ${
                                            settingsActive
                                                ? "bg-slate-800 text-white"
                                                : "text-slate-400 hover:bg-slate-900 hover:text-white"
                                        }
                                    `}
                                >
                                    <Settings className="size-5 shrink-0" />

                                    <span className="flex-1 text-left">
                                        Settings
                                    </span>

                                    <ChevronDown
                                        className={`
                                            size-4
                                            transition-transform
                                            duration-200
                                            ${
                                                settingsOpen
                                                    ? "rotate-180"
                                                    : ""
                                            }
                                        `}
                                    />
                                </button>

                                {settingsOpen && (
                                    <div className="ml-5 mt-1 border-l border-slate-800 pl-3">
                                        {/* Mail */}
                                        {can(
                                            "mail-settings.view",
                                        ) && (
                                            <SidebarSubLink
                                                href="/admin/mail-settings"
                                                label="Mail Configuration"
                                                icon={
                                                    Mail
                                                }
                                                currentUrl={
                                                    url
                                                }
                                                onClick={
                                                    closeMobile
                                                }
                                            />
                                        )}

                                        {/* Header */}
                                        {can(
                                            "global-header.view",
                                        ) && (
                                            <SidebarSubLink
                                                href="/admin/global/header"
                                                label="Header"
                                                icon={
                                                    PanelTop
                                                }
                                                currentUrl={
                                                    url
                                                }
                                                onClick={
                                                    closeMobile
                                                }
                                            />
                                        )}

                                        {/* Footer */}
                                        {can(
                                            "global-footer.view",
                                        ) && (
                                            <SidebarSubLink
                                                href="/admin/global/footer"
                                                label="Footer"
                                                icon={
                                                    PanelBottom
                                                }
                                                currentUrl={
                                                    url
                                                }
                                                onClick={
                                                    closeMobile
                                                }
                                            />
                                        )}

                                        {/* Floating Contact */}
                                        {can(
                                            "floating-contact.view",
                                        ) && (
                                            <SidebarSubLink
                                                href="/admin/global/floating-contact"
                                                label="Floating Contact"
                                                icon={
                                                    PhoneCall
                                                }
                                                currentUrl={
                                                    url
                                                }
                                                onClick={
                                                    closeMobile
                                                }
                                            />
                                        )}
                                    </div>
                                )}
                            </div>
                        </>
                    )}
                </div>

                {/* Bottom user / logout */}
                <div className="border-t border-slate-800 p-3">
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        className="
                            flex w-full items-center
                            gap-3 rounded-lg
                            px-3 py-2.5
                            text-left text-sm
                            font-medium text-slate-400
                            transition
                            hover:bg-red-500/10
                            hover:text-red-400
                        "
                    >
                        <LogOut className="size-5" />

                        <span>
                            Logout
                        </span>
                    </Link>
                </div>
            </aside>
        </>
    );
}


/*
|--------------------------------------------------------------------------
| Navigation Icon Type
|--------------------------------------------------------------------------
*/

type NavIcon =
    React.ComponentType<{
        className?: string;
    }>;


/*
|--------------------------------------------------------------------------
| Main Sidebar Link
|--------------------------------------------------------------------------
*/

type SidebarLinkProps = {
    href: string;

    label: string;

    icon: NavIcon;

    currentUrl: string;

    exact?: boolean;

    onClick?: () => void;
};

function SidebarLink({
    href,
    label,
    icon: Icon,
    currentUrl,
    exact = false,
    onClick,
}: SidebarLinkProps) {
    const active =
        exact
            ? currentUrl === href
            : currentUrl.startsWith(
                  href,
              );

    return (
        <Link
            href={
                href
            }
            onClick={
                onClick
            }
            className={`
                relative flex items-center
                gap-3 rounded-lg
                px-3 py-2.5
                text-sm font-medium
                transition-all
                ${
                    active
                        ? "bg-emerald-600 text-white shadow-lg shadow-emerald-950/20"
                        : "text-slate-400 hover:bg-slate-900 hover:text-white"
                }
            `}
        >
            <Icon className="size-5 shrink-0" />

            <span>
                {label}
            </span>

            {active && (
                <span className="absolute right-2 size-1.5 rounded-full bg-white/70" />
            )}
        </Link>
    );
}


/*
|--------------------------------------------------------------------------
| Sidebar Sub Link
|--------------------------------------------------------------------------
*/

type SidebarSubLinkProps = {
    href: string;

    label: string;

    icon: NavIcon;

    currentUrl: string;

    onClick?: () => void;
};

function SidebarSubLink({
    href,
    label,
    icon: Icon,
    currentUrl,
    onClick,
}: SidebarSubLinkProps) {
    const active =
        currentUrl.startsWith(
            href,
        );

    return (
        <Link
            href={
                href
            }
            onClick={
                onClick
            }
            className={`
                flex items-center gap-2.5
                rounded-lg px-3 py-2
                text-sm transition
                ${
                    active
                        ? "bg-slate-800 font-medium text-emerald-400"
                        : "text-slate-500 hover:bg-slate-900 hover:text-slate-200"
                }
            `}
        >
            <Icon className="size-4 shrink-0" />

            <span>
                {label}
            </span>
        </Link>
    );
}
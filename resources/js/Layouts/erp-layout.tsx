import AccountingLayout from "@/Layouts/accounting-layout";
import type {
    PropsWithChildren,
} from "react";

import {
    useState,
} from "react";

import {
    Link,
    usePage,
} from "@inertiajs/react";

import {
    Menu,
    User,
    PanelsTopLeft,
} from "lucide-react";

import FlashMessages from "@/Components/FlashMessages";
import ERPSidebar from "@/Components/ERP/ERPSidebar";
import NotificationBell from "@/Components/ERP/NotificationBell";

import type {
    PageProps,
} from "@/types";

export default function ERPLayout({
    children,
}: PropsWithChildren) {
    const [
        mobileSidebarOpen,
        setMobileSidebarOpen,
    ] = useState(false);

    const page =
        usePage<PageProps>();

    const user =
        page.props.auth?.user;

    if (page.component.startsWith("ERP/Accounting/")) {
        return <AccountingLayout>{children}</AccountingLayout>;
    }

    return (
        <div className="erp-light-theme min-h-screen bg-slate-100 text-slate-900">
            <FlashMessages />

            <div className="flex min-h-screen">
                <ERPSidebar
                    mobileOpen={
                        mobileSidebarOpen
                    }
                    onMobileClose={() =>
                        setMobileSidebarOpen(
                            false,
                        )
                    }
                />

                <div className="min-w-0 flex-1">
                    {/* Top bar */}
                    <header className="sticky top-0 z-30 flex h-16 items-center justify-between border-b bg-white/95 px-4 shadow-sm backdrop-blur sm:px-6 lg:px-8">
                        <div className="flex min-w-0 items-center gap-3">
                            <button
                                type="button"
                                onClick={() =>
                                    setMobileSidebarOpen(
                                        true,
                                    )
                                }
                                className="rounded-lg border p-2 text-slate-600 transition hover:bg-slate-50 lg:hidden"
                            >
                                <Menu className="size-5" />
                            </button>

                            <div className="flex min-w-0 items-center gap-3">
                                <div className="hidden size-9 shrink-0 items-center justify-center rounded-lg bg-indigo-100 text-indigo-700 sm:flex">
                                    <PanelsTopLeft className="size-5" />
                                </div>

                                <div className="min-w-0">
                                    <h1 className="truncate text-sm font-semibold text-slate-900 sm:text-base">
                                        Corporate ERP
                                    </h1>

                                    <p className="hidden text-xs text-slate-500 sm:block">
                                        Enterprise Resource Planning
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div className="flex shrink-0 items-center gap-2 sm:gap-3">
                            {/* CMS switcher */}
                            <Link
                                href="/admin/dashboard"
                                className="
                                    hidden rounded-lg border
                                    border-slate-200 bg-white
                                    px-3 py-2 text-sm
                                    font-medium text-slate-600
                                    transition
                                    hover:bg-slate-50
                                    hover:text-slate-950
                                    md:inline-flex
                                "
                            >
                                CMS Admin
                            </Link>
                            <NotificationBell />

                            {/* Logged-in user */}
                            <div className="hidden text-right sm:block">
                                <div className="text-sm font-semibold text-slate-800">
                                    {user?.name ??
                                        "Administrator"}
                                </div>

                                <div className="text-xs text-slate-500">
                                    {user?.email ??
                                        ""}
                                </div>
                            </div>

                            <Link
                                href="/profile"
                                className="flex size-9 items-center justify-center rounded-full bg-slate-100 text-slate-600 transition hover:bg-slate-200"
                            >
                                <User className="size-4" />
                            </Link>
                        </div>
                    </header>

                    {/* Main content */}
                    <main className="p-4 sm:p-6 lg:p-8">
                        {children}
                    </main>
                </div>
            </div>
            
        </div>
    );
}
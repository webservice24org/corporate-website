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
} from "lucide-react";

import FlashMessages from "@/Components/FlashMessages";

import AdminSidebar from "@/Components/Admin/AdminSidebar";

import type {
    PageProps,
} from "@/types";

export default function AdminLayout({
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

    return (
        <div className="min-h-screen bg-slate-100">
            <FlashMessages />

            <div className="flex min-h-screen">
                <AdminSidebar
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
                        <div className="flex items-center gap-3">
                            <button
                                type="button"
                                onClick={() =>
                                    setMobileSidebarOpen(
                                        true,
                                    )
                                }
                                className="rounded-lg border p-2 text-slate-600 hover:bg-slate-50 lg:hidden"
                            >
                                <Menu className="size-5" />
                            </button>

                            <div>
                                <h1 className="text-sm font-semibold text-slate-900 sm:text-base">
                                    Corporate
                                    Website
                                    Admin
                                </h1>

                                <p className="hidden text-xs text-slate-500 sm:block">
                                    Manage
                                    website
                                    content and
                                    settings
                                </p>
                            </div>
                        </div>

                        {/* Logged-in user */}
                        <div className="flex items-center gap-3">
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

                    {/* Main Content */}
                    <main className="p-4 sm:p-6 lg:p-8">
                        {children}
                    </main>
                </div>
            </div>
        </div>
    );
}
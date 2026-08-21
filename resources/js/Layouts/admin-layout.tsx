// resources/js/layouts/admin-layout.tsx

import { Link } from "@inertiajs/react";
import { PropsWithChildren } from "react";

export default function AdminLayout({
    children,
}: PropsWithChildren) {
    return (
        <div className="min-h-screen bg-slate-100">
            <div className="flex min-h-screen">
                <aside className="w-64 bg-slate-900 text-white">
                    <div className="border-b border-slate-800 px-6 py-5">
                        <h2 className="text-lg font-bold">
                            Admin Panel
                        </h2>
                    </div>

                    <nav className="space-y-1 p-4">
                        <Link
                            href="/admin/dashboard"
                            className="block rounded-md px-4 py-2 hover:bg-slate-800"
                        >
                            Dashboard
                        </Link>

                        <Link
                            href="/admin/pages"
                            className="block rounded-md px-4 py-2 hover:bg-slate-800"
                        >
                            Pages
                        </Link>
                    </nav>
                </aside>

                <div className="flex-1">
                    <header className="border-b bg-white px-8 py-4">
                        <h1 className="font-semibold">
                            Corporate Website Admin
                        </h1>
                    </header>

                    <main className="p-8">
                        {children}
                    </main>
                </div>
            </div>
        </div>
    );
}
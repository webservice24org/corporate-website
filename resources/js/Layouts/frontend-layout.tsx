// resources/js/layouts/frontend-layout.tsx

import { PropsWithChildren } from "react";

export default function FrontendLayout({
    children,
}: PropsWithChildren) {
    return (
        <div className="min-h-screen bg-white text-slate-900">
            <header className="border-b">
                <div className="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
                    <div className="text-xl font-bold">
                        Corporate Website
                    </div>

                    <nav className="flex gap-6 text-sm">
                        <a href="/">Home</a>
                        <a href="/about">About</a>
                        <a href="/services">Services</a>
                        <a href="/contact">Contact</a>
                    </nav>
                </div>
            </header>

            <main>{children}</main>

            <footer className="mt-16 border-t">
                <div className="mx-auto max-w-7xl px-6 py-8 text-sm text-slate-500">
                    © Corporate Website
                </div>
            </footer>
        </div>
    );
}
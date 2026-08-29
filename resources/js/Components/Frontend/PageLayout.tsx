import { ReactNode } from "react";

export type PageLayoutType =
    | "full-width"
    | "left-sidebar"
    | "right-sidebar";

type Props = {
    layout?: PageLayoutType | null;
    children: ReactNode;
    sidebar?: ReactNode;
};

export default function PageLayout({
    layout = "full-width",
    children,
    sidebar,
}: Props) {
    if (layout === "left-sidebar") {
        return (
            <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
                <div className="grid gap-8 lg:grid-cols-12">
                    {/* Sidebar */}
                    <aside className="order-2 lg:order-1 lg:col-span-3">
                        {sidebar}
                    </aside>

                    {/* Main content */}
                    <main className="order-1 min-w-0 lg:order-2 lg:col-span-9">
                        {children}
                    </main>
                </div>
            </div>
        );
    }

    if (layout === "right-sidebar") {
        return (
            <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
                <div className="grid gap-8 lg:grid-cols-12">
                    {/* Main content */}
                    <main className="min-w-0 lg:col-span-9">
                        {children}
                    </main>

                    {/* Sidebar */}
                    <aside className="lg:col-span-3">
                        {sidebar}
                    </aside>
                </div>
            </div>
        );
    }

    return (
        <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <main className="min-w-0">
                {children}
            </main>
        </div>
    );
}
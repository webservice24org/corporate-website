// resources/js/Layouts/frontend-layout.tsx
import type {
    PageProps,
} from "@/types";
import type {
    PropsWithChildren,
} from "react";

import {
    usePage,
} from "@inertiajs/react";

import FlashMessages from "@/Components/FlashMessages";

import GlobalSectionRenderer from "@/PageBuilder/components/GlobalSectionRenderer";

type GlobalSection = {
    id: number;

    type: string;
    variant: string;

    settings: Record<
        string,
        unknown
    > | null;

    is_active: boolean;
};

type FrontendPageProps = PageProps<{
    globalHeader?:
        | GlobalSection
        | null;
    
    globalFooter:
        | GlobalSection
        | null;

    globalFloatingContact:
        | GlobalSection
        | null;
}>;

export default function FrontendLayout({
    children,
}: PropsWithChildren) {

    const {
        globalHeader,
        globalFooter,
        globalFloatingContact,
        } = usePage<FrontendPageProps>()
        .props;

    return (
        <div className="erp-light-theme flex min-h-screen flex-col bg-white text-slate-900">
            <FlashMessages />

            <GlobalSectionRenderer
                section={
                    globalHeader
                }
            />

            <main className="flex-1">
                {children}
            </main>

            <GlobalSectionRenderer
                section={
                    globalFooter
                }
            />

            <GlobalSectionRenderer
                section={
                    globalFloatingContact
                }
            />
        </div>
    );
}
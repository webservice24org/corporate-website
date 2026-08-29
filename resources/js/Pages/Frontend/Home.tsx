// resources/js/Pages/Frontend/Home.tsx

import {
    Head,
} from "@inertiajs/react";

import FrontendLayout from "@/Layouts/frontend-layout";

import {
    getSectionDesign,
} from "@/PageBuilder/registry";

import type {
    SectionSettings,
    SectionType,
} from "@/PageBuilder/types";

type PageData = {
    id: number;

    title: string;
    slug: string;

    meta_title?: string | null;
    meta_description?: string | null;
};

type PageSection = {
    id: number;

    type: SectionType;

    variant: string;

    settings: SectionSettings;

    is_active: boolean;

    sort_order: number;
};

type Props = {
    page:
        | PageData
        | null;

    sections?:
        PageSection[];
};

export default function Home({
    page,
    sections = [],
}: Props) {
    return (
        <FrontendLayout>
            <Head
                title={
                    page?.meta_title ??
                    page?.title ??
                    "Home"
                }
            />

            {sections.length > 0 ? (
                sections.map(
                    (section) => {
                        const design =
                            getSectionDesign(
                                section.type,
                                section.variant,
                            );

                        if (!design) {
                            return null;
                        }

                        const SectionComponent =
                            design.component;

                        return (
                            <SectionComponent
                                key={
                                    section.id
                                }
                                settings={
                                    section.settings ??
                                    {}
                                }
                            />
                        );
                    },
                )
            ) : (
                <div className="mx-auto max-w-7xl px-6 py-24 text-center">
                    <h1 className="text-3xl font-bold">
                        Homepage not configured
                    </h1>

                    <p className="mt-3 text-muted-foreground">
                        Select a published
                        page as the homepage
                        from the admin panel.
                    </p>
                </div>
            )}
        </FrontendLayout>
    );
}
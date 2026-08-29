import { Head } from "@inertiajs/react";

import FrontendLayout from "@/Layouts/frontend-layout";

import PageLayout, {
    type PageLayoutType,
} from "@/Components/Frontend/PageLayout";

import PageSidebar from "@/Components/Frontend/PageSidebar";

import StandardPageContent from "@/Components/Frontend/StandardPageContent";

import PageRenderer from "@/PageBuilder/components/PageRenderer";

import type {
    BuilderSection,
} from "@/PageBuilder/types";

type PageTemplate =
    | "standard"
    | "builder";

type PageData = {
    id: number;
    title: string;
    slug: string;

    description:
        | string
        | null;

    featured_image:
        | string
        | null;

    layout:
        | PageLayoutType
        | null;

    template: string;

    meta_title:
        | string
        | null;

    meta_description:
        | string
        | null;

    status:
        | "draft"
        | "published";

    sections: BuilderSection[];
};

type Props = {
    page: PageData;
};

export default function Page({
    page,
}: Props) {
    /*
     * Backward compatibility:
     *
     * Existing older pages may still contain
     * template values such as:
     *
     * default
     * full-width
     *
     * Only an explicit "builder" value should
     * be treated as a builder page.
     */
    const template: PageTemplate =
        page.template === "builder"
            ? "builder"
            : "standard";

    const layout: PageLayoutType =
        page.layout === "left-sidebar" ||
        page.layout === "right-sidebar" ||
        page.layout === "full-width"
            ? page.layout
            : "full-width";

    return (
        <FrontendLayout>
            <Head
                title={
                    page.meta_title ??
                    page.title
                }
            >
                {page.meta_description && (
                    <meta
                        name="description"
                        content={
                            page.meta_description
                        }
                    />
                )}
            </Head>

            {template === "builder" ? (
                <BuilderPage
                    sections={
                        page.sections
                    }
                />
            ) : (
                <StandardPage
                    page={page}
                    layout={layout}
                />
            )}
        </FrontendLayout>
    );
}

function BuilderPage({
    sections,
}: {
    sections: BuilderSection[];
}) {
    return (
        <PageRenderer
            sections={
                sections
            }
        />
    );
}

function StandardPage({
    page,
    layout,
}: {
    page: PageData;
    layout: PageLayoutType;
}) {
    return (
        <PageLayout
            layout={layout}
            sidebar={
                layout ===
                "full-width"
                    ? undefined
                    : (
                        <PageSidebar />
                    )
            }
        >
            <StandardPageContent
                page={page}
            />
        </PageLayout>
    );
}
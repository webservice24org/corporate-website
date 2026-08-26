import { Head } from "@inertiajs/react";

import FrontendLayout from "@/Layouts/frontend-layout";

import PageRenderer from "@/PageBuilder/components/PageRenderer";

import type { BuilderSection } from "@/PageBuilder/types";

type PageData = {
    id: number;
    title: string;
    slug: string;

    meta_title: string | null;
    meta_description: string | null;

    status: "draft" | "published";

    sections: BuilderSection[];
};

type Props = {
    page: PageData;
};

export default function Page({
    page,
}: Props) {
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

            <PageRenderer
                sections={page.sections}
            />
        </FrontendLayout>
    );
}
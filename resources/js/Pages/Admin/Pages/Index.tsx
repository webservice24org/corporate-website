import AdminLayout from "@/Layouts/admin-layout";
import { Head, Link } from "@inertiajs/react";

import { columns, type Page } from "./columns";
import { DataTable } from "./data-table";

import {
    useAuthorization,
} from "@/hooks/useAuthorization";

type Props = {
    pages: Page[];
};

export default function Index({ pages }: Props) {
    const {
        can,
    } = useAuthorization();

    return (
        <AdminLayout>
            <Head title="Pages" />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-foreground">
                            Pages
                        </h1>

                        <p className="mt-1 text-sm text-muted-foreground">
                            Create and manage website pages.
                        </p>
                    </div>
                    {can(
                        "pages.create",
                    ) && (
                        <Link
                            href="/admin/pages/create"
                            className="inline-flex h-8 items-center justify-center rounded-lg bg-primary px-2.5 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/80"
                        >
                            Create Page
                        </Link>
                    )}
                </div>

                <DataTable
                    columns={columns}
                    data={pages}
                />
            </div>
        </AdminLayout>
    );
}
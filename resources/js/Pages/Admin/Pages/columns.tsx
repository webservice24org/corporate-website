import { type ColumnDef } from "@tanstack/react-table";
import { ArrowUpDown } from "lucide-react";

import { Button } from "@/Components/ui/button";
import PageActions from "./page-actions";

export type Page = {
    id: number;
    title: string;
    slug: string;
    status: "draft" | "published";
    template: string;
    published_at: string | null;
    created_at: string;
};

export const columns: ColumnDef<Page>[] = [
    {
        accessorKey: "title",

        header: ({ column }) => (
            <Button
                variant="ghost"
                onPress={() =>
                    column.toggleSorting(
                        column.getIsSorted() === "asc",
                    )
                }
            >
                Title
                <ArrowUpDown className="ml-1 size-4" />
            </Button>
        ),

        cell: ({ row }) => (
            <div className="font-medium">
                {row.original.title}
            </div>
        ),
    },

    {
        accessorKey: "slug",

        header: "Slug",

        cell: ({ row }) => (
            <span className="text-muted-foreground">
                /{row.original.slug}
            </span>
        ),
    },

    {
        accessorKey: "status",

        header: "Status",

        cell: ({ row }) => {
            const status = row.original.status;

            return (
                <span
                    className={
                        status === "published"
                            ? "inline-flex rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
                            : "inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-700 dark:bg-amber-950 dark:text-amber-300"
                    }
                >
                    {status === "published"
                        ? "Published"
                        : "Draft"}
                </span>
            );
        },
    },

    {
        accessorKey: "template",

        header: "Template",

        cell: ({ row }) => (
            <span className="capitalize">
                {row.original.template.replace("-", " ")}
            </span>
        ),
    },

    {
        accessorKey: "created_at",

        header: ({ column }) => (
            <Button
                variant="ghost"
                onPress={() =>
                    column.toggleSorting(
                        column.getIsSorted() === "asc",
                    )
                }
            >
                Created
                <ArrowUpDown className="ml-1 size-4" />
            </Button>
        ),

        cell: ({ row }) => {
            const date = new Date(row.original.created_at);

            return (
                <span className="text-muted-foreground">
                    {date.toLocaleDateString("en-GB", {
                        day: "2-digit",
                        month: "short",
                        year: "numeric",
                    })}
                </span>
            );
        },
    },

    {
        id: "actions",

        header: () => (
            <div className="text-right">
                Actions
            </div>
        ),

        enableSorting: false,
        enableHiding: false,

        cell: ({ row }) => (
            <div className="flex justify-end">
                <PageActions page={row.original} />
            </div>
        ),
    },
];
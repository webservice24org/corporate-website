import {
    Head,
    Link,
    router,
} from "@inertiajs/react";

import {
    FilePenLine,
    Plus,
    Search,
    Trash2,
} from "lucide-react";

import {
    useState,
} from "react";

import ERPLayout from "@/Layouts/erp-layout";

import {
    useAuthorization,
} from "@/hooks/useAuthorization";

type Company = {
    id: number;
    name: string;
};

type LeadSource = {
    id: number;
    company_id: number;
    name: string;
    code: string;
    description:
        string | null;
    sort_order: number;
    status: boolean;

    company?:
        Company | null;
};

type PaginationLink = {
    url:
        string | null;

    label:
        string;

    active:
        boolean;
};

type Props = {
    sources: {
        data:
            LeadSource[];

        links:
            PaginationLink[];

        total:
            number;
    };

    companies:
        Company[];

    filters: {
        search?:
            string;

        company_id?:
            string | number;

        status?:
            string;
    };
};

export default function Index({
    sources,
    companies,
    filters,
}: Props) {
    const { can } =
        useAuthorization();

    const [
        search,
        setSearch,
    ] = useState(
        filters.search ??
            ""
    );

    const [
        companyId,
        setCompanyId,
    ] = useState(
        filters.company_id
            ? String(
                  filters.company_id
              )
            : ""
    );

    const [
        status,
        setStatus,
    ] = useState(
        filters.status ??
            ""
    );

    const [
        deletingSource,
        setDeletingSource,
    ] =
        useState<LeadSource | null>(
            null
        );

    const applyFilters =
        () => {
            router.get(
                "/erp/crm/lead-sources",
                {
                    search:
                        search ||
                        undefined,

                    company_id:
                        companyId ||
                        undefined,

                    status:
                        status ||
                        undefined,
                },
                {
                    preserveState:
                        true,
                    preserveScroll:
                        true,
                    replace:
                        true,
                }
            );
        };

    const resetFilters =
        () => {
            setSearch("");
            setCompanyId("");
            setStatus("");

            router.get(
                "/erp/crm/lead-sources"
            );
        };

    const confirmDelete =
        () => {
            if (
                !deletingSource
            ) {
                return;
            }

            router.delete(
                `/erp/crm/lead-sources/${deletingSource.id}`,
                {
                    preserveScroll:
                        true,

                    onSuccess:
                        () =>
                            setDeletingSource(
                                null
                            ),
                }
            );
        };

    return (
        <ERPLayout>
            <Head title="Lead Sources" />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold">
                            Lead Sources
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Manage where CRM
                            leads originate.
                        </p>
                    </div>

                    {can(
                        "erp.lead-sources.create"
                    ) && (
                        <Link
                            href="/erp/crm/lead-sources/create"
                            className="inline-flex h-10 items-center gap-2 rounded-md bg-slate-900 px-4 text-sm font-medium text-white dark:bg-white dark:text-slate-950"
                        >
                            <Plus className="h-4 w-4" />

                            New Lead Source
                        </Link>
                    )}
                </div>

                <div className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <div className="relative">
                            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />

                            <input
                                value={
                                    search
                                }
                                onChange={(
                                    event
                                ) =>
                                    setSearch(
                                        event.target
                                            .value
                                    )
                                }
                                onKeyDown={(
                                    event
                                ) => {
                                    if (
                                        event.key ===
                                        "Enter"
                                    ) {
                                        applyFilters();
                                    }
                                }}
                                placeholder="Name or code..."
                                className="h-10 w-full rounded-md border border-slate-200 bg-white pl-9 pr-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                            />
                        </div>

                        <select
                            value={
                                companyId
                            }
                            onChange={(
                                event
                            ) =>
                                setCompanyId(
                                    event.target
                                        .value
                                )
                            }
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
                            <option value="">
                                All Companies
                            </option>

                            {companies.map(
                                (
                                    company
                                ) => (
                                    <option
                                        key={
                                            company.id
                                        }
                                        value={
                                            company.id
                                        }
                                    >
                                        {
                                            company.name
                                        }
                                    </option>
                                )
                            )}
                        </select>

                        <select
                            value={
                                status
                            }
                            onChange={(
                                event
                            ) =>
                                setStatus(
                                    event.target
                                        .value
                                )
                            }
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
                            <option value="">
                                All Statuses
                            </option>

                            <option value="active">
                                Active
                            </option>

                            <option value="inactive">
                                Inactive
                            </option>
                        </select>

                        <div className="flex gap-2">
                            <button
                                type="button"
                                onClick={
                                    applyFilters
                                }
                                className="h-10 flex-1 rounded-md bg-slate-900 px-4 text-sm font-medium text-white dark:bg-white dark:text-slate-950"
                            >
                                Filter
                            </button>

                            <button
                                type="button"
                                onClick={
                                    resetFilters
                                }
                                className="h-10 rounded-md border border-slate-200 px-4 text-sm dark:border-slate-800"
                            >
                                Reset
                            </button>
                        </div>
                    </div>
                </div>

                <div className="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase text-slate-500 dark:border-slate-800 dark:bg-slate-900/50">
                                <tr>
                                    <th className="px-4 py-3">
                                        Source
                                    </th>

                                    <th className="px-4 py-3">
                                        Company
                                    </th>

                                    <th className="px-4 py-3">
                                        Sort
                                    </th>

                                    <th className="px-4 py-3">
                                        Status
                                    </th>

                                    <th className="px-4 py-3 text-right">
                                        Actions
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {sources.data.map(
                                    (
                                        source
                                    ) => (
                                        <tr
                                            key={
                                                source.id
                                            }
                                        >
                                            <td className="px-4 py-4">
                                                <div className="font-medium">
                                                    {
                                                        source.name
                                                    }
                                                </div>

                                                <div className="mt-1 font-mono text-xs text-slate-500">
                                                    {
                                                        source.code
                                                    }
                                                </div>

                                                {source.description && (
                                                    <div className="mt-2 max-w-md text-xs text-slate-500">
                                                        {
                                                            source.description
                                                        }
                                                    </div>
                                                )}
                                            </td>

                                            <td className="px-4 py-4">
                                                {source
                                                    .company
                                                    ?.name ??
                                                    "—"}
                                            </td>

                                            <td className="px-4 py-4">
                                                {
                                                    source.sort_order
                                                }
                                            </td>

                                            <td className="px-4 py-4">
                                                <span
                                                    className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${
                                                        source.status
                                                            ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400"
                                                            : "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400"
                                                    }`}
                                                >
                                                    {source.status
                                                        ? "Active"
                                                        : "Inactive"}
                                                </span>
                                            </td>

                                            <td className="px-4 py-4">
                                                <div className="flex justify-end gap-2">
                                                    {can(
                                                        "erp.lead-sources.edit"
                                                    ) && (
                                                        <Link
                                                            href={`/erp/crm/lead-sources/${source.id}/edit`}
                                                            className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-200 dark:border-slate-800"
                                                        >
                                                            <FilePenLine className="h-4 w-4" />
                                                        </Link>
                                                    )}

                                                    {can(
                                                        "erp.lead-sources.delete"
                                                    ) && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setDeletingSource(
                                                                    source
                                                                )
                                                            }
                                                            className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-red-200 text-red-600 dark:border-red-900 dark:text-red-400"
                                                        >
                                                            <Trash2 className="h-4 w-4" />
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    )
                                )}

                                {sources.data
                                    .length ===
                                    0 && (
                                    <tr>
                                        <td
                                            colSpan={
                                                5
                                            }
                                            className="px-4 py-14 text-center text-slate-500"
                                        >
                                            No lead
                                            sources
                                            found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {sources.links
                        .length >
                        3 && (
                        <div className="flex flex-wrap gap-1 border-t border-slate-200 p-4 dark:border-slate-800">
                            {sources.links.map(
                                (
                                    link,
                                    index
                                ) => (
                                    <button
                                        key={
                                            index
                                        }
                                        type="button"
                                        disabled={
                                            !link.url
                                        }
                                        onClick={() => {
                                            if (
                                                link.url
                                            ) {
                                                router.visit(
                                                    link.url,
                                                    {
                                                        preserveScroll:
                                                            true,
                                                    }
                                                );
                                            }
                                        }}
                                        dangerouslySetInnerHTML={{
                                            __html:
                                                link.label,
                                        }}
                                        className={`min-w-9 rounded-md px-3 py-2 text-sm ${
                                            link.active
                                                ? "bg-slate-900 text-white dark:bg-white dark:text-slate-950"
                                                : "border border-slate-200 dark:border-slate-800"
                                        } disabled:opacity-40`}
                                    />
                                )
                            )}
                        </div>
                    )}
                </div>
            </div>

            {deletingSource && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-slate-950">
                        <Trash2 className="h-8 w-8 text-red-600" />

                        <h2 className="mt-4 text-lg font-semibold">
                            Delete Lead Source
                        </h2>

                        <p className="mt-2 text-sm leading-6 text-slate-500">
                            Delete{" "}
                            <strong>
                                {
                                    deletingSource.name
                                }
                            </strong>
                            ? If it is already
                            used by leads, the
                            backend will reject
                            deletion and you
                            should deactivate it
                            instead.
                        </p>

                        <div className="mt-6 flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={() =>
                                    setDeletingSource(
                                        null
                                    )
                                }
                                className="h-10 rounded-md border border-slate-200 px-4 text-sm dark:border-slate-800"
                            >
                                Cancel
                            </button>

                            <button
                                type="button"
                                onClick={
                                    confirmDelete
                                }
                                className="h-10 rounded-md bg-red-600 px-4 text-sm font-medium text-white"
                            >
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </ERPLayout>
    );
}
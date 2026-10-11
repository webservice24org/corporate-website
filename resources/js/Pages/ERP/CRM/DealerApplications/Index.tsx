import {
    Head,
    Link,
    router,
} from "@inertiajs/react";

import {
    Building2,
    Eye,
    Search,
    Store,
} from "lucide-react";

import {
    useState,
} from "react";

import type {
    FormEvent,
} from "react";

import ERPLayout from "@/Layouts/erp-layout";

import type {
    DealerApplication,
    Paginated,
} from "@/types/dealer";

type Props = {
    applications:
        Paginated<DealerApplication>;

    filters: {
        search?: string | null;
        status?: string | null;
        depot_id?: number | string | null;
    };
};

const statusStyles: Record<
    DealerApplication["status"],
    string
> = {
    pending:
        "bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300",

    under_review:
        "bg-blue-100 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300",

    approved:
        "bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300",

    rejected:
        "bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-300",
};

function statusLabel(
    status: DealerApplication["status"]
) {
    return status
        .replaceAll("_", " ")
        .replace(/\b\w/g, (char) =>
            char.toUpperCase()
        );
}

export default function Index({
    applications,
    filters,
}: Props) {
    const [
        search,
        setSearch,
    ] =
        useState(
            filters.search ?? ""
        );

    const [
        status,
        setStatus,
    ] =
        useState(
            filters.status ?? ""
        );

    const submitFilter = (
        event: FormEvent
    ) => {
        event.preventDefault();

        router.get(
            "/erp/crm/dealer-applications",
            {
                search:
                    search ||
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

    const clearFilters =
        () => {
            setSearch("");
            setStatus("");

            router.get(
                "/erp/crm/dealer-applications",
                {},
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

    return (
        <ERPLayout>
            <Head title="Dealer Applications" />

            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold">
                        Dealer Applications
                    </h1>

                    <p className="mt-1 text-sm text-neutral-500">
                        Review Dealer registration
                        applications, verify documents,
                        and approve or reject applicants.
                    </p>
                </div>

                <div className="grid gap-4 md:grid-cols-3">
                    <div className="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-950">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-sm text-neutral-500">
                                    Applications
                                </p>

                                <p className="mt-2 text-2xl font-semibold">
                                    {applications.total}
                                </p>
                            </div>

                            <Store className="h-5 w-5 text-neutral-400" />
                        </div>
                    </div>
                </div>

                <form
                    onSubmit={
                        submitFilter
                    }
                    className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-950"
                >
                    <div className="grid gap-3 lg:grid-cols-[1fr_220px_auto_auto]">
                        <div className="relative">
                            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />

                            <input
                                value={
                                    search
                                }
                                onChange={(
                                    event
                                ) =>
                                    setSearch(
                                        event.target.value
                                    )
                                }
                                placeholder="Search business, proprietor, phone or email..."
                                className="h-10 w-full rounded-md border border-neutral-200 bg-white pl-9 pr-3 text-sm outline-none dark:border-neutral-800 dark:bg-neutral-950"
                            />
                        </div>

                        <select
                            value={
                                status
                            }
                            onChange={(
                                event
                            ) =>
                                setStatus(
                                    event.target.value
                                )
                            }
                            className="h-10 rounded-md border border-neutral-200 bg-white px-3 text-sm outline-none dark:border-neutral-800 dark:bg-neutral-950"
                        >
                            <option value="">
                                All Statuses
                            </option>

                            <option value="pending">
                                Pending
                            </option>

                            <option value="under_review">
                                Under Review
                            </option>

                            <option value="approved">
                                Approved
                            </option>

                            <option value="rejected">
                                Rejected
                            </option>
                        </select>

                        <button
                            type="submit"
                            className="h-10 rounded-md bg-neutral-950 px-4 text-sm font-medium text-white dark:bg-white dark:text-neutral-950"
                        >
                            Filter
                        </button>

                        <button
                            type="button"
                            onClick={
                                clearFilters
                            }
                            className="h-10 rounded-md border border-neutral-200 px-4 text-sm font-medium dark:border-neutral-800"
                        >
                            Clear
                        </button>
                    </div>
                </form>

                <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-950">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="border-b border-neutral-200 bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500 dark:border-neutral-800 dark:bg-neutral-900/40">
                                <tr>
                                    <th className="px-4 py-3">
                                        Business
                                    </th>

                                    <th className="px-4 py-3">
                                        Depot
                                    </th>

                                    <th className="px-4 py-3">
                                        Upazila
                                    </th>

                                    <th className="px-4 py-3">
                                        Contact
                                    </th>

                                    <th className="px-4 py-3">
                                        Status
                                    </th>

                                    <th className="px-4 py-3">
                                        Submitted
                                    </th>

                                    <th className="px-4 py-3 text-right">
                                        Action
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y divide-neutral-100 dark:divide-neutral-800">
                                {applications.data.map(
                                    (
                                        application
                                    ) => (
                                        <tr
                                            key={
                                                application.id
                                            }
                                            className="hover:bg-neutral-50 dark:hover:bg-neutral-900/40"
                                        >
                                            <td className="px-4 py-4">
                                                <div className="flex items-center gap-3">
                                                    <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-neutral-100 dark:bg-neutral-900">
                                                        <Building2 className="h-4 w-4" />
                                                    </div>

                                                    <div>
                                                        <p className="font-medium">
                                                            {
                                                                application.business_name
                                                            }
                                                        </p>

                                                        <p className="mt-0.5 text-xs text-neutral-500">
                                                            {
                                                                application.proprietor_name
                                                            }
                                                        </p>
                                                    </div>
                                                </div>
                                            </td>

                                            <td className="px-4 py-4">
                                                {
                                                    application.depot
                                                        ?.name ??
                                                    "—"
                                                }
                                            </td>

                                            <td className="px-4 py-4">
                                                {
                                                    application.upazila
                                                        ?.name ??
                                                    "—"
                                                }
                                            </td>

                                            <td className="px-4 py-4">
                                                <p>
                                                    {
                                                        application.phone
                                                    }
                                                </p>

                                                {application.email && (
                                                    <p className="mt-0.5 text-xs text-neutral-500">
                                                        {
                                                            application.email
                                                        }
                                                    </p>
                                                )}
                                            </td>

                                            <td className="px-4 py-4">
                                                <span
                                                    className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                                                        statusStyles[
                                                            application
                                                                .status
                                                        ]
                                                    }`}
                                                >
                                                    {statusLabel(
                                                        application.status
                                                    )}
                                                </span>
                                            </td>

                                            <td className="px-4 py-4 text-neutral-500">
                                                {new Date(
                                                    application.created_at
                                                ).toLocaleDateString()}
                                            </td>

                                            <td className="px-4 py-4">
                                                <div className="flex justify-end">
                                                    <Link
                                                        href={`/erp/crm/dealer-applications/${application.id}`}
                                                        className="inline-flex h-9 items-center gap-2 rounded-md border border-neutral-200 px-3 text-sm font-medium transition hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-900"
                                                    >
                                                        <Eye className="h-4 w-4" />

                                                        Review
                                                    </Link>
                                                </div>
                                            </td>
                                        </tr>
                                    )
                                )}

                                {applications.data.length ===
                                    0 && (
                                    <tr>
                                        <td
                                            colSpan={
                                                7
                                            }
                                            className="px-6 py-14 text-center text-neutral-500"
                                        >
                                            No Dealer applications found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {applications.links.length >
                        3 && (
                        <div className="flex flex-wrap gap-1 border-t border-neutral-200 p-4 dark:border-neutral-800">
                            {applications.links.map(
                                (
                                    link,
                                    index
                                ) =>
                                    link.url ? (
                                        <Link
                                            key={
                                                index
                                            }
                                            href={
                                                link.url
                                            }
                                            preserveScroll
                                            className={`rounded-md px-3 py-2 text-sm ${
                                                link.active
                                                    ? "bg-neutral-950 text-white dark:bg-white dark:text-neutral-950"
                                                    : "border border-neutral-200 dark:border-neutral-800"
                                            }`}
                                            dangerouslySetInnerHTML={{
                                                __html:
                                                    link.label,
                                            }}
                                        />
                                    ) : (
                                        <span
                                            key={
                                                index
                                            }
                                            className="rounded-md border border-neutral-200 px-3 py-2 text-sm opacity-40 dark:border-neutral-800"
                                            dangerouslySetInnerHTML={{
                                                __html:
                                                    link.label,
                                            }}
                                        />
                                    )
                            )}
                        </div>
                    )}
                </div>
            </div>
        </ERPLayout>
    );
}
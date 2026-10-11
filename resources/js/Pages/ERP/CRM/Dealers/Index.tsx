import {
    Head,
    Link,
    router,
} from "@inertiajs/react";

import {
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
    Dealer,
    Paginated,
} from "@/types/dealer";

type Props = {
    dealers:
        Paginated<Dealer>;

    filters: {
        search?: string | null;
        depot_id?: number | string | null;
        upazila_id?: number | string | null;
    };
};

export default function Index({
    dealers,
    filters,
}: Props) {
    const [
        search,
        setSearch,
    ] =
        useState(
            filters.search ?? ""
        );

    const submitFilter = (
        event:
            FormEvent
    ) => {
        event.preventDefault();

        router.get(
            "/erp/crm/dealers",
            {
                search:
                    search ||
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

            router.get(
                "/erp/crm/dealers",
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
            <Head title="Dealers" />

            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold">
                        Dealers
                    </h1>

                    <p className="mt-1 text-sm text-neutral-500">
                        Approved Dealers operating
                        under your Depot distribution
                        network.
                    </p>
                </div>

                <div className="grid gap-4 md:grid-cols-3">
                    <div className="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-950">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-sm text-neutral-500">
                                    Total Dealers
                                </p>

                                <p className="mt-2 text-2xl font-semibold">
                                    {dealers.total}
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
                    className="flex flex-col gap-3 rounded-xl border border-neutral-200 bg-white p-4 sm:flex-row dark:border-neutral-800 dark:bg-neutral-950"
                >
                    <div className="relative flex-1">
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
                            placeholder="Search dealer, proprietor, phone or code..."
                            className="h-10 w-full rounded-md border border-neutral-200 bg-white pl-9 pr-3 text-sm outline-none dark:border-neutral-800 dark:bg-neutral-950"
                        />
                    </div>

                    <button
                        type="submit"
                        className="h-10 rounded-md bg-neutral-950 px-4 text-sm font-medium text-white dark:bg-white dark:text-neutral-950"
                    >
                        Search
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
                </form>

                <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-950">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="border-b border-neutral-200 bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500 dark:border-neutral-800 dark:bg-neutral-900/40">
                                <tr>
                                    <th className="px-4 py-3">
                                        Dealer
                                    </th>

                                    <th className="px-4 py-3">
                                        Depot
                                    </th>

                                    <th className="px-4 py-3">
                                        Upazila
                                    </th>

                                    <th className="px-4 py-3">
                                        Phone
                                    </th>

                                    <th className="px-4 py-3">
                                        Credit Limit
                                    </th>

                                    <th className="px-4 py-3">
                                        Status
                                    </th>

                                    <th className="px-4 py-3 text-right">
                                        Action
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y divide-neutral-100 dark:divide-neutral-800">
                                {dealers.data.map(
                                    (
                                        dealer
                                    ) => (
                                        <tr
                                            key={
                                                dealer.id
                                            }
                                            className="hover:bg-neutral-50 dark:hover:bg-neutral-900/40"
                                        >
                                            <td className="px-4 py-4">
                                                <div>
                                                    <p className="font-medium">
                                                        {
                                                            dealer.business_name
                                                        }
                                                    </p>

                                                    <p className="mt-0.5 text-xs text-neutral-500">
                                                        {dealer.dealer_code ??
                                                            `Dealer #${dealer.id}`}
                                                    </p>
                                                </div>
                                            </td>

                                            <td className="px-4 py-4">
                                                {
                                                    dealer.depot
                                                        ?.name ??
                                                    "—"
                                                }
                                            </td>

                                            <td className="px-4 py-4">
                                                {
                                                    dealer.upazila
                                                        ?.name ??
                                                    "—"
                                                }
                                            </td>

                                            <td className="px-4 py-4">
                                                {
                                                    dealer.phone ??
                                                    "—"
                                                }
                                            </td>

                                            <td className="px-4 py-4">
                                                {Number(
                                                    dealer.credit_limit
                                                ).toLocaleString()}
                                            </td>

                                            <td className="px-4 py-4">
                                                <span
                                                    className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                                                        dealer.is_active
                                                            ? "bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"
                                                            : "bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300"
                                                    }`}
                                                >
                                                    {dealer.is_active
                                                        ? "Active"
                                                        : "Inactive"}
                                                </span>
                                            </td>

                                            <td className="px-4 py-4">
                                                <div className="flex justify-end">
                                                    <Link
                                                        href={`/erp/crm/dealers/${dealer.id}`}
                                                        className="inline-flex h-9 items-center gap-2 rounded-md border border-neutral-200 px-3 text-sm font-medium dark:border-neutral-800"
                                                    >
                                                        <Eye className="h-4 w-4" />

                                                        View
                                                    </Link>
                                                </div>
                                            </td>
                                        </tr>
                                    )
                                )}

                                {dealers.data.length ===
                                    0 && (
                                    <tr>
                                        <td
                                            colSpan={
                                                7
                                            }
                                            className="px-6 py-14 text-center text-neutral-500"
                                        >
                                            No approved Dealers found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {dealers.links.length >
                        3 && (
                        <div className="flex flex-wrap gap-1 border-t border-neutral-200 p-4 dark:border-neutral-800">
                            {dealers.links.map(
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
import {
    Head,
    Link,
} from "@inertiajs/react";

import {
    Building2,
    Eye,
    MapPin,
    Phone,
} from "lucide-react";

import ERPLayout from "@/Layouts/erp-layout";

import type {
    Depot,
    Paginated,
} from "@/types/depot";

type Props = {
    depots:
        Paginated<Depot>;
};

export default function Index({
    depots,
}: Props) {
    return (
        <ERPLayout>
            <Head title="Depots" />

            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold">
                        Depots
                    </h1>

                    <p className="mt-1 text-sm text-neutral-500">
                        Approved distribution
                        Depots available for
                        future inventory,
                        dealer and sales
                        operations.
                    </p>
                </div>

                <div className="rounded-xl border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-950">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="border-b border-neutral-200 bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500 dark:border-neutral-800 dark:bg-neutral-900/40">
                                <tr>
                                    <th className="px-4 py-3">
                                        Depot
                                    </th>

                                    <th className="px-4 py-3">
                                        Company
                                    </th>

                                    <th className="px-4 py-3">
                                        District
                                    </th>

                                    <th className="px-4 py-3">
                                        Contact
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
                                {depots.data.map(
                                    (
                                        depot
                                    ) => (
                                        <tr
                                            key={
                                                depot.id
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
                                                                depot.name
                                                            }
                                                        </p>

                                                        <p className="text-xs text-neutral-500">
                                                            {
                                                                depot.depot_code ??
                                                                `Depot #${depot.id}`
                                                            }
                                                        </p>
                                                    </div>
                                                </div>
                                            </td>

                                            <td className="px-4 py-4">
                                                {
                                                    depot.company
                                                        ?.name ??
                                                    "—"
                                                }
                                            </td>

                                            <td className="px-4 py-4">
                                                <div className="flex items-center gap-1.5">
                                                    <MapPin className="h-4 w-4 text-neutral-400" />

                                                    {
                                                        depot.district
                                                            ?.name ??
                                                        "—"
                                                    }
                                                </div>
                                            </td>

                                            <td className="px-4 py-4">
                                                <div className="flex items-center gap-1.5">
                                                    <Phone className="h-4 w-4 text-neutral-400" />

                                                    {
                                                        depot.phone ??
                                                        "—"
                                                    }
                                                </div>
                                            </td>

                                            <td className="px-4 py-4">
                                                {new Intl.NumberFormat(
                                                    "en-BD",
                                                    {
                                                        minimumFractionDigits:
                                                            2,
                                                    }
                                                ).format(
                                                    Number(
                                                        depot.credit_limit
                                                    )
                                                )}
                                            </td>

                                            <td className="px-4 py-4">
                                                <span
                                                    className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                                                        depot.is_active
                                                            ? "bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"
                                                            : "bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300"
                                                    }`}
                                                >
                                                    {depot.is_active
                                                        ? "Active"
                                                        : "Inactive"}
                                                </span>
                                            </td>

                                            <td className="px-4 py-4 text-right">
                                                <Link
                                                    href={`/erp/crm/depots/${depot.id}`}
                                                    className="inline-flex h-9 items-center gap-2 rounded-md border border-neutral-200 px-3 text-xs font-medium dark:border-neutral-800"
                                                >
                                                    <Eye className="h-4 w-4" />
                                                    View
                                                </Link>
                                            </td>
                                        </tr>
                                    )
                                )}

                                {depots.data.length ===
                                    0 && (
                                    <tr>
                                        <td
                                            colSpan={
                                                7
                                            }
                                            className="px-6 py-14 text-center text-neutral-500"
                                        >
                                            No approved
                                            Depots yet.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {depots.links.length >
                        3 && (
                        <div className="flex flex-wrap gap-1 border-t border-neutral-200 p-4 dark:border-neutral-800">
                            {depots.links.map(
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
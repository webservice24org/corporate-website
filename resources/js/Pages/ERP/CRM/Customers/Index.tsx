import { useState, type FormEvent } from "react";
import { Head, Link, router } from "@inertiajs/react";
import { Eye, Pencil, Plus, Search } from "lucide-react";
import ERPLayout from "@/Layouts/erp-layout";
import { useAuthorization } from "@/hooks/useAuthorization";
import type { Customer } from "@/types/customer";

type LinkItem = {
    url: string | null;
    label: string;
    active: boolean;
};

type Props = {
    customers: {
        data: Customer[];
        links: LinkItem[];
        total: number;
    };
    filters: {
        search?: string;
        customer_type?: string;
        status?: string;
    };
};

export default function Index({ customers, filters }: Props) {
    const { can } = useAuthorization();
    const [search, setSearch] = useState(filters.search ?? "");

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(
            "/erp/crm/customers",
            {
                search,
                customer_type: filters.customer_type ?? "",
                status: filters.status ?? "",
            },
            {
                preserveState: true,
                preserveScroll: true,
            }
        );
    };

    return (
        <ERPLayout>
            <Head title="General Customers" />

            <div className="space-y-6">
                {/* Header Section */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold">General Customers</h1>
                        <p className="mt-1 text-sm text-neutral-500">
                            Individual and company customers who may buy through Company, Depot, Dealer or Seller channels.
                        </p>
                    </div>

                    {can("erp.customers.create") && (
                        <Link
                            href="/erp/crm/customers/create"
                            className="inline-flex h-10 items-center gap-2 rounded-md bg-neutral-950 px-4 text-sm font-medium text-white transition-colors hover:bg-neutral-800 dark:bg-white dark:text-neutral-950 dark:hover:bg-neutral-200"
                        >
                            <Plus className="h-4 w-4" />
                            New Customer
                        </Link>
                    )}
                </div>

                {/* Search Bar */}
                <form onSubmit={submit} className="flex gap-2">
                    <div className="relative max-w-md flex-1">
                        <Search className="absolute left-3 top-3 h-4 w-4 text-neutral-400" />
                        <input
                            type="text"
                            className="h-10 w-full rounded-md border pl-9 pr-3 text-sm focus:outline-none focus:ring-2 focus:ring-neutral-950 dark:border-neutral-800 dark:bg-neutral-950 dark:focus:ring-white"
                            placeholder="Search customer, code, phone..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                    </div>
                    <button
                        type="submit"
                        className="rounded-md border px-4 text-sm font-medium transition-colors hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-900"
                    >
                        Search
                    </button>
                </form>

                {/* Table Data Container */}
                <div className="overflow-hidden rounded-xl border bg-white dark:border-neutral-800 dark:bg-neutral-950">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-neutral-50 text-left dark:bg-neutral-900">
                                <tr>
                                    <th className="p-4 font-medium text-neutral-700 dark:text-neutral-300">Customer</th>
                                    <th className="p-4 font-medium text-neutral-700 dark:text-neutral-300">Type</th>
                                    <th className="p-4 font-medium text-neutral-700 dark:text-neutral-300">Company</th>
                                    <th className="p-4 font-medium text-neutral-700 dark:text-neutral-300">Phone</th>
                                    <th className="p-4 font-medium text-neutral-700 dark:text-neutral-300">Credit Limit</th>
                                    <th className="p-4 font-medium text-neutral-700 dark:text-neutral-300">Status</th>
                                    <th className="p-4 text-right font-medium text-neutral-700 dark:text-neutral-300">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y dark:divide-neutral-800">
                                {customers.data.map((c) => (
                                    <tr key={c.id} className="transition-colors hover:bg-neutral-50/50 dark:hover:bg-neutral-900/50">
                                        <td className="p-4">
                                            <div className="font-medium text-neutral-900 dark:text-neutral-100">
                                                {c.business_name || c.name}
                                            </div>
                                            <div className="text-xs text-neutral-500">
                                                {c.customer_code ?? `Customer #${c.id}`}
                                            </div>
                                        </td>
                                        <td className="p-4 capitalize">{c.customer_type}</td>
                                        <td className="p-4">{c.company?.name ?? "—"}</td>
                                        <td className="p-4">{c.phone}</td>
                                        <td className="p-4">{Number(c.credit_limit).toLocaleString()}</td>
                                        <td className="p-4">
                                            <span
                                                className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                                                    c.is_active
                                                        ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400"
                                                        : "bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400"
                                                }`}
                                            >
                                                {c.is_active ? "Active" : "Inactive"}
                                            </span>
                                        </td>
                                        <td className="p-4">
                                            <div className="flex justify-end gap-2">
                                                <Link
                                                    href={`/erp/crm/customers/${c.id}`}
                                                    className="rounded-md border p-2 transition-colors hover:bg-neutral-100 dark:border-neutral-800 dark:hover:bg-neutral-900"
                                                    title="View Customer"
                                                >
                                                    <Eye className="h-4 w-4" />
                                                </Link>
                                                {can("erp.customers.edit") && (
                                                    <Link
                                                        href={`/erp/crm/customers/${c.id}/edit`}
                                                        className="rounded-md border p-2 transition-colors hover:bg-neutral-100 dark:border-neutral-800 dark:hover:bg-neutral-900"
                                                        title="Edit Customer"
                                                    >
                                                        <Pencil className="h-4 w-4" />
                                                    </Link>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {customers.data.length === 0 && (
                        <div className="p-10 text-center text-sm text-neutral-500">
                            No customers found.
                        </div>
                    )}
                </div>
            </div>
        </ERPLayout>
    );
}
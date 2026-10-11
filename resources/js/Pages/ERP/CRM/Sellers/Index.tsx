import {
    Head,
    Link,
    router,
} from "@inertiajs/react";

import {
    Eye,
    Plus,
    Search,
} from "lucide-react";

import {
    useState,
} from "react";

import type {
    FormEvent,
} from "react";

import ERPLayout from "@/Layouts/erp-layout";

import {
    useAuthorization,
} from "@/hooks/useAuthorization";

import type {
    Paginated,
    Seller,
    SellerFormDealer,
} from "@/types/seller";

type Props = {
    sellers:
        Paginated<Seller>;

    dealers:
        SellerFormDealer[];

    filters: {
        search?: string;
        dealer_id?:
            number | string;
        status?: string;
    };
};

export default function Index({
    sellers,
    dealers,
    filters,
}: Props) {
    const {
        can,
    } =
        useAuthorization();

    const [
        search,
        setSearch,
    ] =
        useState(
            filters.search ??
                ""
        );

    const [
        dealerId,
        setDealerId,
    ] =
        useState(
            String(
                filters.dealer_id ??
                    ""
            )
        );

    const [
        status,
        setStatus,
    ] =
        useState(
            filters.status ??
                ""
        );

    const applyFilters = (
        event:
            FormEvent
    ) => {
        event.preventDefault();

        router.get(
            "/erp/crm/sellers",
            {
                search:
                    search ||
                    undefined,

                dealer_id:
                    dealerId ||
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
            }
        );
    };

    return (
        <ERPLayout>
            <Head title="Sellers" />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">
                            Sellers
                        </h1>

                        <p className="mt-1 text-sm text-neutral-500">
                            Retailers and
                            general sellers
                            operating under
                            Dealers.
                        </p>
                    </div>

                    {can(
                        "erp.sellers.create"
                    ) && (
                        <Link
                            href="/erp/crm/sellers/create"
                            className="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-neutral-950 px-4 text-sm font-medium text-white dark:bg-white dark:text-neutral-950"
                        >
                            <Plus className="h-4 w-4" />

                            Add Seller
                        </Link>
                    )}
                </div>

                <form
                    onSubmit={
                        applyFilters
                    }
                    className="grid gap-3 rounded-lg border border-neutral-200 bg-white p-4 md:grid-cols-[1fr_240px_180px_auto] dark:border-neutral-800 dark:bg-neutral-900"
                >
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
                                    event
                                        .target
                                        .value
                                )
                            }
                            placeholder="Search seller..."
                            className="h-10 w-full rounded-md border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-950"
                        />
                    </div>

                    <select
                        value={
                            dealerId
                        }
                        onChange={(
                            event
                        ) =>
                            setDealerId(
                                event
                                    .target
                                    .value
                            )
                        }
                        className="h-10 rounded-md border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-950"
                    >
                        <option value="">
                            All Dealers
                        </option>

                        {dealers.map(
                            (
                                dealer
                            ) => (
                                <option
                                    key={
                                        dealer.id
                                    }
                                    value={
                                        dealer.id
                                    }
                                >
                                    {
                                        dealer.business_name
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
                                event
                                    .target
                                    .value
                            )
                        }
                        className="h-10 rounded-md border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-950"
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

                    <button
                        type="submit"
                        className="h-10 rounded-md bg-neutral-950 px-5 text-sm font-medium text-white dark:bg-white dark:text-neutral-950"
                    >
                        Filter
                    </button>
                </form>

                <div className="overflow-hidden rounded-lg border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="border-b border-neutral-200 bg-neutral-50 text-left dark:border-neutral-800 dark:bg-neutral-950">
                                <tr>
                                    <th className="px-4 py-3">
                                        Seller
                                    </th>

                                    <th className="px-4 py-3">
                                        Dealer
                                    </th>

                                    <th className="px-4 py-3">
                                        Area
                                    </th>

                                    <th className="px-4 py-3">
                                        Contact
                                    </th>

                                    <th className="px-4 py-3">
                                        Credit
                                    </th>

                                    <th className="px-4 py-3">
                                        Status
                                    </th>

                                    <th className="px-4 py-3 text-right">
                                        Action
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                {sellers.data.map(
                                    (
                                        seller
                                    ) => (
                                        <tr
                                            key={
                                                seller.id
                                            }
                                            className="border-b border-neutral-100 last:border-0 dark:border-neutral-800"
                                        >
                                            <td className="px-4 py-3">
                                                <div className="font-medium">
                                                    {
                                                        seller.business_name
                                                    }
                                                </div>

                                                <div className="text-xs text-neutral-500">
                                                    {seller.seller_code ??
                                                        `Seller #${seller.id}`}
                                                </div>

                                                <div className="mt-1 text-xs capitalize text-neutral-500">
                                                    {seller.seller_type.replace(
                                                        "_",
                                                        " "
                                                    )}
                                                </div>
                                            </td>

                                            <td className="px-4 py-3">
                                                {
                                                    seller.dealer
                                                        ?.business_name
                                                }

                                                {seller.dealer
                                                    ?.dealer_code && (
                                                    <div className="text-xs text-neutral-500">
                                                        {
                                                            seller
                                                                .dealer
                                                                .dealer_code
                                                        }
                                                    </div>
                                                )}
                                            </td>

                                            <td className="px-4 py-3">
                                                <div>
                                                    {seller
                                                        .dealer
                                                        ?.upazila
                                                        ?.name ??
                                                        "—"}
                                                </div>

                                                <div className="text-xs text-neutral-500">
                                                    {seller
                                                        .dealer
                                                        ?.depot
                                                        ?.name ??
                                                        ""}
                                                </div>
                                            </td>

                                            <td className="px-4 py-3">
                                                <div>
                                                    {
                                                        seller.phone
                                                    }
                                                </div>

                                                <div className="text-xs text-neutral-500">
                                                    {seller.email ??
                                                        ""}
                                                </div>
                                            </td>

                                            <td className="px-4 py-3">
                                                {Number(
                                                    seller.credit_limit
                                                ).toLocaleString()}
                                            </td>

                                            <td className="px-4 py-3">
                                                <span
                                                    className={
                                                        seller.is_active
                                                            ? "rounded-full bg-green-100 px-2 py-1 text-xs text-green-700 dark:bg-green-950/30 dark:text-green-400"
                                                            : "rounded-full bg-neutral-100 px-2 py-1 text-xs text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400"
                                                    }
                                                >
                                                    {seller.is_active
                                                        ? "Active"
                                                        : "Inactive"}
                                                </span>
                                            </td>

                                            <td className="px-4 py-3 text-right">
                                                <Link
                                                    href={`/erp/crm/sellers/${seller.id}`}
                                                    className="inline-flex h-8 items-center gap-1 rounded-md border border-neutral-200 px-3 text-xs dark:border-neutral-700"
                                                >
                                                    <Eye className="h-3.5 w-3.5" />

                                                    View
                                                </Link>
                                            </td>
                                        </tr>
                                    )
                                )}

                                {sellers.data
                                    .length ===
                                    0 && (
                                    <tr>
                                        <td
                                            colSpan={
                                                7
                                            }
                                            className="px-4 py-10 text-center text-neutral-500"
                                        >
                                            No Sellers
                                            found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {sellers.links
                    .length >
                    3 && (
                    <div className="flex flex-wrap gap-2">
                        {sellers.links.map(
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
                                        preserveState
                                        className={`rounded-md border px-3 py-1.5 text-sm ${
                                            link.active
                                                ? "border-neutral-950 bg-neutral-950 text-white dark:border-white dark:bg-white dark:text-neutral-950"
                                                : "border-neutral-200 dark:border-neutral-700"
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
                                        className="rounded-md border border-neutral-200 px-3 py-1.5 text-sm text-neutral-400 dark:border-neutral-700"
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
        </ERPLayout>
    );
}
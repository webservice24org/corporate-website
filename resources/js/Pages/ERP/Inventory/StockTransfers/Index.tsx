
import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import ERPLayout from "@/Layouts/erp-layout";

type Company = {
    id: number;
    name: string;
};

type Warehouse = {
    id: number;
    name: string;
    code: string | null;
} | null;

type Transfer = {
    id: number;
    document_number: string;
    transfer_date: string;
    status: 'draft' | 'posted' | string;
    notes: string | null;
    company: Company | null;
    source_warehouse: Warehouse;
    destination_warehouse: Warehouse;
    creator: {
        id: number;
        name: string;
    } | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: PaginationLink[];
};

type Props = {
    stockTransfers: Paginated<Transfer>;
    filters: {
        search?: string | null;
        company_id?: string | number | null;
        status?: string | null;
    };
    companies: Company[];
    statuses: string[];
};

const BASE = '/erp/inventory/stock-transfers';

const inputClass =
    'w-full min-w-0 rounded-lg border border-slate-300 ' +
    'bg-white px-3 py-2 text-sm text-slate-800 outline-none ' +
    'transition focus:border-indigo-500 focus:ring-2 ' +
    'focus:ring-indigo-100 dark:border-slate-700 ' +
    'dark:bg-slate-900 dark:text-slate-100 ' +
    'dark:focus:ring-indigo-950';

function dateLabel(value: string): string {
    const date = new Date(
        `${value.slice(0, 10)}T00:00:00`
    );

    return Number.isNaN(date.getTime())
        ? value
        : new Intl.DateTimeFormat('en-GB', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          }).format(date);
}

function warehouseLabel(warehouse: Warehouse): string {
    if (!warehouse) {
        return '—';
    }

    return warehouse.code
        ? `${warehouse.name} (${warehouse.code})`
        : warehouse.name;
}

export default function Index({
    stockTransfers,
    filters,
    companies,
    statuses,
}: Props) {
    const [search, setSearch] = useState(
        filters.search ?? ''
    );

    const [companyId, setCompanyId] = useState(
        String(filters.company_id ?? '')
    );

    const [status, setStatus] = useState(
        filters.status ?? ''
    );

    const [deletingId, setDeletingId] = useState<
        number | null
    >(null);

    const applyFilters = (
        event: FormEvent<HTMLFormElement>
    ) => {
        event.preventDefault();

        router.get(
            BASE,
            {
                ...(search.trim()
                    ? { search: search.trim() }
                    : {}),

                ...(companyId
                    ? { company_id: companyId }
                    : {}),

                ...(status ? { status } : {}),
            },
            {
                preserveState: true,
                replace: true,
            }
        );
    };

    const resetFilters = () => {
        setSearch('');
        setCompanyId('');
        setStatus('');

        router.get(
            BASE,
            {},
            {
                preserveState: true,
                replace: true,
            }
        );
    };

    const deleteDraft = (transfer: Transfer) => {
        if (
            transfer.status !== 'draft' ||
            deletingId !== null
        ) {
            return;
        }

        if (
            !window.confirm(
                `Delete draft ${transfer.document_number}? ` +
                    'This cannot be undone.'
            )
        ) {
            return;
        }

        setDeletingId(transfer.id);

        router.delete(`${BASE}/${transfer.id}`, {
            preserveScroll: true,
            onFinish: () => setDeletingId(null),
        });
    };

    return (
        <ERPLayout>
            <Head title="Stock Transfers" />

            <main className="mx-auto w-full min-w-0 max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                {/* Page Header */}
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                            Stock Transfers
                        </h1>

                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            Manage stock movements between
                            warehouses.
                        </p>
                    </div>

                    <Link
                        href={`${BASE}/create`}
                        className="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                    >
                        + New Transfer
                    </Link>
                </div>

                {/* Filters */}
                <form
                    onSubmit={applyFilters}
                    className="grid min-w-0 gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-950 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto_auto] lg:items-end"
                >
                    <label className="block min-w-0 text-xs font-medium text-slate-600 dark:text-slate-300">
                        Document number

                        <input
                            value={search}
                            onChange={(event) =>
                                setSearch(event.target.value)
                            }
                            placeholder="Search document number"
                            className={`mt-1 ${inputClass}`}
                        />
                    </label>

                    <label className="block min-w-0 text-xs font-medium text-slate-600 dark:text-slate-300">
                        Company

                        <select
                            value={companyId}
                            onChange={(event) =>
                                setCompanyId(
                                    event.target.value
                                )
                            }
                            className={`mt-1 ${inputClass}`}
                        >
                            <option value="">
                                All companies
                            </option>

                            {companies.map((company) => (
                                <option
                                    key={company.id}
                                    value={company.id}
                                >
                                    {company.name}
                                </option>
                            ))}
                        </select>
                    </label>

                    <label className="block min-w-0 text-xs font-medium text-slate-600 dark:text-slate-300">
                        Status

                        <select
                            value={status}
                            onChange={(event) =>
                                setStatus(event.target.value)
                            }
                            className={`mt-1 ${inputClass}`}
                        >
                            <option value="">
                                All statuses
                            </option>

                            {statuses.map((item) => (
                                <option
                                    key={item}
                                    value={item}
                                >
                                    {item.charAt(0).toUpperCase() +
                                        item.slice(1)}
                                </option>
                            ))}
                        </select>
                    </label>

                    <button
                        type="submit"
                        className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 dark:bg-slate-700 dark:hover:bg-slate-600"
                    >
                        Search
                    </button>

                    <button
                        type="button"
                        onClick={resetFilters}
                        className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-900"
                    >
                        Reset
                    </button>
                </form>

                {/* Transfer Table */}
                <section className="min-w-0 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-950">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                        <h2 className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                            Transfer Records
                        </h2>

                        <span className="text-xs text-slate-500 dark:text-slate-400">
                            {stockTransfers.total} total ·
                            Showing {stockTransfers.from ?? 0}–
                            {stockTransfers.to ?? 0}
                        </span>
                    </div>

                    <div className="w-full overflow-x-auto">
                        <table className="w-full min-w-240 border-collapse text-left text-sm">
                            <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-900 dark:text-slate-400">
                                <tr>
                                    <th className="px-4 py-3 font-semibold">
                                        Document
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Date
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Company
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        From
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        To
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Status
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Created by
                                    </th>
                                    <th className="px-4 py-3 text-right font-semibold">
                                        Actions
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {stockTransfers.data.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={8}
                                            className="px-4 py-16 text-center text-slate-500 dark:text-slate-400"
                                        >
                                            No stock transfers found.
                                            Try adjusting your filters.
                                        </td>
                                    </tr>
                                ) : (
                                    stockTransfers.data.map(
                                        (transfer) => (
                                            <tr
                                                key={transfer.id}
                                                className="hover:bg-slate-50/70 dark:hover:bg-slate-900/60"
                                            >
                                                <td className="px-4 py-3 font-semibold text-indigo-700 dark:text-indigo-400">
                                                    <Link
                                                        href={`${BASE}/${transfer.id}`}
                                                        className="hover:underline"
                                                    >
                                                        {transfer.document_number}
                                                    </Link>
                                                </td>

                                                <td className="whitespace-nowrap px-4 py-3 text-slate-700 dark:text-slate-300">
                                                    {dateLabel(
                                                        transfer.transfer_date
                                                    )}
                                                </td>

                                                <td className="px-4 py-3 text-slate-700 dark:text-slate-300">
                                                    {transfer.company?.name ??
                                                        '—'}
                                                </td>

                                                <td className="px-4 py-3 text-slate-700 dark:text-slate-300">
                                                    {warehouseLabel(
                                                        transfer.source_warehouse
                                                    )}
                                                </td>

                                                <td className="px-4 py-3 text-slate-700 dark:text-slate-300">
                                                    {warehouseLabel(
                                                        transfer.destination_warehouse
                                                    )}
                                                </td>

                                                <td className="px-4 py-3">
                                                    <span
                                                        className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${
                                                            transfer.status ===
                                                            'posted'
                                                                ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                                                                : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'
                                                        }`}
                                                    >
                                                        {transfer.status
                                                            .charAt(0)
                                                            .toUpperCase() +
                                                            transfer.status.slice(
                                                                1
                                                            )}
                                                    </span>
                                                </td>

                                                <td className="px-4 py-3 text-slate-700 dark:text-slate-300">
                                                    {transfer.creator?.name ??
                                                        '—'}
                                                </td>

                                                <td className="px-4 py-3">
                                                    <div className="flex items-center justify-end gap-3 whitespace-nowrap">
                                                        <Link
                                                            href={`${BASE}/${transfer.id}`}
                                                            className="font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                                                        >
                                                            View
                                                        </Link>

                                                        {transfer.status ===
                                                            'draft' && (
                                                            <>
                                                                <Link
                                                                    href={`${BASE}/${transfer.id}/edit`}
                                                                    className="font-medium text-slate-700 hover:underline dark:text-slate-200"
                                                                >
                                                                    Edit
                                                                </Link>

                                                                <button
                                                                    type="button"
                                                                    disabled={
                                                                        deletingId !==
                                                                        null
                                                                    }
                                                                    onClick={() =>
                                                                        deleteDraft(
                                                                            transfer
                                                                        )
                                                                    }
                                                                    className="font-medium text-red-600 hover:underline disabled:cursor-not-allowed disabled:opacity-50 dark:text-red-400"
                                                                >
                                                                    {deletingId ===
                                                                    transfer.id
                                                                        ? 'Deleting…'
                                                                        : 'Delete'}
                                                                </button>
                                                            </>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        )
                                    )
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    {stockTransfers.last_page > 1 && (
                        <nav
                            aria-label="Stock transfer pagination"
                            className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 dark:border-slate-800"
                        >
                            <span className="text-xs text-slate-500 dark:text-slate-400">
                                Page {stockTransfers.current_page} of{' '}
                                {stockTransfers.last_page}
                            </span>

                            <div className="flex flex-wrap gap-1">
                                {stockTransfers.links.map(
                                    (link, index) => (
                                        <button
                                            key={`${index}-${link.label}`}
                                            type="button"
                                            disabled={
                                                !link.url || link.active
                                            }
                                            aria-current={
                                                link.active
                                                    ? 'page'
                                                    : undefined
                                            }
                                            onClick={() => {
                                                if (link.url) {
                                                    router.get(
                                                        link.url,
                                                        {},
                                                        {
                                                            preserveState: true,
                                                            preserveScroll: true,
                                                        }
                                                    );
                                                }
                                            }}
                                            className={`min-w-9 rounded-md border px-2.5 py-1.5 text-xs font-medium ${
                                                link.active
                                                    ? 'border-indigo-600 bg-indigo-600 text-white'
                                                    : 'border-slate-200 text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-900'
                                            } disabled:cursor-default disabled:opacity-50`}
                                        >
                                            {link.label.includes('Previous')
                                                ? '‹ Previous'
                                                : link.label.includes('Next')
                                                  ? 'Next ›'
                                                  : link.label}
                                        </button>
                                    )
                                )}
                            </div>
                        </nav>
                    )}
                </section>
            </main>
        </ERPLayout>
    );
}


import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import ERPLayout from '@/Layouts/erp-layout';

type Company = {
    id: number;
    name: string;
} | null;

type Warehouse = {
    id: number;
    name: string;
    code?: string | null;
} | null;

type User = {
    id: number;
    name: string;
} | null;

type Product = {
    id: number;
    name: string;
    sku?: string | null;
} | null;

type Variant = {
    id: number;
    name?: string | null;
    sku?: string | null;
} | null;

type TransferItem = {
    id: number;
    product_id: number;
    product_variant_id: number | null;
    quantity: string | number;
    unit_cost: string | number | null;
    total_cost: string | number | null;
    product: Product;
    variant: Variant;
};

type StockTransfer = {
    id: number;
    document_number: string;
    transfer_date: string;
    company_id: number;
    status: string;
    notes: string | null;
    created_at?: string | null;
    posted_at: string | null;
    company: Company;
    source_warehouse: Warehouse;
    destination_warehouse: Warehouse;
    creator: User;
    poster: User;
    items: TransferItem[];
};

type Props = {
    stockTransfer: StockTransfer;
};

const BASE = '/erp/inventory/stock-transfers';

const cardClass =
    'min-w-0 rounded-xl border border-slate-200 ' +
    'bg-white p-5 shadow-sm dark:border-slate-800 ' +
    'dark:bg-slate-950';

function displayDate(
    value: string | null | undefined,
    withTime = false
): string {
    if (!value) return '—';

    const date = new Date(
        value.length === 10
            ? `${value}T00:00:00`
            : value
    );

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        ...(withTime
            ? {
                  hour: '2-digit' as const,
                  minute: '2-digit' as const,
              }
            : {}),
    }).format(date);
}

function formatQuantity(
    value: string | number | null | undefined
): string {
    const number = Number(value ?? 0);

    return Number.isFinite(number)
        ? number.toLocaleString('en-US', {
              minimumFractionDigits: 0,
              maximumFractionDigits: 4,
          })
        : '—';
}

function formatCost(
    value: string | number | null | undefined
): string {
    if (value === null || value === undefined) {
        return '—';
    }

    const number = Number(value);

    return Number.isFinite(number)
        ? number.toLocaleString('en-US', {
              minimumFractionDigits: 2,
              maximumFractionDigits: 4,
          })
        : '—';
}

function warehouseName(warehouse: Warehouse): string {
    if (!warehouse) return '—';

    return warehouse.code
        ? `${warehouse.name} (${warehouse.code})`
        : warehouse.name;
}

function Detail({
    label,
    value,
}: {
    label: string;
    value: React.ReactNode;
}) {
    return (
        <div className="min-w-0">
            <dt className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                {label}
            </dt>

            <dd className="mt-1 wrap-break-words text-sm font-medium text-slate-900 dark:text-slate-100">
                {value}
            </dd>
        </div>
    );
}

export default function Show({
    stockTransfer,
}: Props) {
    const [confirmOpen, setConfirmOpen] =
        useState(false);

    const [posting, setPosting] =
        useState(false);

    const isDraft =
        stockTransfer.status === 'draft';

    const isPosted =
        stockTransfer.status === 'posted';

    const totalQuantity =
        stockTransfer.items.reduce(
            (sum, item) =>
                sum + Number(item.quantity || 0),
            0
        );

    const hasPostedCosts =
        isPosted &&
        stockTransfer.items.every(
            (item) =>
                item.unit_cost !== null &&
                item.total_cost !== null
        );

    const totalCost = hasPostedCosts
        ? stockTransfer.items.reduce(
              (sum, item) =>
                  sum + Number(item.total_cost ?? 0),
              0
          )
        : null;

    const postTransfer = () => {
        if (!isDraft || posting) return;

        setPosting(true);

        router.post(
            `${BASE}/${stockTransfer.id}/post`,
            {},
            {
                preserveScroll: true,

                onSuccess: () => {
                    setConfirmOpen(false);
                },

                onFinish: () => {
                    setPosting(false);
                },
            }
        );
    };

    return (
        <ERPLayout>
            <Head
                title={`Stock Transfer ${stockTransfer.document_number}`}
            />

            <main className="mx-auto w-full min-w-0 max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                {/* Header */}
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0">
                        <Link
                            href={BASE}
                            className="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                        >
                            ← Back to Stock Transfers
                        </Link>

                        <h1 className="mt-3 break-all text-2xl font-bold text-slate-900 dark:text-white">
                            {stockTransfer.document_number}
                        </h1>

                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            Warehouse-to-warehouse stock
                            transfer
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <span
                            className={`inline-flex rounded-full px-3 py-1.5 text-xs font-semibold ${
                                isPosted
                                    ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                                    : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'
                            }`}
                        >
                            {stockTransfer.status
                                .charAt(0)
                                .toUpperCase() +
                                stockTransfer.status.slice(1)}
                        </span>

                        {isDraft && (
                            <>
                                <Link
                                    href={`${BASE}/${stockTransfer.id}/edit`}
                                    className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-900"
                                >
                                    Edit Draft
                                </Link>

                                <button
                                    type="button"
                                    onClick={() =>
                                        setConfirmOpen(true)
                                    }
                                    disabled={posting}
                                    className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                                >
                                    Post Transfer
                                </button>
                            </>
                        )}
                    </div>
                </div>

                {/* Status Notice */}
                {isPosted ? (
                    <div
                        role="status"
                        className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200"
                    >
                        This transfer has been posted.
                        Inventory movements are complete,
                        and the document is read-only.
                    </div>
                ) : (
                    <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                        This document is a draft.
                        Inventory will not change until
                        the transfer is posted successfully.
                    </div>
                )}

                {/* Summary */}
                <section className={cardClass}>
                    <h2 className="mb-5 text-lg font-semibold text-slate-900 dark:text-white">
                        Transfer Information
                    </h2>

                    <dl className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        <Detail
                            label="Document Number"
                            value={
                                stockTransfer.document_number
                            }
                        />

                        <Detail
                            label="Transfer Date"
                            value={displayDate(
                                stockTransfer.transfer_date
                            )}
                        />

                        <Detail
                            label="Company"
                            value={
                                stockTransfer.company?.name ??
                                '—'
                            }
                        />

                        <Detail
                            label="Status"
                            value={
                                stockTransfer.status.toUpperCase()
                            }
                        />

                        <Detail
                            label="Created By"
                            value={
                                stockTransfer.creator?.name ??
                                '—'
                            }
                        />

                        <Detail
                            label="Created At"
                            value={displayDate(
                                stockTransfer.created_at,
                                true
                            )}
                        />

                        <Detail
                            label="Posted By"
                            value={
                                stockTransfer.poster?.name ??
                                '—'
                            }
                        />

                        <Detail
                            label="Posted At"
                            value={displayDate(
                                stockTransfer.posted_at,
                                true
                            )}
                        />
                    </dl>
                </section>

                {/* Warehouse Flow */}
                <section className="grid min-w-0 gap-4 md:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] md:items-center">
                    <div className={cardClass}>
                        <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Source Warehouse
                        </p>

                        <p className="mt-2 wrap-break-words text-lg font-bold text-slate-900 dark:text-white">
                            {warehouseName(
                                stockTransfer.source_warehouse
                            )}
                        </p>

                        <p className="mt-2 text-sm text-red-600 dark:text-red-400">
                            Stock deducted when posted
                        </p>
                    </div>

                    <div className="text-center text-2xl font-bold text-indigo-600">
                        <span className="md:hidden">↓</span>
                        <span className="hidden md:inline">
                            →
                        </span>
                    </div>

                    <div className={cardClass}>
                        <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Destination Warehouse
                        </p>

                        <p className="mt-2 wrap-break-words text-lg font-bold text-slate-900 dark:text-white">
                            {warehouseName(
                                stockTransfer.destination_warehouse
                            )}
                        </p>

                        <p className="mt-2 text-sm text-emerald-600 dark:text-emerald-400">
                            Stock received when posted
                        </p>
                    </div>
                </section>

                {/* Items */}
                <section className="min-w-0 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-950">
                    <div className="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                        <h2 className="text-lg font-semibold text-slate-900 dark:text-white">
                            Transfer Items
                        </h2>

                        <p className="mt-1 text-xs text-slate-500">
                            {stockTransfer.items.length} line
                            item(s)
                        </p>
                    </div>

                    <div className="w-full overflow-x-auto">
                        <table className="w-full min-w-190 text-left text-sm">
                            <thead className="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-900 dark:text-slate-400">
                                <tr>
                                    <th className="px-4 py-3">
                                        #
                                    </th>
                                    <th className="px-4 py-3">
                                        Product
                                    </th>
                                    <th className="px-4 py-3">
                                        Variant
                                    </th>
                                    <th className="px-4 py-3 text-right">
                                        Quantity
                                    </th>
                                    <th className="px-4 py-3 text-right">
                                        Unit Cost
                                    </th>
                                    <th className="px-4 py-3 text-right">
                                        Total Cost
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {stockTransfer.items.map(
                                    (item, index) => (
                                        <tr key={item.id}>
                                            <td className="px-4 py-3 text-slate-500">
                                                {index + 1}
                                            </td>

                                            <td className="px-4 py-3">
                                                <p className="font-semibold text-slate-900 dark:text-white">
                                                    {item.product?.name ??
                                                        `Product #${item.product_id}`}
                                                </p>

                                                {item.product?.sku && (
                                                    <p className="mt-1 text-xs text-slate-500">
                                                        SKU:{' '}
                                                        {item.product.sku}
                                                    </p>
                                                )}
                                            </td>

                                            <td className="px-4 py-3 text-slate-700 dark:text-slate-300">
                                                {item.variant?.name ??
                                                    item.variant?.sku ??
                                                    (item.product_variant_id
                                                        ? `Variant #${item.product_variant_id}`
                                                        : '—')}
                                            </td>

                                            <td className="px-4 py-3 text-right font-semibold tabular-nums">
                                                {formatQuantity(
                                                    item.quantity
                                                )}
                                            </td>

                                            <td className="px-4 py-3 text-right tabular-nums">
                                                {isPosted
                                                    ? formatCost(
                                                          item.unit_cost
                                                      )
                                                    : 'Pending'}
                                            </td>

                                            <td className="px-4 py-3 text-right tabular-nums">
                                                {isPosted
                                                    ? formatCost(
                                                          item.total_cost
                                                      )
                                                    : 'Pending'}
                                            </td>
                                        </tr>
                                    )
                                )}

                                {stockTransfer.items.length ===
                                    0 && (
                                    <tr>
                                        <td
                                            colSpan={6}
                                            className="px-4 py-10 text-center text-slate-500"
                                        >
                                            No transfer items.
                                        </td>
                                    </tr>
                                )}
                            </tbody>

                            <tfoot className="border-t border-slate-200 bg-slate-50 font-semibold dark:border-slate-800 dark:bg-slate-900">
                                <tr>
                                    <td
                                        colSpan={3}
                                        className="px-4 py-3"
                                    >
                                        Total
                                    </td>

                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {formatQuantity(
                                            totalQuantity
                                        )}
                                    </td>

                                    <td className="px-4 py-3" />

                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {totalCost === null
                                            ? '—'
                                            : formatCost(
                                                  totalCost
                                              )}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <p className="px-5 py-3 text-xs text-slate-500 dark:text-slate-400">
                        Cost values are shown in the
                        company's base currency and become
                        available after posting.
                    </p>
                </section>

                {/* Notes */}
                <section className={cardClass}>
                    <h2 className="mb-3 text-lg font-semibold text-slate-900 dark:text-white">
                        Notes
                    </h2>

                    <p className="whitespace-pre-wrap wrap-break-words text-sm text-slate-700 dark:text-slate-300">
                        {stockTransfer.notes?.trim() ||
                            'No notes added.'}
                    </p>
                </section>

                {/* Confirmation Dialog */}
                {confirmOpen && isDraft && (
                    <div
                        className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
                        role="presentation"
                    >
                        <div
                            role="alertdialog"
                            aria-modal="true"
                            aria-labelledby="post-title"
                            aria-describedby="post-description"
                            className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl dark:bg-slate-950"
                        >
                            <h2
                                id="post-title"
                                className="text-lg font-bold text-slate-900 dark:text-white"
                            >
                                Confirm Stock Transfer
                            </h2>

                            <p
                                id="post-description"
                                className="mt-3 text-sm leading-6 text-slate-600 dark:text-slate-300"
                            >
                                Posting{' '}
                                <strong>
                                    {stockTransfer.document_number}
                                </strong>{' '}
                                will deduct stock from{' '}
                                <strong>
                                    {warehouseName(
                                        stockTransfer.source_warehouse
                                    )}
                                </strong>{' '}
                                and add it to{' '}
                                <strong>
                                    {warehouseName(
                                        stockTransfer.destination_warehouse
                                    )}
                                </strong>
                                .
                            </p>

                            <p className="mt-3 text-sm font-semibold text-amber-700 dark:text-amber-400">
                                This action cannot be undone
                                through the draft editor.
                            </p>

                            <div className="mt-6 flex flex-wrap justify-end gap-3">
                                <button
                                    type="button"
                                    disabled={posting}
                                    onClick={() =>
                                        setConfirmOpen(false)
                                    }
                                    className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold dark:border-slate-700"
                                >
                                    Cancel
                                </button>

                                <button
                                    type="button"
                                    onClick={postTransfer}
                                    disabled={posting}
                                    className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                                >
                                    {posting
                                        ? 'Posting...'
                                        : 'Confirm & Post'}
                                </button>
                            </div>
                        </div>
                    </div>
                )}
            </main>
        </ERPLayout>
    );
}

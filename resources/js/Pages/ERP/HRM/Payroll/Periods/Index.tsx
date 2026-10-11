import {
    Head,
    Link,
    router,
} from "@inertiajs/react";
import {
    CalendarDays,
    FilePenLine,
    LockKeyhole,
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

type PayrollPeriod = {
    id: number;

    company_id:
        number;

    name:
        string;

    code:
        string;

    start_date:
        string;

    end_date:
        string;

    payment_date:
        string | null;

    status:
        | "draft"
        | "open"
        | "processing"
        | "closed";

    is_locked:
        boolean;

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

type PaginatedPeriods = {
    data:
        PayrollPeriod[];

    links:
        PaginationLink[];

    total:
        number;
};

type Filters = {
    company_id?:
        string | number;

    status?:
        string;

    year?:
        string | number;

    search?:
        string;
};

type Props = {
    periods:
        PaginatedPeriods;

    companies:
        Company[];

    filters:
        Filters;
};

function statusClasses(
    status:
        PayrollPeriod["status"]
): string {
    switch (status) {
        case "open":
            return "bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400";

        case "processing":
            return "bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-400";

        case "closed":
            return "bg-slate-200 text-slate-700 dark:bg-slate-800 dark:text-slate-300";

        default:
            return "bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400";
    }
}

export default function Index({
    periods,
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
        year,
        setYear,
    ] = useState(
        filters.year
            ? String(
                  filters.year
              )
            : ""
    );

    const [
        deletingPeriod,
        setDeletingPeriod,
    ] =
        useState<PayrollPeriod | null>(
            null
        );

    const applyFilters =
        () => {
            router.get(
                "/erp/payroll/periods",
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

                    year:
                        year ||
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
            setYear("");

            router.get(
                "/erp/payroll/periods"
            );
        };

    const confirmDelete =
        () => {
            if (
                !deletingPeriod
            ) {
                return;
            }

            router.delete(
                `/erp/payroll/periods/${deletingPeriod.id}`,
                {
                    preserveScroll:
                        true,

                    onSuccess:
                        () =>
                            setDeletingPeriod(
                                null
                            ),
                }
            );
        };

    return (
        <ERPLayout>
            <Head title="Payroll Periods" />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Payroll Periods
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Define the
                            payroll calendar
                            that future
                            payroll runs will
                            use.
                        </p>
                    </div>

                    {can(
                        "erp.payroll-periods.create"
                    ) && (
                        <Link
                            href="/erp/payroll/periods/create"
                            className="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-slate-900 px-4 text-sm font-medium text-white hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
                        >
                            <Plus className="h-4 w-4" />

                            New Payroll
                            Period
                        </Link>
                    )}
                </div>

                <div className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                        <div className="relative">
                            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />

                            <input
                                type="text"
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
                                    event
                                        .target
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
                                    event
                                        .target
                                        .value
                                )
                            }
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
                            <option value="">
                                All Statuses
                            </option>

                            <option value="draft">
                                Draft
                            </option>

                            <option value="open">
                                Open
                            </option>

                            <option value="processing">
                                Processing
                            </option>

                            <option value="closed">
                                Closed
                            </option>
                        </select>

                        <input
                            type="number"
                            min="2000"
                            max="2100"
                            value={
                                year
                            }
                            onChange={(
                                event
                            ) =>
                                setYear(
                                    event
                                        .target
                                        .value
                                )
                            }
                            placeholder="Year"
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        />

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
                                className="h-10 rounded-md border border-slate-200 px-4 text-sm hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
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
                                        Period
                                    </th>

                                    <th className="px-4 py-3">
                                        Company
                                    </th>

                                    <th className="px-4 py-3">
                                        Date Range
                                    </th>

                                    <th className="px-4 py-3">
                                        Payment
                                    </th>

                                    <th className="px-4 py-3">
                                        Status
                                    </th>

                                    <th className="px-4 py-3">
                                        Lock
                                    </th>

                                    <th className="px-4 py-3 text-right">
                                        Actions
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {periods.data.map(
                                    (
                                        period
                                    ) => (
                                        <tr
                                            key={
                                                period.id
                                            }
                                        >
                                            <td className="px-4 py-4">
                                                <div className="flex items-start gap-3">
                                                    <div className="mt-0.5 flex h-9 w-9 items-center justify-center rounded-lg bg-slate-100 dark:bg-slate-900">
                                                        <CalendarDays className="h-4 w-4" />
                                                    </div>

                                                    <div>
                                                        <div className="font-medium">
                                                            {
                                                                period.name
                                                            }
                                                        </div>

                                                        <div className="mt-1 font-mono text-xs text-slate-500">
                                                            {
                                                                period.code
                                                            }
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>

                                            <td className="px-4 py-4">
                                                {period
                                                    .company
                                                    ?.name ??
                                                    "—"}
                                            </td>

                                            <td className="px-4 py-4">
                                                <div>
                                                    {
                                                        period.start_date
                                                    }
                                                </div>

                                                <div className="mt-1 text-xs text-slate-500">
                                                    to{" "}
                                                    {
                                                        period.end_date
                                                    }
                                                </div>
                                            </td>

                                            <td className="px-4 py-4">
                                                {period.payment_date ??
                                                    "Not set"}
                                            </td>

                                            <td className="px-4 py-4">
                                                <span
                                                    className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${statusClasses(
                                                        period.status
                                                    )}`}
                                                >
                                                    {
                                                        period.status
                                                    }
                                                </span>
                                            </td>

                                            <td className="px-4 py-4">
                                                {period.is_locked ? (
                                                    <span className="inline-flex items-center gap-1.5 text-xs font-medium text-red-600 dark:text-red-400">
                                                        <LockKeyhole className="h-3.5 w-3.5" />

                                                        Locked
                                                    </span>
                                                ) : (
                                                    <span className="text-xs text-slate-500">
                                                        Unlocked
                                                    </span>
                                                )}
                                            </td>

                                            <td className="px-4 py-4">
                                                <div className="flex justify-end gap-2">
                                                    {can(
                                                        "erp.payroll-periods.edit"
                                                    ) &&
                                                        !period.is_locked &&
                                                        period.status !==
                                                            "processing" && (
                                                            <Link
                                                                href={`/erp/payroll/periods/${period.id}/edit`}
                                                                className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                                                                title="Edit"
                                                            >
                                                                <FilePenLine className="h-4 w-4" />
                                                            </Link>
                                                        )}

                                                    {can(
                                                        "erp.payroll-periods.delete"
                                                    ) &&
                                                        !period.is_locked &&
                                                        period.status ===
                                                            "draft" && (
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    setDeletingPeriod(
                                                                        period
                                                                    )
                                                                }
                                                                className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-red-200 text-red-600 hover:bg-red-50 dark:border-red-900 dark:text-red-400 dark:hover:bg-red-950/30"
                                                                title="Delete"
                                                            >
                                                                <Trash2 className="h-4 w-4" />
                                                            </button>
                                                        )}
                                                </div>
                                            </td>
                                        </tr>
                                    )
                                )}

                                {periods.data
                                    .length ===
                                    0 && (
                                    <tr>
                                        <td
                                            colSpan={
                                                7
                                            }
                                            className="px-4 py-16 text-center text-slate-500"
                                        >
                                            No payroll
                                            periods
                                            found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {periods.links
                        .length >
                        3 && (
                        <div className="flex flex-wrap gap-1 border-t border-slate-200 p-4 dark:border-slate-800">
                            {periods.links.map(
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
                                                : "border border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                                        } disabled:opacity-40`}
                                    />
                                )
                            )}
                        </div>
                    )}
                </div>
            </div>

            {deletingPeriod && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-slate-950">
                        <div className="flex h-12 w-12 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-950/50 dark:text-red-400">
                            <Trash2 className="h-5 w-5" />
                        </div>

                        <h2 className="mt-4 text-lg font-semibold">
                            Delete Payroll
                            Period
                        </h2>

                        <p className="mt-2 text-sm leading-6 text-slate-500">
                            Delete{" "}
                            <span className="font-medium text-slate-900 dark:text-white">
                                {
                                    deletingPeriod.name
                                }
                            </span>
                            ? Only unused
                            draft periods
                            should be
                            deleted.
                        </p>

                        <div className="mt-6 flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={() =>
                                    setDeletingPeriod(
                                        null
                                    )
                                }
                                className="h-10 rounded-md border border-slate-200 px-4 text-sm font-medium dark:border-slate-800"
                            >
                                Cancel
                            </button>

                            <button
                                type="button"
                                onClick={
                                    confirmDelete
                                }
                                className="inline-flex h-10 items-center gap-2 rounded-md bg-red-600 px-4 text-sm font-medium text-white hover:bg-red-700"
                            >
                                <Trash2 className="h-4 w-4" />

                                Delete
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </ERPLayout>
    );
}
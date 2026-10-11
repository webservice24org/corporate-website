import {
    Head,
    Link,
    router,
} from "@inertiajs/react";
import {
    CalendarRange,
    Edit,
    Filter,
    Plus,
    RotateCcw,
    Trash2,
} from "lucide-react";
import { useState } from "react";

import ERPLayout from "@/Layouts/erp-layout";
import { useAuthorization } from "@/hooks/useAuthorization";

type Company = {
    id: number;
    name: string;
};

type LeaveType = {
    id: number;
    company_id: number;

    name: string;
    code: string;

    annual_allowance:
        | number
        | string;

    is_paid: boolean;
    allow_half_day: boolean;

    carry_forward: boolean;

    max_carry_forward:
        | number
        | string;

    requires_attachment: boolean;

    exclude_weekly_off: boolean;
    exclude_holidays: boolean;

    status: string;

    description:
        | string
        | null;

    company: Company;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type Pagination = {
    data: LeaveType[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    total: number;
};

type Filters = {
    search?: string;
    company_id?: string;
};

type Props = {
    leaveTypes: Pagination;
    companies: Company[];
    filters: Filters;
};

export default function Index({
    leaveTypes,
    companies,
    filters,
}: Props) {
    const { can } =
        useAuthorization();

    const [search, setSearch] =
        useState(
            filters.search ?? ""
        );

    const [
        companyId,
        setCompanyId,
    ] = useState(
        filters.company_id ?? ""
    );

    const applyFilters = () => {
        router.get(
            "/erp/leave/types",
            {
                search:
                    search ||
                    undefined,

                company_id:
                    companyId ||
                    undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            }
        );
    };

    const resetFilters = () => {
        setSearch("");
        setCompanyId("");

        router.get(
            "/erp/leave/types"
        );
    };

    const remove = (
        leaveType: LeaveType
    ) => {
        const confirmed =
            window.confirm(
                `Delete "${leaveType.name}"?`
            );

        if (!confirmed) {
            return;
        }

        router.delete(
            `/erp/leave/types/${leaveType.id}`,
            {
                preserveScroll: true,
            }
        );
    };

    return (
        <ERPLayout>
            <Head title="Leave Types" />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Leave Types
                        </h1>

                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            Configure leave policies,
                            yearly entitlement and leave
                            calculation rules.
                        </p>
                    </div>

                    {can(
                        "erp.leave-types.create"
                    ) && (
                        <Link
                            href="/erp/leave/types/create"
                            className="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-slate-900 px-4 text-sm font-medium text-white hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
                        >
                            <Plus className="h-4 w-4" />

                            New Leave Type
                        </Link>
                    )}
                </div>

                <div className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                    <div className="grid gap-3 md:grid-cols-[1fr_250px_auto]">
                        <input
                            type="text"
                            value={search}
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
                            placeholder="Search name or code..."
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm outline-none dark:border-slate-800 dark:bg-slate-950"
                        />

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
                                All companies
                            </option>

                            {companies.map(
                                (company) => (
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

                        <div className="flex gap-2">
                            <button
                                type="button"
                                onClick={
                                    applyFilters
                                }
                                className="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-slate-900 px-4 text-sm font-medium text-white dark:bg-white dark:text-slate-950"
                            >
                                <Filter className="h-4 w-4" />

                                Filter
                            </button>

                            <button
                                type="button"
                                onClick={
                                    resetFilters
                                }
                                className="inline-flex h-10 w-10 items-center justify-center rounded-md border border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                                title="Reset"
                            >
                                <RotateCcw className="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </div>

                <div className="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-900/60">
                                <tr>
                                    <th className="px-4 py-3">
                                        Leave Type
                                    </th>

                                    <th className="px-4 py-3">
                                        Company
                                    </th>

                                    <th className="px-4 py-3 text-center">
                                        Allowance
                                    </th>

                                    <th className="px-4 py-3">
                                        Payment
                                    </th>

                                    <th className="px-4 py-3">
                                        Rules
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
                                {leaveTypes.data.map(
                                    (
                                        leaveType
                                    ) => (
                                        <tr
                                            key={
                                                leaveType.id
                                            }
                                            className="hover:bg-slate-50 dark:hover:bg-slate-900/40"
                                        >
                                            <td className="px-4 py-4">
                                                <div className="font-medium">
                                                    {
                                                        leaveType.name
                                                    }
                                                </div>

                                                <div className="mt-1 text-xs text-slate-500">
                                                    Code:{" "}
                                                    {
                                                        leaveType.code
                                                    }
                                                </div>
                                            </td>

                                            <td className="px-4 py-4">
                                                {
                                                    leaveType.company
                                                        ?.name
                                                }
                                            </td>

                                            <td className="px-4 py-4 text-center font-medium tabular-nums">
                                                {
                                                    leaveType.annual_allowance
                                                }{" "}
                                                days
                                            </td>

                                            <td className="px-4 py-4">
                                                <span
                                                    className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${
                                                        leaveType.is_paid
                                                            ? "bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300"
                                                            : "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300"
                                                    }`}
                                                >
                                                    {leaveType.is_paid
                                                        ? "Paid"
                                                        : "Unpaid"}
                                                </span>
                                            </td>

                                            <td className="px-4 py-4">
                                                <div className="flex max-w-xs flex-wrap gap-1">
                                                    {leaveType.allow_half_day && (
                                                        <RuleBadge>
                                                            Half
                                                            Day
                                                        </RuleBadge>
                                                    )}

                                                    {leaveType.carry_forward && (
                                                        <RuleBadge>
                                                            Carry
                                                            Forward
                                                        </RuleBadge>
                                                    )}

                                                    {leaveType.requires_attachment && (
                                                        <RuleBadge>
                                                            Attachment
                                                        </RuleBadge>
                                                    )}

                                                    {leaveType.exclude_weekly_off && (
                                                        <RuleBadge>
                                                            Exclude
                                                            Off
                                                            Days
                                                        </RuleBadge>
                                                    )}

                                                    {leaveType.exclude_holidays && (
                                                        <RuleBadge>
                                                            Exclude
                                                            Holidays
                                                        </RuleBadge>
                                                    )}
                                                </div>
                                            </td>

                                            <td className="px-4 py-4">
                                                <span
                                                    className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium capitalize ${
                                                        leaveType.status ===
                                                        "active"
                                                            ? "bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300"
                                                            : "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300"
                                                    }`}
                                                >
                                                    {
                                                        leaveType.status
                                                    }
                                                </span>
                                            </td>

                                            <td className="px-4 py-4">
                                                <div className="flex justify-end gap-2">
                                                    {can(
                                                        "erp.leave-types.edit"
                                                    ) && (
                                                        <Link
                                                            href={`/erp/leave/types/${leaveType.id}/edit`}
                                                            className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-200 hover:bg-slate-100 dark:border-slate-800 dark:hover:bg-slate-800"
                                                            title="Edit"
                                                        >
                                                            <Edit className="h-4 w-4" />
                                                        </Link>
                                                    )}

                                                    {can(
                                                        "erp.leave-types.delete"
                                                    ) && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                remove(
                                                                    leaveType
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

                                {leaveTypes.data
                                    .length ===
                                    0 && (
                                    <tr>
                                        <td
                                            colSpan={
                                                7
                                            }
                                            className="px-4 py-16 text-center"
                                        >
                                            <CalendarRange className="mx-auto h-10 w-10 text-slate-300" />

                                            <h3 className="mt-3 font-medium">
                                                No
                                                leave
                                                types
                                                found
                                            </h3>

                                            <p className="mt-1 text-sm text-slate-500">
                                                Create
                                                your
                                                first
                                                leave
                                                type
                                                to
                                                start
                                                managing
                                                employee
                                                leave.
                                            </p>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex flex-col gap-3 border-t border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
                        <div className="text-sm text-slate-500">
                            Total:{" "}
                            {
                                leaveTypes.total
                            }
                        </div>

                        {leaveTypes.links
                            .length > 3 && (
                            <div className="flex flex-wrap gap-1">
                                {leaveTypes.links.map(
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
                                                            preserveState:
                                                                true,
                                                        }
                                                    );
                                                }
                                            }}
                                            className={`min-w-9 rounded-md px-3 py-2 text-sm ${
                                                link.active
                                                    ? "bg-slate-900 text-white dark:bg-white dark:text-slate-950"
                                                    : "border border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                                            } disabled:cursor-not-allowed disabled:opacity-40`}
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
            </div>
        </ERPLayout>
    );
}

function RuleBadge({
    children,
}: {
    children: string;
}) {
    return (
        <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600 dark:bg-slate-800 dark:text-slate-300">
            {children}
        </span>
    );
}
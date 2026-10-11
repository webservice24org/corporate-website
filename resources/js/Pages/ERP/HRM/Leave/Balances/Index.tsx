import {
    Head,
    router,
} from "@inertiajs/react";
import {
    CalendarRange,
    Filter,
    RotateCcw,
    SlidersHorizontal,
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
};

type Employee = {
    id: number;
    employee_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;

    company?: {
        id: number;
        name: string;
    } | null;

    branch?: {
        id: number;
        name: string;
    } | null;

    department?: {
        id: number;
        name: string;
    } | null;
};

type Balance = {
    id: number;
    year: number;

    opening_balance: number;
    entitled_days: number;
    adjustment_days: number;
    used_days: number;
    pending_days: number;
    available_days: number;

    employee: Employee;

    leave_type: {
        id: number;
        name: string;
        code: string;
        is_paid: boolean;
    };
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedBalances = {
    data: Balance[];
    links: PaginationLink[];
    total: number;
};

type Filters = {
    search?: string;
    company_id?: string;
    leave_type_id?: string;
    year?: string;
};

type Props = {
    balances: PaginatedBalances;
    companies: Company[];
    leaveTypes: LeaveType[];
    filters: Filters;
};

function employeeName(
    employee: Employee
): string {
    return [
        employee.first_name,
        employee.middle_name,
        employee.last_name,
    ]
        .filter(Boolean)
        .join(" ");
}

function amount(
    value: number
): string {
    return Number(value).toFixed(2);
}

export default function Index({
    balances,
    companies,
    leaveTypes,
    filters,
}: Props) {
    const [search, setSearch] =
        useState(
            filters.search ?? ""
        );

    const [companyId, setCompanyId] =
        useState(
            filters.company_id ?? ""
        );

    const [
        leaveTypeId,
        setLeaveTypeId,
    ] = useState(
        filters.leave_type_id ?? ""
    );

    const [year, setYear] =
        useState(
            filters.year ??
                String(
                    new Date().getFullYear()
                )
        );

    const { can } =
        useAuthorization();

    const [
        adjustingBalance,
        setAdjustingBalance,
    ] = useState<Balance | null>(
        null
    );

    const [
        adjustmentDays,
        setAdjustmentDays,
    ] = useState("");

    const [
        adjustmentReason,
        setAdjustmentReason,
    ] = useState("");

    const applyFilters = () => {
        router.get(
            "/erp/leave/balances",
            {
                search:
                    search || undefined,

                company_id:
                    companyId || undefined,

                leave_type_id:
                    leaveTypeId ||
                    undefined,

                year:
                    year || undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
            }
        );
    };

    const resetFilters = () => {
        const currentYear =
            String(
                new Date().getFullYear()
            );

        setSearch("");
        setLeaveTypeId("");
        setYear(currentYear);

        router.get(
            "/erp/leave/balances",
            {
                company_id:
                    companyId ||
                    undefined,

                year:
                    currentYear,
            }
        );
    };

    return (
        <ERPLayout>
            <Head title="Leave Balances" />

            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Leave Balances
                    </h1>

                    <p className="mt-1 text-sm text-slate-500">
                        View employee entitlement,
                        usage, pending leave and
                        available leave balances.
                    </p>
                </div>

                <div className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                    <div className="grid gap-3 lg:grid-cols-5">
                        <input
                            value={search}
                            onChange={(event) =>
                                setSearch(
                                    event.target
                                        .value
                                )
                            }
                            placeholder="Employee name or number"
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        />

                        <select
                            value={
                                companyId
                            }
                            onChange={(
                                event
                            ) => {
                                setCompanyId(
                                    event.target
                                        .value
                                );

                                setLeaveTypeId(
                                    ""
                                );
                            }}
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
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

                        <select
                            value={
                                leaveTypeId
                            }
                            onChange={(
                                event
                            ) =>
                                setLeaveTypeId(
                                    event.target
                                        .value
                                )
                            }
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
                            <option value="">
                                All leave types
                            </option>

                            {leaveTypes.map(
                                (type) => (
                                    <option
                                        key={
                                            type.id
                                        }
                                        value={
                                            type.id
                                        }
                                    >
                                        {type.name} (
                                        {type.code})
                                    </option>
                                )
                            )}
                        </select>

                        <input
                            type="number"
                            min="2000"
                            max="2100"
                            value={year}
                            onChange={(event) =>
                                setYear(
                                    event.target
                                        .value
                                )
                            }
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        />

                        <div className="flex gap-2">
                            <button
                                type="button"
                                onClick={
                                    applyFilters
                                }
                                className="inline-flex h-10 flex-1 items-center justify-center gap-2 rounded-md bg-slate-900 px-3 text-sm font-medium text-white dark:bg-white dark:text-slate-950"
                            >
                                <Filter className="h-4 w-4" />

                                Filter
                            </button>

                            <button
                                type="button"
                                onClick={
                                    resetFilters
                                }
                                className="inline-flex h-10 items-center justify-center rounded-md border border-slate-200 px-3 dark:border-slate-800"
                            >
                                <RotateCcw className="h-4 w-4" />
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
                                        Employee
                                    </th>

                                    <th className="px-4 py-3">
                                        Leave Type
                                    </th>

                                    <th className="px-4 py-3 text-right">
                                        Opening
                                    </th>

                                    <th className="px-4 py-3 text-right">
                                        Entitled
                                    </th>

                                    <th className="px-4 py-3 text-right">
                                        Adjustment
                                    </th>

                                    <th className="px-4 py-3 text-right">
                                        Used
                                    </th>

                                    <th className="px-4 py-3 text-right">
                                        Pending
                                    </th>

                                    <th className="px-4 py-3 text-right">
                                        Available
                                    </th>
                                    <th className="px-4 py-3 text-right">
                                        Actions
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {balances.data.map(
                                    (balance) => (
                                        <tr
                                            key={
                                                balance.id
                                            }
                                            className="hover:bg-slate-50 dark:hover:bg-slate-900/40"
                                        >
                                            <td className="px-4 py-4">
                                                <div className="font-medium">
                                                    {employeeName(
                                                        balance.employee
                                                    )}
                                                </div>

                                                <div className="mt-1 text-xs text-slate-500">
                                                    {
                                                        balance
                                                            .employee
                                                            .employee_number
                                                    }

                                                    {balance
                                                        .employee
                                                        .department
                                                        ?.name &&
                                                        ` · ${balance.employee.department.name}`}
                                                </div>
                                            </td>

                                            <td className="px-4 py-4">
                                                <div className="font-medium">
                                                    {
                                                        balance
                                                            .leave_type
                                                            .name
                                                    }
                                                </div>

                                                <div className="mt-1 text-xs text-slate-500">
                                                    {
                                                        balance
                                                            .leave_type
                                                            .code
                                                    }

                                                    {" · "}

                                                    {balance
                                                        .leave_type
                                                        .is_paid
                                                        ? "Paid"
                                                        : "Unpaid"}
                                                </div>
                                            </td>

                                            <td className="px-4 py-4 text-right tabular-nums">
                                                {amount(
                                                    balance.opening_balance
                                                )}
                                            </td>

                                            <td className="px-4 py-4 text-right tabular-nums">
                                                {amount(
                                                    balance.entitled_days
                                                )}
                                            </td>

                                            <td className="px-4 py-4 text-right tabular-nums">
                                                {amount(
                                                    balance.adjustment_days
                                                )}
                                            </td>

                                            <td className="px-4 py-4 text-right font-medium tabular-nums text-red-600">
                                                {amount(
                                                    balance.used_days
                                                )}
                                            </td>

                                            <td className="px-4 py-4 text-right font-medium tabular-nums text-amber-600">
                                                {amount(
                                                    balance.pending_days
                                                )}
                                            </td>

                                            <td className="px-4 py-4 text-right">
                                                <span
                                                    className={`inline-flex min-w-16 justify-center rounded-full px-2.5 py-1 font-semibold tabular-nums ${
                                                        balance.available_days <
                                                        0
                                                            ? "bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300"
                                                            : "bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300"
                                                    }`}
                                                >
                                                    {amount(
                                                        balance.available_days
                                                    )}
                                                </span>
                                            </td>

                                            <td className="px-4 py-4 text-right">
                                                {can(
                                                    "erp.leave-balances.adjust"
                                                ) && (
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setAdjustingBalance(
                                                                balance
                                                            );

                                                            setAdjustmentDays("");

                                                            setAdjustmentReason("");
                                                        }}
                                                        className="inline-flex h-9 items-center gap-2 rounded-md border border-slate-200 px-3 text-xs font-medium hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                                                    >
                                                        <SlidersHorizontal className="h-4 w-4" />

                                                        Adjust
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                    )
                                )}

                                {balances.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={9}
                                            className="px-4 py-16 text-center"
                                        >
                                            <CalendarRange className="mx-auto h-10 w-10 text-slate-300" />

                                            <p className="mt-3 font-medium">
                                                No leave balances found.
                                            </p>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {balances.links.length >
                        3 && (
                        <div className="flex flex-wrap gap-1 border-t border-slate-200 p-4 dark:border-slate-800">
                            {balances.links.map(
                                (link, index) => (
                                    <button
                                        key={index}
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
                                        className={`min-w-9 rounded-md px-3 py-2 text-sm ${
                                            link.active
                                                ? "bg-slate-900 text-white dark:bg-white dark:text-slate-950"
                                                : "border border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                                        } disabled:opacity-40`}
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

            {adjustingBalance && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl dark:bg-slate-950">
                        <h2 className="text-lg font-semibold">
                            Adjust Leave Balance
                        </h2>

                        <p className="mt-1 text-sm text-slate-500">
                            {employeeName(
                                adjustingBalance.employee
                            )}
                            {" · "}
                            {
                                adjustingBalance
                                    .leave_type.name
                            }
                            {" · "}
                            {adjustingBalance.year}
                        </p>

                        <div className="mt-6 space-y-4">
                            <div>
                                <label className="mb-1.5 block text-sm font-medium">
                                    Adjustment Days
                                </label>

                                <input
                                    type="number"
                                    step="0.5"
                                    value={
                                        adjustmentDays
                                    }
                                    onChange={(
                                        event
                                    ) =>
                                        setAdjustmentDays(
                                            event.target
                                                .value
                                        )
                                    }
                                    placeholder="+2 or -1"
                                    className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                                />

                                <p className="mt-1 text-xs text-slate-500">
                                    Positive values add
                                    leave. Negative values
                                    deduct leave.
                                </p>
                            </div>

                            <div>
                                <label className="mb-1.5 block text-sm font-medium">
                                    Reason
                                </label>

                                <textarea
                                    rows={4}
                                    value={
                                        adjustmentReason
                                    }
                                    onChange={(
                                        event
                                    ) =>
                                        setAdjustmentReason(
                                            event.target
                                                .value
                                        )
                                    }
                                    className="w-full rounded-md border border-slate-200 bg-white p-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                                />
                            </div>
                        </div>

                        <div className="mt-6 flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={() =>
                                    setAdjustingBalance(
                                        null
                                    )
                                }
                                className="h-10 rounded-md border border-slate-200 px-4 text-sm dark:border-slate-800"
                            >
                                Cancel
                            </button>

                            <button
                                type="button"
                                disabled={
                                    !adjustmentDays ||
                                    Number(
                                        adjustmentDays
                                    ) === 0 ||
                                    !adjustmentReason.trim()
                                }
                                onClick={() => {
                                    router.post(
                                        "/erp/leave/balances/adjust",
                                        {
                                            employee_id:
                                                adjustingBalance
                                                    .employee
                                                    .id,

                                            leave_type_id:
                                                adjustingBalance
                                                    .leave_type
                                                    .id,

                                            year:
                                                adjustingBalance
                                                    .year,

                                            days:
                                                adjustmentDays,

                                            reason:
                                                adjustmentReason.trim(),
                                        },
                                        {
                                            preserveScroll:
                                                true,

                                            onSuccess:
                                                () => {
                                                    setAdjustingBalance(
                                                        null
                                                    );

                                                    setAdjustmentDays(
                                                        ""
                                                    );

                                                    setAdjustmentReason(
                                                        ""
                                                    );
                                                },
                                        }
                                    );
                                }}
                                className="h-10 rounded-md bg-slate-900 px-5 text-sm font-medium text-white disabled:opacity-50 dark:bg-white dark:text-slate-950"
                            >
                                Apply Adjustment
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </ERPLayout>
    );
}
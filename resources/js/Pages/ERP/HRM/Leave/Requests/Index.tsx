import { Head, Link, router } from "@inertiajs/react";
import {
    CalendarDays,
    Check,
    Eye,
    Filter,
    Plus,
    RotateCcw,
    X,
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
};

type User = {
    id: number;
    name: string;
};

type LeaveRequest = {
    id: number;
    employee_id: number;
    company_id: number;
    leave_type_id: number;

    start_date: string;
    end_date: string;

    duration_type: string;
    half_day_period: string | null;

    total_days: string | number;
    reason: string | null;

    status: string;

    created_at: string;

    employee: Employee;
    company: Company;

    leave_type: {
        id: number;
        name: string;
        code: string;
        is_paid: boolean;
    };

    approved_by?: User | null;
    rejected_by?: User | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedRequests = {
    data: LeaveRequest[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    total: number;
};

type Filters = {
    search?: string;
    company_id?: string;
    leave_type_id?: string;
    status?: string;
};

type Props = {
    leaveRequests: PaginatedRequests;
    companies: Company[];
    leaveTypes: LeaveType[];
    filters: Filters;
};

function employeeName(employee: Employee): string {
    return [
        employee.first_name,
        employee.middle_name,
        employee.last_name,
    ]
        .filter(Boolean)
        .join(" ");
}

function dateValue(value: string): string {
    return value ? value.substring(0, 10) : "";
}

function statusClass(status: string): string {
    switch (status) {
        case "approved":
            return "bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300";

        case "rejected":
            return "bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300";

        case "cancelled":
            return "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300";

        default:
            return "bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300";
    }
}

export default function Index({
    leaveRequests,
    companies,
    leaveTypes,
    filters,
}: Props) {
    const { can } = useAuthorization();

    const [search, setSearch] = useState(
        filters.search ?? ""
    );

    const [companyId, setCompanyId] = useState(
        filters.company_id ?? ""
    );

    const [leaveTypeId, setLeaveTypeId] = useState(
        filters.leave_type_id ?? ""
    );

    const [status, setStatus] = useState(
        filters.status ?? ""
    );

    const [
        approvingLeaveRequest,
        setApprovingLeaveRequest,
    ] = useState<LeaveRequest | null>(null);

    const filteredLeaveTypes =
        companyId === ""
            ? leaveTypes
            : leaveTypes.filter(
                  (type) =>
                      String(type.company_id) ===
                      companyId
              );

    const applyFilters = () => {
        router.get(
            "/erp/leave/requests",
            {
                search:
                    search || undefined,

                company_id:
                    companyId || undefined,

                leave_type_id:
                    leaveTypeId || undefined,

                status:
                    status || undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
            }
        );
    };

    const resetFilters = () => {
        setSearch("");
        setCompanyId("");
        setLeaveTypeId("");
        setStatus("");

        router.get(
            "/erp/leave/requests"
        );
    };

    const approve = (
    leaveRequest: LeaveRequest
        ) => {
            setApprovingLeaveRequest(
                leaveRequest
            );
        };

    const confirmApprove = () => {
        if (!approvingLeaveRequest) {
            return;
        }

        router.post(
            `/erp/leave/requests/${approvingLeaveRequest.id}/approve`,
            {},
            {
                preserveScroll: true,

                onSuccess: () => {
                    setApprovingLeaveRequest(
                        null
                    );
                },
            }
        );
    };

    const reject = (
        leaveRequest: LeaveRequest
    ) => {
        const reason =
            window.prompt(
                "Enter rejection reason:"
            );

        if (!reason?.trim()) {
            return;
        }

        router.post(
            `/erp/leave/requests/${leaveRequest.id}/reject`,
            {
                rejection_reason:
                    reason.trim(),
            },
            {
                preserveScroll: true,
            }
        );
    };

    return (
        <ERPLayout>
            <Head title="Leave Requests" />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Leave Requests
                        </h1>

                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            Review employee leave requests
                            and manage approval decisions.
                        </p>
                    </div>

                    {can(
                        "erp.leave-requests.create"
                    ) && (
                        <Link
                            href="/erp/leave/requests/create"
                            className="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-slate-900 px-4 text-sm font-medium text-white hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
                        >
                            <Plus className="h-4 w-4" />

                            New Leave Request
                        </Link>
                    )}
                </div>

                <div className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                    <div className="grid gap-3 lg:grid-cols-5">
                        <input
                            value={search}
                            onChange={(event) =>
                                setSearch(
                                    event.target.value
                                )
                            }
                            placeholder="Employee name or number"
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-slate-400 dark:border-slate-800 dark:bg-slate-950"
                        />

                        <select
                            value={companyId}
                            onChange={(event) => {
                                setCompanyId(
                                    event.target.value
                                );

                                setLeaveTypeId("");
                            }}
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

                        <select
                            value={leaveTypeId}
                            onChange={(event) =>
                                setLeaveTypeId(
                                    event.target.value
                                )
                            }
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
                            <option value="">
                                All leave types
                            </option>

                            {filteredLeaveTypes.map(
                                (type) => (
                                    <option
                                        key={type.id}
                                        value={type.id}
                                    >
                                        {type.name} (
                                        {type.code})
                                    </option>
                                )
                            )}
                        </select>

                        <select
                            value={status}
                            onChange={(event) =>
                                setStatus(
                                    event.target.value
                                )
                            }
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
                            <option value="">
                                All statuses
                            </option>

                            <option value="pending">
                                Pending
                            </option>

                            <option value="approved">
                                Approved
                            </option>

                            <option value="rejected">
                                Rejected
                            </option>

                            <option value="cancelled">
                                Cancelled
                            </option>
                        </select>

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
                                title="Reset filters"
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
                                        Leave
                                    </th>

                                    <th className="px-4 py-3">
                                        Dates
                                    </th>

                                    <th className="px-4 py-3">
                                        Days
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
                                {leaveRequests.data.map(
                                    (
                                        leaveRequest
                                    ) => (
                                        <tr
                                            key={
                                                leaveRequest.id
                                            }
                                            className="hover:bg-slate-50 dark:hover:bg-slate-900/40"
                                        >
                                            <td className="px-4 py-4">
                                                <div className="font-medium">
                                                    {employeeName(
                                                        leaveRequest.employee
                                                    )}
                                                </div>

                                                <div className="mt-1 text-xs text-slate-500">
                                                    {
                                                        leaveRequest
                                                            .employee
                                                            .employee_number
                                                    }
                                                    {" · "}
                                                    {
                                                        leaveRequest
                                                            .company
                                                            .name
                                                    }
                                                </div>
                                            </td>

                                            <td className="px-4 py-4">
                                                <div className="font-medium">
                                                    {
                                                        leaveRequest
                                                            .leave_type
                                                            .name
                                                    }
                                                </div>

                                                <div className="mt-1 text-xs text-slate-500">
                                                    {
                                                        leaveRequest
                                                            .leave_type
                                                            .code
                                                    }

                                                    {leaveRequest
                                                        .duration_type ===
                                                        "half_day" &&
                                                        " · Half Day"}
                                                </div>
                                            </td>

                                            <td className="whitespace-nowrap px-4 py-4">
                                                <div>
                                                    {dateValue(
                                                        leaveRequest.start_date
                                                    )}
                                                </div>

                                                {dateValue(
                                                    leaveRequest.end_date
                                                ) !==
                                                    dateValue(
                                                        leaveRequest.start_date
                                                    ) && (
                                                    <div className="text-xs text-slate-500">
                                                        to{" "}
                                                        {dateValue(
                                                            leaveRequest.end_date
                                                        )}
                                                    </div>
                                                )}
                                            </td>

                                            <td className="px-4 py-4">
                                                {
                                                    leaveRequest.total_days
                                                }
                                            </td>

                                            <td className="px-4 py-4">
                                                <span
                                                    className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium capitalize ${statusClass(
                                                        leaveRequest.status
                                                    )}`}
                                                >
                                                    {
                                                        leaveRequest.status
                                                    }
                                                </span>
                                            </td>

                                            <td className="px-4 py-4">
                                                <div className="flex justify-end gap-1">
                                                    <Link
                                                        href={`/erp/leave/requests/${leaveRequest.id}`}
                                                        className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-200 hover:bg-slate-100 dark:border-slate-800 dark:hover:bg-slate-800"
                                                        title="View"
                                                    >
                                                        <Eye className="h-4 w-4" />
                                                    </Link>

                                                    {leaveRequest.status ===
                                                        "pending" &&
                                                        can(
                                                            "erp.leave-requests.approve"
                                                        ) && (
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    approve(
                                                                        leaveRequest
                                                                    )
                                                                }
                                                                className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-emerald-200 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-900 dark:text-emerald-400"
                                                                title="Approve"
                                                            >
                                                                <Check className="h-4 w-4" />
                                                            </button>
                                                        )}

                                                    {leaveRequest.status ===
                                                        "pending" &&
                                                        can(
                                                            "erp.leave-requests.reject"
                                                        ) && (
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    reject(
                                                                        leaveRequest
                                                                    )
                                                                }
                                                                className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-red-200 text-red-700 hover:bg-red-50 dark:border-red-900 dark:text-red-400"
                                                                title="Reject"
                                                            >
                                                                <X className="h-4 w-4" />
                                                            </button>
                                                        )}
                                                </div>
                                            </td>
                                        </tr>
                                    )
                                )}

                                {leaveRequests.data.length ===
                                    0 && (
                                    <tr>
                                        <td
                                            colSpan={6}
                                            className="px-4 py-16 text-center"
                                        >
                                            <CalendarDays className="mx-auto h-10 w-10 text-slate-300" />

                                            <p className="mt-3 font-medium">
                                                No leave
                                                requests
                                                found.
                                            </p>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {leaveRequests.links.length >
                        3 && (
                        <div className="flex flex-wrap gap-1 border-t border-slate-200 p-4 dark:border-slate-800">
                            {leaveRequests.links.map(
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

            {approvingLeaveRequest && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-slate-950">
                        <div className="flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400">
                            <Check className="h-6 w-6" />
                        </div>

                        <h2 className="mt-4 text-lg font-semibold">
                            Approve Leave Request
                        </h2>

                        <p className="mt-2 text-sm text-slate-500">
                            Are you sure you want to
                            approve this leave request
                            for{" "}
                            <span className="font-medium text-slate-900 dark:text-slate-100">
                                {employeeName(
                                    approvingLeaveRequest.employee
                                )}
                            </span>
                            ?
                        </p>

                        <div className="mt-6 flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={() =>
                                    setApprovingLeaveRequest(
                                        null
                                    )
                                }
                                className="h-10 rounded-md border border-slate-200 px-4 text-sm font-medium hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                            >
                                Cancel
                            </button>

                            <button
                                type="button"
                                onClick={
                                    confirmApprove
                                }
                                className="inline-flex h-10 items-center gap-2 rounded-md bg-emerald-600 px-4 text-sm font-medium text-white hover:bg-emerald-700"
                            >
                                <Check className="h-4 w-4" />

                                Approve Leave
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </ERPLayout>
    );
}
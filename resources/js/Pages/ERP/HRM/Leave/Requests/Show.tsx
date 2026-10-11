import {
    Head,
    Link,
    router,
} from "@inertiajs/react";
import {
    ArrowLeft,
    Ban,
    CalendarDays,
    Check,
    FileText,
    UserRound,
    X,
} from "lucide-react";

import { useState } from "react";

import ERPLayout from "@/Layouts/erp-layout";
import { useAuthorization } from "@/hooks/useAuthorization";

type User = {
    id: number;
    name: string;
};

type LeaveRequest = {
    id: number;

    start_date: string;
    end_date: string;

    duration_type: string;
    half_day_period: string | null;

    total_days: string | number;

    reason: string | null;
    attachment: string | null;

    status: string;

    approved_at: string | null;
    rejected_at: string | null;
    rejection_reason: string | null;

    employee: {
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

        designation?: {
            id: number;
            name: string;
        } | null;
    };

    leave_type: {
        id: number;
        name: string;
        code: string;
        is_paid: boolean;
        annual_allowance: string | number;
    };

    approved_by?: User | null;
    rejected_by?: User | null;

    cancelled_at: string | null;
    cancellation_reason: string | null;
    cancelled_by?: User | null;

};

type Props = {
    leaveRequest: LeaveRequest;
};

function dateOnly(value: string): string {
    return value
        ? value.substring(0, 10)
        : "—";
}

function dateTime(
    value: string | null
): string {
    if (!value) {
        return "—";
    }

    const date = new Date(value);

    if (
        Number.isNaN(
            date.getTime()
        )
    ) {
        return value;
    }

    return date.toLocaleString();
}

function employeeName(
    employee: LeaveRequest["employee"]
): string {
    return [
        employee.first_name,
        employee.middle_name,
        employee.last_name,
    ]
        .filter(Boolean)
        .join(" ");
}

export default function Show({
    leaveRequest,
}: Props) {
    const { can } = useAuthorization();

    const [rejecting, setRejecting] =
        useState(false);

    const [rejectionReason, setRejectionReason] =
        useState("");

    const [cancelling, setCancelling] =
        useState(false);

    const [
        cancellationReason,
        setCancellationReason,
    ] = useState("");

    const [
        approving,
        setApproving,
    ] = useState(false);

    const approve = () => {
        setApproving(true);
    };

    const confirmApprove = () => {
        router.post(
            `/erp/leave/requests/${leaveRequest.id}/approve`,
            {},
            {
                preserveScroll: true,

                onSuccess: () => {
                    setApproving(false);
                },
            }
        );
    };

    const reject = () => {
        if (!rejectionReason.trim()) {
            return;
        }

        router.post(
            `/erp/leave/requests/${leaveRequest.id}/reject`,
            {
                rejection_reason:
                    rejectionReason.trim(),
            },
            {
                preserveScroll: true,

                onSuccess: () => {
                    setRejecting(false);
                    setRejectionReason("");
                },
            }
        );
    };

    const cancelLeave = () => {
        if (
            !cancellationReason.trim()
        ) {
            return;
        }

        router.post(
            `/erp/leave/requests/${leaveRequest.id}/cancel`,
            {
                cancellation_reason:
                    cancellationReason.trim(),
            },
            {
                preserveScroll: true,

                onSuccess: () => {
                    setCancelling(false);

                    setCancellationReason("");
                },
            }
        );
    };

    return (
        <ERPLayout>
            <Head title="Leave Request" />

            <div className="mx-auto max-w-5xl space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-4">
                        <Link
                            href="/erp/leave/requests"
                            className="inline-flex h-10 w-10 items-center justify-center rounded-md border border-slate-200 dark:border-slate-800"
                        >
                            <ArrowLeft className="h-4 w-4" />
                        </Link>

                        <div>
                            <h1 className="text-2xl font-semibold">
                                Leave Request #
                                {
                                    leaveRequest.id
                                }
                            </h1>

                            <p className="mt-1 text-sm text-slate-500">
                                {employeeName(
                                    leaveRequest.employee
                                )}
                            </p>
                        </div>
                    </div>

                    <span
                        className={`self-start rounded-full px-3 py-1.5 text-sm font-medium capitalize ${
                            leaveRequest.status ===
                            "approved"
                                ? "bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300"
                                : leaveRequest.status ===
                                    "rejected"
                                  ? "bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300"
                                  : "bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300"
                        }`}
                    >
                        {leaveRequest.status}
                    </span>
                </div>

                {leaveRequest.status ===
                    "pending" && (
                    <div className="flex flex-wrap gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/50 dark:bg-amber-950/20">
                        {can(
                            "erp.leave-requests.approve"
                        ) && (
                            <button
                                type="button"
                                onClick={approve}
                                className="inline-flex h-10 items-center gap-2 rounded-md bg-emerald-600 px-4 text-sm font-medium text-white hover:bg-emerald-700"
                            >
                                <Check className="h-4 w-4" />

                                Approve Leave
                            </button>
                        )}

                        {can(
                            "erp.leave-requests.reject"
                        ) && (
                            <button
                                type="button"
                                onClick={() =>
                                    setRejecting(
                                        true
                                    )
                                }
                                className="inline-flex h-10 items-center gap-2 rounded-md bg-red-600 px-4 text-sm font-medium text-white hover:bg-red-700"
                            >
                                <X className="h-4 w-4" />

                                Reject Leave
                            </button>
                        )}

                        {(
                            leaveRequest.status === "pending" ||
                            leaveRequest.status === "approved"
                        ) &&
                            can(
                                "erp.leave-requests.cancel"
                            ) && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        setCancelling(true)
                                    }
                                    className="inline-flex h-10 items-center gap-2 rounded-md border border-slate-300 px-4 text-sm font-medium hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-900"
                                >
                                    <Ban className="h-4 w-4" />

                                    Cancel Leave
                                </button>
                            )}
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="space-y-6 lg:col-span-2">
                        <div className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                            <div className="mb-5 flex items-center gap-2">
                                <CalendarDays className="h-5 w-5" />

                                <h2 className="font-semibold">
                                    Leave Details
                                </h2>
                            </div>

                            <div className="grid gap-5 sm:grid-cols-2">
                                <Detail
                                    label="Leave Type"
                                    value={`${leaveRequest.leave_type.name} (${leaveRequest.leave_type.code})`}
                                />

                                <Detail
                                    label="Payment"
                                    value={
                                        leaveRequest
                                            .leave_type
                                            .is_paid
                                            ? "Paid Leave"
                                            : "Unpaid Leave"
                                    }
                                />

                                <Detail
                                    label="Start Date"
                                    value={dateOnly(
                                        leaveRequest.start_date
                                    )}
                                />

                                <Detail
                                    label="End Date"
                                    value={dateOnly(
                                        leaveRequest.end_date
                                    )}
                                />

                                <Detail
                                    label="Total Days"
                                    value={`${leaveRequest.total_days} day(s)`}
                                />

                                <Detail
                                    label="Duration"
                                    value={
                                        leaveRequest.duration_type ===
                                        "half_day"
                                            ? `Half Day — ${
                                                  leaveRequest.half_day_period ===
                                                  "first_half"
                                                      ? "First Half"
                                                      : "Second Half"
                                              }`
                                            : "Full Day"
                                    }
                                />
                            </div>

                            <div className="mt-6 border-t border-slate-200 pt-5 dark:border-slate-800">
                                <div className="text-sm font-medium">
                                    Reason
                                </div>

                                <p className="mt-2 whitespace-pre-wrap text-sm text-slate-600 dark:text-slate-300">
                                    {leaveRequest.reason ||
                                        "No reason provided."}
                                </p>
                            </div>

                            {leaveRequest.attachment && (
                                <div className="mt-6 border-t border-slate-200 pt-5 dark:border-slate-800">
                                    <a
                                        href={`/storage/${leaveRequest.attachment}`}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="inline-flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                                    >
                                        <FileText className="h-4 w-4" />

                                        Open Attachment
                                    </a>
                                </div>
                            )}
                        </div>

                        {(leaveRequest.status ===
                            "approved" ||
                            leaveRequest.status ===
                                "rejected") && (
                            <div className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                                <h2 className="mb-5 font-semibold">
                                    Approval History
                                </h2>

                                {leaveRequest.status ===
                                    "approved" && (
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Detail
                                            label="Approved By"
                                            value={
                                                leaveRequest
                                                    .approved_by
                                                    ?.name ??
                                                "—"
                                            }
                                        />

                                        <Detail
                                            label="Approved At"
                                            value={dateTime(
                                                leaveRequest.approved_at
                                            )}
                                        />
                                    </div>
                                )}

                                {leaveRequest.status ===
                                    "rejected" && (
                                    <>
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <Detail
                                                label="Rejected By"
                                                value={
                                                    leaveRequest
                                                        .rejected_by
                                                        ?.name ??
                                                    "—"
                                                }
                                            />

                                            <Detail
                                                label="Rejected At"
                                                value={dateTime(
                                                    leaveRequest.rejected_at
                                                )}
                                            />
                                        </div>

                                        <div className="mt-5">
                                            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">
                                                Rejection
                                                Reason
                                            </div>

                                            <p className="mt-2 whitespace-pre-wrap text-sm">
                                                {leaveRequest.rejection_reason ||
                                                    "—"}
                                            </p>
                                        </div>
                                    </>
                                )}
                            </div>
                        )}
                    </div>

                    <div>
                        <div className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                            <div className="mb-5 flex items-center gap-2">
                                <UserRound className="h-5 w-5" />

                                <h2 className="font-semibold">
                                    Employee
                                </h2>
                            </div>

                            <div className="space-y-4">
                                <Detail
                                    label="Name"
                                    value={employeeName(
                                        leaveRequest.employee
                                    )}
                                />

                                <Detail
                                    label="Employee Number"
                                    value={
                                        leaveRequest
                                            .employee
                                            .employee_number
                                    }
                                />

                                <Detail
                                    label="Company"
                                    value={
                                        leaveRequest
                                            .employee
                                            .company
                                            ?.name ??
                                        "—"
                                    }
                                />

                                <Detail
                                    label="Branch"
                                    value={
                                        leaveRequest
                                            .employee
                                            .branch
                                            ?.name ??
                                        "—"
                                    }
                                />

                                <Detail
                                    label="Department"
                                    value={
                                        leaveRequest
                                            .employee
                                            .department
                                            ?.name ??
                                        "—"
                                    }
                                />

                                <Detail
                                    label="Designation"
                                    value={
                                        leaveRequest
                                            .employee
                                            .designation
                                            ?.name ??
                                        "—"
                                    }
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {rejecting && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl dark:bg-slate-950">
                        <h2 className="text-lg font-semibold">
                            Reject Leave
                            Request
                        </h2>

                        <p className="mt-1 text-sm text-slate-500">
                            A rejection reason is
                            required.
                        </p>

                        <textarea
                            rows={5}
                            value={
                                rejectionReason
                            }
                            onChange={(event) =>
                                setRejectionReason(
                                    event.target
                                        .value
                                )
                            }
                            className="mt-5 w-full rounded-md border border-slate-200 bg-white p-3 text-sm outline-none dark:border-slate-800 dark:bg-slate-950"
                            placeholder="Reason for rejection..."
                            autoFocus
                        />

                        <div className="mt-5 flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={() => {
                                    setRejecting(
                                        false
                                    );

                                    setRejectionReason(
                                        ""
                                    );
                                }}
                                className="h-10 rounded-md border border-slate-200 px-4 text-sm dark:border-slate-800"
                            >
                                Cancel
                            </button>

                            <button
                                type="button"
                                disabled={
                                    !rejectionReason.trim()
                                }
                                onClick={reject}
                                className="h-10 rounded-md bg-red-600 px-4 text-sm font-medium text-white disabled:opacity-50"
                            >
                                Reject Request
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {cancelling && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl dark:bg-slate-950">
                        <h2 className="text-lg font-semibold">
                            Cancel Leave
                        </h2>

                        <p className="mt-1 text-sm text-slate-500">
                            {leaveRequest.status ===
                            "approved"
                                ? "The approved leave will be reversed, its balance restored, and generated leave attendance reconciled."
                                : "The pending leave request will be cancelled and the reserved balance released."}
                        </p>

                        <textarea
                            rows={5}
                            value={
                                cancellationReason
                            }
                            onChange={(event) =>
                                setCancellationReason(
                                    event.target.value
                                )
                            }
                            placeholder="Reason for cancellation..."
                            className="mt-5 w-full rounded-md border border-slate-200 bg-white p-3 text-sm outline-none dark:border-slate-800 dark:bg-slate-950"
                            autoFocus
                        />

                        <div className="mt-5 flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={() => {
                                    setCancelling(
                                        false
                                    );

                                    setCancellationReason(
                                        ""
                                    );
                                }}
                                className="h-10 rounded-md border border-slate-200 px-4 text-sm dark:border-slate-800"
                            >
                                Close
                            </button>

                            <button
                                type="button"
                                disabled={
                                    !cancellationReason.trim()
                                }
                                onClick={
                                    cancelLeave
                                }
                                className="h-10 rounded-md bg-red-600 px-4 text-sm font-medium text-white disabled:opacity-50"
                            >
                                Confirm Cancellation
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {leaveRequest.status ===
                "cancelled" && (
                <div className="rounded-xl border border-red-200 bg-red-50 p-6 dark:border-red-900/50 dark:bg-red-950/20">
                    <h2 className="font-semibold">
                        Leave Cancelled
                    </h2>

                    <div className="mt-5 grid gap-4 sm:grid-cols-2">
                        <Detail
                            label="Cancelled By"
                            value={
                                leaveRequest
                                    .cancelled_by
                                    ?.name ??
                                "—"
                            }
                        />

                        <Detail
                            label="Cancelled At"
                            value={dateTime(
                                leaveRequest
                                    .cancelled_at
                            )}
                        />
                    </div>

                    <div className="mt-5">
                        <div className="text-xs font-medium uppercase tracking-wide text-slate-500">
                            Cancellation Reason
                        </div>

                        <p className="mt-2 whitespace-pre-wrap text-sm">
                            {leaveRequest
                                .cancellation_reason ??
                                "—"}
                        </p>
                    </div>
                </div>
            )}

            {approving && (
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
                                    leaveRequest.employee
                                )}
                            </span>
                            ?
                        </p>

                        <div className="mt-6 flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={() =>
                                    setApproving(false)
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

function Detail({
    label,
    value,
}: {
    label: string;
    value: string;
}) {
    return (
        <div>
            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">
                {label}
            </div>

            <div className="mt-1 text-sm font-medium">
                {value}
            </div>
        </div>
    );
}
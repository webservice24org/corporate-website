import { Head, Link, router } from "@inertiajs/react";
import {
    ArrowLeft,
    BadgeCheck,
    Building2,
    CalendarDays,
    Clock3,
    Database,
    Fingerprint,
    MapPin,
    Timer,
    UserRound,
    RefreshCw,
} from "lucide-react";
import {
    useState,
    type ReactNode,
} from "react";

import ERPLayout from "@/Layouts/erp-layout";
import { useAuthorization } from "@/hooks/useAuthorization";

type RawPunch = {
    id: number;
    external_user_id: string | null;
    device_punched_at: string | null;
    punched_at: string;
    source_timezone: string | null;
    punch_state: string | null;
    verification_method: string | null;
    source: string;
    source_record_id: string | null;
    processing_status: string;
    raw_payload: Record<
        string,
        unknown
    > | null;

    device: {
        id: number;
        name: string;
        code: string;
        provider: string;
        model: string | null;
        serial_number: string | null;
        timezone: string | null;
    } | null;
};

type CalculationDetails = {
    timezone?: string;
    working_day?: boolean;
    raw_punch_count?: number;
    effective_punch_count?: number;
    duplicate_punch_count?: number;
    duplicate_window_seconds?: number;
    effective_raw_punch_ids?: number[];
    all_raw_punch_ids?: number[];
};

type AttendanceRecord = {
    id: number;
    attendance_date: string;

    scheduled_start_at: string | null;
    scheduled_end_at: string | null;
    check_in_at: string | null;
    check_out_at: string | null;

    worked_minutes: number;
    break_minutes: number;
    late_minutes: number;
    early_leave_minutes: number;
    overtime_minutes: number;
    punch_count: number;

    attendance_status: string;
    source: string;
    is_manual: boolean;

    calculation_details:
        | CalculationDetails
        | null;

    notes: string | null;
    processed_at: string | null;
    created_at: string;
    updated_at: string;

    company: {
        id: number;
        name: string;
        timezone?: string | null;
    };

    employee: {
        id: number;
        employee_number: string;
        first_name: string;
        middle_name: string | null;
        last_name: string;
        preferred_name: string | null;
        work_email: string | null;
        work_phone: string | null;
        photo: string | null;
    };

    shift: {
        id: number;
        name: string;
        code: string;
        start_time: string;
        end_time: string;
        grace_minutes: number;
        early_leave_grace_minutes: number;
        minimum_work_minutes: number;
        half_day_minutes: number;
        overtime_after_minutes: number;
        break_minutes: number;
        break_is_paid: boolean;
    } | null;

    branch: {
        id: number;
        name: string;
    } | null;

    department: {
        id: number;
        name: string;
    } | null;

    designation: {
        id: number;
        name: string;
    } | null;

    raw_punches: RawPunch[];
};

type Props = {
    record: AttendanceRecord;
};

function employeeName(
    employee: AttendanceRecord["employee"]
): string {
    return [
        employee.first_name,
        employee.middle_name,
        employee.last_name,
    ]
        .filter(Boolean)
        .join(" ");
}

function formatMinutes(
    value: number
): string {
    const minutes =
        Number(value ?? 0);

    if (minutes <= 0) {
        return "0 minutes";
    }

    const hours = Math.floor(
        minutes / 60
    );

    const remaining =
        minutes % 60;

    if (hours === 0) {
        return `${remaining} minutes`;
    }

    if (remaining === 0) {
        return `${hours} hour${
            hours > 1 ? "s" : ""
        }`;
    }

    return `${hours}h ${remaining}m`;
}

function formatLocalDateTime(
    value: string | null,
    timezone: string
): string {
    if (!value) {
        return "—";
    }

    const date =
        new Date(value);

    if (
        Number.isNaN(
            date.getTime()
        )
    ) {
        return value;
    }

    return new Intl.DateTimeFormat(
        "en-GB",
        {
            timeZone: timezone,
            year: "numeric",
            month: "short",
            day: "2-digit",
            hour: "2-digit",
            minute: "2-digit",
            second: "2-digit",
            hour12: true,
        }
    ).format(date);
}

function formatLocalTime(
    value: string | null,
    timezone: string
): string {
    if (!value) {
        return "—";
    }

    const date =
        new Date(value);

    return new Intl.DateTimeFormat(
        "en-GB",
        {
            timeZone: timezone,
            hour: "2-digit",
            minute: "2-digit",
            second: "2-digit",
            hour12: true,
        }
    ).format(date);
}

function statusLabel(
    value: string
): string {
    return value
        .replaceAll("_", " ")
        .replace(
            /\b\w/g,
            (letter) =>
                letter.toUpperCase()
        );
}

function statusClass(
    status: string
): string {
    switch (status) {
        case "present":
            return "bg-emerald-50 text-emerald-700 ring-emerald-600/20";

        case "half_day":
            return "bg-amber-50 text-amber-700 ring-amber-600/20";

        case "absent":
            return "bg-red-50 text-red-700 ring-red-600/20";

        case "incomplete":
            return "bg-orange-50 text-orange-700 ring-orange-600/20";

        case "off_day_work":
            return "bg-blue-50 text-blue-700 ring-blue-600/20";

        default:
            return "bg-slate-100 text-slate-700 ring-slate-600/20";
    }
}

function InfoCard({
    label,
    value,
    icon,
}: {
    label: string;
    value: ReactNode;
    icon: ReactNode;
}) {
    return (
        <div className="rounded-xl border bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-950">
            <div className="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-500">
                {icon}
                {label}
            </div>

            <div className="mt-2 text-lg font-semibold">
                {value}
            </div>
        </div>
    );
}

export default function Show({
    record,
}: Props) {
    const { can } =
        useAuthorization();

    const [reprocessing, setReprocessing] =
        useState(false);

    const timezone =
        record.calculation_details
            ?.timezone ||
        record.company.timezone ||
        "Asia/Dhaka";

    const effectivePunchIds =
        record.calculation_details
            ?.effective_raw_punch_ids ??
        [];

    const effectivePunchSet =
        new Set(
            effectivePunchIds
        );

    function reprocessAttendance() {
        const confirmed =
            window.confirm(
                "Reprocess this attendance record from its preserved raw punch evidence and any new eligible pending punches?"
            );

        if (!confirmed) {
            return;
        }

        router.post(
            `/erp/attendance/records/${record.id}/reprocess`,
            {},
            {
                preserveScroll: true,

                onStart: () => {
                    setReprocessing(
                        true
                    );
                },

                onFinish: () => {
                    setReprocessing(
                        false
                    );
                },
            }
        );
    }

    return (
        <ERPLayout>
            <Head
                title={`Attendance ${record.attendance_date}`}
            />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <Link
                            href="/erp/attendance/records"
                            className="mb-3 inline-flex items-center gap-2 text-sm font-medium text-slate-500 hover:text-slate-950 dark:hover:text-white"
                        >
                            <ArrowLeft className="size-4" />
                            Attendance Records
                        </Link>

                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {employeeName(
                                    record.employee
                                )}
                            </h1>

                            <span
                                className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset ${statusClass(
                                    record.attendance_status
                                )}`}
                            >
                                {statusLabel(
                                    record.attendance_status
                                )}
                            </span>
                        </div>

                        <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-500">
                            <span>
                                {
                                    record
                                        .employee
                                        .employee_number
                                }
                            </span>

                            <span>
                                {
                                    record.attendance_date
                                }
                            </span>

                            <span>
                                Record #
                                {record.id}
                            </span>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-3">
                        <div className="rounded-xl border bg-white px-4 py-3 text-sm shadow-sm dark:border-slate-800 dark:bg-slate-950">
                            <div className="text-xs uppercase tracking-wide text-slate-500">
                                Processing timezone
                            </div>

                            <div className="mt-1 font-semibold">
                                {timezone}
                            </div>
                        </div>

                        {can(
                            "erp.attendance-records.process"
                        ) && (
                            <button
                                type="button"
                                disabled={reprocessing}
                                onClick={
                                    reprocessAttendance
                                }
                                className="inline-flex h-11 items-center gap-2 rounded-md bg-slate-950 px-4 text-sm font-medium text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
                            >
                                <RefreshCw
                                    className={`size-4 ${
                                        reprocessing
                                            ? "animate-spin"
                                            : ""
                                    }`}
                                />

                                {reprocessing
                                    ? "Reprocessing..."
                                    : "Reprocess"}
                            </button>
                        )}
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <InfoCard
                        label="Check In"
                        icon={
                            <Clock3 className="size-4" />
                        }
                        value={formatLocalTime(
                            record.check_in_at,
                            timezone
                        )}
                    />

                    <InfoCard
                        label="Check Out"
                        icon={
                            <Clock3 className="size-4" />
                        }
                        value={formatLocalTime(
                            record.check_out_at,
                            timezone
                        )}
                    />

                    <InfoCard
                        label="Worked"
                        icon={
                            <Timer className="size-4" />
                        }
                        value={formatMinutes(
                            record.worked_minutes
                        )}
                    />

                    <InfoCard
                        label="Overtime"
                        icon={
                            <Timer className="size-4" />
                        }
                        value={formatMinutes(
                            record.overtime_minutes
                        )}
                    />

                    <InfoCard
                        label="Punches"
                        icon={
                            <Fingerprint className="size-4" />
                        }
                        value={
                            record.punch_count
                        }
                    />
                </div>

                <div className="grid gap-6 xl:grid-cols-2">
                    <section className="rounded-xl border bg-white shadow-sm dark:border-slate-800 dark:bg-slate-950">
                        <div className="border-b px-5 py-4 dark:border-slate-800">
                            <h2 className="font-semibold">
                                Employee &
                                Organization
                            </h2>
                        </div>

                        <div className="grid gap-5 p-5 sm:grid-cols-2">
                            <div>
                                <p className="text-xs uppercase tracking-wide text-slate-500">
                                    Employee
                                </p>

                                <div className="mt-2 flex items-center gap-3">
                                    {record
                                        .employee
                                        .photo ? (
                                        <img
                                            src={`/storage/${record.employee.photo}`}
                                            alt={employeeName(
                                                record.employee
                                            )}
                                            className="size-12 rounded-full object-cover"
                                        />
                                    ) : (
                                        <div className="flex size-12 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800">
                                            <UserRound className="size-5" />
                                        </div>
                                    )}

                                    <div>
                                        <div className="font-medium">
                                            {employeeName(
                                                record.employee
                                            )}
                                        </div>

                                        <div className="text-sm text-slate-500">
                                            {
                                                record
                                                    .employee
                                                    .employee_number
                                            }
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <p className="text-xs uppercase tracking-wide text-slate-500">
                                    Company
                                </p>

                                <div className="mt-2 flex items-center gap-2 font-medium">
                                    <Building2 className="size-4 text-slate-400" />
                                    {
                                        record
                                            .company
                                            .name
                                    }
                                </div>
                            </div>

                            <div>
                                <p className="text-xs uppercase tracking-wide text-slate-500">
                                    Branch
                                </p>

                                <p className="mt-2 font-medium">
                                    {record.branch
                                        ?.name ??
                                        "—"}
                                </p>
                            </div>

                            <div>
                                <p className="text-xs uppercase tracking-wide text-slate-500">
                                    Department
                                </p>

                                <p className="mt-2 font-medium">
                                    {record
                                        .department
                                        ?.name ??
                                        "—"}
                                </p>
                            </div>

                            <div>
                                <p className="text-xs uppercase tracking-wide text-slate-500">
                                    Designation
                                </p>

                                <p className="mt-2 font-medium">
                                    {record
                                        .designation
                                        ?.name ??
                                        "—"}
                                </p>
                            </div>

                            <div>
                                <p className="text-xs uppercase tracking-wide text-slate-500">
                                    Contact
                                </p>

                                <p className="mt-2 text-sm">
                                    {record
                                        .employee
                                        .work_email ??
                                        "—"}
                                </p>

                                <p className="text-sm text-slate-500">
                                    {record
                                        .employee
                                        .work_phone ??
                                        ""}
                                </p>
                            </div>
                        </div>
                    </section>

                    <section className="rounded-xl border bg-white shadow-sm dark:border-slate-800 dark:bg-slate-950">
                        <div className="border-b px-5 py-4 dark:border-slate-800">
                            <h2 className="font-semibold">
                                Shift & Schedule
                            </h2>
                        </div>

                        <div className="grid gap-5 p-5 sm:grid-cols-2">
                            <div>
                                <p className="text-xs uppercase tracking-wide text-slate-500">
                                    Shift
                                </p>

                                <p className="mt-2 font-medium">
                                    {record.shift
                                        ? `${record.shift.name} (${record.shift.code})`
                                        : "—"}
                                </p>
                            </div>

                            <div>
                                <p className="text-xs uppercase tracking-wide text-slate-500">
                                    Attendance
                                    Date
                                </p>

                                <div className="mt-2 flex items-center gap-2 font-medium">
                                    <CalendarDays className="size-4 text-slate-400" />
                                    {
                                        record.attendance_date
                                    }
                                </div>
                            </div>

                            <div>
                                <p className="text-xs uppercase tracking-wide text-slate-500">
                                    Scheduled
                                    Start
                                </p>

                                <p className="mt-2 font-medium">
                                    {formatLocalDateTime(
                                        record.scheduled_start_at,
                                        timezone
                                    )}
                                </p>
                            </div>

                            <div>
                                <p className="text-xs uppercase tracking-wide text-slate-500">
                                    Scheduled End
                                </p>

                                <p className="mt-2 font-medium">
                                    {formatLocalDateTime(
                                        record.scheduled_end_at,
                                        timezone
                                    )}
                                </p>
                            </div>

                            <div>
                                <p className="text-xs uppercase tracking-wide text-slate-500">
                                    Actual Check
                                    In
                                </p>

                                <p className="mt-2 font-medium">
                                    {formatLocalDateTime(
                                        record.check_in_at,
                                        timezone
                                    )}
                                </p>
                            </div>

                            <div>
                                <p className="text-xs uppercase tracking-wide text-slate-500">
                                    Actual Check
                                    Out
                                </p>

                                <p className="mt-2 font-medium">
                                    {formatLocalDateTime(
                                        record.check_out_at,
                                        timezone
                                    )}
                                </p>
                            </div>
                        </div>
                    </section>
                </div>

                <section className="rounded-xl border bg-white shadow-sm dark:border-slate-800 dark:bg-slate-950">
                    <div className="border-b px-5 py-4 dark:border-slate-800">
                        <h2 className="font-semibold">
                            Attendance
                            Calculation
                        </h2>

                        <p className="mt-1 text-sm text-slate-500">
                            Final calculated
                            metrics generated by the
                            attendance engine.
                        </p>
                    </div>

                    <div className="grid gap-px bg-slate-200 sm:grid-cols-2 lg:grid-cols-4 dark:bg-slate-800">
                        {[
                            [
                                "Worked",
                                formatMinutes(
                                    record.worked_minutes
                                ),
                            ],
                            [
                                "Break",
                                formatMinutes(
                                    record.break_minutes
                                ),
                            ],
                            [
                                "Late",
                                formatMinutes(
                                    record.late_minutes
                                ),
                            ],
                            [
                                "Early Leave",
                                formatMinutes(
                                    record.early_leave_minutes
                                ),
                            ],
                            [
                                "Overtime",
                                formatMinutes(
                                    record.overtime_minutes
                                ),
                            ],
                            [
                                "Raw Punches",
                                record.punch_count,
                            ],
                            [
                                "Effective Punches",
                                record
                                    .calculation_details
                                    ?.effective_punch_count ??
                                    "—",
                            ],
                            [
                                "Duplicates",
                                record
                                    .calculation_details
                                    ?.duplicate_punch_count ??
                                    "—",
                            ],
                        ].map(
                            ([label, value]) => (
                                <div
                                    key={String(
                                        label
                                    )}
                                    className="bg-white p-5 dark:bg-slate-950"
                                >
                                    <div className="text-xs uppercase tracking-wide text-slate-500">
                                        {
                                            label
                                        }
                                    </div>

                                    <div className="mt-2 text-xl font-semibold">
                                        {
                                            value
                                        }
                                    </div>
                                </div>
                            )
                        )}
                    </div>
                </section>

                <section className="overflow-hidden rounded-xl border bg-white shadow-sm dark:border-slate-800 dark:bg-slate-950">
                    <div className="border-b px-5 py-4 dark:border-slate-800">
                        <div className="flex items-center gap-2">
                            <Fingerprint className="size-5" />

                            <h2 className="font-semibold">
                                Raw Punch Audit
                                Trail
                            </h2>
                        </div>

                        <p className="mt-1 text-sm text-slate-500">
                            Complete source punch
                            evidence linked to this
                            attendance record.
                            Duplicate punches remain
                            preserved for audit.
                        </p>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[1100px] text-sm">
                            <thead className="border-b bg-slate-50 text-left dark:border-slate-800 dark:bg-slate-900/50">
                                <tr>
                                    <th className="px-4 py-3 font-medium">
                                        Punch
                                    </th>

                                    <th className="px-4 py-3 font-medium">
                                        Normalized
                                        Time
                                    </th>

                                    <th className="px-4 py-3 font-medium">
                                        External ID
                                    </th>

                                    <th className="px-4 py-3 font-medium">
                                        Device
                                    </th>

                                    <th className="px-4 py-3 font-medium">
                                        Verification
                                    </th>

                                    <th className="px-4 py-3 font-medium">
                                        State
                                    </th>

                                    <th className="px-4 py-3 font-medium">
                                        Source ID
                                    </th>

                                    <th className="px-4 py-3 font-medium">
                                        Processing
                                    </th>

                                    <th className="px-4 py-3 text-right font-medium">
                                        Raw Log
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y dark:divide-slate-800">
                                {record.raw_punches.map(
                                    (
                                        punch
                                    ) => {
                                        const effective =
                                            effectivePunchSet.has(
                                                punch.id
                                            );

                                        return (
                                            <tr
                                                key={
                                                    punch.id
                                                }
                                                className={
                                                    effective
                                                        ? "bg-emerald-50/40 dark:bg-emerald-950/10"
                                                        : ""
                                                }
                                            >
                                                <td className="px-4 py-3">
                                                    <div className="flex items-center gap-2">
                                                        {effective ? (
                                                            <BadgeCheck className="size-4 text-emerald-600" />
                                                        ) : (
                                                            <Database className="size-4 text-amber-600" />
                                                        )}

                                                        <div>
                                                            <div className="font-medium">
                                                                #
                                                                {
                                                                    punch.id
                                                                }
                                                            </div>

                                                            <div className="text-xs text-slate-500">
                                                                {effective
                                                                ? "Effective"
                                                                : "Not used in final calculation"}
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>

                                                <td className="whitespace-nowrap px-4 py-3">
                                                    {formatLocalDateTime(
                                                        punch.punched_at,
                                                        timezone
                                                    )}
                                                </td>

                                                <td className="px-4 py-3">
                                                    {
                                                        punch.external_user_id ??
                                                        "—"
                                                    }
                                                </td>

                                                <td className="px-4 py-3">
                                                    {punch.device ? (
                                                        <>
                                                            <div className="font-medium">
                                                                {
                                                                    punch
                                                                        .device
                                                                        .name
                                                                }
                                                            </div>

                                                            <div className="text-xs text-slate-500">
                                                                {
                                                                    punch
                                                                        .device
                                                                        .code
                                                                }
                                                            </div>
                                                        </>
                                                    ) : (
                                                        "—"
                                                    )}
                                                </td>

                                                <td className="px-4 py-3 capitalize">
                                                    {punch.verification_method ??
                                                        "—"}
                                                </td>

                                                <td className="px-4 py-3">
                                                    {punch.punch_state ??
                                                        "—"}
                                                </td>

                                                <td className="px-4 py-3 font-mono text-xs">
                                                    {punch.source_record_id ??
                                                        "—"}
                                                </td>

                                                <td className="px-4 py-3">
                                                    <span className="inline-flex rounded-full bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
                                                        {statusLabel(
                                                            punch.processing_status
                                                        )}
                                                    </span>
                                                </td>

                                                <td className="px-4 py-3 text-right">
                                                    <Link
                                                        href={`/erp/attendance/raw-punches/${punch.id}`}
                                                        className="inline-flex h-8 items-center rounded-md border border-slate-200 px-3 text-xs font-medium hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                                                    >
                                                        View
                                                    </Link>
                                                </td>
                                            </tr>
                                        );
                                    }
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>

                <div className="grid gap-6 xl:grid-cols-2">
                    <section className="rounded-xl border bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-950">
                        <h2 className="font-semibold">
                            Processing
                            Information
                        </h2>

                        <dl className="mt-4 space-y-3 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-slate-500">
                                    Source
                                </dt>

                                <dd className="font-medium capitalize">
                                    {
                                        record.source
                                    }
                                </dd>
                            </div>

                            <div className="flex justify-between gap-4">
                                <dt className="text-slate-500">
                                    Manual
                                </dt>

                                <dd className="font-medium">
                                    {record.is_manual
                                        ? "Yes"
                                        : "No"}
                                </dd>
                            </div>

                            <div className="flex justify-between gap-4">
                                <dt className="text-slate-500">
                                    Working Day
                                </dt>

                                <dd className="font-medium">
                                    {record
                                        .calculation_details
                                        ?.working_day ===
                                    true
                                        ? "Yes"
                                        : record
                                                .calculation_details
                                                ?.working_day ===
                                            false
                                          ? "No"
                                          : "—"}
                                </dd>
                            </div>

                            <div className="flex justify-between gap-4">
                                <dt className="text-slate-500">
                                    Duplicate
                                    Window
                                </dt>

                                <dd className="font-medium">
                                    {record
                                        .calculation_details
                                        ?.duplicate_window_seconds ??
                                        0}{" "}
                                    seconds
                                </dd>
                            </div>

                            <div className="flex justify-between gap-4">
                                <dt className="text-slate-500">
                                    Processed At
                                </dt>

                                <dd className="text-right font-medium">
                                    {formatLocalDateTime(
                                        record.processed_at,
                                        timezone
                                    )}
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <section className="rounded-xl border bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-950">
                        <h2 className="font-semibold">
                            Shift Rules Used
                        </h2>

                        {record.shift ? (
                            <dl className="mt-4 space-y-3 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-slate-500">
                                        Grace
                                    </dt>

                                    <dd className="font-medium">
                                        {
                                            record
                                                .shift
                                                .grace_minutes
                                        }{" "}
                                        min
                                    </dd>
                                </div>

                                <div className="flex justify-between gap-4">
                                    <dt className="text-slate-500">
                                        Early Leave
                                        Grace
                                    </dt>

                                    <dd className="font-medium">
                                        {
                                            record
                                                .shift
                                                .early_leave_grace_minutes
                                        }{" "}
                                        min
                                    </dd>
                                </div>

                                <div className="flex justify-between gap-4">
                                    <dt className="text-slate-500">
                                        Minimum Work
                                    </dt>

                                    <dd className="font-medium">
                                        {
                                            record
                                                .shift
                                                .minimum_work_minutes
                                        }{" "}
                                        min
                                    </dd>
                                </div>

                                <div className="flex justify-between gap-4">
                                    <dt className="text-slate-500">
                                        Half Day
                                    </dt>

                                    <dd className="font-medium">
                                        {
                                            record
                                                .shift
                                                .half_day_minutes
                                        }{" "}
                                        min
                                    </dd>
                                </div>

                                <div className="flex justify-between gap-4">
                                    <dt className="text-slate-500">
                                        Break
                                    </dt>

                                    <dd className="font-medium">
                                        {
                                            record
                                                .shift
                                                .break_minutes
                                        }{" "}
                                        min{" "}
                                        {record
                                            .shift
                                            .break_is_paid
                                            ? "(paid)"
                                            : "(unpaid)"}
                                    </dd>
                                </div>
                            </dl>
                        ) : (
                            <p className="mt-3 text-sm text-slate-500">
                                No shift
                                snapshot is
                                available.
                            </p>
                        )}
                    </section>
                </div>

                {record.notes && (
                    <section className="rounded-xl border bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-950">
                        <h2 className="font-semibold">
                            Notes
                        </h2>

                        <p className="mt-3 whitespace-pre-wrap text-sm text-slate-600 dark:text-slate-300">
                            {record.notes}
                        </p>
                    </section>
                )}
            </div>
        </ERPLayout>
    );
}
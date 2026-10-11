import { Head, Link, router } from "@inertiajs/react";
import {
    CalendarDays,
    Clock3,
    Eye,
    Filter,
    Search,
    Timer,
    UserCheck,
    X,
} from "lucide-react";
import {
    useMemo,
    useState,
    type FormEvent,
} from "react";

import ERPLayout from "@/Layouts/erp-layout";

type Company = {
    id: number;
    name: string;
};

type Branch = {
    id: number;
    company_id: number;
    name: string;
};

type Department = {
    id: number;
    company_id: number;
    branch_id: number | null;
    name: string;
};

type ShiftOption = {
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
    preferred_name: string | null;
};

type Shift = {
    id: number;
    name: string;
    code: string;
    start_time: string;
    end_time: string;
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

    company: {
        id: number;
        name: string;
    };

    employee: Employee;

    shift: Shift | null;

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
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedRecords = {
    data: AttendanceRecord[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: PaginationLink[];
};

type Filters = {
    search: string;
    company_id: number | string;
    branch_id: number | string;
    department_id: number | string;
    shift_id: number | string;
    attendance_status: string;
    date_from: string;
    date_to: string;
};

type Props = {
    records: PaginatedRecords;
    filters: Filters;
    companies: Company[];
    branches: Branch[];
    departments: Department[];
    shifts: ShiftOption[];
};

function employeeName(
    employee: Employee
): string {
    const parts = [
        employee.first_name,
        employee.middle_name,
        employee.last_name,
    ].filter(Boolean);

    return parts.join(" ");
}

function formatMinutes(
    value: number | null | undefined
): string {
    const minutes = Number(value ?? 0);

    if (minutes <= 0) {
        return "0m";
    }

    const hours = Math.floor(
        minutes / 60
    );

    const remaining =
        minutes % 60;

    if (hours <= 0) {
        return `${remaining}m`;
    }

    if (remaining === 0) {
        return `${hours}h`;
    }

    return `${hours}h ${remaining}m`;
}

function localTimeFromUtc(
    value: string | null,
    timezone = "Asia/Dhaka"
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
            hour: "2-digit",
            minute: "2-digit",
            hour12: true,
        }
    ).format(date);
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

        case "off_day":
            return "bg-slate-100 text-slate-700 ring-slate-600/20";

        case "off_day_work":
            return "bg-blue-50 text-blue-700 ring-blue-600/20";

        default:
            return "bg-slate-100 text-slate-700 ring-slate-600/20";
    }
}

function statusLabel(
    status: string
): string {
    return status
        .replaceAll("_", " ")
        .replace(
            /\b\w/g,
            (letter) =>
                letter.toUpperCase()
        );
}

export default function Index({
    records,
    filters,
    companies,
    branches,
    departments,
    shifts,
}: Props) {
    const [form, setForm] =
        useState<Filters>({
            search:
                filters.search ?? "",
            company_id:
                filters.company_id ?? "",
            branch_id:
                filters.branch_id ?? "",
            department_id:
                filters.department_id ??
                "",
            shift_id:
                filters.shift_id ?? "",
            attendance_status:
                filters.attendance_status ??
                "",
            date_from:
                filters.date_from ?? "",
            date_to:
                filters.date_to ?? "",
        });

    const availableBranches =
        useMemo(() => {
            if (!form.company_id) {
                return branches;
            }

            return branches.filter(
                (branch) =>
                    String(
                        branch.company_id
                    ) ===
                    String(
                        form.company_id
                    )
            );
        }, [
            branches,
            form.company_id,
        ]);

    const availableDepartments =
        useMemo(() => {
            return departments.filter(
                (department) => {
                    if (
                        form.company_id &&
                        String(
                            department.company_id
                        ) !==
                            String(
                                form.company_id
                            )
                    ) {
                        return false;
                    }

                    if (
                        form.branch_id &&
                        department.branch_id &&
                        String(
                            department.branch_id
                        ) !==
                            String(
                                form.branch_id
                            )
                    ) {
                        return false;
                    }

                    return true;
                }
            );
        }, [
            departments,
            form.company_id,
            form.branch_id,
        ]);

    const availableShifts =
        useMemo(() => {
            if (!form.company_id) {
                return shifts;
            }

            return shifts.filter(
                (shift) =>
                    String(
                        shift.company_id
                    ) ===
                    String(
                        form.company_id
                    )
            );
        }, [
            shifts,
            form.company_id,
        ]);

    function submit(
        event: FormEvent
    ) {
        event.preventDefault();

        router.get(
            "/erp/attendance/records",
            form,
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            }
        );
    }

    function resetFilters() {
        const empty: Filters = {
            search: "",
            company_id: "",
            branch_id: "",
            department_id: "",
            shift_id: "",
            attendance_status: "",
            date_from: "",
            date_to: "",
        };

        setForm(empty);

        router.get(
            "/erp/attendance/records",
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            }
        );
    }

    return (
        <ERPLayout>
            <Head title="Attendance Records" />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight text-slate-950 dark:text-white">
                            Attendance Records
                        </h1>

                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            Review calculated employee
                            attendance and biometric
                            punch evidence.
                        </p>
                    </div>

                    <div className="rounded-xl border bg-white px-4 py-3 shadow-sm dark:border-slate-800 dark:bg-slate-950">
                        <div className="flex items-center gap-3">
                            <div className="rounded-lg bg-slate-100 p-2 dark:bg-slate-800">
                                <UserCheck className="size-5" />
                            </div>

                            <div>
                                <p className="text-xs text-slate-500">
                                    Total records
                                </p>

                                <p className="text-lg font-semibold">
                                    {records.total}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <form
                    onSubmit={submit}
                    className="rounded-xl border bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-950"
                >
                    <div className="mb-4 flex items-center gap-2">
                        <Filter className="size-4" />

                        <h2 className="font-medium">
                            Filters
                        </h2>
                    </div>

                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <label className="mb-1.5 block text-sm font-medium">
                                Search employee
                            </label>

                            <div className="relative">
                                <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />

                                <input
                                    value={
                                        form.search
                                    }
                                    onChange={(
                                        event
                                    ) =>
                                        setForm(
                                            (
                                                current
                                            ) => ({
                                                ...current,
                                                search:
                                                    event
                                                        .target
                                                        .value,
                                            })
                                        )
                                    }
                                    placeholder="Name or employee no."
                                    className="h-10 w-full rounded-md border border-slate-200 bg-white pl-9 pr-3 text-sm outline-none focus:border-slate-400 dark:border-slate-800 dark:bg-slate-950"
                                />
                            </div>
                        </div>

                        <div>
                            <label className="mb-1.5 block text-sm font-medium">
                                Company
                            </label>

                            <select
                                value={
                                    form.company_id
                                }
                                onChange={(
                                    event
                                ) =>
                                    setForm(
                                        (
                                            current
                                        ) => ({
                                            ...current,
                                            company_id:
                                                event
                                                    .target
                                                    .value,
                                            branch_id:
                                                "",
                                            department_id:
                                                "",
                                            shift_id:
                                                "",
                                        })
                                    )
                                }
                                className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                            >
                                <option value="">
                                    All companies
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
                        </div>

                        <div>
                            <label className="mb-1.5 block text-sm font-medium">
                                Branch
                            </label>

                            <select
                                value={
                                    form.branch_id
                                }
                                onChange={(
                                    event
                                ) =>
                                    setForm(
                                        (
                                            current
                                        ) => ({
                                            ...current,
                                            branch_id:
                                                event
                                                    .target
                                                    .value,
                                            department_id:
                                                "",
                                        })
                                    )
                                }
                                className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                            >
                                <option value="">
                                    All branches
                                </option>

                                {availableBranches.map(
                                    (
                                        branch
                                    ) => (
                                        <option
                                            key={
                                                branch.id
                                            }
                                            value={
                                                branch.id
                                            }
                                        >
                                            {
                                                branch.name
                                            }
                                        </option>
                                    )
                                )}
                            </select>
                        </div>

                        <div>
                            <label className="mb-1.5 block text-sm font-medium">
                                Department
                            </label>

                            <select
                                value={
                                    form.department_id
                                }
                                onChange={(
                                    event
                                ) =>
                                    setForm(
                                        (
                                            current
                                        ) => ({
                                            ...current,
                                            department_id:
                                                event
                                                    .target
                                                    .value,
                                        })
                                    )
                                }
                                className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                            >
                                <option value="">
                                    All departments
                                </option>

                                {availableDepartments.map(
                                    (
                                        department
                                    ) => (
                                        <option
                                            key={
                                                department.id
                                            }
                                            value={
                                                department.id
                                            }
                                        >
                                            {
                                                department.name
                                            }
                                        </option>
                                    )
                                )}
                            </select>
                        </div>

                        <div>
                            <label className="mb-1.5 block text-sm font-medium">
                                Shift
                            </label>

                            <select
                                value={
                                    form.shift_id
                                }
                                onChange={(
                                    event
                                ) =>
                                    setForm(
                                        (
                                            current
                                        ) => ({
                                            ...current,
                                            shift_id:
                                                event
                                                    .target
                                                    .value,
                                        })
                                    )
                                }
                                className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                            >
                                <option value="">
                                    All shifts
                                </option>

                                {availableShifts.map(
                                    (shift) => (
                                        <option
                                            key={
                                                shift.id
                                            }
                                            value={
                                                shift.id
                                            }
                                        >
                                            {
                                                shift.name
                                            }{" "}
                                            (
                                            {
                                                shift.code
                                            }
                                            )
                                        </option>
                                    )
                                )}
                            </select>
                        </div>

                        <div>
                            <label className="mb-1.5 block text-sm font-medium">
                                Status
                            </label>

                            <select
                                value={
                                    form.attendance_status
                                }
                                onChange={(
                                    event
                                ) =>
                                    setForm(
                                        (
                                            current
                                        ) => ({
                                            ...current,
                                            attendance_status:
                                                event
                                                    .target
                                                    .value,
                                        })
                                    )
                                }
                                className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                            >
                                <option value="">
                                    All statuses
                                </option>

                                <option value="present">
                                    Present
                                </option>

                                <option value="half_day">
                                    Half Day
                                </option>

                                <option value="incomplete">
                                    Incomplete
                                </option>

                                <option value="absent">
                                    Absent
                                </option>

                                <option value="off_day">
                                    Off Day
                                </option>

                                <option value="off_day_work">
                                    Off Day Work
                                </option>
                            </select>
                        </div>

                        <div>
                            <label className="mb-1.5 block text-sm font-medium">
                                Date from
                            </label>

                            <input
                                type="date"
                                value={
                                    form.date_from
                                }
                                onChange={(
                                    event
                                ) =>
                                    setForm(
                                        (
                                            current
                                        ) => ({
                                            ...current,
                                            date_from:
                                                event
                                                    .target
                                                    .value,
                                        })
                                    )
                                }
                                className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                            />
                        </div>

                        <div>
                            <label className="mb-1.5 block text-sm font-medium">
                                Date to
                            </label>

                            <input
                                type="date"
                                value={
                                    form.date_to
                                }
                                onChange={(
                                    event
                                ) =>
                                    setForm(
                                        (
                                            current
                                        ) => ({
                                            ...current,
                                            date_to:
                                                event
                                                    .target
                                                    .value,
                                        })
                                    )
                                }
                                className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                            />
                        </div>
                    </div>

                    <div className="mt-4 flex flex-wrap gap-2">
                        <button
                            type="submit"
                            className="inline-flex h-10 items-center gap-2 rounded-md bg-slate-950 px-4 text-sm font-medium text-white hover:bg-slate-800 dark:bg-white dark:text-slate-950"
                        >
                            <Filter className="size-4" />
                            Apply Filters
                        </button>

                        <button
                            type="button"
                            onClick={
                                resetFilters
                            }
                            className="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 px-4 text-sm font-medium hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                        >
                            <X className="size-4" />
                            Reset
                        </button>
                    </div>
                </form>

                <div className="overflow-hidden rounded-xl border bg-white shadow-sm dark:border-slate-800 dark:bg-slate-950">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[1200px] text-sm">
                            <thead className="border-b bg-slate-50 text-left dark:border-slate-800 dark:bg-slate-900/50">
                                <tr>
                                    <th className="px-4 py-3 font-medium">
                                        Employee
                                    </th>

                                    <th className="px-4 py-3 font-medium">
                                        Date
                                    </th>

                                    <th className="px-4 py-3 font-medium">
                                        Shift
                                    </th>

                                    <th className="px-4 py-3 font-medium">
                                        In
                                    </th>

                                    <th className="px-4 py-3 font-medium">
                                        Out
                                    </th>

                                    <th className="px-4 py-3 font-medium">
                                        Worked
                                    </th>

                                    <th className="px-4 py-3 font-medium">
                                        Late
                                    </th>

                                    <th className="px-4 py-3 font-medium">
                                        Early
                                    </th>

                                    <th className="px-4 py-3 font-medium">
                                        OT
                                    </th>

                                    <th className="px-4 py-3 font-medium">
                                        Punches
                                    </th>

                                    <th className="px-4 py-3 font-medium">
                                        Status
                                    </th>

                                    <th className="px-4 py-3 text-right font-medium">
                                        Action
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y dark:divide-slate-800">
                                {records.data.length >
                                0 ? (
                                    records.data.map(
                                        (
                                            record
                                        ) => (
                                            <tr
                                                key={
                                                    record.id
                                                }
                                                className="hover:bg-slate-50/70 dark:hover:bg-slate-900/30"
                                            >
                                                <td className="px-4 py-3">
                                                    <div className="font-medium">
                                                        {employeeName(
                                                            record.employee
                                                        )}
                                                    </div>

                                                    <div className="mt-0.5 text-xs text-slate-500">
                                                        {
                                                            record
                                                                .employee
                                                                .employee_number
                                                        }
                                                    </div>
                                                </td>

                                                <td className="whitespace-nowrap px-4 py-3">
                                                    <div className="flex items-center gap-2">
                                                        <CalendarDays className="size-4 text-slate-400" />

                                                        {
                                                            record.attendance_date
                                                        }
                                                    </div>
                                                </td>

                                                <td className="px-4 py-3">
                                                    {record.shift ? (
                                                        <>
                                                            <div className="font-medium">
                                                                {
                                                                    record
                                                                        .shift
                                                                        .name
                                                                }
                                                            </div>

                                                            <div className="text-xs text-slate-500">
                                                                {
                                                                    record
                                                                        .shift
                                                                        .code
                                                                }
                                                            </div>
                                                        </>
                                                    ) : (
                                                        "—"
                                                    )}
                                                </td>

                                                <td className="whitespace-nowrap px-4 py-3">
                                                    {localTimeFromUtc(
                                                        record.check_in_at
                                                    )}
                                                </td>

                                                <td className="whitespace-nowrap px-4 py-3">
                                                    {localTimeFromUtc(
                                                        record.check_out_at
                                                    )}
                                                </td>

                                                <td className="whitespace-nowrap px-4 py-3 font-medium">
                                                    {formatMinutes(
                                                        record.worked_minutes
                                                    )}
                                                </td>

                                                <td className="px-4 py-3">
                                                    {formatMinutes(
                                                        record.late_minutes
                                                    )}
                                                </td>

                                                <td className="px-4 py-3">
                                                    {formatMinutes(
                                                        record.early_leave_minutes
                                                    )}
                                                </td>

                                                <td className="px-4 py-3">
                                                    {formatMinutes(
                                                        record.overtime_minutes
                                                    )}
                                                </td>

                                                <td className="px-4 py-3">
                                                    {
                                                        record.punch_count
                                                    }
                                                </td>

                                                <td className="px-4 py-3">
                                                    <span
                                                        className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset ${statusClass(
                                                            record.attendance_status
                                                        )}`}
                                                    >
                                                        {statusLabel(
                                                            record.attendance_status
                                                        )}
                                                    </span>
                                                </td>

                                                <td className="px-4 py-3 text-right">
                                                    <Link
                                                        href={`/erp/attendance/records/${record.id}`}
                                                        className="inline-flex h-9 items-center gap-2 rounded-md border border-slate-200 px-3 text-sm font-medium hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                                                    >
                                                        <Eye className="size-4" />
                                                        View
                                                    </Link>
                                                </td>
                                            </tr>
                                        )
                                    )
                                ) : (
                                    <tr>
                                        <td
                                            colSpan={
                                                12
                                            }
                                            className="px-4 py-16 text-center"
                                        >
                                            <div className="mx-auto flex max-w-sm flex-col items-center">
                                                <Clock3 className="mb-3 size-10 text-slate-300" />

                                                <p className="font-medium">
                                                    No attendance
                                                    records
                                                    found
                                                </p>

                                                <p className="mt-1 text-sm text-slate-500">
                                                    Try changing
                                                    the current
                                                    filters.
                                                </p>
                                            </div>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {records.last_page >
                        1 && (
                        <div className="flex flex-col gap-3 border-t px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
                            <div className="text-sm text-slate-500">
                                Showing{" "}
                                {records.from ??
                                    0}{" "}
                                to{" "}
                                {records.to ??
                                    0}{" "}
                                of{" "}
                                {records.total}
                            </div>

                            <div className="flex flex-wrap gap-1">
                                {records.links.map(
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
                                                            preserveState:
                                                                true,
                                                            preserveScroll:
                                                                true,
                                                        }
                                                    );
                                                }
                                            }}
                                            className={`min-w-9 rounded-md border px-3 py-1.5 text-sm ${
                                                link.active
                                                    ? "border-slate-950 bg-slate-950 text-white dark:border-white dark:bg-white dark:text-slate-950"
                                                    : "border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                                            } disabled:cursor-not-allowed disabled:opacity-40`}
                                            dangerouslySetInnerHTML={{
                                                __html:
                                                    link.label,
                                            }}
                                        />
                                    )
                                )}
                            </div>
                        </div>
                    )}
                </div>

                <div className="grid gap-4 md:grid-cols-3">
                    <div className="rounded-xl border bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                        <div className="flex items-center gap-2 text-sm font-medium">
                            <Timer className="size-4" />
                            Timezone
                        </div>

                        <p className="mt-2 text-sm text-slate-500">
                            Attendance times are
                            displayed in
                            Asia/Dhaka for this
                            initial HRM interface.
                        </p>
                    </div>

                    <div className="rounded-xl border bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                        <div className="flex items-center gap-2 text-sm font-medium">
                            <Clock3 className="size-4" />
                            Calculated Records
                        </div>

                        <p className="mt-2 text-sm text-slate-500">
                            These records are
                            generated from resolved
                            raw punch evidence and
                            effective shifts.
                        </p>
                    </div>

                    <div className="rounded-xl border bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                        <div className="flex items-center gap-2 text-sm font-medium">
                            <UserCheck className="size-4" />
                            Audit Trail
                        </div>

                        <p className="mt-2 text-sm text-slate-500">
                            Open any record to see
                            the exact device punches
                            used in its calculation.
                        </p>
                    </div>
                </div>
            </div>
        </ERPLayout>
    );
}
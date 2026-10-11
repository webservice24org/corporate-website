import {
    Head,
    router,
} from "@inertiajs/react";
import {
    CalendarDays,
    Clock3,
    Download,
    Filter,
    TimerOff,
    UserRoundX,
} from "lucide-react";
import { useState } from "react";

import ERPLayout from "@/Layouts/erp-layout";
import { useAuthorization } from "@/hooks/useAuthorization";

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
    name: string;
};

type Employee = {
    id: number;
    company_id: number;
    branch_id: number | null;
    department_id: number | null;
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

type AttendanceRecord = {
    id: number;
    attendance_date: string;
    attendance_status: string;

    check_in_at: string | null;
    check_out_at: string | null;

    worked_minutes: number;
    late_minutes: number;
    early_leave_minutes: number;
    overtime_minutes: number;

    punch_count: number;
    source: string | null;

    employee: Employee;

    shift?: {
        id: number;
        name: string;
        code: string;
    } | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedRecords = {
    data: AttendanceRecord[];
    links: PaginationLink[];
    total: number;
};

type Summary = {
    total_records: number;
    present: number;
    absent: number;
    leave: number;
    off_day: number;
    off_day_work: number;
    late: number;
    early_leave: number;
    overtime: number;
    late_minutes: number;
    early_leave_minutes: number;
    overtime_minutes: number;
    worked_minutes: number;
};

type StatusSummary = {
    attendance_status: string;
    total: number;
    worked_minutes: number;
    late_minutes: number;
    early_leave_minutes: number;
    overtime_minutes: number;
};

type EmployeeSummary = {
    employee_id: number;
    total_days: number;
    present_days: number;
    absent_days: number;
    leave_days: number;
    off_days: number;
    off_day_work_days: number;
    late_days: number;
    early_leave_days: number;
    late_minutes: number;
    early_leave_minutes: number;
    overtime_minutes: number;
    worked_minutes: number;

    employee: Employee;
};

type Filters = {
    date_from: string;
    date_to: string;

    company_id?: number | null;
    branch_id?: number | null;
    department_id?: number | null;
    employee_id?: number | null;

    attendance_status?: string | null;

    exception_type:
        | "all"
        | "late"
        | "early_leave"
        | "overtime";

    view:
        | "daily"
        | "employee"
        | "exceptions"
        | "summary";
};

type Props = {
    records: PaginatedRecords;

    summary: Summary;

    statusSummary: StatusSummary[];

    employeeSummary: EmployeeSummary[];

    companies: Company[];
    branches: Branch[];
    departments: Department[];
    employees: Employee[];

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

function minutes(
    value: number
): string {
    const total =
        Number(value ?? 0);

    const hours =
        Math.floor(
            total / 60
        );

    const mins =
        total % 60;

    if (hours === 0) {
        return `${mins}m`;
    }

    return `${hours}h ${mins}m`;
}

function formatDateTime(
    value: string | null
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
        return "—";
    }

    return date.toLocaleString();
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

export default function Index({
    records,
    summary,
    statusSummary,
    employeeSummary,
    companies,
    branches,
    departments,
    employees,
    filters,
}: Props) {
    const { can } =
        useAuthorization();

    const [
        dateFrom,
        setDateFrom,
    ] = useState(
        filters.date_from
    );

    const [
        dateTo,
        setDateTo,
    ] = useState(
        filters.date_to
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
        branchId,
        setBranchId,
    ] = useState(
        filters.branch_id
            ? String(
                  filters.branch_id
              )
            : ""
    );

    const [
        departmentId,
        setDepartmentId,
    ] = useState(
        filters.department_id
            ? String(
                  filters.department_id
              )
            : ""
    );

    const [
        employeeId,
        setEmployeeId,
    ] = useState(
        filters.employee_id
            ? String(
                  filters.employee_id
              )
            : ""
    );

    const [
        attendanceStatus,
        setAttendanceStatus,
    ] = useState(
        filters.attendance_status ??
            ""
    );

    const [
        exceptionType,
        setExceptionType,
    ] = useState(
        filters.exception_type
    );

    const view =
        filters.view;

    const changeView = (
        nextView: Filters["view"]
    ) => {
        router.get(
            "/erp/reports/attendance",
            {
                ...filters,
                view: nextView,
            },
            {
                preserveState: true,
                preserveScroll: true,
            }
        );
    };

    const applyFilters = () => {
        router.get(
            "/erp/reports/attendance",
            {
                date_from:
                    dateFrom,

                date_to:
                    dateTo,

                company_id:
                    companyId ||
                    undefined,

                branch_id:
                    branchId ||
                    undefined,

                department_id:
                    departmentId ||
                    undefined,

                employee_id:
                    employeeId ||
                    undefined,

                attendance_status:
                    attendanceStatus ||
                    undefined,

                exception_type:
                    exceptionType,

                view,
            },
            {
                preserveState: true,
                preserveScroll: true,
            }
        );
    };

    const exportCsv = () => {
        const params =
            new URLSearchParams();

        params.set(
            "date_from",
            dateFrom
        );

        params.set(
            "date_to",
            dateTo
        );

        if (companyId) {
            params.set(
                "company_id",
                companyId
            );
        }

        if (branchId) {
            params.set(
                "branch_id",
                branchId
            );
        }

        if (departmentId) {
            params.set(
                "department_id",
                departmentId
            );
        }

        if (employeeId) {
            params.set(
                "employee_id",
                employeeId
            );
        }

        if (attendanceStatus) {
            params.set(
                "attendance_status",
                attendanceStatus
            );
        }

        params.set(
            "exception_type",
            exceptionType
        );

        params.set(
            "view",
            view
        );

        window.location.href =
            `/erp/reports/attendance/export/csv?${params.toString()}`;
    };

    const tabs: {
        key: Filters["view"];
        label: string;
    }[] = [
        {
            key: "daily",
            label:
                "Daily Attendance",
        },
        {
            key: "employee",
            label:
                "Employee Range",
        },
        {
            key: "exceptions",
            label:
                "Late / Early / Overtime",
        },
        {
            key: "summary",
            label:
                "Status Summary",
        },
    ];

    return (
        <ERPLayout>
            <Head title="Attendance Reports" />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Attendance Reports
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Daily attendance,
                            employee history,
                            exceptions and
                            attendance summaries.
                        </p>
                    </div>

                    {can(
                        "erp.attendance-reports.export"
                    ) && (
                        <button
                            type="button"
                            onClick={
                                exportCsv
                            }
                            className="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 px-4 text-sm font-medium hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                        >
                            <Download className="h-4 w-4" />

                            Export CSV
                        </button>
                    )}
                </div>

                <div className="flex flex-wrap gap-2">
                    {tabs.map(
                        (tab) => (
                            <button
                                key={
                                    tab.key
                                }
                                type="button"
                                onClick={() =>
                                    changeView(
                                        tab.key
                                    )
                                }
                                className={`rounded-md px-4 py-2 text-sm font-medium ${
                                    view ===
                                    tab.key
                                        ? "bg-slate-900 text-white dark:bg-white dark:text-slate-950"
                                        : "border border-slate-200 bg-white hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-950 dark:hover:bg-slate-900"
                                }`}
                            >
                                {
                                    tab.label
                                }
                            </button>
                        )
                    )}
                </div>

                <div className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <input
                            type="date"
                            value={
                                dateFrom
                            }
                            onChange={(
                                event
                            ) =>
                                setDateFrom(
                                    event
                                        .target
                                        .value
                                )
                            }
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        />

                        <input
                            type="date"
                            value={
                                dateTo
                            }
                            onChange={(
                                event
                            ) =>
                                setDateTo(
                                    event
                                        .target
                                        .value
                                )
                            }
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
                                    event
                                        .target
                                        .value
                                );

                                setBranchId(
                                    ""
                                );

                                setDepartmentId(
                                    ""
                                );

                                setEmployeeId(
                                    ""
                                );
                            }}
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
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

                        <select
                            value={
                                branchId
                            }
                            onChange={(
                                event
                            ) => {
                                setBranchId(
                                    event
                                        .target
                                        .value
                                );

                                setEmployeeId(
                                    ""
                                );
                            }}
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
                            <option value="">
                                All branches
                            </option>

                            {branches.map(
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

                        <select
                            value={
                                departmentId
                            }
                            onChange={(
                                event
                            ) => {
                                setDepartmentId(
                                    event
                                        .target
                                        .value
                                );

                                setEmployeeId(
                                    ""
                                );
                            }}
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
                            <option value="">
                                All departments
                            </option>

                            {departments.map(
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

                        <select
                            value={
                                employeeId
                            }
                            onChange={(
                                event
                            ) =>
                                setEmployeeId(
                                    event
                                        .target
                                        .value
                                )
                            }
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
                            <option value="">
                                All employees
                            </option>

                            {employees.map(
                                (
                                    employee
                                ) => (
                                    <option
                                        key={
                                            employee.id
                                        }
                                        value={
                                            employee.id
                                        }
                                    >
                                        {
                                            employee.employee_number
                                        }{" "}
                                        —{" "}
                                        {employeeName(
                                            employee
                                        )}
                                    </option>
                                )
                            )}
                        </select>

                        <select
                            value={
                                attendanceStatus
                            }
                            onChange={(
                                event
                            ) =>
                                setAttendanceStatus(
                                    event
                                        .target
                                        .value
                                )
                            }
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
                            <option value="">
                                All statuses
                            </option>

                            <option value="present">
                                Present
                            </option>

                            <option value="absent">
                                Absent
                            </option>

                            <option value="leave">
                                Leave
                            </option>

                            <option value="off_day">
                                Off Day
                            </option>

                            <option value="off_day_work">
                                Off Day Work
                            </option>
                        </select>

                        {view ===
                            "exceptions" && (
                            <select
                                value={
                                    exceptionType
                                }
                                onChange={(
                                    event
                                ) =>
                                    setExceptionType(
                                        event
                                            .target
                                            .value as Filters["exception_type"]
                                    )
                                }
                                className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                            >
                                <option value="all">
                                    All Exceptions
                                </option>

                                <option value="late">
                                    Late Only
                                </option>

                                <option value="early_leave">
                                    Early Leave
                                </option>

                                <option value="overtime">
                                    Overtime
                                </option>
                            </select>
                        )}

                        <button
                            type="button"
                            onClick={
                                applyFilters
                            }
                            className="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-slate-900 px-4 text-sm font-medium text-white dark:bg-white dark:text-slate-950"
                        >
                            <Filter className="h-4 w-4" />

                            Apply Filters
                        </button>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <SummaryCard
                        title="Present"
                        value={
                            summary.present
                        }
                        icon={
                            CalendarDays
                        }
                    />

                    <SummaryCard
                        title="Absent"
                        value={
                            summary.absent
                        }
                        icon={
                            UserRoundX
                        }
                    />

                    <SummaryCard
                        title="Late"
                        value={
                            summary.late
                        }
                        detail={minutes(
                            summary.late_minutes
                        )}
                        icon={
                            Clock3
                        }
                    />

                    <SummaryCard
                        title="Overtime"
                        value={
                            summary.overtime
                        }
                        detail={minutes(
                            summary.overtime_minutes
                        )}
                        icon={
                            TimerOff
                        }
                    />
                </div>

                {view ===
                    "employee" ? (
                    <EmployeeSummaryTable
                        rows={
                            employeeSummary
                        }
                    />
                ) : view ===
                  "summary" ? (
                    <StatusSummaryTable
                        rows={
                            statusSummary
                        }
                    />
                ) : (
                    <AttendanceTable
                        records={
                            records
                        }
                    />
                )}
            </div>
        </ERPLayout>
    );
}

function SummaryCard({
    title,
    value,
    detail,
    icon: Icon,
}: {
    title: string;
    value: number;
    detail?: string;
    icon: typeof CalendarDays;
}) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
            <div className="flex items-start justify-between">
                <div>
                    <p className="text-sm text-slate-500">
                        {title}
                    </p>

                    <p className="mt-2 text-2xl font-semibold">
                        {value}
                    </p>

                    {detail && (
                        <p className="mt-1 text-xs text-slate-500">
                            {detail}
                        </p>
                    )}
                </div>

                <Icon className="h-5 w-5 text-slate-400" />
            </div>
        </div>
    );
}

function AttendanceTable({
    records,
}: {
    records: PaginatedRecords;
}) {
    return (
        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
            <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase text-slate-500 dark:border-slate-800 dark:bg-slate-900/50">
                        <tr>
                            <th className="px-4 py-3">
                                Date
                            </th>

                            <th className="px-4 py-3">
                                Employee
                            </th>

                            <th className="px-4 py-3">
                                Status
                            </th>

                            <th className="px-4 py-3">
                                Shift
                            </th>

                            <th className="px-4 py-3">
                                Check In
                            </th>

                            <th className="px-4 py-3">
                                Check Out
                            </th>

                            <th className="px-4 py-3 text-right">
                                Worked
                            </th>

                            <th className="px-4 py-3 text-right">
                                Late
                            </th>

                            <th className="px-4 py-3 text-right">
                                Early
                            </th>

                            <th className="px-4 py-3 text-right">
                                OT
                            </th>
                        </tr>
                    </thead>

                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                        {records.data.map(
                            (record) => (
                                <tr
                                    key={
                                        record.id
                                    }
                                >
                                    <td className="px-4 py-4 whitespace-nowrap">
                                        {
                                            record.attendance_date
                                        }
                                    </td>

                                    <td className="px-4 py-4">
                                        <div className="font-medium">
                                            {employeeName(
                                                record.employee
                                            )}
                                        </div>

                                        <div className="mt-1 text-xs text-slate-500">
                                            {
                                                record
                                                    .employee
                                                    .employee_number
                                            }
                                        </div>
                                    </td>

                                    <td className="px-4 py-4">
                                        {statusLabel(
                                            record.attendance_status
                                        )}
                                    </td>

                                    <td className="px-4 py-4">
                                        {record
                                            .shift
                                            ?.name ??
                                            "—"}
                                    </td>

                                    <td className="px-4 py-4 whitespace-nowrap">
                                        {formatDateTime(
                                            record.check_in_at
                                        )}
                                    </td>

                                    <td className="px-4 py-4 whitespace-nowrap">
                                        {formatDateTime(
                                            record.check_out_at
                                        )}
                                    </td>

                                    <td className="px-4 py-4 text-right">
                                        {minutes(
                                            record.worked_minutes
                                        )}
                                    </td>

                                    <td className="px-4 py-4 text-right">
                                        {minutes(
                                            record.late_minutes
                                        )}
                                    </td>

                                    <td className="px-4 py-4 text-right">
                                        {minutes(
                                            record.early_leave_minutes
                                        )}
                                    </td>

                                    <td className="px-4 py-4 text-right">
                                        {minutes(
                                            record.overtime_minutes
                                        )}
                                    </td>
                                </tr>
                            )
                        )}

                        {records.data
                            .length ===
                            0 && (
                            <tr>
                                <td
                                    colSpan={
                                        10
                                    }
                                    className="px-4 py-16 text-center text-slate-500"
                                >
                                    No attendance
                                    records found.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            {records.links.length >
                3 && (
                <div className="flex flex-wrap gap-1 border-t border-slate-200 p-4 dark:border-slate-800">
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
    );
}

function EmployeeSummaryTable({
    rows,
}: {
    rows: EmployeeSummary[];
}) {
    return (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
            <table className="w-full text-left text-sm">
                <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase text-slate-500 dark:border-slate-800 dark:bg-slate-900/50">
                    <tr>
                        <th className="px-4 py-3">
                            Employee
                        </th>

                        <th className="px-4 py-3 text-right">
                            Present
                        </th>

                        <th className="px-4 py-3 text-right">
                            Absent
                        </th>

                        <th className="px-4 py-3 text-right">
                            Leave
                        </th>

                        <th className="px-4 py-3 text-right">
                            Off Day
                        </th>

                        <th className="px-4 py-3 text-right">
                            Late
                        </th>

                        <th className="px-4 py-3 text-right">
                            Early
                        </th>

                        <th className="px-4 py-3 text-right">
                            OT
                        </th>

                        <th className="px-4 py-3 text-right">
                            Worked
                        </th>
                    </tr>
                </thead>

                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                    {rows.map(
                        (row) => (
                            <tr
                                key={
                                    row.employee_id
                                }
                            >
                                <td className="px-4 py-4">
                                    <div className="font-medium">
                                        {employeeName(
                                            row.employee
                                        )}
                                    </div>

                                    <div className="mt-1 text-xs text-slate-500">
                                        {
                                            row
                                                .employee
                                                .employee_number
                                        }
                                    </div>
                                </td>

                                <td className="px-4 py-4 text-right">
                                    {
                                        row.present_days
                                    }
                                </td>

                                <td className="px-4 py-4 text-right">
                                    {
                                        row.absent_days
                                    }
                                </td>

                                <td className="px-4 py-4 text-right">
                                    {
                                        row.leave_days
                                    }
                                </td>

                                <td className="px-4 py-4 text-right">
                                    {
                                        row.off_days
                                    }
                                </td>

                                <td className="px-4 py-4 text-right">
                                    {row.late_days}
                                </td>

                                <td className="px-4 py-4 text-right">
                                    {
                                        row.early_leave_days
                                    }
                                </td>

                                <td className="px-4 py-4 text-right">
                                    {minutes(
                                        row.overtime_minutes
                                    )}
                                </td>

                                <td className="px-4 py-4 text-right">
                                    {minutes(
                                        row.worked_minutes
                                    )}
                                </td>
                            </tr>
                        )
                    )}
                </tbody>
            </table>
        </div>
    );
}

function StatusSummaryTable({
    rows,
}: {
    rows: StatusSummary[];
}) {
    return (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
            <table className="w-full text-left text-sm">
                <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase text-slate-500 dark:border-slate-800 dark:bg-slate-900/50">
                    <tr>
                        <th className="px-4 py-3">
                            Status
                        </th>

                        <th className="px-4 py-3 text-right">
                            Days
                        </th>

                        <th className="px-4 py-3 text-right">
                            Worked
                        </th>

                        <th className="px-4 py-3 text-right">
                            Late
                        </th>

                        <th className="px-4 py-3 text-right">
                            Early
                        </th>

                        <th className="px-4 py-3 text-right">
                            Overtime
                        </th>
                    </tr>
                </thead>

                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                    {rows.map(
                        (row) => (
                            <tr
                                key={
                                    row.attendance_status
                                }
                            >
                                <td className="px-4 py-4 font-medium">
                                    {statusLabel(
                                        row.attendance_status
                                    )}
                                </td>

                                <td className="px-4 py-4 text-right">
                                    {row.total}
                                </td>

                                <td className="px-4 py-4 text-right">
                                    {minutes(
                                        row.worked_minutes
                                    )}
                                </td>

                                <td className="px-4 py-4 text-right">
                                    {minutes(
                                        row.late_minutes
                                    )}
                                </td>

                                <td className="px-4 py-4 text-right">
                                    {minutes(
                                        row.early_leave_minutes
                                    )}
                                </td>

                                <td className="px-4 py-4 text-right">
                                    {minutes(
                                        row.overtime_minutes
                                    )}
                                </td>
                            </tr>
                        )
                    )}
                </tbody>
            </table>
        </div>
    );
}
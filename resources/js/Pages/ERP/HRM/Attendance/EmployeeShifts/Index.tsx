import DeleteConfirmDialog from "@/Components/Common/DeleteConfirmDialog";
import ERPLayout from "@/Layouts/erp-layout";

import {
    useAuthorization,
} from "@/hooks/useAuthorization";

import {
    Head,
    Link,
    router,
} from "@inertiajs/react";

import {
    CalendarClock,
    Pencil,
    Plus,
    Search,
    Trash2,
} from "lucide-react";

import {
    useState,
} from "react";

import type {
    FormEvent,
} from "react";

type Company = {
    id: number;
    name: string;
    code: string;
};

type EmployeeOption = {
    id: number;

    company_id: number;

    employee_number: string;

    first_name: string;

    middle_name:
        | string
        | null;

    last_name: string;
};

type ShiftOption = {
    id: number;
    company_id: number;

    name: string;
    code: string;
};

type Assignment = {
    id: number;

    employee_id: number;
    shift_id: number;

    effective_from:
        string;

    effective_to:
        | string
        | null;

    status: string;

    employee: {
        id: number;

        employee_number:
            string;

        first_name:
            string;

        middle_name:
            | string
            | null;

        last_name:
            string;

        employment_status:
            string;

        company:
            | Company
            | null;

        branch:
            | {
                  id: number;
                  name: string;
                  code: string;
              }
            | null;

        department:
            | {
                  id: number;
                  name: string;
                  code: string;
              }
            | null;

        designation:
            | {
                  id: number;
                  name: string;
                  code: string;
              }
            | null;
    };

    shift: {
        id: number;

        name: string;
        code: string;

        start_time:
            string;

        end_time:
            string;

        crosses_midnight:
            boolean;
    };

    assigned_by:
        | {
              id: number;
              name: string;
          }
        | null;
};

type PaginationLink = {
    url:
        | string
        | null;

    label: string;

    active: boolean;
};

type PaginatedAssignments = {
    data:
        Assignment[];

    links:
        PaginationLink[];
};

type Filters = {
    search:
        | string
        | null;

    company_id:
        | string
        | null;

    employee_id:
        | string
        | null;

    shift_id:
        | string
        | null;

    status:
        | string
        | null;
};

type Props = {
    assignments:
        PaginatedAssignments;

    companies:
        Company[];

    employees:
        EmployeeOption[];

    shifts:
        ShiftOption[];

    filters:
        Filters;
};

export default function Index({
    assignments,
    companies,
    employees,
    shifts,
    filters,
}: Props) {
    const {
        can,
    } = useAuthorization();

    const [
        search,
        setSearch,
    ] = useState(
        filters.search ??
            "",
    );

    const [
        deleteAssignment,
        setDeleteAssignment,
    ] =
        useState<Assignment | null>(
            null,
        );

    const [
        deleting,
        setDeleting,
    ] = useState(false);

    const applyFilters = (
        changes: Record<
            string,
            string
        >,
    ) => {
        router.get(
            "/erp/attendance/employee-shifts",
            {
                search,
                company_id:
                    filters.company_id ??
                    "",
                employee_id:
                    filters.employee_id ??
                    "",
                shift_id:
                    filters.shift_id ??
                    "",
                status:
                    filters.status ??
                    "",
                ...changes,
            },
            {
                preserveState:
                    true,

                replace:
                    true,
            },
        );
    };

    const submitSearch = (
        event:
            FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();

        applyFilters({
            search,
        });
    };

    const confirmDelete =
        () => {
            if (
                !deleteAssignment ||
                deleting
            ) {
                return;
            }

            setDeleting(true);

            router.delete(
                `/erp/attendance/employee-shifts/${deleteAssignment.id}`,
                {
                    preserveScroll:
                        true,

                    onSuccess:
                        () =>
                            setDeleteAssignment(
                                null,
                            ),

                    onFinish:
                        () =>
                            setDeleting(
                                false,
                            ),
                },
            );
        };

    return (
        <ERPLayout>
            <Head title="Employee Shift Assignments" />

            <div className="mx-auto max-w-7xl space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-sm text-slate-500">
                            <CalendarClock className="size-4" />

                            Attendance
                        </div>

                        <h1 className="mt-2 text-2xl font-bold tracking-tight text-slate-900">
                            Employee Shift Assignments
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Manage effective-dated employee work schedules.
                        </p>
                    </div>

                    {can(
                        "erp.employee-shifts.create",
                    ) && (
                        <Link
                            href="/erp/attendance/employee-shifts/create"
                            className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 text-sm font-medium text-white"
                        >
                            <Plus className="size-4" />

                            Assign Shift
                        </Link>
                    )}
                </div>

                <section className="rounded-xl border bg-white p-4 shadow-sm">
                    <div className="grid gap-3 xl:grid-cols-[1fr_200px_240px_220px_160px]">
                        <form
                            onSubmit={
                                submitSearch
                            }
                            className="relative"
                        >
                            <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />

                            <input
                                value={
                                    search
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setSearch(
                                        event.target.value,
                                    )
                                }
                                placeholder="Employee name or number..."
                                className="min-h-10 w-full rounded-lg border border-slate-200 pl-9 pr-3 text-sm"
                            />
                        </form>

                        <select
                            value={
                                filters.company_id ??
                                ""
                            }
                            onChange={(
                                event,
                            ) =>
                                applyFilters({
                                    company_id:
                                        event.target.value,

                                    employee_id:
                                        "",

                                    shift_id:
                                        "",
                                })
                            }
                            className="min-h-10 rounded-lg border border-slate-200 px-3 text-sm"
                        >
                            <option value="">
                                All companies
                            </option>

                            {companies.map(
                                (
                                    company,
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
                                ),
                            )}
                        </select>

                        <select
                            value={
                                filters.employee_id ??
                                ""
                            }
                            onChange={(
                                event,
                            ) =>
                                applyFilters({
                                    employee_id:
                                        event.target.value,
                                })
                            }
                            className="min-h-10 rounded-lg border border-slate-200 px-3 text-sm"
                        >
                            <option value="">
                                All employees
                            </option>

                            {employees
                                .filter(
                                    (
                                        employee,
                                    ) =>
                                        !filters.company_id ||
                                        String(
                                            employee.company_id,
                                        ) ===
                                            String(
                                                filters.company_id,
                                            ),
                                )
                                .map(
                                    (
                                        employee,
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
                                            }
                                            {" — "}
                                            {employeeName(
                                                employee,
                                            )}
                                        </option>
                                    ),
                                )}
                        </select>

                        <select
                            value={
                                filters.shift_id ??
                                ""
                            }
                            onChange={(
                                event,
                            ) =>
                                applyFilters({
                                    shift_id:
                                        event.target.value,
                                })
                            }
                            className="min-h-10 rounded-lg border border-slate-200 px-3 text-sm"
                        >
                            <option value="">
                                All shifts
                            </option>

                            {shifts
                                .filter(
                                    (
                                        shift,
                                    ) =>
                                        !filters.company_id ||
                                        String(
                                            shift.company_id,
                                        ) ===
                                            String(
                                                filters.company_id,
                                            ),
                                )
                                .map(
                                    (
                                        shift,
                                    ) => (
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
                                            }
                                            {" ("}
                                            {
                                                shift.code
                                            }
                                            {")"}
                                        </option>
                                    ),
                                )}
                        </select>

                        <select
                            value={
                                filters.status ??
                                ""
                            }
                            onChange={(
                                event,
                            ) =>
                                applyFilters({
                                    status:
                                        event.target.value,
                                })
                            }
                            className="min-h-10 rounded-lg border border-slate-200 px-3 text-sm"
                        >
                            <option value="">
                                All statuses
                            </option>

                            <option value="active">
                                Active
                            </option>

                            <option value="inactive">
                                Inactive
                            </option>
                        </select>
                    </div>
                </section>

                <section className="overflow-hidden rounded-xl border bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-5 py-3">
                                        Employee
                                    </th>

                                    <th className="px-5 py-3">
                                        Organization
                                    </th>

                                    <th className="px-5 py-3">
                                        Shift
                                    </th>

                                    <th className="px-5 py-3">
                                        Effective Period
                                    </th>

                                    <th className="px-5 py-3">
                                        State
                                    </th>

                                    <th className="px-5 py-3 text-right">
                                        Actions
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y">
                                {assignments.data.map(
                                    (
                                        assignment,
                                    ) => {
                                        const state =
                                            assignmentState(
                                                assignment,
                                            );

                                        return (
                                            <tr
                                                key={
                                                    assignment.id
                                                }
                                            >
                                                <td className="px-5 py-4">
                                                    <div className="font-medium text-slate-900">
                                                        {employeeName(
                                                            assignment.employee,
                                                        )}
                                                    </div>

                                                    <div className="mt-1 text-xs text-slate-500">
                                                        {
                                                            assignment
                                                                .employee
                                                                .employee_number
                                                        }
                                                    </div>
                                                </td>

                                                <td className="px-5 py-4">
                                                    <div>
                                                        {assignment.employee.company?.name ??
                                                            "—"}
                                                    </div>

                                                    <div className="mt-1 text-xs text-slate-500">
                                                        {[
                                                            assignment
                                                                .employee
                                                                .department
                                                                ?.name,

                                                            assignment
                                                                .employee
                                                                .designation
                                                                ?.name,
                                                        ]
                                                            .filter(
                                                                Boolean,
                                                            )
                                                            .join(
                                                                " • ",
                                                            ) ||
                                                            "—"}
                                                    </div>
                                                </td>

                                                <td className="px-5 py-4">
                                                    <div className="font-medium text-slate-800">
                                                        {
                                                            assignment
                                                                .shift
                                                                .name
                                                        }
                                                    </div>

                                                    <div className="mt-1 text-xs text-slate-500">
                                                        {
                                                            assignment
                                                                .shift
                                                                .code
                                                        }

                                                        {" • "}

                                                        {formatTime(
                                                            assignment
                                                                .shift
                                                                .start_time,
                                                        )}

                                                        {" → "}

                                                        {formatTime(
                                                            assignment
                                                                .shift
                                                                .end_time,
                                                        )}
                                                    </div>
                                                </td>

                                                <td className="px-5 py-4">
                                                    <div>
                                                        {formatDate(
                                                            assignment.effective_from,
                                                        )}
                                                    </div>

                                                    <div className="mt-1 text-xs text-slate-500">
                                                        to{" "}

                                                        {assignment.effective_to
                                                            ? formatDate(
                                                                  assignment.effective_to,
                                                              )
                                                            : "Present"}
                                                    </div>
                                                </td>

                                                <td className="px-5 py-4">
                                                    <StateBadge
                                                        state={
                                                            state
                                                        }
                                                    />
                                                </td>

                                                <td className="px-5 py-4">
                                                    <div className="flex justify-end gap-2">
                                                        {can(
                                                            "erp.employee-shifts.edit",
                                                        ) && (
                                                            <Link
                                                                href={`/erp/attendance/employee-shifts/${assignment.id}/edit`}
                                                                className="inline-flex size-9 items-center justify-center rounded-lg border hover:bg-slate-50"
                                                            >
                                                                <Pencil className="size-4" />
                                                            </Link>
                                                        )}

                                                        {can(
                                                            "erp.employee-shifts.delete",
                                                        ) && (
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    setDeleteAssignment(
                                                                        assignment,
                                                                    )
                                                                }
                                                                className="inline-flex size-9 items-center justify-center rounded-lg border text-red-600 hover:bg-red-50"
                                                            >
                                                                <Trash2 className="size-4" />
                                                            </button>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    },
                                )}

                                {assignments.data.length ===
                                    0 && (
                                    <tr>
                                        <td
                                            colSpan={
                                                6
                                            }
                                            className="px-5 py-12 text-center text-slate-500"
                                        >
                                            No shift assignments found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex flex-wrap gap-2 border-t px-5 py-4">
                        {assignments.links.map(
                            (
                                link,
                                index,
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
                                            );
                                        }
                                    }}
                                    className={`rounded-lg border px-3 py-1.5 text-sm ${
                                        link.active
                                            ? "bg-slate-900 text-white"
                                            : "bg-white text-slate-600"
                                    } disabled:opacity-40`}
                                    dangerouslySetInnerHTML={{
                                        __html:
                                            link.label,
                                    }}
                                />
                            ),
                        )}
                    </div>
                </section>
            </div>

            <DeleteConfirmDialog
                open={
                    deleteAssignment !==
                    null
                }
                onOpenChange={(
                    open,
                ) => {
                    if (
                        !open &&
                        !deleting
                    ) {
                        setDeleteAssignment(
                            null,
                        );
                    }
                }}
                title="Delete shift assignment?"
                itemName={
                    deleteAssignment
                        ? `${deleteAssignment.employee.employee_number} — ${deleteAssignment.shift.name}`
                        : undefined
                }
                processing={
                    deleting
                }
                onConfirm={
                    confirmDelete
                }
            />
        </ERPLayout>
    );
}

function employeeName(
    employee: {
        first_name: string;

        middle_name?:
            | string
            | null;

        last_name: string;
    },
): string {
    return [
        employee.first_name,
        employee.middle_name,
        employee.last_name,
    ]
        .filter(Boolean)
        .join(" ");
}

function formatTime(
    value: string,
): string {
    return value.substring(
        0,
        5,
    );
}

function formatDate(
    value: string,
): string {
    return value.substring(
        0,
        10,
    );
}

function assignmentState(
    assignment: Assignment,
):
    | "current"
    | "upcoming"
    | "ended"
    | "inactive" {
    if (
        assignment.status ===
        "inactive"
    ) {
        return "inactive";
    }

    const today =
        new Date()
            .toISOString()
            .substring(
                0,
                10,
            );

    const from =
        formatDate(
            assignment.effective_from,
        );

    const to =
        assignment.effective_to
            ? formatDate(
                  assignment.effective_to,
              )
            : null;

    if (from > today) {
        return "upcoming";
    }

    if (
        to &&
        to < today
    ) {
        return "ended";
    }

    return "current";
}

function StateBadge({
    state,
}: {
    state:
        | "current"
        | "upcoming"
        | "ended"
        | "inactive";
}) {
    const classes = {
        current:
            "bg-emerald-50 text-emerald-700",

        upcoming:
            "bg-blue-50 text-blue-700",

        ended:
            "bg-slate-100 text-slate-600",

        inactive:
            "bg-red-50 text-red-700",
    };

    const labels = {
        current:
            "Current",

        upcoming:
            "Upcoming",

        ended:
            "Ended",

        inactive:
            "Inactive",
    };

    return (
        <span
            className={`rounded-full px-2.5 py-1 text-xs font-medium ${classes[state]}`}
        >
            {
                labels[state]
            }
        </span>
    );
}
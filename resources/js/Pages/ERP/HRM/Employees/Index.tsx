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
    Pencil,
    Plus,
    Search,
    Trash2,
    UsersRound,
    Eye,
} from "lucide-react";

import {
    FormEvent,
    useState,
} from "react";

type Company = {
    id: number;
    name: string;
    code: string;
};

type Branch = {
    id: number;
    company_id: number;
    name: string;
    code: string;
};

type Department = {
    id: number;
    company_id: number;
    branch_id:
        | number
        | null;
    name: string;
    code: string;
};

type Designation = {
    id: number;
    company_id: number;
    department_id:
        | number
        | null;
    name: string;
    code: string;
};

type Employee = {
    id: number;

    employee_number: string;

    first_name: string;

    middle_name:
        | string
        | null;

    last_name: string;

    work_email:
        | string
        | null;

    work_phone:
        | string
        | null;

    photo:
        | string
        | null;

    employment_type:
        string;

    employment_status:
        string;

    company: Company;

    branch:
        | Branch
        | null;

    department:
        | Department
        | null;

    designation:
        | Designation
        | null;
};

type PaginationLink = {
    url:
        | string
        | null;

    label: string;

    active: boolean;
};

type PaginatedEmployees = {
    data: Employee[];
    links: PaginationLink[];

    from:
        | number
        | null;

    to:
        | number
        | null;

    total: number;
};

type Filters = {
    search?: string;
    company_id?: string;
    employment_status?: string;
};

type Props = {
    employees:
        PaginatedEmployees;

    companies:
        Company[];

    filters: Filters;
};

export default function Index({
    employees,
    companies,
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
        companyId,
        setCompanyId,
    ] = useState(
        filters.company_id ??
            "",
    );

    const [
        employmentStatus,
        setEmploymentStatus,
    ] = useState(
        filters.employment_status ??
            "",
    );

    const [
        employeeToDelete,
        setEmployeeToDelete,
    ] =
        useState<Employee | null>(
            null,
        );

    const [
        deleting,
        setDeleting,
    ] = useState(false);

    const applyFilters = (
        event?: FormEvent,
    ) => {
        event?.preventDefault();

        router.get(
            "/erp/employees",
            {
                search:
                    search ||
                    undefined,

                company_id:
                    companyId ||
                    undefined,

                employment_status:
                    employmentStatus ||
                    undefined,
            },
            {
                preserveState:
                    true,

                preserveScroll:
                    true,

                replace:
                    true,
            },
        );
    };

    const resetFilters =
        () => {
            setSearch("");
            setCompanyId("");
            setEmploymentStatus(
                "",
            );

            router.get(
                "/erp/employees",
                {},
                {
                    preserveState:
                        true,

                    preserveScroll:
                        true,

                    replace:
                        true,
                },
            );
        };

    const deleteEmployee =
        () => {
            if (
                !employeeToDelete ||
                deleting
            ) {
                return;
            }

            setDeleting(true);

            router.delete(
                `/erp/employees/${employeeToDelete.id}`,
                {
                    preserveScroll:
                        true,

                    onSuccess:
                        () =>
                            setEmployeeToDelete(
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
            <Head title="Employees" />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div className="mb-2 flex items-center gap-2 text-sm text-slate-500">
                            <UsersRound className="size-4" />

                            HRM
                        </div>

                        <h1 className="text-2xl font-bold tracking-tight text-slate-900">
                            Employees
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Manage employee
                            master records
                            and organizational
                            assignments.
                        </p>
                    </div>

                    {can(
                        "erp.employees.create",
                    ) && (
                        <Link
                            href="/erp/employees/create"
                            className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800"
                        >
                            <Plus className="size-4" />

                            Add Employee
                        </Link>
                    )}
                </div>

                <div className="rounded-xl border bg-white p-4 shadow-sm">
                    <form
                        onSubmit={
                            applyFilters
                        }
                        className="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(260px,1fr)_240px_190px_auto]"
                    >
                        <div className="relative">
                            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />

                            <input
                                type="text"
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
                                placeholder="Search employee..."
                                className="min-h-10 w-full rounded-lg border border-slate-200 bg-white py-2 pl-9 pr-3 text-sm outline-none transition focus:border-slate-400 focus:ring-2 focus:ring-slate-200"
                            />
                        </div>

                        <select
                            value={
                                companyId
                            }
                            onChange={(
                                event,
                            ) =>
                                setCompanyId(
                                    event.target.value,
                                )
                            }
                            className={
                                filterClass
                            }
                        >
                            <option value="">
                                All Companies
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
                                employmentStatus
                            }
                            onChange={(
                                event,
                            ) =>
                                setEmploymentStatus(
                                    event.target.value,
                                )
                            }
                            className={
                                filterClass
                            }
                        >
                            <option value="">
                                All Statuses
                            </option>

                            <option value="active">
                                Active
                            </option>

                            <option value="probation">
                                Probation
                            </option>

                            <option value="inactive">
                                Inactive
                            </option>

                            <option value="resigned">
                                Resigned
                            </option>

                            <option value="terminated">
                                Terminated
                            </option>

                            <option value="retired">
                                Retired
                            </option>
                        </select>

                        <div className="flex gap-2">
                            <button
                                type="submit"
                                className="inline-flex min-h-10 flex-1 items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800"
                            >
                                Filter
                            </button>

                            <button
                                type="button"
                                onClick={
                                    resetFilters
                                }
                                className="inline-flex min-h-10 items-center justify-center rounded-lg border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                            >
                                Reset
                            </button>
                        </div>
                    </form>
                </div>

                <div className="overflow-hidden rounded-xl border bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[1100px]">
                            <thead className="border-b bg-slate-50">
                                <tr className="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    <th className="px-5 py-3">
                                        Employee
                                    </th>

                                    <th className="px-5 py-3">
                                        Employee ID
                                    </th>

                                    <th className="px-5 py-3">
                                        Company
                                    </th>

                                    <th className="px-5 py-3">
                                        Department
                                    </th>

                                    <th className="px-5 py-3">
                                        Designation
                                    </th>

                                    <th className="px-5 py-3">
                                        Status
                                    </th>

                                    <th className="px-5 py-3 text-right">
                                        Actions
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y">
                                {employees.data.length ===
                                0 ? (
                                    <tr>
                                        <td
                                            colSpan={
                                                7
                                            }
                                            className="px-5 py-14 text-center"
                                        >
                                            <UsersRound className="mx-auto size-9 text-slate-300" />

                                            <p className="mt-3 font-medium text-slate-700">
                                                No employees
                                                found
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    employees.data.map(
                                        (
                                            employee,
                                        ) => {
                                            const fullName =
                                                [
                                                    employee.first_name,
                                                    employee.middle_name,
                                                    employee.last_name,
                                                ]
                                                    .filter(
                                                        Boolean,
                                                    )
                                                    .join(
                                                        " ",
                                                    );

                                            return (
                                                <tr
                                                    key={
                                                        employee.id
                                                    }
                                                    className="transition hover:bg-slate-50/70"
                                                >
                                                    <td className="px-5 py-4">
                                                        <div className="flex items-center gap-3">
                                                            {employee.photo ? (
                                                                <img
                                                                    src={`/storage/${employee.photo}`}
                                                                    alt={
                                                                        fullName
                                                                    }
                                                                    className="size-10 rounded-full border object-cover"
                                                                />
                                                            ) : (
                                                                <div className="flex size-10 items-center justify-center rounded-full bg-slate-100 font-semibold text-slate-600">
                                                                    {
                                                                        employee
                                                                            .first_name[0]
                                                                    }
                                                                </div>
                                                            )}

                                                            <div>
                                                                <div className="font-medium text-slate-900">
                                                                    {
                                                                        fullName
                                                                    }
                                                                </div>

                                                                <div className="text-xs text-slate-500">
                                                                    {employee.work_email ??
                                                                        employee.work_phone ??
                                                                        "—"}
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>

                                                    <td className="px-5 py-4">
                                                        <span className="rounded-md bg-slate-100 px-2 py-1 font-mono text-xs font-semibold text-slate-700">
                                                            {
                                                                employee.employee_number
                                                            }
                                                        </span>
                                                    </td>

                                                    <td className="px-5 py-4 text-sm text-slate-700">
                                                        {
                                                            employee.company.name
                                                        }

                                                        {employee.branch && (
                                                            <div className="mt-1 text-xs text-slate-400">
                                                                {
                                                                    employee.branch.name
                                                                }
                                                            </div>
                                                        )}
                                                    </td>

                                                    <td className="px-5 py-4 text-sm text-slate-700">
                                                        {employee.department?.name ??
                                                            "—"}
                                                    </td>

                                                    <td className="px-5 py-4 text-sm text-slate-700">
                                                        {employee.designation?.name ??
                                                            "—"}
                                                    </td>

                                                    <td className="px-5 py-4">
                                                        <StatusBadge
                                                            status={
                                                                employee.employment_status
                                                            }
                                                        />
                                                    </td>

                                                    <td className="px-5 py-4">
                                                        <div className="flex justify-end gap-2">
                                                            {can(
                                                                "erp.employees.view",
                                                            ) && (
                                                                <Link
                                                                    href={`/erp/employees/${employee.id}`}
                                                                    title="View employee"
                                                                    className="inline-flex size-9 items-center justify-center rounded-lg border text-slate-500 transition hover:bg-slate-50 hover:text-slate-900"
                                                                >
                                                                    <Eye className="size-4" />
                                                                </Link>
                                                            )}
                                                            {can(
                                                                "erp.employees.edit",
                                                            ) && (
                                                                <Link
                                                                    href={`/erp/employees/${employee.id}/edit`}
                                                                    title="Edit employee"
                                                                    className="inline-flex size-9 items-center justify-center rounded-lg border text-slate-500 transition hover:bg-slate-50 hover:text-slate-900"
                                                                >
                                                                    <Pencil className="size-4" />
                                                                </Link>
                                                            )}

                                                            {can(
                                                                "erp.employees.delete",
                                                            ) && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() =>
                                                                        setEmployeeToDelete(
                                                                            employee,
                                                                        )
                                                                    }
                                                                    title="Delete employee"
                                                                    className="inline-flex size-9 items-center justify-center rounded-lg border text-slate-500 transition hover:border-red-200 hover:bg-red-50 hover:text-red-600"
                                                                >
                                                                    <Trash2 className="size-4" />
                                                                </button>
                                                            )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        },
                                    )
                                )}
                            </tbody>
                        </table>
                    </div>

                    {employees.total >
                        0 && (
                        <div className="flex flex-col gap-3 border-t px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <p className="text-sm text-slate-500">
                                Showing{" "}
                                {
                                    employees.from
                                }{" "}
                                to{" "}
                                {
                                    employees.to
                                }{" "}
                                of{" "}
                                {
                                    employees.total
                                }{" "}
                                employees
                            </p>

                            <div className="flex flex-wrap gap-1">
                                {employees.links.map(
                                    (
                                        link,
                                        index,
                                    ) => {
                                        const label =
                                            link.label
                                                .replace(
                                                    "&laquo;",
                                                    "«",
                                                )
                                                .replace(
                                                    "&raquo;",
                                                    "»",
                                                );

                                        if (
                                            !link.url
                                        ) {
                                            return (
                                                <span
                                                    key={
                                                        index
                                                    }
                                                    className="inline-flex min-h-9 min-w-9 items-center justify-center rounded-md border px-3 text-sm text-slate-300"
                                                >
                                                    {
                                                        label
                                                    }
                                                </span>
                                            );
                                        }

                                        return (
                                            <Link
                                                key={
                                                    index
                                                }
                                                href={
                                                    link.url
                                                }
                                                preserveScroll
                                                className={`inline-flex min-h-9 min-w-9 items-center justify-center rounded-md border px-3 text-sm ${
                                                    link.active
                                                        ? "border-slate-900 bg-slate-900 text-white"
                                                        : "bg-white text-slate-600 hover:bg-slate-50"
                                                }`}
                                            >
                                                {
                                                    label
                                                }
                                            </Link>
                                        );
                                    },
                                )}
                            </div>
                        </div>
                    )}
                </div>
            </div>

            <DeleteConfirmDialog
                open={
                    employeeToDelete !==
                    null
                }
                onOpenChange={(
                    open,
                ) => {
                    if (
                        !open &&
                        !deleting
                    ) {
                        setEmployeeToDelete(
                            null,
                        );
                    }
                }}
                onConfirm={
                    deleteEmployee
                }
                title="Delete employee?"
                itemName={
                    employeeToDelete
                        ? `${employeeToDelete.first_name} ${employeeToDelete.last_name}`
                        : undefined
                }
                processing={
                    deleting
                }
            />
        </ERPLayout>
    );
}

function StatusBadge({
    status,
}: {
    status: string;
}) {
    const styles: Record<
        string,
        string
    > = {
        active:
            "bg-emerald-50 text-emerald-700",

        probation:
            "bg-amber-50 text-amber-700",

        inactive:
            "bg-slate-100 text-slate-600",

        resigned:
            "bg-blue-50 text-blue-700",

        terminated:
            "bg-red-50 text-red-700",

        retired:
            "bg-purple-50 text-purple-700",
    };

    return (
        <span
            className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium capitalize ${
                styles[status] ??
                styles.inactive
            }`}
        >
            {status.replace(
                "_",
                " ",
            )}
        </span>
    );
}

const filterClass =
    "min-h-10 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 outline-none transition focus:border-slate-400 focus:ring-2 focus:ring-slate-200";
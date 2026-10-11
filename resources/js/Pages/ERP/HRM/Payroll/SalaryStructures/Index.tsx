import {
    Head,
    Link,
    router,
} from "@inertiajs/react";
import {
    Eye,
    FilePenLine,
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

type Employee = {
    id: number;

    company_id:
        number;

    employee_number:
        string;

    first_name:
        string;

    middle_name?:
        string | null;

    last_name?:
        string | null;

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

type SalaryStructure = {
    id: number;

    company_id:
        number;

    employee_id:
        number;

    effective_from:
        string;

    effective_to:
        string | null;

    status:
        | "active"
        | "inactive";

    currency:
        string;

    gross_salary:
        string | number;

    total_earnings:
        string | number;

    total_deductions:
        string | number;

    net_salary:
        string | number;

    company?:
        Company | null;

    employee?:
        Employee | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedStructures = {
    data:
        SalaryStructure[];

    links:
        PaginationLink[];

    total:
        number;
};

type Filters = {
    company_id?:
        string | number;

    employee_id?:
        string | number;

    status?:
        string;

    search?:
        string;
};

type Props = {
    structures:
        PaginatedStructures;

    companies:
        Company[];

    employees:
        Employee[];

    filters:
        Filters;
};

function employeeName(
    employee:
        Employee | null | undefined
): string {
    if (!employee) {
        return "—";
    }

    return [
        employee.first_name,
        employee.middle_name,
        employee.last_name,
    ]
        .filter(Boolean)
        .join(" ");
}

function money(
    value:
        | number
        | string,
    currency:
        string
): string {
    return `${Number(
        value ?? 0
    ).toLocaleString(
        undefined,
        {
            minimumFractionDigits:
                2,
            maximumFractionDigits:
                2,
        }
    )} ${currency}`;
}

export default function Index({
    structures,
    companies,
    employees,
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
        status,
        setStatus,
    ] = useState(
        filters.status ??
            ""
    );

    const [
        deletingStructure,
        setDeletingStructure,
    ] =
        useState<SalaryStructure | null>(
            null
        );

    const filteredEmployees =
        employees.filter(
            (employee) =>
                !companyId ||
                String(
                    employee.company_id
                ) === companyId
        );

    const applyFilters =
        () => {
            router.get(
                "/erp/payroll/salary-structures",
                {
                    search:
                        search ||
                        undefined,

                    company_id:
                        companyId ||
                        undefined,

                    employee_id:
                        employeeId ||
                        undefined,

                    status:
                        status ||
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
            setEmployeeId("");
            setStatus("");

            router.get(
                "/erp/payroll/salary-structures"
            );
        };

    const confirmDelete =
        () => {
            if (
                !deletingStructure
            ) {
                return;
            }

            router.delete(
                `/erp/payroll/salary-structures/${deletingStructure.id}`,
                {
                    preserveScroll:
                        true,

                    onSuccess:
                        () =>
                            setDeletingStructure(
                                null
                            ),
                }
            );
        };

    return (
        <ERPLayout>
            <Head title="Employee Salary Structures" />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Employee
                            Salary
                            Structures
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Manage
                            effective-dated
                            salary
                            structures for
                            employees.
                        </p>
                    </div>

                    {can(
                        "erp.salary-structures.create"
                    ) && (
                        <Link
                            href="/erp/payroll/salary-structures/create"
                            className="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-slate-900 px-4 text-sm font-medium text-white hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
                        >
                            <Plus className="h-4 w-4" />

                            New Salary
                            Structure
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
                                placeholder="Employee name or number..."
                                className="h-10 w-full rounded-md border border-slate-200 bg-white pl-9 pr-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                            />
                        </div>

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

                                setEmployeeId(
                                    ""
                                );
                            }}
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
                            <option value="">
                                All
                                companies
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
                                All
                                employees
                            </option>

                            {filteredEmployees.map(
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
                                All
                                statuses
                            </option>

                            <option value="active">
                                Active
                            </option>

                            <option value="inactive">
                                Inactive
                            </option>
                        </select>

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
                                        Employee
                                    </th>

                                    <th className="px-4 py-3">
                                        Company
                                    </th>

                                    <th className="px-4 py-3">
                                        Effective
                                        Period
                                    </th>

                                    <th className="px-4 py-3">
                                        Gross
                                    </th>

                                    <th className="px-4 py-3">
                                        Deductions
                                    </th>

                                    <th className="px-4 py-3">
                                        Net
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
                                {structures.data.map(
                                    (
                                        structure
                                    ) => (
                                        <tr
                                            key={
                                                structure.id
                                            }
                                        >
                                            <td className="px-4 py-4">
                                                <Link
                                                    href={`/erp/payroll/salary-structures/${structure.id}`}
                                                    className="font-medium hover:underline"
                                                >
                                                    {employeeName(
                                                        structure.employee
                                                    )}
                                                </Link>

                                                <div className="mt-1 text-xs text-slate-500">
                                                    {structure
                                                        .employee
                                                        ?.employee_number ??
                                                        "—"}
                                                </div>

                                                {structure
                                                    .employee
                                                    ?.designation
                                                    ?.name && (
                                                    <div className="mt-1 text-xs text-slate-400">
                                                        {
                                                            structure
                                                                .employee
                                                                .designation
                                                                .name
                                                        }
                                                    </div>
                                                )}
                                            </td>

                                            <td className="px-4 py-4">
                                                {structure
                                                    .company
                                                    ?.name ??
                                                    "—"}
                                            </td>

                                            <td className="px-4 py-4">
                                                <div>
                                                    {
                                                        structure.effective_from
                                                    }
                                                </div>

                                                <div className="mt-1 text-xs text-slate-500">
                                                    to{" "}
                                                    {structure.effective_to ??
                                                        "Current"}
                                                </div>
                                            </td>

                                            <td className="px-4 py-4 font-medium">
                                                {money(
                                                    structure.gross_salary,
                                                    structure.currency
                                                )}
                                            </td>

                                            <td className="px-4 py-4 text-red-600 dark:text-red-400">
                                                {money(
                                                    structure.total_deductions,
                                                    structure.currency
                                                )}
                                            </td>

                                            <td className="px-4 py-4 font-semibold">
                                                {money(
                                                    structure.net_salary,
                                                    structure.currency
                                                )}
                                            </td>

                                            <td className="px-4 py-4">
                                                <span
                                                    className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${
                                                        structure.status ===
                                                        "active"
                                                            ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400"
                                                            : "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400"
                                                    }`}
                                                >
                                                    {structure.status ===
                                                    "active"
                                                        ? "Active"
                                                        : "Inactive"}
                                                </span>
                                            </td>

                                            <td className="px-4 py-4">
                                                <div className="flex justify-end gap-2">
                                                    {can(
                                                        "erp.salary-structures.view"
                                                    ) && (
                                                        <Link
                                                            href={`/erp/payroll/salary-structures/${structure.id}`}
                                                            className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                                                            title="View"
                                                        >
                                                            <Eye className="h-4 w-4" />
                                                        </Link>
                                                    )}

                                                    {can(
                                                        "erp.salary-structures.edit"
                                                    ) && (
                                                        <Link
                                                            href={`/erp/payroll/salary-structures/${structure.id}/edit`}
                                                            className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                                                            title="Edit"
                                                        >
                                                            <FilePenLine className="h-4 w-4" />
                                                        </Link>
                                                    )}

                                                    {can(
                                                        "erp.salary-structures.delete"
                                                    ) && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setDeletingStructure(
                                                                    structure
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

                                {structures
                                    .data
                                    .length ===
                                    0 && (
                                    <tr>
                                        <td
                                            colSpan={
                                                8
                                            }
                                            className="px-4 py-16 text-center text-slate-500"
                                        >
                                            No
                                            salary
                                            structures
                                            found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {structures.links
                        .length >
                        3 && (
                        <div className="flex flex-wrap gap-1 border-t border-slate-200 p-4 dark:border-slate-800">
                            {structures.links.map(
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

            {deletingStructure && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-slate-950">
                        <div className="flex h-12 w-12 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-950/50 dark:text-red-400">
                            <Trash2 className="h-5 w-5" />
                        </div>

                        <h2 className="mt-4 text-lg font-semibold">
                            Delete
                            Salary
                            Structure
                        </h2>

                        <p className="mt-2 text-sm leading-6 text-slate-500">
                            Delete the
                            salary
                            structure for{" "}
                            <span className="font-medium text-slate-900 dark:text-white">
                                {employeeName(
                                    deletingStructure.employee
                                )}
                            </span>
                            ? This salary
                            history will be
                            removed.
                        </p>

                        <div className="mt-6 flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={() =>
                                    setDeletingStructure(
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
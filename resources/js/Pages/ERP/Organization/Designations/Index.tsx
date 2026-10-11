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
    BadgeCheck,
    Building2,
    Network,
    Pencil,
    Plus,
    Search,
    Trash2,
} from "lucide-react";

import {
    FormEvent,
    useMemo,
    useState,
} from "react";

type Company = {
    id: number;
    name: string;
    code: string;
};

type Branch = {
    id: number;
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

    branch?:
        | Branch
        | null;
};

type Designation = {
    id: number;
    company_id: number;

    department_id:
        | number
        | null;

    name: string;
    code: string;

    description:
        | string
        | null;

    sort_order: number;

    status: string;

    company: Company;

    department:
        | Department
        | null;
};

type PaginationLink = {
    url:
        | string
        | null;

    label: string;

    active: boolean;
};

type PaginatedDesignations = {
    data: Designation[];

    links:
        PaginationLink[];

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

    department_id?: string;

    status?: string;
};

type Props = {
    designations:
        PaginatedDesignations;

    companies: Company[];

    departments:
        Department[];

    filters: Filters;
};

export default function Index({
    designations,
    companies,
    departments,
    filters,
}: Props) {
    const {
        can,
    } = useAuthorization();

    const [
        search,
        setSearch,
    ] = useState(
        filters.search ?? "",
    );

    const [
        companyId,
        setCompanyId,
    ] = useState(
        filters.company_id ??
            "",
    );

    const [
        departmentId,
        setDepartmentId,
    ] = useState(
        filters.department_id ??
            "",
    );

    const [
        status,
        setStatus,
    ] = useState(
        filters.status ?? "",
    );

    const [
        designationToDelete,
        setDesignationToDelete,
    ] =
        useState<Designation | null>(
            null,
        );

    const [
        deleting,
        setDeleting,
    ] = useState(false);

    const availableDepartments =
        useMemo(() => {
            if (! companyId) {
                return departments;
            }

            return departments.filter(
                (department) =>
                    String(
                        department.company_id,
                    ) ===
                    String(
                        companyId,
                    ),
            );
        }, [
            departments,
            companyId,
        ]);

    const applyFilters = (
        event?: FormEvent,
    ) => {
        event?.preventDefault();

        router.get(
            "/erp/designations",
            {
                search:
                    search ||
                    undefined,

                company_id:
                    companyId ||
                    undefined,

                department_id:
                    departmentId ||
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
            },
        );
    };

    const resetFilters =
        () => {
            setSearch("");

            setCompanyId("");

            setDepartmentId("");

            setStatus("");

            router.get(
                "/erp/designations",
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

    const handleCompanyChange = (
        value: string,
    ) => {
        setCompanyId(
            value,
        );

        setDepartmentId("");
    };

    const deleteDesignation =
        () => {
            if (
                ! designationToDelete ||
                deleting
            ) {
                return;
            }

            setDeleting(true);

            router.delete(
                `/erp/designations/${designationToDelete.id}`,
                {
                    preserveScroll:
                        true,

                    onSuccess:
                        () => {
                            setDesignationToDelete(
                                null,
                            );
                        },

                    onFinish:
                        () => {
                            setDeleting(
                                false,
                            );
                        },
                },
            );
        };

    return (
        <ERPLayout>
            <Head title="Designations" />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div className="mb-2 flex items-center gap-2 text-sm text-slate-500">
                            <BadgeCheck className="size-4" />

                            Organization
                        </div>

                        <h1 className="text-2xl font-bold tracking-tight text-slate-900">
                            Designations
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Manage employee
                            positions and
                            organizational
                            titles.
                        </p>
                    </div>

                    {can(
                        "erp.designations.create",
                    ) && (
                        <Link
                            href="/erp/designations/create"
                            className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800"
                        >
                            <Plus className="size-4" />

                            Add Designation
                        </Link>
                    )}
                </div>

                <div className="rounded-xl border bg-white p-4 shadow-sm">
                    <form
                        onSubmit={
                            applyFilters
                        }
                        className="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(220px,1fr)_220px_220px_170px_auto]"
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
                                        event
                                            .target
                                            .value,
                                    )
                                }
                                placeholder="Search designations..."
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
                                handleCompanyChange(
                                    event
                                        .target
                                        .value,
                                )
                            }
                            className={
                                filterClass
                            }
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
                                departmentId
                            }
                            onChange={(
                                event,
                            ) =>
                                setDepartmentId(
                                    event
                                        .target
                                        .value,
                                )
                            }
                            className={
                                filterClass
                            }
                        >
                            <option value="">
                                All departments
                            </option>

                            {availableDepartments.map(
                                (
                                    department,
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
                                ),
                            )}
                        </select>

                        <select
                            value={
                                status
                            }
                            onChange={(
                                event,
                            ) =>
                                setStatus(
                                    event
                                        .target
                                        .value,
                                )
                            }
                            className={
                                filterClass
                            }
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
                        <table className="w-full min-w-[1050px]">
                            <thead className="border-b bg-slate-50">
                                <tr className="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    <th className="px-5 py-3">
                                        Designation
                                    </th>

                                    <th className="px-5 py-3">
                                        Company
                                    </th>

                                    <th className="px-5 py-3">
                                        Department
                                    </th>

                                    <th className="px-5 py-3">
                                        Code
                                    </th>

                                    <th className="px-5 py-3">
                                        Order
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
                                {designations
                                    .data
                                    .length ===
                                0 ? (
                                    <tr>
                                        <td
                                            colSpan={
                                                7
                                            }
                                            className="px-5 py-14 text-center"
                                        >
                                            <BadgeCheck className="mx-auto size-8 text-slate-300" />

                                            <p className="mt-3 font-medium text-slate-700">
                                                No
                                                designations
                                                found
                                            </p>

                                            <p className="mt-1 text-sm text-slate-500">
                                                Create a
                                                designation
                                                or change
                                                your
                                                filters.
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    designations.data.map(
                                        (
                                            designation,
                                        ) => (
                                            <tr
                                                key={
                                                    designation.id
                                                }
                                                className="transition hover:bg-slate-50/70"
                                            >
                                                <td className="px-5 py-4">
                                                    <div className="font-medium text-slate-900">
                                                        {
                                                            designation.name
                                                        }
                                                    </div>

                                                    {designation.description && (
                                                        <div className="mt-1 max-w-xs truncate text-xs text-slate-500">
                                                            {
                                                                designation.description
                                                            }
                                                        </div>
                                                    )}
                                                </td>

                                                <td className="px-5 py-4">
                                                    <div className="flex items-center gap-2">
                                                        <Building2 className="size-4 text-slate-400" />

                                                        <div>
                                                            <div className="text-sm font-medium text-slate-700">
                                                                {
                                                                    designation
                                                                        .company
                                                                        .name
                                                                }
                                                            </div>

                                                            <div className="text-xs text-slate-400">
                                                                {
                                                                    designation
                                                                        .company
                                                                        .code
                                                                }
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>

                                                <td className="px-5 py-4">
                                                    {designation.department ? (
                                                        <div className="flex items-center gap-2">
                                                            <Network className="size-4 shrink-0 text-slate-400" />

                                                            <div>
                                                                <div className="text-sm text-slate-700">
                                                                    {
                                                                        designation
                                                                            .department
                                                                            .name
                                                                    }
                                                                </div>

                                                                <div className="text-xs text-slate-400">
                                                                    {
                                                                        designation
                                                                            .department
                                                                            .code
                                                                    }
                                                                </div>
                                                            </div>
                                                        </div>
                                                    ) : (
                                                        <span className="inline-flex rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700">
                                                            Company-wide
                                                        </span>
                                                    )}
                                                </td>

                                                <td className="px-5 py-4">
                                                    <span className="rounded-md bg-slate-100 px-2 py-1 font-mono text-xs font-semibold text-slate-700">
                                                        {
                                                            designation.code
                                                        }
                                                    </span>
                                                </td>

                                                <td className="px-5 py-4 text-sm text-slate-600">
                                                    {
                                                        designation.sort_order
                                                    }
                                                </td>

                                                <td className="px-5 py-4">
                                                    <StatusBadge
                                                        status={
                                                            designation.status
                                                        }
                                                    />
                                                </td>

                                                <td className="px-5 py-4">
                                                    <div className="flex justify-end gap-2">
                                                        {can(
                                                            "erp.designations.edit",
                                                        ) && (
                                                            <Link
                                                                href={`/erp/designations/${designation.id}/edit`}
                                                                title="Edit designation"
                                                                className="inline-flex size-9 items-center justify-center rounded-lg border text-slate-500 transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900"
                                                            >
                                                                <Pencil className="size-4" />
                                                            </Link>
                                                        )}

                                                        {can(
                                                            "erp.designations.delete",
                                                        ) && (
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    setDesignationToDelete(
                                                                        designation,
                                                                    )
                                                                }
                                                                title="Delete designation"
                                                                className="inline-flex size-9 items-center justify-center rounded-lg border text-slate-500 transition hover:border-red-200 hover:bg-red-50 hover:text-red-600"
                                                            >
                                                                <Trash2 className="size-4" />
                                                            </button>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ),
                                    )
                                )}
                            </tbody>
                        </table>
                    </div>

                    {designations.total >
                        0 && (
                        <div className="flex flex-col gap-3 border-t px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <p className="text-sm text-slate-500">
                                Showing{" "}
                                {
                                    designations.from
                                }{" "}
                                to{" "}
                                {
                                    designations.to
                                }{" "}
                                of{" "}
                                {
                                    designations.total
                                }{" "}
                                designations
                            </p>

                            <div className="flex flex-wrap gap-1">
                                {designations.links.map(
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
                                            ! link.url
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
                                                className={`inline-flex min-h-9 min-w-9 items-center justify-center rounded-md border px-3 text-sm transition ${
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
                    designationToDelete !==
                    null
                }
                onOpenChange={(
                    open,
                ) => {
                    if (
                        ! open &&
                        ! deleting
                    ) {
                        setDesignationToDelete(
                            null,
                        );
                    }
                }}
                onConfirm={
                    deleteDesignation
                }
                title="Delete designation?"
                itemName={
                    designationToDelete?.name
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
    if (
        status === "active"
    ) {
        return (
            <span className="inline-flex rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium capitalize text-emerald-700">
                {status}
            </span>
        );
    }

    return (
        <span className="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium capitalize text-slate-600">
            {status}
        </span>
    );
}

const filterClass =
    "min-h-10 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 outline-none transition focus:border-slate-400 focus:ring-2 focus:ring-slate-200";
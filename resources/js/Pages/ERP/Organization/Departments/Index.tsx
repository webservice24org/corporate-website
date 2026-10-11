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
    Building2,
    MapPin,
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
    description:
        | string
        | null;
    status: string;

    company: Company;

    branch:
        | Branch
        | null;
};

type PaginationLink = {
    url:
        | string
        | null;
    label: string;
    active: boolean;
};

type PaginatedDepartments = {
    data: Department[];
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
    branch_id?: string;
    status?: string;
};

type Props = {
    departments:
        PaginatedDepartments;

    companies: Company[];

    branches: Branch[];

    filters: Filters;
};

export default function Index({
    departments,
    companies,
    branches,
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
        branchId,
        setBranchId,
    ] = useState(
        filters.branch_id ??
            "",
    );

    const [
        status,
        setStatus,
    ] = useState(
        filters.status ?? "",
    );

    const [
        departmentToDelete,
        setDepartmentToDelete,
    ] =
        useState<Department | null>(
            null,
        );

    const [
        deleting,
        setDeleting,
    ] = useState(false);

    const availableBranches =
        useMemo(() => {
            if (! companyId) {
                return branches;
            }

            return branches.filter(
                (branch) =>
                    String(
                        branch.company_id,
                    ) ===
                    String(
                        companyId,
                    ),
            );
        }, [
            branches,
            companyId,
        ]);

    const applyFilters = (
        event?: FormEvent,
    ) => {
        event?.preventDefault();

        router.get(
            "/erp/departments",
            {
                search:
                    search ||
                    undefined,

                company_id:
                    companyId ||
                    undefined,

                branch_id:
                    branchId ||
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
                replace: true,
            },
        );
    };

    const resetFilters =
        () => {
            setSearch("");
            setCompanyId("");
            setBranchId("");
            setStatus("");

            router.get(
                "/erp/departments",
                {},
                {
                    preserveState:
                        true,
                    preserveScroll:
                        true,
                    replace: true,
                },
            );
        };

    const handleCompanyChange = (
        value: string,
    ) => {
        setCompanyId(
            value,
        );

        /*
         * Clear branch if
         * company filter changes.
         */
        setBranchId("");
    };

    const deleteDepartment =
        () => {
            if (
                ! departmentToDelete ||
                deleting
            ) {
                return;
            }

            setDeleting(true);

            router.delete(
                `/erp/departments/${departmentToDelete.id}`,
                {
                    preserveScroll:
                        true,

                    onSuccess:
                        () => {
                            setDepartmentToDelete(
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
            <Head title="Departments" />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div className="mb-2 flex items-center gap-2 text-sm text-slate-500">
                            <Building2 className="size-4" />

                            Organization
                        </div>

                        <h1 className="text-2xl font-bold tracking-tight text-slate-900">
                            Departments
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Manage
                            company-wide
                            and
                            branch-specific
                            departments.
                        </p>
                    </div>

                    {can(
                        "erp.departments.create",
                    ) && (
                        <Link
                            href="/erp/departments/create"
                            className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800"
                        >
                            <Plus className="size-4" />

                            Add Department
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
                                placeholder="Search departments..."
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
                            className={filterClass}
                        >
                            <option value="">
                                All
                                companies
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
                                branchId
                            }
                            onChange={(
                                event,
                            ) =>
                                setBranchId(
                                    event
                                        .target
                                        .value,
                                )
                            }
                            className={filterClass}
                        >
                            <option value="">
                                All
                                branches
                            </option>

                            {availableBranches.map(
                                (
                                    branch,
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
                            className={filterClass}
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
                        <table className="w-full min-w-[1000px]">
                            <thead className="border-b bg-slate-50">
                                <tr className="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    <th className="px-5 py-3">
                                        Department
                                    </th>

                                    <th className="px-5 py-3">
                                        Company
                                    </th>

                                    <th className="px-5 py-3">
                                        Branch
                                    </th>

                                    <th className="px-5 py-3">
                                        Code
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
                                {departments
                                    .data
                                    .length ===
                                0 ? (
                                    <tr>
                                        <td
                                            colSpan={
                                                6
                                            }
                                            className="px-5 py-14 text-center"
                                        >
                                            <Building2 className="mx-auto size-8 text-slate-300" />

                                            <p className="mt-3 font-medium text-slate-700">
                                                No
                                                departments
                                                found
                                            </p>

                                            <p className="mt-1 text-sm text-slate-500">
                                                Create
                                                the
                                                first
                                                department
                                                or
                                                change
                                                your
                                                filters.
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    departments.data.map(
                                        (
                                            department,
                                        ) => (
                                            <tr
                                                key={
                                                    department.id
                                                }
                                                className="transition hover:bg-slate-50/70"
                                            >
                                                <td className="px-5 py-4">
                                                    <div className="font-medium text-slate-900">
                                                        {
                                                            department.name
                                                        }
                                                    </div>

                                                    {department.description && (
                                                        <div className="mt-1 max-w-xs truncate text-xs text-slate-500">
                                                            {
                                                                department.description
                                                            }
                                                        </div>
                                                    )}
                                                </td>

                                                <td className="px-5 py-4">
                                                    <div className="text-sm font-medium text-slate-700">
                                                        {
                                                            department
                                                                .company
                                                                .name
                                                        }
                                                    </div>

                                                    <div className="text-xs text-slate-400">
                                                        {
                                                            department
                                                                .company
                                                                .code
                                                        }
                                                    </div>
                                                </td>

                                                <td className="px-5 py-4">
                                                    {department.branch ? (
                                                        <div className="flex items-center gap-2">
                                                            <MapPin className="size-4 shrink-0 text-slate-400" />

                                                            <div>
                                                                <div className="text-sm text-slate-700">
                                                                    {
                                                                        department
                                                                            .branch
                                                                            .name
                                                                    }
                                                                </div>

                                                                <div className="text-xs text-slate-400">
                                                                    {
                                                                        department
                                                                            .branch
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
                                                            department.code
                                                        }
                                                    </span>
                                                </td>

                                                <td className="px-5 py-4">
                                                    <StatusBadge
                                                        status={
                                                            department.status
                                                        }
                                                    />
                                                </td>

                                                <td className="px-5 py-4">
                                                    <div className="flex justify-end gap-2">
                                                        {can(
                                                            "erp.departments.edit",
                                                        ) && (
                                                            <Link
                                                                href={`/erp/departments/${department.id}/edit`}
                                                                title="Edit department"
                                                                className="inline-flex size-9 items-center justify-center rounded-lg border text-slate-500 transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900"
                                                            >
                                                                <Pencil className="size-4" />
                                                            </Link>
                                                        )}

                                                        {can(
                                                            "erp.departments.delete",
                                                        ) && (
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    setDepartmentToDelete(
                                                                        department,
                                                                    )
                                                                }
                                                                title="Delete department"
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

                    {departments.total >
                        0 && (
                        <div className="flex flex-col gap-3 border-t px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <p className="text-sm text-slate-500">
                                Showing{" "}
                                {
                                    departments.from
                                }{" "}
                                to{" "}
                                {
                                    departments.to
                                }{" "}
                                of{" "}
                                {
                                    departments.total
                                }{" "}
                                departments
                            </p>

                            <div className="flex flex-wrap gap-1">
                                {departments.links.map(
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
                    departmentToDelete !==
                    null
                }
                onOpenChange={(
                    open,
                ) => {
                    if (
                        ! open &&
                        ! deleting
                    ) {
                        setDepartmentToDelete(
                            null,
                        );
                    }
                }}
                onConfirm={
                    deleteDepartment
                }
                title="Delete department?"
                itemName={
                    departmentToDelete?.name
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
import {
    Head,
    Link,
    router,
} from "@inertiajs/react";

import {
    Edit3,
    MapPin,
    Plus,
    Search,
    Star,
    Trash2,
} from "lucide-react";

import {
    useState,
} from "react";

import ERPLayout from "@/Layouts/erp-layout";

import {
    useAuthorization,
} from "@/hooks/useAuthorization";

import DeleteConfirmDialog from "@/Components/Common/DeleteConfirmDialog";

type Company = {
    id: number;
    name: string;
    code: string;
};

type Branch = {
    id: number;
    name: string;
    code: string;
    email: string | null;
    phone: string | null;
    city: string | null;
    country: string | null;
    timezone: string;
    status: string;
    is_default: boolean;
    company: Company;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type BranchPagination = {
    data: Branch[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
};

export default function Index({
    branches,
    companies,
    filters,
}: {
    branches: BranchPagination;

    companies: Company[];

    filters: {
        search?: string;
        company_id?: string | null;
        status?: string | null;
    };
}) {
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
        filters.company_id ?? "",
    );

    const [
        status,
        setStatus,
    ] = useState(
        filters.status ?? "",
    );

    const submitFilters = () => {
        router.get(
            "/erp/branches",
            {
                search,
                company_id:
                    companyId,
                status,
            },
            {
                preserveState: true,
                replace: true,
            },
        );
    };
    const [
        branchToDelete,
        setBranchToDelete,
    ] = useState<Branch | null>(
        null,
    );

    const [
        deleting,
        setDeleting,
    ] = useState(false);

    const deleteBranch = () => {
        if (
            ! branchToDelete ||
            deleting
        ) {
            return;
        }

        setDeleting(true);

        router.delete(
            `/erp/branches/${branchToDelete.id}`,
            {
                preserveScroll: true,

                onSuccess: () => {
                    setBranchToDelete(
                        null,
                    );
                },

                onFinish: () => {
                    setDeleting(
                        false,
                    );
                },
            },
        );
    };

    return (
        <ERPLayout>
            <Head title="Branches" />

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div className="flex items-center gap-2 text-sm font-medium text-indigo-600">
                        <MapPin className="size-4" />
                        Organization
                    </div>

                    <h2 className="mt-2 text-2xl font-bold text-slate-950">
                        Branches
                    </h2>

                    <p className="mt-1 text-sm text-slate-500">
                        Manage company branches and
                        operational locations.
                    </p>
                </div>

                {can(
                    "erp.branches.create",
                ) && (
                    <Link
                        href="/erp/branches/create"
                        className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700"
                    >
                        <Plus className="size-4" />

                        Add Branch
                    </Link>
                )}
            </div>

            <div className="mb-6 rounded-xl border bg-white p-4 shadow-sm">
                <div className="grid gap-3 md:grid-cols-4">
                    <div className="relative md:col-span-2">
                        <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />

                        <input
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
                            onKeyDown={(
                                event,
                            ) => {
                                if (
                                    event.key ===
                                    "Enter"
                                ) {
                                    submitFilters();
                                }
                            }}
                            placeholder="Search branch name, code, email..."
                            className="w-full rounded-lg border border-slate-300 py-2.5 pl-10 pr-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
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
                                event.target
                                    .value,
                            )
                        }
                        className="rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-indigo-500"
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
                            status
                        }
                        onChange={(
                            event,
                        ) =>
                            setStatus(
                                event.target
                                    .value,
                            )
                        }
                        className="rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-indigo-500"
                    >
                        <option value="">
                            All Statuses
                        </option>

                        <option value="active">
                            Active
                        </option>

                        <option value="inactive">
                            Inactive
                        </option>
                    </select>
                </div>

                <div className="mt-3 flex justify-end">
                    <button
                        type="button"
                        onClick={
                            submitFilters
                        }
                        className="rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800"
                    >
                        Filter
                    </button>
                </div>
            </div>

            <div className="overflow-hidden rounded-xl border bg-white shadow-sm">
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-slate-200">
                        <thead className="bg-slate-50">
                            <tr>
                                <Heading>
                                    Branch
                                </Heading>

                                <Heading>
                                    Company
                                </Heading>

                                <Heading>
                                    Code
                                </Heading>

                                <Heading>
                                    Contact
                                </Heading>

                                <Heading>
                                    Location
                                </Heading>

                                <Heading>
                                    Status
                                </Heading>

                                <Heading>
                                    Default
                                </Heading>

                                <Heading right>
                                    Actions
                                </Heading>
                            </tr>
                        </thead>

                        <tbody className="divide-y divide-slate-100">
                            {branches.data.map(
                                (
                                    branch,
                                ) => (
                                    <tr
                                        key={
                                            branch.id
                                        }
                                        className="hover:bg-slate-50/70"
                                    >
                                        <td className="px-5 py-4">
                                            <div className="font-semibold text-slate-900">
                                                {
                                                    branch.name
                                                }
                                            </div>
                                        </td>

                                        <td className="px-5 py-4">
                                            <div className="text-sm font-medium text-slate-700">
                                                {
                                                    branch.company
                                                        .name
                                                }
                                            </div>

                                            <div className="text-xs text-slate-500">
                                                {
                                                    branch.company
                                                        .code
                                                }
                                            </div>
                                        </td>

                                        <td className="px-5 py-4 text-sm font-medium text-slate-600">
                                            {
                                                branch.code
                                            }
                                        </td>

                                        <td className="px-5 py-4">
                                            <div className="text-sm text-slate-700">
                                                {branch.email ??
                                                    "—"}
                                            </div>

                                            <div className="mt-0.5 text-xs text-slate-500">
                                                {branch.phone ??
                                                    "—"}
                                            </div>
                                        </td>

                                        <td className="px-5 py-4">
                                            <div className="text-sm text-slate-700">
                                                {branch.city ??
                                                    "—"}
                                            </div>

                                            <div className="text-xs text-slate-500">
                                                {branch.country ??
                                                    "—"}
                                            </div>
                                        </td>

                                        <td className="px-5 py-4">
                                            <StatusBadge
                                                status={
                                                    branch.status
                                                }
                                            />
                                        </td>

                                        <td className="px-5 py-4">
                                            {branch.is_default ? (
                                                <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700">
                                                    <Star className="size-3 fill-current" />

                                                    Default
                                                </span>
                                            ) : (
                                                <span className="text-sm text-slate-400">
                                                    —
                                                </span>
                                            )}
                                        </td>

                                        <td className="px-5 py-4">
                                            <div className="flex items-center justify-end gap-2">
                                                {can(
                                                    "erp.branches.edit",
                                                ) && (
                                                    <Link
                                                        href={`/erp/branches/${branch.id}/edit`}
                                                        className="inline-flex size-9 items-center justify-center rounded-lg border text-slate-600 transition hover:bg-slate-50 hover:text-indigo-600"
                                                    >
                                                        <Edit3 className="size-4" />
                                                    </Link>
                                                )}

                                                {can(
                                                    "erp.branches.delete",
                                                ) && (
                                                    <button
                                                        type="button"
                                                        disabled={
                                                            branch.is_default
                                                        }
                                                        onClick={() =>
                                                            setBranchToDelete(
                                                                branch,
                                                            )
                                                        }
                                                        title={
                                                            branch.is_default
                                                                ? "Default branch cannot be deleted"
                                                                : "Delete branch"
                                                        }
                                                        className="
                                                            inline-flex size-9
                                                            items-center justify-center
                                                            rounded-lg border
                                                            text-slate-500
                                                            transition
                                                            hover:border-red-200
                                                            hover:bg-red-50
                                                            hover:text-red-600
                                                            disabled:cursor-not-allowed
                                                            disabled:opacity-30
                                                        "
                                                    >
                                                        <Trash2 className="size-4" />
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ),
                            )}

                            {branches.data.length ===
                                0 && (
                                <tr>
                                    <td
                                        colSpan={8}
                                        className="px-5 py-16 text-center"
                                    >
                                        <MapPin className="mx-auto size-10 text-slate-300" />

                                        <h3 className="mt-3 font-semibold text-slate-700">
                                            No branches
                                            found
                                        </h3>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {branches.last_page >
                    1 && (
                    <div className="flex flex-wrap items-center justify-between gap-4 border-t px-5 py-4">
                        <p className="text-sm text-slate-500">
                            Showing{" "}
                            {branches.from ??
                                0}{" "}
                            to{" "}
                            {branches.to ??
                                0}{" "}
                            of{" "}
                            {
                                branches.total
                            }
                        </p>

                        <div className="flex flex-wrap gap-1">
                            {branches.links.map(
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
                                            ! link.url
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
                                        className={`
                                            min-w-9 rounded-lg border
                                            px-3 py-1.5 text-sm
                                            ${
                                                link.active
                                                    ? "border-indigo-600 bg-indigo-600 text-white"
                                                    : "bg-white text-slate-600 hover:bg-slate-50"
                                            }
                                            disabled:opacity-40
                                        `}
                                        dangerouslySetInnerHTML={{
                                            __html:
                                                link.label,
                                        }}
                                    />
                                ),
                            )}
                        </div>
                    </div>
                )}
            </div>
            <DeleteConfirmDialog
                open={
                    branchToDelete !==
                    null
                }
                onOpenChange={(
                    open,
                ) => {
                    if (
                        ! open &&
                        ! deleting
                    ) {
                        setBranchToDelete(
                            null,
                        );
                    }
                }}
                onConfirm={
                    deleteBranch
                }
                title="Delete branch?"
                itemName={
                    branchToDelete?.name
                }
                processing={
                    deleting
                }
            />
        </ERPLayout>
    );
}

function Heading({
    children,
    right = false,
}: {
    children: React.ReactNode;
    right?: boolean;
}) {
    return (
        <th
            className={`px-5 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500 ${
                right
                    ? "text-right"
                    : "text-left"
            }`}
        >
            {children}
        </th>
    );
}

function StatusBadge({
    status,
}: {
    status: string;
}) {
    const active =
        status === "active";

    return (
        <span
            className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${
                active
                    ? "bg-emerald-50 text-emerald-700"
                    : "bg-slate-100 text-slate-600"
            }`}
        >
            {active
                ? "Active"
                : "Inactive"}
        </span>
    );
}
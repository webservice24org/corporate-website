import {
    Head,
    Link,
    router,
} from "@inertiajs/react";

import {
    Building2,
    Edit3,
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
    legal_name: string | null;
    code: string;
    email: string | null;
    phone: string | null;
    country: string | null;
    currency_code: string;
    timezone: string;
    status: string;
    is_default: boolean;
};


type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};


type CompaniesPagination = {
    data: Company[];

    links: PaginationLink[];

    current_page: number;

    last_page: number;

    from: number | null;

    to: number | null;

    total: number;
};


export default function Index({
    companies,
    filters,
}: {
    companies: CompaniesPagination;

    filters: {
        search?: string;
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
        status,
        setStatus,
    ] = useState(
        filters.status ?? "",
    );

    const submitFilters = () => {
        router.get(
            "/erp/companies",
            {
                search,
                status,
            },
            {
                preserveState: true,
                replace: true,
            },
        );
    };

    const [
        companyToDelete,
        setCompanyToDelete,
    ] = useState<Company | null>(
        null,
    );

    const [
        deleting,
        setDeleting,
    ] = useState(false);

    const deleteCompany = () => {
        if (
            ! companyToDelete ||
            deleting
        ) {
            return;
        }

        setDeleting(true);

        router.delete(
            `/erp/companies/${companyToDelete.id}`,
            {
                preserveScroll: true,

                onSuccess: () => {
                    setCompanyToDelete(
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
            <Head title="Companies" />

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div className="flex items-center gap-2 text-sm font-medium text-indigo-600">
                        <Building2 className="size-4" />

                        Organization
                    </div>

                    <h2 className="mt-2 text-2xl font-bold text-slate-950">
                        Companies
                    </h2>

                    <p className="mt-1 text-sm text-slate-500">
                        Manage organizations operating
                        inside the ERP system.
                    </p>
                </div>

                {can(
                    "erp.companies.create",
                ) && (
                    <Link
                        href="/erp/companies/create"
                        className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700"
                    >
                        <Plus className="size-4" />

                        Add Company
                    </Link>
                )}
            </div>

            {/* Filters */}
            <div className="mb-6 rounded-xl border bg-white p-4 shadow-sm">
                <div className="flex flex-col gap-3 md:flex-row">
                    <div className="relative flex-1">
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
                            placeholder="Search company name, code, email..."
                            className="w-full rounded-lg border border-slate-300 py-2.5 pl-10 pr-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                        />
                    </div>

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

            {/* Companies table */}
            <div className="overflow-hidden rounded-xl border bg-white shadow-sm">
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-slate-200">
                        <thead className="bg-slate-50">
                            <tr>
                                <TableHeading>
                                    Company
                                </TableHeading>

                                <TableHeading>
                                    Code
                                </TableHeading>

                                <TableHeading>
                                    Contact
                                </TableHeading>

                                <TableHeading>
                                    Currency
                                </TableHeading>

                                <TableHeading>
                                    Status
                                </TableHeading>

                                <TableHeading>
                                    Default
                                </TableHeading>

                                <TableHeading align="right">
                                    Actions
                                </TableHeading>
                            </tr>
                        </thead>

                        <tbody className="divide-y divide-slate-100">
                            {companies.data.map(
                                (
                                    company,
                                ) => (
                                    <tr
                                        key={
                                            company.id
                                        }
                                        className="hover:bg-slate-50/70"
                                    >
                                        <td className="whitespace-nowrap px-5 py-4">
                                            <div className="font-semibold text-slate-900">
                                                {
                                                    company.name
                                                }
                                            </div>

                                            {company.legal_name && (
                                                <div className="mt-0.5 text-xs text-slate-500">
                                                    {
                                                        company.legal_name
                                                    }
                                                </div>
                                            )}
                                        </td>

                                        <td className="whitespace-nowrap px-5 py-4 text-sm font-medium text-slate-600">
                                            {
                                                company.code
                                            }
                                        </td>

                                        <td className="px-5 py-4">
                                            <div className="text-sm text-slate-700">
                                                {company.email ??
                                                    "—"}
                                            </div>

                                            <div className="mt-0.5 text-xs text-slate-500">
                                                {company.phone ??
                                                    "—"}
                                            </div>
                                        </td>

                                        <td className="whitespace-nowrap px-5 py-4 text-sm text-slate-600">
                                            {
                                                company.currency_code
                                            }
                                        </td>

                                        <td className="whitespace-nowrap px-5 py-4">
                                            <StatusBadge
                                                status={
                                                    company.status
                                                }
                                            />
                                        </td>

                                        <td className="whitespace-nowrap px-5 py-4">
                                            {company.is_default ? (
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

                                        <td className="whitespace-nowrap px-5 py-4">
                                            <div className="flex items-center justify-end gap-2">
                                                {can(
                                                    "erp.companies.edit",
                                                ) && (
                                                    <Link
                                                        href={`/erp/companies/${company.id}/edit`}
                                                        className="inline-flex size-9 items-center justify-center rounded-lg border text-slate-600 transition hover:bg-slate-50 hover:text-indigo-600"
                                                    >
                                                        <Edit3 className="size-4" />
                                                    </Link>
                                                )}

                                                {can(
                                                    "erp.companies.delete",
                                                ) && (
                                                    <button
                                                        type="button"
                                                        disabled={
                                                            company.is_default
                                                        }
                                                        onClick={() =>
                                                            setCompanyToDelete(
                                                                company,
                                                            )
                                                        }
                                                        title={
                                                            company.is_default
                                                                ? "Default company cannot be deleted"
                                                                : "Delete company"
                                                        }
                                                        className="inline-flex size-9 items-center justify-center rounded-lg border text-slate-500 transition hover:border-red-200 hover:bg-red-50 hover:text-red-600 disabled:cursor-not-allowed disabled:opacity-30"
                                                    >
                                                        <Trash2 className="size-4" />
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ),
                            )}

                            {companies.data.length ===
                                0 && (
                                <tr>
                                    <td
                                        colSpan={
                                            7
                                        }
                                        className="px-5 py-16 text-center"
                                    >
                                        <Building2 className="mx-auto size-10 text-slate-300" />

                                        <h3 className="mt-3 font-semibold text-slate-700">
                                            No
                                            companies
                                            found
                                        </h3>

                                        <p className="mt-1 text-sm text-slate-500">
                                            Create
                                            your first
                                            company to
                                            begin ERP
                                            organization
                                            setup.
                                        </p>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {companies.last_page >
                    1 && (
                    <div className="flex flex-wrap items-center justify-between gap-4 border-t px-5 py-4">
                        <p className="text-sm text-slate-500">
                            Showing{" "}
                            {companies.from ??
                                0}{" "}
                            to{" "}
                            {companies.to ??
                                0}{" "}
                            of{" "}
                            {
                                companies.total
                            }
                        </p>

                        <div className="flex flex-wrap gap-1">
                            {companies.links.map(
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
                    companyToDelete !==
                    null
                }
                onOpenChange={(
                    open,
                ) => {
                    if (
                        ! open &&
                        ! deleting
                    ) {
                        setCompanyToDelete(
                            null,
                        );
                    }
                }}
                onConfirm={
                    deleteCompany
                }
                title="Delete company?"
                itemName={
                    companyToDelete?.name
                }
                processing={
                    deleting
                }
            />
        </ERPLayout>
    );
}


function TableHeading({
    children,
    align = "left",
}: {
    children: React.ReactNode;

    align?: "left" | "right";
}) {
    return (
        <th
            className={`
                px-5 py-3 text-xs font-semibold
                uppercase tracking-wide text-slate-500
                ${
                    align ===
                    "right"
                        ? "text-right"
                        : "text-left"
                }
            `}
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
            className={`
                inline-flex rounded-full
                px-2.5 py-1
                text-xs font-semibold
                ${
                    active
                        ? "bg-emerald-50 text-emerald-700"
                        : "bg-slate-100 text-slate-600"
                }
            `}
        >
            {active
                ? "Active"
                : "Inactive"}
        </span>
    );
}
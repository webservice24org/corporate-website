import {Head, Link, router} from "@inertiajs/react";
import {
    Calculator,
    Edit3,
    Plus,
    Search,
    Trash2,
} from "lucide-react";
import {useState} from "react";
import type { ReactNode} from "react";

import ERPLayout from "@/Layouts/erp-layout";
import { useAuthorization } from "@/hooks/useAuthorization";

type Company = {
    id: number;
    name: string;
};

type SalaryComponent = {
    id: number;

    company_id: number;

    name: string;
    code: string;

    component_type:
        | "earning"
        | "deduction";

    calculation_type:
        | "fixed"
        | "percentage";

    default_amount:
        string | number;

    default_percentage:
        string | number;

    is_taxable: boolean;

    affects_gross_salary:
        boolean;

    is_recurring: boolean;

    status: boolean;

    sort_order: number;

    company?: Company | null;

    percentage_of_component?: {
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

type ComponentsPagination = {
    data: SalaryComponent[];

    links: PaginationLink[];

    total: number;
};

type Filters = {
    company_id?: string | number;
    component_type?: string;
    status?: string;
    search?: string;
};

type Props = {
    components:
        ComponentsPagination;

    companies: Company[];

    filters: Filters;
};

function money(
    value:
        | string
        | number
): string {
    return Number(
        value ?? 0
    ).toLocaleString(
        undefined,
        {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }
    );
}

export default function Index({
    components,
    companies,
    filters,
}: Props) {
    const { can } =
        useAuthorization();

    const [
        search,
        setSearch,
    ] = useState(
        filters.search ?? ""
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
        componentType,
        setComponentType,
    ] = useState(
        filters.component_type ??
            ""
    );

    const [
        status,
        setStatus,
    ] = useState(
        filters.status ?? ""
    );

    const [
        deletingComponent,
        setDeletingComponent,
    ] =
        useState<SalaryComponent | null>(
            null
        );

    const applyFilters = () => {
        router.get(
            "/erp/payroll/salary-components",
            {
                search:
                    search ||
                    undefined,

                company_id:
                    companyId ||
                    undefined,

                component_type:
                    componentType ||
                    undefined,

                status:
                    status ||
                    undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            }
        );
    };

    const resetFilters = () => {
        setSearch("");
        setCompanyId("");
        setComponentType("");
        setStatus("");

        router.get(
            "/erp/payroll/salary-components",
            {},
            {
                preserveScroll: true,
            }
        );
    };

    const confirmDelete = () => {
        if (
            !deletingComponent
        ) {
            return;
        }

        router.delete(
            `/erp/payroll/salary-components/${deletingComponent.id}`,
            {
                preserveScroll: true,

                onSuccess: () =>
                    setDeletingComponent(
                        null
                    ),
            }
        );
    };

    return (
        <ERPLayout>
            <Head title="Salary Components" />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Salary Components
                        </h1>

                        <p className="mt-1 text-sm text-slate-500">
                            Manage reusable
                            payroll earnings
                            and deductions.
                        </p>
                    </div>

                    {can(
                        "erp.salary-components.create"
                    ) && (
                        <Link
                            href="/erp/payroll/salary-components/create"
                            className="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-slate-900 px-4 text-sm font-medium text-white hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
                        >
                            <Plus className="h-4 w-4" />

                            Add Component
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
                                placeholder="Search name or code..."
                                className="h-10 w-full rounded-md border border-slate-200 bg-white pl-9 pr-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                            />
                        </div>

                        <select
                            value={
                                companyId
                            }
                            onChange={(
                                event
                            ) =>
                                setCompanyId(
                                    event
                                        .target
                                        .value
                                )
                            }
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
                                componentType
                            }
                            onChange={(
                                event
                            ) =>
                                setComponentType(
                                    event
                                        .target
                                        .value
                                )
                            }
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
                            <option value="">
                                All types
                            </option>

                            <option value="earning">
                                Earnings
                            </option>

                            <option value="deduction">
                                Deductions
                            </option>
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
                                        Component
                                    </th>

                                    <th className="px-4 py-3">
                                        Company
                                    </th>

                                    <th className="px-4 py-3">
                                        Type
                                    </th>

                                    <th className="px-4 py-3">
                                        Calculation
                                    </th>

                                    <th className="px-4 py-3">
                                        Amount / Rate
                                    </th>

                                    <th className="px-4 py-3">
                                        Behaviour
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
                                {components.data.map(
                                    (
                                        component
                                    ) => (
                                        <tr
                                            key={
                                                component.id
                                            }
                                        >
                                            <td className="px-4 py-4">
                                                <div className="font-medium">
                                                    {
                                                        component.name
                                                    }
                                                </div>

                                                <div className="mt-1 font-mono text-xs text-slate-500">
                                                    {
                                                        component.code
                                                    }
                                                </div>
                                            </td>

                                            <td className="px-4 py-4">
                                                {component
                                                    .company
                                                    ?.name ??
                                                    "—"}
                                            </td>

                                            <td className="px-4 py-4">
                                                <span
                                                    className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${
                                                        component.component_type ===
                                                        "earning"
                                                            ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400"
                                                            : "bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-400"
                                                    }`}
                                                >
                                                    {component.component_type ===
                                                    "earning"
                                                        ? "Earning"
                                                        : "Deduction"}
                                                </span>
                                            </td>

                                            <td className="px-4 py-4">
                                                {component.calculation_type ===
                                                "fixed" ? (
                                                    <span className="inline-flex items-center gap-1.5">
                                                        <Calculator className="h-3.5 w-3.5 text-slate-400" />

                                                        Fixed
                                                    </span>
                                                ) : (
                                                    <div>
                                                        <div>
                                                            Percentage
                                                        </div>

                                                        <div className="mt-1 text-xs text-slate-500">
                                                            of{" "}
                                                            {component
                                                                .percentage_of_component
                                                                ?.code ??
                                                                "—"}
                                                        </div>
                                                    </div>
                                                )}
                                            </td>

                                            <td className="px-4 py-4">
                                                {component.calculation_type ===
                                                "fixed"
                                                    ? money(
                                                          component.default_amount
                                                      )
                                                    : `${Number(
                                                          component.default_percentage
                                                      )}%`}
                                            </td>

                                            <td className="px-4 py-4">
                                                <div className="flex flex-wrap gap-1.5">
                                                    {component.is_recurring && (
                                                        <Badge>
                                                            Recurring
                                                        </Badge>
                                                    )}

                                                    {component.is_taxable && (
                                                        <Badge>
                                                            Taxable
                                                        </Badge>
                                                    )}

                                                    {component.affects_gross_salary && (
                                                        <Badge>
                                                            Gross
                                                        </Badge>
                                                    )}
                                                </div>
                                            </td>

                                            <td className="px-4 py-4">
                                                <span
                                                    className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${
                                                        component.status
                                                            ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400"
                                                            : "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400"
                                                    }`}
                                                >
                                                    {component.status
                                                        ? "Active"
                                                        : "Inactive"}
                                                </span>
                                            </td>

                                            <td className="px-4 py-4">
                                                <div className="flex justify-end gap-2">
                                                    {can(
                                                        "erp.salary-components.edit"
                                                    ) && (
                                                        <Link
                                                            href={`/erp/payroll/salary-components/${component.id}/edit`}
                                                            className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                                                            title="Edit"
                                                        >
                                                            <Edit3 className="h-4 w-4" />
                                                        </Link>
                                                    )}

                                                    {can(
                                                        "erp.salary-components.delete"
                                                    ) && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setDeletingComponent(
                                                                    component
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

                                {components.data
                                    .length ===
                                    0 && (
                                    <tr>
                                        <td
                                            colSpan={
                                                8
                                            }
                                            className="px-4 py-16 text-center text-slate-500"
                                        >
                                            No salary
                                            components
                                            found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {components.links
                        .length >
                        3 && (
                        <div className="flex flex-wrap gap-1 border-t border-slate-200 p-4 dark:border-slate-800">
                            {components.links.map(
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
            </div>

            {deletingComponent && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-slate-950">
                        <div className="flex h-12 w-12 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-950/50 dark:text-red-400">
                            <Trash2 className="h-5 w-5" />
                        </div>

                        <h2 className="mt-4 text-lg font-semibold">
                            Delete Salary
                            Component
                        </h2>

                        <p className="mt-2 text-sm leading-6 text-slate-500">
                            Are you sure you
                            want to delete{" "}
                            <span className="font-medium text-slate-900 dark:text-slate-100">
                                {
                                    deletingComponent.name
                                }
                            </span>
                            ? This action
                            cannot be undone.
                        </p>

                        <div className="mt-6 flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={() =>
                                    setDeletingComponent(
                                        null
                                    )
                                }
                                className="h-10 rounded-md border border-slate-200 px-4 text-sm font-medium hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
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

function Badge({
    children,
}: {
    children: ReactNode;
}) {
    return (
        <span className="inline-flex rounded-full bg-slate-100 px-2 py-1 text-[11px] font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
            {children}
        </span>
    );
}
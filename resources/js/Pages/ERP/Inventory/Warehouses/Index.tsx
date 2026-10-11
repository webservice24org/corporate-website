import {
    Head,
    Link,
    router,
} from "@inertiajs/react";
import {
    Eye,
    Pencil,
    Plus,
    Search,
    Trash2,
    Warehouse as WarehouseIcon,
} from "lucide-react";
import { useState } from "react";

import DeleteConfirmDialog from "@/Components/Common/DeleteConfirmDialog";
import { useAuthorization } from "@/hooks/useAuthorization";
import ERPLayout from "@/Layouts/erp-layout";

type Company = {
    id: number;
    name: string;
};

type Division = {
    id: number;
    name: string;
    name_bn?: string | null;
};

type District = {
    id: number;
    division_id: number;
    name: string;
    name_bn?: string | null;
};

type Upazila = {
    id: number;
    district_id: number;
    name: string;
    name_bn?: string | null;
};

type WarehouseType = {
    value: string;
    label: string;
};

type Warehouse = {
    id: number;

    company_id: number;

    division_id?: number | null;
    district_id?: number | null;
    upazila_id?: number | null;

    code: string;
    name: string;
    type: string;

    contact_person?: string | null;
    phone?: string | null;
    email?: string | null;

    address?: string | null;
    postal_code?: string | null;

    is_active: boolean;

    company?: Company | null;
    division?: Division | null;
    district?: District | null;
    upazila?: Upazila | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedWarehouses = {
    data: Warehouse[];
    links: PaginationLink[];

    current_page?: number;
    last_page?: number;
    per_page?: number;
    total?: number;

    from?: number | null;
    to?: number | null;
};

type Filters = {
    search?: string;
    company_id?: string | number;
    type?: string;
    status?: string;

    division_id?: string | number;
    district_id?: string | number;
    upazila_id?: string | number;
};

type Props = {
    warehouses: PaginatedWarehouses;

    companies: Company[];
    divisions: Division[];
    districts: District[];
    upazilas: Upazila[];

    warehouseTypes: WarehouseType[];

    filters?: Filters;
};

export default function Index({
    warehouses,
    companies,
    divisions,
    districts,
    upazilas,
    warehouseTypes,
    filters,
}: Props) {
    const { can } = useAuthorization();

    const [search, setSearch] = useState(
        filters?.search ?? ""
    );

    const [
        companyFilter,
        setCompanyFilter,
    ] = useState(
        filters?.company_id !== undefined &&
            filters?.company_id !== null
            ? String(filters.company_id)
            : ""
    );

    const [typeFilter, setTypeFilter] =
        useState(filters?.type ?? "");

    const [statusFilter, setStatusFilter] =
        useState(filters?.status ?? "");

    const [
        divisionFilter,
        setDivisionFilter,
    ] = useState(
        filters?.division_id !== undefined &&
            filters?.division_id !== null
            ? String(filters.division_id)
            : ""
    );

    const [
        districtFilter,
        setDistrictFilter,
    ] = useState(
        filters?.district_id !== undefined &&
            filters?.district_id !== null
            ? String(filters.district_id)
            : ""
    );

    const [
        upazilaFilter,
        setUpazilaFilter,
    ] = useState(
        filters?.upazila_id !== undefined &&
            filters?.upazila_id !== null
            ? String(filters.upazila_id)
            : ""
    );

    const [
        deletingWarehouse,
        setDeletingWarehouse,
    ] = useState<Warehouse | null>(null);

    const [
        isDeleting,
        setIsDeleting,
    ] = useState(false);

    const filteredDistricts =
        divisionFilter === ""
            ? []
            : districts.filter(
                  (district) =>
                      district.division_id ===
                      Number(divisionFilter)
              );

    const filteredUpazilas =
        districtFilter === ""
            ? []
            : upazilas.filter(
                  (upazila) =>
                      upazila.district_id ===
                      Number(districtFilter)
              );

    const handleDivisionChange = (
        value: string
    ) => {
        setDivisionFilter(value);

        setDistrictFilter("");
        setUpazilaFilter("");
    };

    const handleDistrictChange = (
        value: string
    ) => {
        setDistrictFilter(value);
        setUpazilaFilter("");
    };

    const applyFilters = () => {
        router.get(
            "/erp/inventory/warehouses",
            {
                search:
                    search.trim() ||
                    undefined,

                company_id:
                    companyFilter ||
                    undefined,

                type:
                    typeFilter ||
                    undefined,

                status:
                    statusFilter ||
                    undefined,

                division_id:
                    divisionFilter ||
                    undefined,

                district_id:
                    districtFilter ||
                    undefined,

                upazila_id:
                    upazilaFilter ||
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
        setCompanyFilter("");
        setTypeFilter("");
        setStatusFilter("");

        setDivisionFilter("");
        setDistrictFilter("");
        setUpazilaFilter("");

        router.get(
            "/erp/inventory/warehouses",
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            }
        );
    };

    const confirmDelete = () => {
        if (!deletingWarehouse) {
            return;
        }

        setIsDeleting(true);

        router.delete(
            `/erp/inventory/warehouses/${deletingWarehouse.id}`,
            {
                preserveScroll: true,

                onFinish: () => {
                    setIsDeleting(false);
                    setDeletingWarehouse(null);
                },
            }
        );
    };

    return (
        <ERPLayout>
            <Head title="Warehouses" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 dark:bg-slate-900">
                                <WarehouseIcon className="h-5 w-5" />
                            </div>

                            <div>
                                <h1 className="text-2xl font-semibold">
                                    Warehouses
                                </h1>

                                <p className="mt-1 text-sm text-slate-500">
                                    Manage physical
                                    inventory locations
                                    across your companies.
                                </p>
                            </div>
                        </div>
                    </div>

                    {can(
                        "erp.warehouses.create"
                    ) && (
                        <Link
                            href="/erp/inventory/warehouses/create"
                            className="inline-flex h-10 w-fit items-center gap-2 rounded-md bg-slate-900 px-4 text-sm font-medium text-white transition hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
                        >
                            <Plus className="h-4 w-4" />
                            Create Warehouse
                        </Link>
                    )}
                </div>

                {/* Filters */}
                <section className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <div className="relative md:col-span-2">
                            <Search className="absolute left-3 top-3 h-4 w-4 text-slate-400" />

                            <input
                                type="text"
                                value={search}
                                onChange={(event) =>
                                    setSearch(
                                        event.target
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
                                placeholder="Search name, code, contact, phone, email or address..."
                                className={`${inputClass} pl-9`}
                            />
                        </div>

                        <select
                            value={companyFilter}
                            onChange={(event) =>
                                setCompanyFilter(
                                    event.target.value
                                )
                            }
                            className={inputClass}
                        >
                            <option value="">
                                All Companies
                            </option>

                            {companies.map(
                                (company) => (
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
                            value={typeFilter}
                            onChange={(event) =>
                                setTypeFilter(
                                    event.target.value
                                )
                            }
                            className={inputClass}
                        >
                            <option value="">
                                All Types
                            </option>

                            {warehouseTypes.map(
                                (type) => (
                                    <option
                                        key={
                                            type.value
                                        }
                                        value={
                                            type.value
                                        }
                                    >
                                        {
                                            type.label
                                        }
                                    </option>
                                )
                            )}
                        </select>

                        <select
                            value={divisionFilter}
                            onChange={(event) =>
                                handleDivisionChange(
                                    event.target.value
                                )
                            }
                            className={inputClass}
                        >
                            <option value="">
                                All Divisions
                            </option>

                            {divisions.map(
                                (division) => (
                                    <option
                                        key={
                                            division.id
                                        }
                                        value={
                                            division.id
                                        }
                                    >
                                        {
                                            division.name
                                        }
                                        {division.name_bn
                                            ? ` — ${division.name_bn}`
                                            : ""}
                                    </option>
                                )
                            )}
                        </select>

                        <select
                            value={districtFilter}
                            disabled={
                                divisionFilter ===
                                ""
                            }
                            onChange={(event) =>
                                handleDistrictChange(
                                    event.target.value
                                )
                            }
                            className={`${inputClass} disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400 dark:disabled:bg-slate-900`}
                        >
                            <option value="">
                                All Districts
                            </option>

                            {filteredDistricts.map(
                                (district) => (
                                    <option
                                        key={
                                            district.id
                                        }
                                        value={
                                            district.id
                                        }
                                    >
                                        {
                                            district.name
                                        }
                                        {district.name_bn
                                            ? ` — ${district.name_bn}`
                                            : ""}
                                    </option>
                                )
                            )}
                        </select>

                        <select
                            value={upazilaFilter}
                            disabled={
                                districtFilter ===
                                ""
                            }
                            onChange={(event) =>
                                setUpazilaFilter(
                                    event.target.value
                                )
                            }
                            className={`${inputClass} disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400 dark:disabled:bg-slate-900`}
                        >
                            <option value="">
                                All Upazilas
                            </option>

                            {filteredUpazilas.map(
                                (upazila) => (
                                    <option
                                        key={
                                            upazila.id
                                        }
                                        value={
                                            upazila.id
                                        }
                                    >
                                        {
                                            upazila.name
                                        }
                                        {upazila.name_bn
                                            ? ` — ${upazila.name_bn}`
                                            : ""}
                                    </option>
                                )
                            )}
                        </select>

                        <select
                            value={statusFilter}
                            onChange={(event) =>
                                setStatusFilter(
                                    event.target.value
                                )
                            }
                            className={inputClass}
                        >
                            <option value="">
                                All Status
                            </option>

                            <option value="active">
                                Active
                            </option>

                            <option value="inactive">
                                Inactive
                            </option>
                        </select>
                    </div>

                    <div className="mt-4 flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={
                                applyFilters
                            }
                            className="inline-flex h-10 items-center gap-2 rounded-md bg-slate-900 px-4 text-sm font-medium text-white dark:bg-white dark:text-slate-950"
                        >
                            <Search className="h-4 w-4" />
                            Apply Filters
                        </button>

                        <button
                            type="button"
                            onClick={
                                resetFilters
                            }
                            className="h-10 rounded-md border border-slate-200 px-4 text-sm font-medium transition hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                        >
                            Reset
                        </button>
                    </div>
                </section>

                {/* Summary */}
                <div className="flex flex-col gap-1 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        {warehouses.total !==
                        undefined ? (
                            <>
                                Showing{" "}
                                <span className="font-medium text-slate-700 dark:text-slate-300">
                                    {warehouses.from ??
                                        0}
                                </span>
                                {" – "}
                                <span className="font-medium text-slate-700 dark:text-slate-300">
                                    {warehouses.to ??
                                        0}
                                </span>{" "}
                                of{" "}
                                <span className="font-medium text-slate-700 dark:text-slate-300">
                                    {
                                        warehouses.total
                                    }
                                </span>{" "}
                                warehouses
                            </>
                        ) : (
                            `${warehouses.data.length} warehouses`
                        )}
                    </div>
                </div>

                {/* Table */}
                <section className="min-w-0 overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                    <div className="w-full overflow-x-auto">
                        <table className="w-full min-w-[1100px] text-left text-sm">
                            <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-900/50">
                                <tr>
                                    <th className="px-5 py-3">
                                        Warehouse
                                    </th>

                                    <th className="px-5 py-3">
                                        Company
                                    </th>

                                    <th className="px-5 py-3">
                                        Type
                                    </th>

                                    <th className="px-5 py-3">
                                        Location
                                    </th>

                                    <th className="px-5 py-3">
                                        Contact
                                    </th>

                                    <th className="px-5 py-3">
                                        Status
                                    </th>

                                    <th className="px-5 py-3 text-right">
                                        Actions
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {warehouses.data.map(
                                    (warehouse) => (
                                        <tr
                                            key={
                                                warehouse.id
                                            }
                                            className="transition hover:bg-slate-50/70 dark:hover:bg-slate-900/40"
                                        >
                                            <td className="px-5 py-4">
                                                <div className="font-medium text-slate-900 dark:text-slate-100">
                                                    {
                                                        warehouse.name
                                                    }
                                                </div>

                                                <div className="mt-1 font-mono text-xs text-slate-500">
                                                    {
                                                        warehouse.code
                                                    }
                                                </div>
                                            </td>

                                            <td className="px-5 py-4">
                                                {warehouse
                                                    .company
                                                    ?.name ??
                                                    "—"}
                                            </td>

                                            <td className="px-5 py-4">
                                                <span className="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                                    {warehouseTypeLabel(
                                                        warehouse.type,
                                                        warehouseTypes
                                                    )}
                                                </span>
                                            </td>

                                            <td className="px-5 py-4">
                                                <WarehouseLocation
                                                    warehouse={
                                                        warehouse
                                                    }
                                                />
                                            </td>

                                            <td className="px-5 py-4">
                                                <div className="space-y-1">
                                                    <div className="font-medium">
                                                        {warehouse.contact_person ??
                                                            "—"}
                                                    </div>

                                                    {warehouse.phone && (
                                                        <div className="text-xs text-slate-500">
                                                            {
                                                                warehouse.phone
                                                            }
                                                        </div>
                                                    )}

                                                    {warehouse.email && (
                                                        <div className="max-w-[220px] truncate text-xs text-slate-500">
                                                            {
                                                                warehouse.email
                                                            }
                                                        </div>
                                                    )}
                                                </div>
                                            </td>

                                            <td className="px-5 py-4">
                                                <StatusBadge
                                                    active={
                                                        warehouse.is_active
                                                    }
                                                />
                                            </td>

                                            <td className="px-5 py-4">
                                                <div className="flex justify-end gap-2">
                                                    {can(
                                                        "erp.warehouses.view"
                                                    ) && (
                                                        <Link
                                                            href={`/erp/inventory/warehouses/${warehouse.id}`}
                                                            title="View Warehouse"
                                                            className={actionClass}
                                                        >
                                                            <Eye className="h-4 w-4" />
                                                        </Link>
                                                    )}

                                                    {can(
                                                        "erp.warehouses.edit"
                                                    ) && (
                                                        <Link
                                                            href={`/erp/inventory/warehouses/${warehouse.id}/edit`}
                                                            title="Edit Warehouse"
                                                            className={actionClass}
                                                        >
                                                            <Pencil className="h-4 w-4" />
                                                        </Link>
                                                    )}

                                                    {can(
                                                        "erp.warehouses.delete"
                                                    ) && (
                                                        <button
                                                            type="button"
                                                            title="Delete Warehouse"
                                                            onClick={() =>
                                                                setDeletingWarehouse(
                                                                    warehouse
                                                                )
                                                            }
                                                            className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-red-200 text-red-600 transition hover:bg-red-50 dark:border-red-900 dark:hover:bg-red-950/30"
                                                        >
                                                            <Trash2 className="h-4 w-4" />
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    )
                                )}

                                {warehouses.data
                                    .length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={
                                                7
                                            }
                                            className="px-5 py-14 text-center"
                                        >
                                            <div className="mx-auto flex max-w-sm flex-col items-center">
                                                <div className="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-900">
                                                    <WarehouseIcon className="h-5 w-5 text-slate-500" />
                                                </div>

                                                <h3 className="mt-4 font-medium">
                                                    No
                                                    warehouses
                                                    found
                                                </h3>

                                                <p className="mt-1 text-sm text-slate-500">
                                                    Try
                                                    changing
                                                    the
                                                    filters
                                                    or create
                                                    your first
                                                    warehouse.
                                                </p>

                                                {can(
                                                    "erp.warehouses.create"
                                                ) && (
                                                    <Link
                                                        href="/erp/inventory/warehouses/create"
                                                        className="mt-4 inline-flex h-9 items-center gap-2 rounded-md bg-slate-900 px-3 text-sm font-medium text-white dark:bg-white dark:text-slate-950"
                                                    >
                                                        <Plus className="h-4 w-4" />
                                                        Create
                                                        Warehouse
                                                    </Link>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>

                {/* Pagination */}
                {warehouses.links?.length >
                    3 && (
                    <div className="flex flex-wrap gap-2">
                        {warehouses.links.map(
                            (
                                link,
                                index
                            ) => (
                                <button
                                    key={`${link.label}-${index}`}
                                    type="button"
                                    disabled={
                                        !link.url
                                    }
                                    onClick={() => {
                                        if (
                                            !link.url
                                        ) {
                                            return;
                                        }

                                        router.visit(
                                            link.url,
                                            {
                                                preserveScroll:
                                                    true,
                                                preserveState:
                                                    true,
                                            }
                                        );
                                    }}
                                    className={`h-9 rounded-md border px-3 text-sm transition ${
                                        link.active
                                            ? "border-slate-900 bg-slate-900 text-white dark:border-white dark:bg-white dark:text-slate-950"
                                            : "border-slate-200 bg-white hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-950 dark:hover:bg-slate-900"
                                    } disabled:cursor-not-allowed disabled:opacity-40`}
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

            <DeleteConfirmDialog
                open={
                    !!deletingWarehouse
                }
                onOpenChange={(open) => {
                    if (
                        !open &&
                        !isDeleting
                    ) {
                        setDeletingWarehouse(
                            null
                        );
                    }
                }}
                onConfirm={
                    confirmDelete
                }
                title="Delete Warehouse?"
                itemName={
                    deletingWarehouse
                        ? `${deletingWarehouse.name} (${deletingWarehouse.code})`
                        : undefined
                }
                processing={
                    isDeleting
                }
            />
        </ERPLayout>
    );
}

function WarehouseLocation({
    warehouse,
}: {
    warehouse: Warehouse;
}) {
    const locationParts = [
        warehouse.upazila?.name,
        warehouse.district?.name,
        warehouse.division?.name,
    ].filter(Boolean);

    if (
        locationParts.length === 0 &&
        !warehouse.address
    ) {
        return (
            <span className="text-slate-400">
                —
            </span>
        );
    }

    return (
        <div className="max-w-[280px]">
            {locationParts.length > 0 && (
                <div className="font-medium">
                    {locationParts.join(
                        ", "
                    )}
                </div>
            )}

            {warehouse.address && (
                <div className="mt-1 line-clamp-2 text-xs leading-5 text-slate-500">
                    {warehouse.address}
                </div>
            )}
        </div>
    );
}

function StatusBadge({
    active,
}: {
    active: boolean;
}) {
    return (
        <span
            className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${
                active
                    ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"
                    : "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300"
            }`}
        >
            {active
                ? "Active"
                : "Inactive"}
        </span>
    );
}

function warehouseTypeLabel(
    value: string,
    types: WarehouseType[]
) {
    return (
        types.find(
            (type) =>
                type.value === value
        )?.label ?? value
    );
}

const inputClass =
    "h-10 w-full min-w-0 rounded-md border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-slate-400 focus:ring-2 focus:ring-slate-100 dark:border-slate-800 dark:bg-slate-950 dark:focus:border-slate-600 dark:focus:ring-slate-900";

const actionClass =
    "inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-200 transition hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900";
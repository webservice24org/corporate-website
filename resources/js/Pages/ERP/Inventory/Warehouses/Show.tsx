import {
    Head,
    Link,
    router,
} from "@inertiajs/react";
import {
    ArrowLeft,
    BookOpen,
    Boxes,
    Building2,
    Edit,
    Mail,
    MapPin,
    Phone,
    Trash2,
    UserRound,
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

    notes?: string | null;

    created_at?: string | null;
    updated_at?: string | null;

    company?: Company | null;
    division?: Division | null;
    district?: District | null;
    upazila?: Upazila | null;
};

type InventorySummary = {
    sku_count: number;

    on_hand: string;
    reserved: string;
    available: string;

    inventory_value: string;
};

type MovementProduct = {
    id: number;
    name: string;
    sku?: string | null;
};

type MovementVariant = {
    id: number;
    product_id: number;
    name: string;
    sku?: string | null;
};

type MovementUser = {
    id: number;
    name: string;
};

type RecentMovement = {
    id: number;

    movement_type: string;

    direction: "in" | "out";

    quantity: string;

    balance_before: string;
    balance_after: string;

    unit_cost: string;
    total_cost: string;

    reference_number?: string | null;

    occurred_at: string;

    product?: MovementProduct | null;

    variant?: MovementVariant | null;

    creator?: MovementUser | null;
};

type Props = {
    warehouse: Warehouse;

    inventorySummary: InventorySummary;

    recentMovements: RecentMovement[];
};

export default function Show({
    warehouse,
    inventorySummary,
    recentMovements,
}: Props) {
    const { can } = useAuthorization();

    const [deleteOpen, setDeleteOpen] =
        useState(false);

    const [deleting, setDeleting] =
        useState(false);

    /*
     * We no longer depend on a warehouseTypes prop.
     * This keeps the Show page independent from the
     * Create/Edit form option list.
     */
    const typeLabel =
        humanize(warehouse.type);

    const deleteWarehouse = () => {
        setDeleting(true);

        router.delete(
            `/erp/inventory/warehouses/${warehouse.id}`,
            {
                preserveScroll: true,

                onFinish: () => {
                    setDeleting(false);
                    setDeleteOpen(false);
                },
            }
        );
    };

    return (
        <ERPLayout>
            <Head title={warehouse.name} />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                    <div className="flex min-w-0 items-start gap-4">
                        <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-slate-100 dark:bg-slate-900">
                            <WarehouseIcon className="h-6 w-6" />
                        </div>

                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="break-words text-2xl font-semibold">
                                    {warehouse.name}
                                </h1>

                                <StatusBadge
                                    active={
                                        warehouse.is_active
                                    }
                                />
                            </div>

                            <div className="mt-2 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                                <span className="rounded-md bg-slate-100 px-2 py-1 font-mono text-xs text-slate-700 dark:bg-slate-900 dark:text-slate-300">
                                    {warehouse.code}
                                </span>

                                <span>
                                    {typeLabel}
                                </span>

                                {warehouse.company
                                    ?.name && (
                                    <>
                                        <span>
                                            •
                                        </span>

                                        <span>
                                            {
                                                warehouse
                                                    .company
                                                    .name
                                            }
                                        </span>
                                    </>
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Link
                            href="/erp/inventory/warehouses"
                            className={
                                secondaryButtonClass
                            }
                        >
                            <ArrowLeft className="h-4 w-4" />
                            Warehouses
                        </Link>

                        {can(
                            "erp.warehouses.edit"
                        ) && (
                            <Link
                                href={`/erp/inventory/warehouses/${warehouse.id}/edit`}
                                className={
                                    secondaryButtonClass
                                }
                            >
                                <Edit className="h-4 w-4" />
                                Edit Warehouse
                            </Link>
                        )}

                        {can(
                            "erp.inventory-stock.view"
                        ) && (
                            <Link
                                href={`/erp/inventory/stock?warehouse_id=${warehouse.id}`}
                                className={
                                    secondaryButtonClass
                                }
                            >
                                <Boxes className="h-4 w-4" />
                                View Stock
                            </Link>
                        )}

                        {can(
                            "erp.stock-movements.view"
                        ) && (
                            <Link
                                href={`/erp/inventory/stock-movements?warehouse_id=${warehouse.id}`}
                                className={
                                    secondaryButtonClass
                                }
                            >
                                <BookOpen className="h-4 w-4" />
                                View Ledger
                            </Link>
                        )}

                        {can(
                            "erp.warehouses.delete"
                        ) && (
                            <button
                                type="button"
                                onClick={() =>
                                    setDeleteOpen(
                                        true
                                    )
                                }
                                className="inline-flex h-10 items-center gap-2 rounded-md border border-red-200 px-4 text-sm font-medium text-red-600 transition hover:bg-red-50 dark:border-red-900 dark:text-red-400 dark:hover:bg-red-950/30"
                            >
                                <Trash2 className="h-4 w-4" />
                                Delete
                            </button>
                        )}
                    </div>
                </div>

                {/* Inventory Summary */}
                <section className="space-y-4">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h2 className="text-lg font-semibold">
                                Inventory Summary
                            </h2>

                            <p className="mt-1 text-sm text-slate-500">
                                Current inventory
                                position for this
                                warehouse.
                            </p>
                        </div>

                        {can(
                            "erp.inventory-stock.view"
                        ) && (
                            <Link
                                href={`/erp/inventory/stock?warehouse_id=${warehouse.id}`}
                                className="text-sm font-medium text-indigo-600 hover:text-indigo-700 dark:text-indigo-400"
                            >
                                View warehouse stock
                            </Link>
                        )}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                        <InventoryStatCard
                            label="SKUs"
                            value={formatNumber(
                                inventorySummary.sku_count
                            )}
                        />

                        <InventoryStatCard
                            label="On Hand"
                            value={formatQuantity(
                                inventorySummary.on_hand
                            )}
                        />

                        <InventoryStatCard
                            label="Reserved"
                            value={formatQuantity(
                                inventorySummary.reserved
                            )}
                        />

                        <InventoryStatCard
                            label="Available"
                            value={formatQuantity(
                                inventorySummary.available
                            )}
                        />

                        <InventoryStatCard
                            label="Inventory Value"
                            value={formatMoney(
                                inventorySummary.inventory_value
                            )}
                        />
                    </div>
                </section>

                {/* Warehouse details */}
                <div className="grid min-w-0 gap-6 xl:grid-cols-3">
                    <div className="min-w-0 space-y-6 xl:col-span-2">
                        <InfoCard
                            title="Warehouse Information"
                            description="Identification and operational classification."
                        >
                            <InfoGrid>
                                <InfoItem
                                    label="Warehouse Name"
                                    value={
                                        warehouse.name
                                    }
                                />

                                <InfoItem
                                    label="Warehouse Code"
                                    value={
                                        warehouse.code
                                    }
                                    mono
                                />

                                <InfoItem
                                    label="Warehouse Type"
                                    value={
                                        typeLabel
                                    }
                                />

                                <InfoItem
                                    label="Status"
                                    value={
                                        warehouse.is_active
                                            ? "Active"
                                            : "Inactive"
                                    }
                                />
                            </InfoGrid>
                        </InfoCard>

                        <InfoCard
                            title="Location"
                            description="Geographic and physical warehouse location."
                        >
                            <div className="mb-5 flex items-start gap-3 rounded-lg bg-slate-50 p-4 dark:bg-slate-900/50">
                                <MapPin className="mt-0.5 h-5 w-5 shrink-0 text-slate-400" />

                                <div className="min-w-0">
                                    <div className="font-medium">
                                        {formatLocation(
                                            warehouse
                                        )}
                                    </div>

                                    {warehouse.address && (
                                        <div className="mt-1 whitespace-pre-line text-sm leading-6 text-slate-500">
                                            {
                                                warehouse.address
                                            }
                                        </div>
                                    )}

                                    {warehouse.postal_code && (
                                        <div className="mt-1 text-sm text-slate-500">
                                            Postal Code:{" "}
                                            {
                                                warehouse.postal_code
                                            }
                                        </div>
                                    )}
                                </div>
                            </div>

                            <InfoGrid>
                                <InfoItem
                                    label="Division"
                                    value={locationName(
                                        warehouse.division
                                    )}
                                />

                                <InfoItem
                                    label="District"
                                    value={locationName(
                                        warehouse.district
                                    )}
                                />

                                <InfoItem
                                    label="Upazila"
                                    value={locationName(
                                        warehouse.upazila
                                    )}
                                />

                                <InfoItem
                                    label="Postal Code"
                                    value={
                                        warehouse.postal_code
                                    }
                                />
                            </InfoGrid>
                        </InfoCard>

                        <InfoCard
                            title="Internal Notes"
                            description="Operational notes maintained for this warehouse."
                        >
                            {warehouse.notes ? (
                                <p className="whitespace-pre-line text-sm leading-7 text-slate-700 dark:text-slate-300">
                                    {
                                        warehouse.notes
                                    }
                                </p>
                            ) : (
                                <EmptyValue text="No internal notes have been added." />
                            )}
                        </InfoCard>
                    </div>

                    <div className="min-w-0 space-y-6">
                        <InfoCard title="Company">
                            <div className="flex items-start gap-3">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 dark:bg-slate-900">
                                    <Building2 className="h-5 w-5" />
                                </div>

                                <div>
                                    <div className="text-xs font-medium uppercase tracking-wide text-slate-500">
                                        Company
                                    </div>

                                    <div className="mt-1 font-medium">
                                        {warehouse
                                            .company
                                            ?.name ??
                                            "—"}
                                    </div>
                                </div>
                            </div>
                        </InfoCard>

                        <InfoCard title="Primary Contact">
                            <div className="space-y-4">
                                <ContactRow
                                    icon={
                                        UserRound
                                    }
                                    label="Contact Person"
                                    value={
                                        warehouse.contact_person
                                    }
                                />

                                <ContactRow
                                    icon={Phone}
                                    label="Phone"
                                    value={
                                        warehouse.phone
                                    }
                                />

                                <ContactRow
                                    icon={Mail}
                                    label="Email"
                                    value={
                                        warehouse.email
                                    }
                                />
                            </div>
                        </InfoCard>

                        <InfoCard title="Record Information">
                            <div className="space-y-4">
                                <InfoItem
                                    label="Created"
                                    value={formatDateTime(
                                        warehouse.created_at
                                    )}
                                />

                                <InfoItem
                                    label="Last Updated"
                                    value={formatDateTime(
                                        warehouse.updated_at
                                    )}
                                />
                            </div>
                        </InfoCard>
                    </div>
                </div>

                {/* Recent Stock Movements */}
                <section className="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                    <div className="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
                        <div>
                            <h2 className="font-semibold">
                                Recent Stock
                                Movements
                            </h2>

                            <p className="mt-1 text-sm text-slate-500">
                                Latest inventory
                                activity recorded for
                                this warehouse.
                            </p>
                        </div>

                        {can(
                            "erp.stock-movements.view"
                        ) && (
                            <Link
                                href={`/erp/inventory/stock-movements?warehouse_id=${warehouse.id}`}
                                className="text-sm font-medium text-indigo-600 hover:text-indigo-700 dark:text-indigo-400"
                            >
                                View full ledger
                            </Link>
                        )}
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[1000px] text-sm">
                            <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-900/60">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Product
                                    </th>

                                    <th className="px-4 py-3 text-left font-medium">
                                        Movement
                                    </th>

                                    <th className="px-4 py-3 text-left font-medium">
                                        Direction
                                    </th>

                                    <th className="px-4 py-3 text-right font-medium">
                                        Quantity
                                    </th>

                                    <th className="px-4 py-3 text-right font-medium">
                                        Before
                                    </th>

                                    <th className="px-4 py-3 text-right font-medium">
                                        After
                                    </th>

                                    <th className="px-4 py-3 text-left font-medium">
                                        Reference
                                    </th>

                                    <th className="px-4 py-3 text-left font-medium">
                                        Occurred
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {recentMovements.map(
                                    (movement) => {
                                        const productName =
                                            movement.variant
                                                ? `${movement.product?.name ?? "Product"} — ${movement.variant.name}`
                                                : movement
                                                      .product
                                                      ?.name ??
                                                  "Unknown product";

                                        const sku =
                                            movement
                                                .variant
                                                ?.sku ||
                                            movement
                                                .product
                                                ?.sku ||
                                            "—";

                                        return (
                                            <tr
                                                key={
                                                    movement.id
                                                }
                                                className="align-top hover:bg-slate-50/70 dark:hover:bg-slate-900/30"
                                            >
                                                <td className="px-4 py-4">
                                                    <div className="font-medium">
                                                        {
                                                            productName
                                                        }
                                                    </div>

                                                    <div className="mt-1 font-mono text-xs text-slate-500">
                                                        {
                                                            sku
                                                        }
                                                    </div>
                                                </td>

                                                <td className="px-4 py-4">
                                                    {humanize(
                                                        movement.movement_type
                                                    )}
                                                </td>

                                                <td className="px-4 py-4">
                                                    <MovementDirectionBadge
                                                        direction={
                                                            movement.direction
                                                        }
                                                    />
                                                </td>

                                                <td className="px-4 py-4 text-right font-medium tabular-nums">
                                                    {formatQuantity(
                                                        movement.quantity
                                                    )}
                                                </td>

                                                <td className="px-4 py-4 text-right tabular-nums text-slate-500">
                                                    {formatQuantity(
                                                        movement.balance_before
                                                    )}
                                                </td>

                                                <td className="px-4 py-4 text-right font-medium tabular-nums">
                                                    {formatQuantity(
                                                        movement.balance_after
                                                    )}
                                                </td>

                                                <td className="px-4 py-4">
                                                    {movement.reference_number ??
                                                        "—"}
                                                </td>

                                                <td className="whitespace-nowrap px-4 py-4">
                                                    {formatDateTime(
                                                        movement.occurred_at
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    }
                                )}

                                {recentMovements.length ===
                                    0 && (
                                    <tr>
                                        <td
                                            colSpan={
                                                8
                                            }
                                            className="px-6 py-12 text-center"
                                        >
                                            <BookOpen className="mx-auto h-8 w-8 text-slate-300" />

                                            <div className="mt-3 font-medium">
                                                No stock
                                                movements
                                            </div>

                                            <p className="mt-1 text-sm text-slate-500">
                                                No stock
                                                movements
                                                have been
                                                recorded
                                                for this
                                                warehouse
                                                yet.
                                            </p>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <DeleteConfirmDialog
                open={deleteOpen}
                onOpenChange={(
                    open
                ) => {
                    if (!deleting) {
                        setDeleteOpen(
                            open
                        );
                    }
                }}
                onConfirm={
                    deleteWarehouse
                }
                title="Delete Warehouse?"
                itemName={`${warehouse.name} (${warehouse.code})`}
                processing={
                    deleting
                }
            />
        </ERPLayout>
    );
}

function InfoCard({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: React.ReactNode;
}) {
    return (
        <section className="min-w-0 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
            <div className="mb-5">
                <h2 className="font-semibold">
                    {title}
                </h2>

                {description && (
                    <p className="mt-1 text-sm text-slate-500">
                        {description}
                    </p>
                )}
            </div>

            {children}
        </section>
    );
}

function InfoGrid({
    children,
}: {
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-x-6 gap-y-5 sm:grid-cols-2">
            {children}
        </div>
    );
}

function InfoItem({
    label,
    value,
    mono = false,
}: {
    label: string;

    value?:
        | string
        | number
        | null;

    mono?: boolean;
}) {
    return (
        <div className="min-w-0">
            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">
                {label}
            </div>

            <div
                className={`mt-1 break-words text-sm ${
                    mono
                        ? "font-mono"
                        : "font-medium"
                }`}
            >
                {value !== undefined &&
                value !== null &&
                String(value).trim() !== ""
                    ? value
                    : "—"}
            </div>
        </div>
    );
}

function ContactRow({
    icon: Icon,
    label,
    value,
}: {
    icon: React.ComponentType<{
        className?: string;
    }>;

    label: string;

    value?: string | null;
}) {
    return (
        <div className="flex min-w-0 items-start gap-3">
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 dark:bg-slate-900">
                <Icon className="h-4 w-4 text-slate-500" />
            </div>

            <div className="min-w-0">
                <div className="text-xs text-slate-500">
                    {label}
                </div>

                <div className="mt-0.5 break-words text-sm font-medium">
                    {value || "—"}
                </div>
            </div>
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

function EmptyValue({
    text,
}: {
    text: string;
}) {
    return (
        <div className="rounded-lg border border-dashed border-slate-200 px-4 py-6 text-center text-sm text-slate-500 dark:border-slate-800">
            {text}
        </div>
    );
}

function InventoryStatCard({
    label,
    value,
}: {
    label: string;
    value: string;
}) {
    return (
        <div className="min-w-0 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">
                {label}
            </div>

            <div className="mt-2 truncate text-xl font-semibold">
                {value}
            </div>
        </div>
    );
}

function MovementDirectionBadge({
    direction,
}: {
    direction: "in" | "out";
}) {
    const incoming =
        direction === "in";

    return (
        <span
            className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${
                incoming
                    ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"
                    : "bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300"
            }`}
        >
            {incoming
                ? "IN"
                : "OUT"}
        </span>
    );
}

function locationName(
    location?:
        | {
              name: string;
              name_bn?: string | null;
          }
        | null
): string {
    if (!location) {
        return "—";
    }

    return location.name_bn
        ? `${location.name} — ${location.name_bn}`
        : location.name;
}

function formatLocation(
    warehouse: Warehouse
): string {
    const parts = [
        warehouse.upazila?.name,
        warehouse.district?.name,
        warehouse.division?.name,
    ].filter(Boolean);

    return parts.length > 0
        ? parts.join(", ")
        : "Location not specified";
}

function numberValue(
    value:
        | string
        | number
        | null
        | undefined
): number {
    const parsed =
        Number(value ?? 0);

    return Number.isFinite(
        parsed
    )
        ? parsed
        : 0;
}

function formatNumber(
    value:
        | string
        | number
        | null
        | undefined
): string {
    return numberValue(
        value
    ).toLocaleString(
        "en-US",
        {
            maximumFractionDigits:
                0,
        }
    );
}

function formatQuantity(
    value:
        | string
        | number
        | null
        | undefined
): string {
    return numberValue(
        value
    ).toLocaleString(
        "en-US",
        {
            minimumFractionDigits:
                0,

            maximumFractionDigits:
                4,
        }
    );
}

function formatMoney(
    value:
        | string
        | number
        | null
        | undefined
): string {
    return numberValue(
        value
    ).toLocaleString(
        "en-US",
        {
            minimumFractionDigits:
                2,

            maximumFractionDigits:
                2,
        }
    );
}

/*
 * Keep exactly ONE formatDateTime().
 * The attached file currently has two copies.
 */
function formatDateTime(
    value?: string | null
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
        return value;
    }

    return new Intl.DateTimeFormat(
        "en-GB",
        {
            dateStyle:
                "medium",

            timeStyle:
                "short",
        }
    ).format(date);
}

function humanize(
    value?: string | null
): string {
    if (!value) {
        return "—";
    }

    return value
        .replace(
            /[_-]+/g,
            " "
        )
        .replace(
            /\b\w/g,
            (character) =>
                character.toUpperCase()
        );
}

const secondaryButtonClass =
    "inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium transition hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-950 dark:hover:bg-slate-900";
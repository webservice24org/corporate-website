import {
    Head,
    router,
} from "@inertiajs/react";
import {
    ArrowDownToLine,
    ArrowUpFromLine,
    BookOpen,
    Search,
    WalletCards,
    X,
} from "lucide-react";
import {
    FormEvent,
    useMemo,
    useState,
} from "react";

import ERPLayout from "@/Layouts/erp-layout";

type Company = {
    id: number;
    name: string;
};

type WarehouseOption = {
    id: number;
    company_id: number;
    code: string;
    name: string;
};

type Category = {
    id: number;
    name: string;
};

type Brand = {
    id: number;
    name: string;
};

type Product = {
    id: number;
    company_id: number;

    product_category_id?:
        | number
        | null;

    brand_id?: number | null;

    name: string;
    sku?: string | null;

    has_variants: boolean;

    category?:
        | Category
        | null;

    brand?:
        | Brand
        | null;
};

type ProductVariant = {
    id: number;
    product_id: number;

    name: string;
    sku?: string | null;
};

type User = {
    id: number;
    name: string;
};

type StockMovement = {
    id: number;

    company_id: number;
    warehouse_id: number;
    product_id: number;

    product_variant_id?:
        | number
        | null;

    movement_type: string;

    direction:
        | "in"
        | "out";

    quantity: string;

    unit_cost: string;
    total_cost: string;

    balance_before: string;
    balance_after: string;

    reference_type?:
        | string
        | null;

    reference_id?:
        | number
        | null;

    reference_number?:
        | string
        | null;

    occurred_at: string;

    notes?: string | null;

    company?:
        | Company
        | null;

    warehouse?:
        | WarehouseOption
        | null;

    product?:
        | Product
        | null;

    variant?:
        | ProductVariant
        | null;

    creator?:
        | User
        | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedMovements = {
    data: StockMovement[];

    current_page: number;
    last_page: number;
    per_page: number;
    total: number;

    from?: number | null;
    to?: number | null;

    links: PaginationLink[];
};

type Summary = {
    total_movements: number;

    quantity_in: string;
    quantity_out: string;

    value_in: string;
    value_out: string;
};

type Filters = {
    search: string;

    company_id: string;
    warehouse_id: string;

    category_id: string;
    brand_id: string;

    movement_type: string;
    direction: string;

    date_from: string;
    date_to: string;

    per_page: string;
};

type MovementTypeOption = {
    value: string;
    label: string;
};

type Props = {
    movements: PaginatedMovements;

    summary: Summary;

    filters: Filters;

    companies: Company[];

    warehouses: WarehouseOption[];

    categories: Category[];

    brands: Brand[];

    movementTypes:
        MovementTypeOption[];
};

export default function Index({
    movements,
    summary,
    filters,
    companies,
    warehouses,
    categories,
    brands,
    movementTypes,
}: Props) {
    const [search, setSearch] =
        useState(
            filters.search ?? ""
        );

    const [
        companyId,
        setCompanyId,
    ] = useState(
        filters.company_id ?? ""
    );

    const [
        warehouseId,
        setWarehouseId,
    ] = useState(
        filters.warehouse_id ?? ""
    );

    const [
        categoryId,
        setCategoryId,
    ] = useState(
        filters.category_id ?? ""
    );

    const [
        brandId,
        setBrandId,
    ] = useState(
        filters.brand_id ?? ""
    );

    const [
        movementType,
        setMovementType,
    ] = useState(
        filters.movement_type ?? ""
    );

    const [
        direction,
        setDirection,
    ] = useState(
        filters.direction ?? ""
    );

    const [
        dateFrom,
        setDateFrom,
    ] = useState(
        filters.date_from ?? ""
    );

    const [
        dateTo,
        setDateTo,
    ] = useState(
        filters.date_to ?? ""
    );

    const [
        perPage,
        setPerPage,
    ] = useState(
        filters.per_page ?? "25"
    );

    const filteredWarehouses =
        useMemo(() => {
            if (!companyId) {
                return warehouses;
            }

            return warehouses.filter(
                (warehouse) =>
                    String(
                        warehouse.company_id
                    ) === companyId
            );
        }, [
            warehouses,
            companyId,
        ]);

    const changeCompany = (
        value: string
    ) => {
        setCompanyId(value);

        if (
            warehouseId &&
            !warehouses.some(
                (warehouse) =>
                    String(
                        warehouse.id
                    ) ===
                        warehouseId &&
                    (
                        !value ||
                        String(
                            warehouse.company_id
                        ) === value
                    )
            )
        ) {
            setWarehouseId("");
        }
    };

    const submitFilters = (
        event?: FormEvent
    ) => {
        event?.preventDefault();

        router.get(
            "/erp/inventory/stock-movements",
            cleanQuery({
                search,

                company_id:
                    companyId,

                warehouse_id:
                    warehouseId,

                category_id:
                    categoryId,

                brand_id:
                    brandId,

                movement_type:
                    movementType,

                direction,

                date_from:
                    dateFrom,

                date_to:
                    dateTo,

                per_page:
                    perPage,
            }),
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
        setWarehouseId("");
        setCategoryId("");
        setBrandId("");
        setMovementType("");
        setDirection("");
        setDateFrom("");
        setDateTo("");
        setPerPage("25");

        router.get(
            "/erp/inventory/stock-movements",
            {},
            {
                preserveState: false,
                preserveScroll: true,
                replace: true,
            }
        );
    };

    return (
        <ERPLayout>
            <Head title="Stock Movement Ledger" />

            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold">
                        Stock Movement Ledger
                    </h1>

                    <p className="mt-1 text-sm text-slate-500">
                        Immutable history
                        of physical inventory
                        movements across
                        warehouses.
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <SummaryCard
                        icon={BookOpen}
                        label="Movements"
                        value={formatNumber(
                            summary.total_movements
                        )}
                    />

                    <SummaryCard
                        icon={ArrowDownToLine}
                        label="Quantity In"
                        value={formatQuantity(
                            summary.quantity_in
                        )}
                    />

                    <SummaryCard
                        icon={ArrowUpFromLine}
                        label="Quantity Out"
                        value={formatQuantity(
                            summary.quantity_out
                        )}
                    />

                    <SummaryCard
                        icon={WalletCards}
                        label="Value In"
                        value={formatMoney(
                            summary.value_in
                        )}
                    />

                    <SummaryCard
                        icon={WalletCards}
                        label="Value Out"
                        value={formatMoney(
                            summary.value_out
                        )}
                    />
                </div>

                <form
                    onSubmit={
                        submitFilters
                    }
                    className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950"
                >
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <div className="relative md:col-span-2 xl:col-span-2">
                            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />

                            <input
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
                                placeholder="Search product, SKU, warehouse, reference or user..."
                                className={`${inputClass} pl-9`}
                            />
                        </div>

                        <select
                            value={
                                companyId
                            }
                            onChange={(
                                event
                            ) =>
                                changeCompany(
                                    event
                                        .target
                                        .value
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                All Companies
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
                                warehouseId
                            }
                            onChange={(
                                event
                            ) =>
                                setWarehouseId(
                                    event
                                        .target
                                        .value
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                All Warehouses
                            </option>

                            {filteredWarehouses.map(
                                (
                                    warehouse
                                ) => (
                                    <option
                                        key={
                                            warehouse.id
                                        }
                                        value={
                                            warehouse.id
                                        }
                                    >
                                        {
                                            warehouse.name
                                        }{" "}
                                        (
                                        {
                                            warehouse.code
                                        }
                                        )
                                    </option>
                                )
                            )}
                        </select>

                        <select
                            value={
                                categoryId
                            }
                            onChange={(
                                event
                            ) =>
                                setCategoryId(
                                    event
                                        .target
                                        .value
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                All Categories
                            </option>

                            {categories.map(
                                (
                                    category
                                ) => (
                                    <option
                                        key={
                                            category.id
                                        }
                                        value={
                                            category.id
                                        }
                                    >
                                        {
                                            category.name
                                        }
                                    </option>
                                )
                            )}
                        </select>

                        <select
                            value={
                                brandId
                            }
                            onChange={(
                                event
                            ) =>
                                setBrandId(
                                    event
                                        .target
                                        .value
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                All Brands
                            </option>

                            {brands.map(
                                (
                                    brand
                                ) => (
                                    <option
                                        key={
                                            brand.id
                                        }
                                        value={
                                            brand.id
                                        }
                                    >
                                        {
                                            brand.name
                                        }
                                    </option>
                                )
                            )}
                        </select>

                        <select
                            value={
                                movementType
                            }
                            onChange={(
                                event
                            ) =>
                                setMovementType(
                                    event
                                        .target
                                        .value
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                All Movement Types
                            </option>

                            {movementTypes.map(
                                (
                                    type
                                ) => (
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
                            value={
                                direction
                            }
                            onChange={(
                                event
                            ) =>
                                setDirection(
                                    event
                                        .target
                                        .value
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                All Directions
                            </option>

                            <option value="in">
                                IN
                            </option>

                            <option value="out">
                                OUT
                            </option>
                        </select>

                        <FieldGroup label="From Date">
                            <input
                                type="date"
                                value={
                                    dateFrom
                                }
                                onChange={(
                                    event
                                ) =>
                                    setDateFrom(
                                        event
                                            .target
                                            .value
                                    )
                                }
                                className={
                                    inputClass
                                }
                            />
                        </FieldGroup>

                        <FieldGroup label="To Date">
                            <input
                                type="date"
                                value={
                                    dateTo
                                }
                                onChange={(
                                    event
                                ) =>
                                    setDateTo(
                                        event
                                            .target
                                            .value
                                    )
                                }
                                className={
                                    inputClass
                                }
                            />
                        </FieldGroup>

                        <FieldGroup label="Rows">
                            <select
                                value={
                                    perPage
                                }
                                onChange={(
                                    event
                                ) =>
                                    setPerPage(
                                        event
                                            .target
                                            .value
                                    )
                                }
                                className={
                                    inputClass
                                }
                            >
                                <option value="10">
                                    10 per page
                                </option>

                                <option value="25">
                                    25 per page
                                </option>

                                <option value="50">
                                    50 per page
                                </option>

                                <option value="100">
                                    100 per page
                                </option>
                            </select>
                        </FieldGroup>
                    </div>

                    <div className="mt-4 flex flex-wrap gap-2">
                        <button
                            type="submit"
                            className="inline-flex h-10 items-center gap-2 rounded-md bg-slate-900 px-4 text-sm font-medium text-white transition hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200"
                        >
                            <Search className="h-4 w-4" />

                            Apply Filters
                        </button>

                        <button
                            type="button"
                            onClick={
                                resetFilters
                            }
                            className={
                                secondaryButtonClass
                            }
                        >
                            <X className="h-4 w-4" />

                            Reset
                        </button>
                    </div>
                </form>

                <div className="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[1550px] text-sm">
                            <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-900/60">
                                <tr>
                                    <Th>
                                        Product
                                    </Th>

                                    <Th>
                                        Warehouse
                                    </Th>

                                    <Th>
                                        Movement
                                    </Th>

                                    <Th>
                                        Direction
                                    </Th>

                                    <Th align="right">
                                        Quantity
                                    </Th>

                                    <Th align="right">
                                        Unit Cost
                                    </Th>

                                    <Th align="right">
                                        Total Cost
                                    </Th>

                                    <Th align="right">
                                        Before
                                    </Th>

                                    <Th align="right">
                                        After
                                    </Th>

                                    <Th>
                                        Reference
                                    </Th>

                                    <Th>
                                        Occurred
                                    </Th>

                                    <Th>
                                        Created By
                                    </Th>
                                </tr>
                            </thead>

                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {movements.data.map(
                                    (
                                        movement
                                    ) => (
                                        <MovementRow
                                            key={
                                                movement.id
                                            }
                                            movement={
                                                movement
                                            }
                                            movementTypes={
                                                movementTypes
                                            }
                                        />
                                    )
                                )}

                                {movements.data
                                    .length ===
                                    0 && (
                                    <tr>
                                        <td
                                            colSpan={
                                                12
                                            }
                                            className="px-6 py-16 text-center"
                                        >
                                            <BookOpen className="mx-auto h-9 w-9 text-slate-300" />

                                            <div className="mt-3 font-medium">
                                                No stock
                                                movements
                                                found
                                            </div>

                                            <p className="mt-1 text-sm text-slate-500">
                                                Movements
                                                will appear
                                                here whenever
                                                inventory is
                                                posted through
                                                the inventory
                                                service.
                                            </p>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    <Pagination
                        movements={
                            movements
                        }
                    />
                </div>
            </div>
        </ERPLayout>
    );
}

function MovementRow({
    movement,
    movementTypes,
}: {
    movement: StockMovement;

    movementTypes:
        MovementTypeOption[];
}) {
    const displayName =
        movement.variant
            ? `${movement.product?.name ?? "Product"} — ${movement.variant.name}`
            : movement.product
                  ?.name ??
              "Unknown product";

    const sku =
        movement.variant?.sku ||
        movement.product?.sku ||
        "—";

    const typeLabel =
        movementTypes.find(
            (type) =>
                type.value ===
                movement.movement_type
        )?.label ??
        humanize(
            movement.movement_type
        );

    return (
        <tr className="align-top hover:bg-slate-50/70 dark:hover:bg-slate-900/30">
            <Td>
                <div className="font-medium">
                    {displayName}
                </div>

                <div className="mt-1 font-mono text-xs text-slate-500">
                    SKU: {sku}
                </div>

                {movement.product
                    ?.category?.name && (
                    <div className="mt-1 text-xs text-slate-500">
                        {
                            movement
                                .product
                                .category
                                .name
                        }

                        {movement
                            .product
                            .brand
                            ?.name
                            ? ` · ${movement.product.brand.name}`
                            : ""}
                    </div>
                )}
            </Td>

            <Td>
                <div className="font-medium">
                    {movement
                        .warehouse
                        ?.name ?? "—"}
                </div>

                <div className="mt-1 font-mono text-xs text-slate-500">
                    {movement
                        .warehouse
                        ?.code ?? "—"}
                </div>

                <div className="mt-1 text-xs text-slate-500">
                    {movement
                        .company
                        ?.name ?? ""}
                </div>
            </Td>

            <Td>
                <span className="font-medium">
                    {typeLabel}
                </span>
            </Td>

            <Td>
                <DirectionBadge
                    direction={
                        movement.direction
                    }
                />
            </Td>

            <Td align="right">
                <span className="font-medium tabular-nums">
                    {formatQuantity(
                        movement.quantity
                    )}
                </span>
            </Td>

            <Td align="right">
                {formatMoney(
                    movement.unit_cost
                )}
            </Td>

            <Td align="right">
                <span className="font-medium tabular-nums">
                    {formatMoney(
                        movement.total_cost
                    )}
                </span>
            </Td>

            <Td align="right">
                <span className="tabular-nums text-slate-500">
                    {formatQuantity(
                        movement.balance_before
                    )}
                </span>
            </Td>

            <Td align="right">
                <span className="font-medium tabular-nums">
                    {formatQuantity(
                        movement.balance_after
                    )}
                </span>
            </Td>

            <Td>
                {movement.reference_number ? (
                    <>
                        <div className="font-medium">
                            {
                                movement.reference_number
                            }
                        </div>

                        {movement.reference_type && (
                            <div className="mt-1 text-xs text-slate-500">
                                {humanize(
                                    movement.reference_type
                                )}
                            </div>
                        )}
                    </>
                ) : (
                    <span className="text-slate-400">
                        —
                    </span>
                )}
            </Td>

            <Td>
                {formatDateTime(
                    movement.occurred_at
                )}
            </Td>

            <Td>
                {movement.creator
                    ?.name ?? "System"}
            </Td>
        </tr>
    );
}

function DirectionBadge({
    direction,
}: {
    direction: "in" | "out";
}) {
    const incoming =
        direction === "in";

    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold ${
                incoming
                    ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"
                    : "bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300"
            }`}
        >
            {incoming ? (
                <ArrowDownToLine className="h-3.5 w-3.5" />
            ) : (
                <ArrowUpFromLine className="h-3.5 w-3.5" />
            )}

            {incoming
                ? "IN"
                : "OUT"}
        </span>
    );
}

function SummaryCard({
    icon: Icon,
    label,
    value,
}: {
    icon: React.ComponentType<{
        className?: string;
    }>;

    label: string;
    value: string;
}) {
    return (
        <div className="min-w-0 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="text-xs font-medium uppercase tracking-wide text-slate-500">
                        {label}
                    </div>

                    <div className="mt-2 truncate text-xl font-semibold">
                        {value}
                    </div>
                </div>

                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 dark:bg-slate-900">
                    <Icon className="h-4 w-4 text-slate-500" />
                </div>
            </div>
        </div>
    );
}

function FieldGroup({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <label className="mb-1.5 block text-xs font-medium text-slate-500">
                {label}
            </label>

            {children}
        </div>
    );
}

function Pagination({
    movements,
}: {
    movements: PaginatedMovements;
}) {
    if (
        movements.last_page <= 1
    ) {
        return (
            <div className="border-t border-slate-200 px-4 py-3 text-sm text-slate-500 dark:border-slate-800">
                {movements.total}{" "}
                movement
                {movements.total === 1
                    ? ""
                    : "s"}
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-3 border-t border-slate-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
            <div className="text-sm text-slate-500">
                Showing{" "}
                {movements.from ?? 0}
                {" – "}
                {movements.to ?? 0}{" "}
                of{" "}
                {movements.total}
            </div>

            <div className="flex flex-wrap gap-1">
                {movements.links.map(
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
                                        preserveState:
                                            true,

                                        preserveScroll:
                                            true,
                                    }
                                );
                            }}
                            className={`min-w-9 rounded-md border px-3 py-1.5 text-sm transition ${
                                link.active
                                    ? "border-slate-900 bg-slate-900 text-white dark:border-white dark:bg-white dark:text-slate-900"
                                    : "border-slate-200 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-800 dark:hover:bg-slate-900"
                            }`}
                            dangerouslySetInnerHTML={{
                                __html:
                                    link.label,
                            }}
                        />
                    )
                )}
            </div>
        </div>
    );
}

function Th({
    children,
    align = "left",
}: {
    children: React.ReactNode;

    align?:
        | "left"
        | "right";
}) {
    return (
        <th
            className={`whitespace-nowrap px-4 py-3 font-medium ${
                align === "right"
                    ? "text-right"
                    : "text-left"
            }`}
        >
            {children}
        </th>
    );
}

function Td({
    children,
    align = "left",
}: {
    children: React.ReactNode;

    align?:
        | "left"
        | "right";
}) {
    return (
        <td
            className={`px-4 py-4 ${
                align === "right"
                    ? "text-right"
                    : "text-left"
            }`}
        >
            {children}
        </td>
    );
}

function numberValue(
    value:
        | string
        | number
        | null
        | undefined
): number {
    const number =
        Number(value ?? 0);

    return Number.isFinite(
        number
    )
        ? number
        : 0;
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
    value: string
): string {
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

function cleanQuery(
    values: Record<
        string,
        string
    >
): Record<string, string> {
    return Object.fromEntries(
        Object.entries(
            values
        ).filter(
            ([, value]) =>
                value !== ""
        )
    );
}

const inputClass =
    "h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-slate-400 focus:ring-2 focus:ring-slate-100 dark:border-slate-800 dark:bg-slate-950 dark:focus:border-slate-600 dark:focus:ring-slate-900";

const secondaryButtonClass =
    "inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 px-4 text-sm font-medium transition hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900";
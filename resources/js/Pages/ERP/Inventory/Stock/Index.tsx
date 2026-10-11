import {
    Head,
    router,
} from "@inertiajs/react";
import {
    Boxes,
    CircleAlert,
    PackageCheck,
    PackageOpen,
    Search,
    Warehouse,
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
    product_category_id?: number | null;
    brand_id?: number | null;

    name: string;
    sku?: string | null;

    reorder_level?: string | number | null;
    minimum_stock?: string | number | null;

    has_variants: boolean;

    category?: Category | null;
    brand?: Brand | null;
};

type ProductVariant = {
    id: number;
    product_id: number;

    name: string;
    sku?: string | null;
};

type InventoryBalance = {
    id: number;

    company_id: number;
    warehouse_id: number;
    product_id: number;
    product_variant_id?: number | null;

    quantity_on_hand: string;
    quantity_reserved: string;
    average_cost: string;

    last_movement_at?: string | null;

    company?: Company | null;
    warehouse?: WarehouseOption | null;
    product?: Product | null;
    variant?: ProductVariant | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedBalances = {
    data: InventoryBalance[];

    current_page: number;
    last_page: number;
    per_page: number;
    total: number;

    from?: number | null;
    to?: number | null;

    links: PaginationLink[];
};

type Summary = {
    stock_items: number;

    quantity_on_hand: string;
    quantity_reserved: string;
    quantity_available: string;

    inventory_value: string;
};

type Filters = {
    search: string;
    company_id: string;
    warehouse_id: string;
    category_id: string;
    brand_id: string;
    stock_status: string;
    per_page: string;
};

type Props = {
    balances: PaginatedBalances;

    summary: Summary;

    filters: Filters;

    companies: Company[];
    warehouses: WarehouseOption[];
    categories: Category[];
    brands: Brand[];
};

export default function Index({
    balances,
    summary,
    filters,
    companies,
    warehouses,
    categories,
    brands,
}: Props) {
    const [search, setSearch] =
        useState(filters.search ?? "");

    const [companyId, setCompanyId] =
        useState(
            filters.company_id ?? ""
        );

    const [warehouseId, setWarehouseId] =
        useState(
            filters.warehouse_id ?? ""
        );

    const [categoryId, setCategoryId] =
        useState(
            filters.category_id ?? ""
        );

    const [brandId, setBrandId] =
        useState(
            filters.brand_id ?? ""
        );

    const [
        stockStatus,
        setStockStatus,
    ] = useState(
        filters.stock_status ?? ""
    );

    const [perPage, setPerPage] =
        useState(
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

    const submitFilters = (
        event?: FormEvent
    ) => {
        event?.preventDefault();

        router.get(
            "/erp/inventory/stock",
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
                stock_status:
                    stockStatus,
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
        setStockStatus("");
        setPerPage("25");

        router.get(
            "/erp/inventory/stock",
            {},
            {
                preserveState: false,
                preserveScroll: true,
                replace: true,
            }
        );
    };

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

    return (
        <ERPLayout>
            <Head title="Warehouse Stock" />

            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold">
                        Warehouse Stock
                    </h1>

                    <p className="mt-1 text-sm text-slate-500">
                        Current physical,
                        reserved and available
                        inventory across
                        warehouses.
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <SummaryCard
                        icon={Boxes}
                        label="Stock Items"
                        value={formatNumber(
                            summary.stock_items,
                            0
                        )}
                    />

                    <SummaryCard
                        icon={PackageCheck}
                        label="On Hand"
                        value={formatQuantity(
                            summary.quantity_on_hand
                        )}
                    />

                    <SummaryCard
                        icon={CircleAlert}
                        label="Reserved"
                        value={formatQuantity(
                            summary.quantity_reserved
                        )}
                    />

                    <SummaryCard
                        icon={PackageOpen}
                        label="Available"
                        value={formatQuantity(
                            summary.quantity_available
                        )}
                    />

                    <SummaryCard
                        icon={Warehouse}
                        label="Inventory Value"
                        value={formatMoney(
                            summary.inventory_value
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
                        <div className="relative xl:col-span-2">
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
                                placeholder="Search product, SKU, variant or warehouse..."
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
                            value={brandId}
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
                                (brand) => (
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
                                stockStatus
                            }
                            onChange={(
                                event
                            ) =>
                                setStockStatus(
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
                                All Stock
                            </option>

                            <option value="in_stock">
                                In Stock
                            </option>

                            <option value="low_stock">
                                Low Stock
                            </option>

                            <option value="out_of_stock">
                                Out of Stock
                            </option>

                            <option value="negative">
                                Negative Stock
                            </option>
                        </select>

                        <select
                            value={perPage}
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
                            className={secondaryButtonClass}
                        >
                            <X className="h-4 w-4" />
                            Reset
                        </button>
                    </div>
                </form>

                <div className="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                    <div className="overflow-x-auto">
                        <table className="min-w-[1250px] w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-900/60">
                                <tr>
                                    <Th>
                                        Product
                                    </Th>

                                    <Th>
                                        Warehouse
                                    </Th>

                                    <Th>
                                        Category /
                                        Brand
                                    </Th>

                                    <Th align="right">
                                        On Hand
                                    </Th>

                                    <Th align="right">
                                        Reserved
                                    </Th>

                                    <Th align="right">
                                        Available
                                    </Th>

                                    <Th align="right">
                                        Avg. Cost
                                    </Th>

                                    <Th align="right">
                                        Value
                                    </Th>

                                    <Th>
                                        Status
                                    </Th>

                                    <Th>
                                        Last
                                        Movement
                                    </Th>
                                </tr>
                            </thead>

                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {balances.data.map(
                                    (
                                        balance
                                    ) => (
                                        <StockRow
                                            key={
                                                balance.id
                                            }
                                            balance={
                                                balance
                                            }
                                        />
                                    )
                                )}

                                {balances.data
                                    .length ===
                                    0 && (
                                    <tr>
                                        <td
                                            colSpan={
                                                10
                                            }
                                            className="px-6 py-16 text-center"
                                        >
                                            <PackageOpen className="mx-auto h-9 w-9 text-slate-300" />

                                            <div className="mt-3 font-medium">
                                                No
                                                inventory
                                                balances
                                                found
                                            </div>

                                            <p className="mt-1 text-sm text-slate-500">
                                                Stock
                                                rows will
                                                appear
                                                after an
                                                inventory
                                                movement
                                                creates a
                                                balance.
                                            </p>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    <Pagination
                        balances={
                            balances
                        }
                    />
                </div>
            </div>
        </ERPLayout>
    );
}

function StockRow({
    balance,
}: {
    balance: InventoryBalance;
}) {
    const onHand =
        numberValue(
            balance.quantity_on_hand
        );

    const reserved =
        numberValue(
            balance.quantity_reserved
        );

    const available =
        onHand - reserved;

    const averageCost =
        numberValue(
            balance.average_cost
        );

    const inventoryValue =
        onHand * averageCost;

    const reorderLevel =
        numberValue(
            balance.product
                ?.reorder_level
        );

    const status =
        stockStatus(
            onHand,
            reorderLevel
        );

    const displayName =
        balance.variant
            ? `${balance.product?.name ?? "Product"} — ${balance.variant.name}`
            : balance.product?.name ??
              "Unknown product";

    const sku =
        balance.variant?.sku ||
        balance.product?.sku ||
        "—";

    return (
        <tr className="align-top hover:bg-slate-50/70 dark:hover:bg-slate-900/30">
            <Td>
                <div className="font-medium">
                    {displayName}
                </div>

                <div className="mt-1 font-mono text-xs text-slate-500">
                    SKU: {sku}
                </div>

                {balance.variant && (
                    <div className="mt-1 text-xs text-slate-500">
                        Variant inventory
                    </div>
                )}
            </Td>

            <Td>
                <div className="font-medium">
                    {balance.warehouse
                        ?.name ?? "—"}
                </div>

                <div className="mt-1 text-xs text-slate-500">
                    {balance.warehouse
                        ?.code ?? "—"}
                </div>

                <div className="mt-1 text-xs text-slate-500">
                    {balance.company
                        ?.name ?? ""}
                </div>
            </Td>

            <Td>
                <div>
                    {balance.product
                        ?.category
                        ?.name ?? "—"}
                </div>

                <div className="mt-1 text-xs text-slate-500">
                    {balance.product
                        ?.brand
                        ?.name ?? "No brand"}
                </div>
            </Td>

            <Td align="right">
                <Quantity
                    value={onHand}
                />
            </Td>

            <Td align="right">
                <Quantity
                    value={reserved}
                />
            </Td>

            <Td align="right">
                <Quantity
                    value={available}
                    strong
                />
            </Td>

            <Td align="right">
                {formatMoney(
                    averageCost
                )}
            </Td>

            <Td align="right">
                <span className="font-medium">
                    {formatMoney(
                        inventoryValue
                    )}
                </span>
            </Td>

            <Td>
                <StockStatusBadge
                    status={status}
                />
            </Td>

            <Td>
                {formatDateTime(
                    balance.last_movement_at
                )}
            </Td>
        </tr>
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

function StockStatusBadge({
    status,
}: {
    status:
        | "in_stock"
        | "low_stock"
        | "out_of_stock"
        | "negative";
}) {
    const styles = {
        in_stock:
            "bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300",

        low_stock:
            "bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300",

        out_of_stock:
            "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300",

        negative:
            "bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300",
    };

    const labels = {
        in_stock:
            "In Stock",

        low_stock:
            "Low Stock",

        out_of_stock:
            "Out of Stock",

        negative:
            "Negative",
    };

    return (
        <span
            className={`inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium ${styles[status]}`}
        >
            {labels[status]}
        </span>
    );
}

function Pagination({
    balances,
}: {
    balances: PaginatedBalances;
}) {
    if (
        balances.last_page <= 1
    ) {
        return (
            <div className="border-t border-slate-200 px-4 py-3 text-sm text-slate-500 dark:border-slate-800">
                {balances.total}{" "}
                record
                {balances.total === 1
                    ? ""
                    : "s"}
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-3 border-t border-slate-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
            <div className="text-sm text-slate-500">
                Showing{" "}
                {balances.from ?? 0}
                {" – "}
                {balances.to ?? 0}{" "}
                of {balances.total}
            </div>

            <div className="flex flex-wrap gap-1">
                {balances.links.map(
                    (link, index) => (
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

function Quantity({
    value,
    strong = false,
}: {
    value: number;
    strong?: boolean;
}) {
    return (
        <span
            className={
                strong
                    ? "font-semibold tabular-nums"
                    : "tabular-nums"
            }
        >
            {formatQuantity(
                value
            )}
        </span>
    );
}

function Th({
    children,
    align = "left",
}: {
    children: React.ReactNode;
    align?: "left" | "right";
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
    align?: "left" | "right";
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

function stockStatus(
    onHand: number,
    reorderLevel: number
):
    | "in_stock"
    | "low_stock"
    | "out_of_stock"
    | "negative" {
    if (onHand < 0) {
        return "negative";
    }

    if (onHand === 0) {
        return "out_of_stock";
    }

    if (
        reorderLevel > 0 &&
        onHand <= reorderLevel
    ) {
        return "low_stock";
    }

    return "in_stock";
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

    return Number.isFinite(number)
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
        | undefined,
    decimals = 0
): string {
    return numberValue(
        value
    ).toLocaleString(
        "en-US",
        {
            minimumFractionDigits:
                decimals,
            maximumFractionDigits:
                decimals,
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

    const date = new Date(value);

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
            dateStyle: "medium",
            timeStyle: "short",
        }
    ).format(date);
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

import { Link, useForm } from "@inertiajs/react";
import { useMemo } from "react";
import type { FormEvent } from "react";

type Company = {
    id: number;
    name: string;
};

type Warehouse = {
    id: number;
    company_id: number;
    code: string | null;
    name: string;
    is_active: boolean;
};

type Variant = {
    id: number;
    product_id: number;
    name: string;
    sku: string | null;
};

type Product = {
    id: number;
    company_id: number;
    name: string;
    sku: string | null;
    variants: Variant[];
};

export type OpeningStockItem = {
    product_id: string;
    product_variant_id: string;
    quantity: string;
    unit_cost: string;
};

export type OpeningStockFormData = {
    company_id: string;
    warehouse_id: string;
    document_date: string;
    notes: string;
    items: OpeningStockItem[];
};

export type OpeningStockDocument = {
    id: number;
    document_number: string;
    company_id: number;
    warehouse_id: number;
    document_date: string;
    notes: string | null;
    status: string;
    items: {
        id: number;
        product_id: number;
        product_variant_id: number | null;
        quantity: string | number;
        unit_cost: string | number;
    }[];
};

type Props = {
    mode: "create" | "edit";
    companies: Company[];
    warehouses: Warehouse[];
    products: Product[];
    openingStock?: OpeningStockDocument;
};

const emptyItem = (): OpeningStockItem => ({
    product_id: "",
    product_variant_id: "",
    quantity: "1",
    unit_cost: "0",
});

const inputClass =
    "block w-full min-w-0 rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100";

const labelClass =
    "mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200";

const money = (value: number) =>
    new Intl.NumberFormat("en-BD", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 4,
    }).format(value);

const toDateInput = (value?: string) => {
    if (!value) {
        const now = new Date();
        const local = new Date(
            now.getTime() - now.getTimezoneOffset() * 60000,
        );
        return local.toISOString().slice(0, 10);
    }

    return value.slice(0, 10);
};

export default function Form({
    mode,
    companies,
    warehouses,
    products,
    openingStock,
}: Props) {
    const form = useForm<OpeningStockFormData>({
        company_id: openingStock
            ? String(openingStock.company_id)
            : "",
        warehouse_id: openingStock
            ? String(openingStock.warehouse_id)
            : "",
        document_date: toDateInput(openingStock?.document_date),
        notes: openingStock?.notes ?? "",
        items:
            openingStock?.items?.length
                ? openingStock.items.map((item) => ({
                      product_id: String(item.product_id),
                      product_variant_id:
                          item.product_variant_id == null
                              ? ""
                              : String(item.product_variant_id),
                      quantity: String(item.quantity),
                      unit_cost: String(item.unit_cost),
                  }))
                : [emptyItem()],
    });

    const availableWarehouses = useMemo(
        () =>
            warehouses.filter(
                (warehouse) =>
                    String(warehouse.company_id) ===
                        form.data.company_id &&
                    warehouse.is_active,
            ),
        [warehouses, form.data.company_id],
    );

    const availableProducts = useMemo(
        () =>
            products.filter(
                (product) =>
                    String(product.company_id) ===
                    form.data.company_id,
            ),
        [products, form.data.company_id],
    );

    const duplicateRows = useMemo(() => {
        const seen = new Map<string, number>();
        const duplicates = new Set<number>();

        form.data.items.forEach((item, index) => {
            if (!item.product_id) return;

            const key = `${item.product_id}:${item.product_variant_id || "none"}`;
            const previous = seen.get(key);

            if (previous !== undefined) {
                duplicates.add(previous);
                duplicates.add(index);
            } else {
                seen.set(key, index);
            }
        });

        return duplicates;
    }, [form.data.items]);

    const totals = useMemo(() => {
        return form.data.items.reduce(
            (result, item) => {
                const quantity = Number(item.quantity) || 0;
                const cost = Number(item.unit_cost) || 0;

                result.quantity += quantity;
                result.value += quantity * cost;

                return result;
            },
            { quantity: 0, value: 0 },
        );
    }, [form.data.items]);

    const setItem = (
        index: number,
        changes: Partial<OpeningStockItem>,
    ) => {
        form.setData(
            "items",
            form.data.items.map((item, itemIndex) =>
                itemIndex === index
                    ? { ...item, ...changes }
                    : item,
            ),
        );
    };

    const changeCompany = (companyId: string) => {
        form.setData({
            ...form.data,
            company_id: companyId,
            warehouse_id: "",
            items: [emptyItem()],
        });
        form.clearErrors();
    };

    const changeProduct = (index: number, productId: string) => {
        setItem(index, {
            product_id: productId,
            product_variant_id: "",
        });
    };

    const removeItem = (index: number) => {
        if (form.data.items.length <= 1) return;

        form.setData(
            "items",
            form.data.items.filter(
                (_, itemIndex) => itemIndex !== index,
            ),
        );
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (duplicateRows.size > 0 || form.processing) return;

        if (mode === "create") {
            form.post("/erp/inventory/opening-stocks");
        } else if (openingStock) {
            form.put(
                `/erp/inventory/opening-stocks/${openingStock.id}`,
            );
        }
    };

    const error = (key: string) =>
        (form.errors as Record<string, string | undefined>)[key];

    return (
        <form onSubmit={submit} className="min-w-0 space-y-6">
            <div className="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1fr)_290px]">
                <div className="min-w-0 space-y-6">
                    <section className="min-w-0 rounded-xl border border-neutral-200 bg-white p-4 shadow-sm sm:p-6 dark:border-neutral-800 dark:bg-neutral-950">
                        <h2 className="mb-5 text-lg font-semibold">
                            Document Information
                        </h2>

                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="min-w-0">
                                <label className={labelClass}>
                                    Company *
                                </label>
                                <select
                                    className={inputClass}
                                    value={form.data.company_id}
                                    onChange={(event) =>
                                        changeCompany(
                                            event.target.value,
                                        )
                                    }
                                    required
                                >
                                    <option value="">
                                        Select company
                                    </option>
                                    {companies.map((company) => (
                                        <option
                                            key={company.id}
                                            value={company.id}
                                        >
                                            {company.name}
                                        </option>
                                    ))}
                                </select>
                                {error("company_id") && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {error("company_id")}
                                    </p>
                                )}
                            </div>

                            <div className="min-w-0">
                                <label className={labelClass}>
                                    Warehouse *
                                </label>
                                <select
                                    className={inputClass}
                                    value={form.data.warehouse_id}
                                    onChange={(event) =>
                                        form.setData(
                                            "warehouse_id",
                                            event.target.value,
                                        )
                                    }
                                    disabled={!form.data.company_id}
                                    required
                                >
                                    <option value="">
                                        Select warehouse
                                    </option>
                                    {availableWarehouses.map(
                                        (warehouse) => (
                                            <option
                                                key={warehouse.id}
                                                value={warehouse.id}
                                            >
                                                {warehouse.name}
                                                {warehouse.code
                                                    ? ` (${warehouse.code})`
                                                    : ""}
                                            </option>
                                        ),
                                    )}
                                </select>
                                {error("warehouse_id") && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {error("warehouse_id")}
                                    </p>
                                )}
                            </div>

                            <div className="min-w-0">
                                <label className={labelClass}>
                                    Document Date *
                                </label>
                                <input
                                    type="date"
                                    className={inputClass}
                                    value={form.data.document_date}
                                    onChange={(event) =>
                                        form.setData(
                                            "document_date",
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                                {error("document_date") && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {error("document_date")}
                                    </p>
                                )}
                            </div>

                            {mode === "edit" && openingStock && (
                                <div className="min-w-0">
                                    <label className={labelClass}>
                                        Document Number
                                    </label>
                                    <div className={`${inputClass} bg-neutral-50 dark:bg-neutral-800`}>
                                        {openingStock.document_number}
                                    </div>
                                </div>
                            )}
                        </div>
                    </section>

                    <section className="min-w-0 rounded-xl border border-neutral-200 bg-white p-4 shadow-sm sm:p-6 dark:border-neutral-800 dark:bg-neutral-950">
                        <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 className="text-lg font-semibold">
                                    Opening Stock Items
                                </h2>
                                <p className="text-sm text-neutral-500">
                                    Enter the initial quantity and unit cost.
                                </p>
                            </div>

                            <button
                                type="button"
                                onClick={() =>
                                    form.setData("items", [
                                        ...form.data.items,
                                        emptyItem(),
                                    ])
                                }
                                disabled={!form.data.company_id}
                                className="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50 dark:bg-neutral-100 dark:text-neutral-900"
                            >
                                + Add Item
                            </button>
                        </div>

                        {error("items") && (
                            <p className="mb-3 text-sm text-red-600">
                                {error("items")}
                            </p>
                        )}

                        <div className="space-y-4">
                            {form.data.items.map((item, index) => {
                                const product = availableProducts.find(
                                    (candidate) =>
                                        String(candidate.id) ===
                                        item.product_id,
                                );

                                const variants = product?.variants ?? [];
                                const hasVariants = variants.length > 0;
                                const lineTotal =
                                    (Number(item.quantity) || 0) *
                                    (Number(item.unit_cost) || 0);

                                return (
                                    <div
                                        key={index}
                                        className={`min-w-0 rounded-xl border p-4 ${
                                            duplicateRows.has(index)
                                                ? "border-red-400"
                                                : "border-neutral-200 dark:border-neutral-800"
                                        }`}
                                    >
                                        <div className="mb-4 flex items-center justify-between">
                                            <span className="text-sm font-semibold">
                                                Item {index + 1}
                                            </span>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    removeItem(index)
                                                }
                                                disabled={
                                                    form.data.items.length <= 1
                                                }
                                                className="text-sm text-red-600 disabled:opacity-40"
                                            >
                                                Remove
                                            </button>
                                        </div>

                                        <div className="grid min-w-0 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                            <div className="min-w-0 lg:col-span-2">
                                                <label className={labelClass}>
                                                    Product *
                                                </label>
                                                <select
                                                    className={inputClass}
                                                    value={item.product_id}
                                                    onChange={(event) =>
                                                        changeProduct(
                                                            index,
                                                            event.target.value,
                                                        )
                                                    }
                                                    required
                                                >
                                                    <option value="">
                                                        Select product
                                                    </option>
                                                    {availableProducts.map(
                                                        (candidate) => (
                                                            <option
                                                                key={candidate.id}
                                                                value={candidate.id}
                                                            >
                                                                {candidate.name}
                                                                {candidate.sku
                                                                    ? ` — ${candidate.sku}`
                                                                    : ""}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                                {error(`items.${index}.product_id`) && (
                                                    <p className="mt-1 text-xs text-red-600">
                                                        {error(`items.${index}.product_id`)}
                                                    </p>
                                                )}
                                            </div>

                                            <div className="min-w-0 lg:col-span-2">
                                                <label className={labelClass}>
                                                    Variant
                                                </label>
                                                <select
                                                    className={inputClass}
                                                    value={item.product_variant_id}
                                                    disabled={!hasVariants}
                                                    onChange={(event) =>
                                                        setItem(index, {
                                                            product_variant_id:
                                                                event.target.value,
                                                        })
                                                    }
                                                    required={hasVariants}
                                                >
                                                    <option value="">
                                                        {hasVariants
                                                            ? "Select variant"
                                                            : "No variants"}
                                                    </option>
                                                    {variants.map((variant) => (
                                                        <option
                                                            key={variant.id}
                                                            value={variant.id}
                                                        >
                                                            {variant.name}
                                                            {variant.sku
                                                                ? ` — ${variant.sku}`
                                                                : ""}
                                                        </option>
                                                    ))}
                                                </select>
                                                {error(`items.${index}.product_variant_id`) && (
                                                    <p className="mt-1 text-xs text-red-600">
                                                        {error(`items.${index}.product_variant_id`)}
                                                    </p>
                                                )}
                                            </div>

                                            <div className="min-w-0">
                                                <label className={labelClass}>
                                                    Quantity *
                                                </label>
                                                <input
                                                    type="number"
                                                    min="0.0001"
                                                    step="0.0001"
                                                    className={inputClass}
                                                    value={item.quantity}
                                                    onChange={(event) =>
                                                        setItem(index, {
                                                            quantity:
                                                                event.target.value,
                                                        })
                                                    }
                                                    required
                                                />
                                                {error(`items.${index}.quantity`) && (
                                                    <p className="mt-1 text-xs text-red-600">
                                                        {error(`items.${index}.quantity`)}
                                                    </p>
                                                )}
                                            </div>

                                            <div className="min-w-0">
                                                <label className={labelClass}>
                                                    Unit Cost *
                                                </label>
                                                <input
                                                    type="number"
                                                    min="0"
                                                    step="0.0001"
                                                    className={inputClass}
                                                    value={item.unit_cost}
                                                    onChange={(event) =>
                                                        setItem(index, {
                                                            unit_cost:
                                                                event.target.value,
                                                        })
                                                    }
                                                    required
                                                />
                                                {error(`items.${index}.unit_cost`) && (
                                                    <p className="mt-1 text-xs text-red-600">
                                                        {error(`items.${index}.unit_cost`)}
                                                    </p>
                                                )}
                                            </div>

                                            <div className="min-w-0 sm:col-span-2">
                                                <label className={labelClass}>
                                                    Line Total
                                                </label>
                                                <div className="rounded-lg bg-neutral-100 px-3 py-2 text-sm font-semibold dark:bg-neutral-800">
                                                    ৳ {money(lineTotal)}
                                                </div>
                                            </div>
                                        </div>

                                        {duplicateRows.has(index) && (
                                            <p className="mt-3 text-sm text-red-600">
                                                Duplicate product/variant combination.
                                            </p>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </section>

                    <section className="min-w-0 rounded-xl border border-neutral-200 bg-white p-4 shadow-sm sm:p-6 dark:border-neutral-800 dark:bg-neutral-950">
                        <h2 className="mb-3 text-lg font-semibold">
                            Notes
                        </h2>
                        <textarea
                            rows={4}
                            className={inputClass}
                            value={form.data.notes}
                            onChange={(event) =>
                                form.setData(
                                    "notes",
                                    event.target.value,
                                )
                            }
                            placeholder="Optional opening stock notes..."
                        />
                        {error("notes") && (
                            <p className="mt-1 text-xs text-red-600">
                                {error("notes")}
                            </p>
                        )}
                    </section>
                </div>

                <aside className="min-w-0">
                    <div className="space-y-4 rounded-xl border border-neutral-200 bg-white p-5 shadow-sm xl:sticky xl:top-5 dark:border-neutral-800 dark:bg-neutral-950">
                        <h2 className="text-lg font-semibold">
                            Document Summary
                        </h2>

                        <div className="space-y-3 text-sm">
                            <div className="flex justify-between gap-3">
                                <span>Items</span>
                                <strong>{form.data.items.length}</strong>
                            </div>
                            <div className="flex justify-between gap-3">
                                <span>Total Quantity</span>
                                <strong>{money(totals.quantity)}</strong>
                            </div>
                            <div className="border-t border-neutral-200 pt-3 dark:border-neutral-800">
                                <span className="text-neutral-500">
                                    Total Stock Value
                                </span>
                                <p className="mt-1 break-words text-2xl font-bold">
                                    ৳ {money(totals.value)}
                                </p>
                            </div>
                        </div>

                        <div className="rounded-lg bg-blue-50 p-3 text-xs text-blue-800 dark:bg-blue-950 dark:text-blue-200">
                            Saving creates or updates a draft only.
                            Inventory balances will not change until posting.
                        </div>

                        <button
                            type="submit"
                            disabled={
                                form.processing ||
                                duplicateRows.size > 0
                            }
                            className="w-full rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {form.processing
                                ? "Saving..."
                                : mode === "create"
                                  ? "Save Draft"
                                  : "Update Draft"}
                        </button>

                        <Link
                            href={
                                openingStock
                                    ? `/erp/inventory/opening-stocks/${openingStock.id}`
                                    : "/erp/inventory/opening-stocks"
                            }
                            className="block rounded-lg border border-neutral-300 px-4 py-2.5 text-center text-sm font-medium dark:border-neutral-700"
                        >
                            Cancel
                        </Link>
                    </div>
                </aside>
            </div>
        </form>
    );
}


import { Link, useForm } from '@inertiajs/react';
import { useMemo, type FormEvent } from 'react';

export type Company = {
    id: number;
    name: string;
};

export type Warehouse = {
    id: number;
    company_id: number;
    name: string;
    code: string | null;
};

export type ProductVariant = {
    id: number;
    product_id: number;
    name: string;
    sku: string | null;
};

export type Product = {
    id: number;
    company_id: number;
    name: string;
    sku: string | null;
    has_variants: boolean;
    track_inventory: boolean;
    variants: ProductVariant[];
};

export type TransferItem = {
    id?: number;
    product_id: number;
    product_variant_id: number | null;
    quantity: string;
};

export type StockTransfer = {
    id: number;
    company_id: number;
    document_number: string;
    transfer_date: string;
    source_warehouse_id: number;
    destination_warehouse_id: number;
    status: string;
    notes: string | null;
    items: TransferItem[];
};

export type FormOptions = {
    companies: Company[];
    warehouses: Warehouse[];
    products: Product[];
};

type FormValues = {
    company_id: string;
    transfer_date: string;
    source_warehouse_id: string;
    destination_warehouse_id: string;
    notes: string;
    items: TransferItem[];
};

type Props = FormOptions & {
    stockTransfer?: StockTransfer;
};

const BASE = '/erp/inventory/stock-transfers';

const fieldClass =
    'mt-1 block w-full min-w-0 rounded-lg border ' +
    'border-slate-300 bg-white px-3 py-2 text-sm ' +
    'text-slate-900 outline-none focus:border-indigo-500 ' +
    'focus:ring-2 focus:ring-indigo-100 ' +
    'dark:border-slate-700 dark:bg-slate-900 ' +
    'dark:text-slate-100 dark:focus:ring-indigo-950';

const blankItem = (): TransferItem => ({
    product_id: 0,
    product_variant_id: null,
    quantity: '1',
});

function formatDate(value?: string): string {
    if (!value) return '';

    return value.slice(0, 10);
}

function today(): string {
    const date = new Date();
    const local = new Date(
        date.getTime() - date.getTimezoneOffset() * 60000
    );

    return local.toISOString().slice(0, 10);
}

function ErrorText({ message }: { message?: string }) {
    if (!message) return null;

    return (
        <p className="mt-1 text-xs text-red-600 dark:text-red-400">
            {message}
        </p>
    );
}

export default function Form({
    companies,
    warehouses,
    products,
    stockTransfer,
}: Props) {
    const editing = Boolean(stockTransfer);

    const { data, setData, post, put, processing, errors } =
        useForm<FormValues>({
            company_id: stockTransfer
                ? String(stockTransfer.company_id)
                : companies.length === 1
                  ? String(companies[0].id)
                  : '',
            transfer_date: stockTransfer
                ? formatDate(stockTransfer.transfer_date)
                : today(),
            source_warehouse_id: stockTransfer
                ? String(stockTransfer.source_warehouse_id)
                : '',
            destination_warehouse_id: stockTransfer
                ? String(stockTransfer.destination_warehouse_id)
                : '',
            notes: stockTransfer?.notes ?? '',
            items: stockTransfer?.items?.length
                ? stockTransfer.items.map((item) => ({
                      product_id: Number(item.product_id),
                      product_variant_id:
                          item.product_variant_id === null
                              ? null
                              : Number(item.product_variant_id),
                      quantity: String(item.quantity),
                  }))
                : [blankItem()],
        });

    const companyId = Number(data.company_id);

    const availableWarehouses = useMemo(
        () =>
            warehouses.filter(
                (warehouse) =>
                    warehouse.company_id === companyId
            ),
        [warehouses, companyId]
    );

    const availableProducts = useMemo(
        () =>
            products.filter(
                (product) =>
                    product.company_id === companyId &&
                    product.track_inventory
            ),
        [products, companyId]
    );

    const changeCompany = (value: string) => {
        setData((previous) => ({
            ...previous,
            company_id: value,
            source_warehouse_id: '',
            destination_warehouse_id: '',
            items: [blankItem()],
        }));
    };

    const changeItem = (
        index: number,
        changes: Partial<TransferItem>
    ) => {
        setData(
            'items',
            data.items.map((item, itemIndex) =>
                itemIndex === index
                    ? { ...item, ...changes }
                    : item
            )
        );
    };

    const addItem = () => {
        setData('items', [...data.items, blankItem()]);
    };

    const removeItem = (index: number) => {
        if (data.items.length === 1) return;

        setData(
            'items',
            data.items.filter(
                (_, itemIndex) => itemIndex !== index
            )
        );
    };

    const duplicateRows = useMemo(() => {
        const seen = new Set<string>();
        const duplicates = new Set<number>();

        data.items.forEach((item, index) => {
            if (!item.product_id) return;

            const key = `${item.product_id}:${
                item.product_variant_id ?? 0
            }`;

            if (seen.has(key)) {
                duplicates.add(index);
            } else {
                seen.add(key);
            }
        });

        return duplicates;
    }, [data.items]);

    const invalidItems = data.items.some((item) => {
        if (!item.product_id) return true;

        const product = availableProducts.find(
            (entry) => entry.id === item.product_id
        );

        if (!product) return true;

        if (
            product.has_variants &&
            !item.product_variant_id
        ) {
            return true;
        }

        if (
            !product.has_variants &&
            item.product_variant_id !== null
        ) {
            return true;
        }

        const quantity = Number(item.quantity);

        return (
            !Number.isFinite(quantity) ||
            quantity <= 0 ||
            !/^\d{1,14}(?:\.\d{1,4})?$/.test(
                item.quantity
            )
        );
    });

    const invalidWarehouses =
        !data.source_warehouse_id ||
        !data.destination_warehouse_id ||
        data.source_warehouse_id ===
            data.destination_warehouse_id;

    const cannotSubmit =
        processing ||
        !data.company_id ||
        !data.transfer_date ||
        invalidWarehouses ||
        invalidItems ||
        duplicateRows.size > 0;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (cannotSubmit) return;

        if (stockTransfer) {
            put(`${BASE}/${stockTransfer.id}`, {
                preserveScroll: true,
            });
        } else {
            post(BASE, {
                preserveScroll: true,
            });
        }
    };

    return (
        <form
            onSubmit={submit}
            className="space-y-6"
            noValidate
        >
            {/* Transfer Details */}
            <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-950">
                <h2 className="mb-5 text-lg font-semibold text-slate-900 dark:text-white">
                    Transfer Details
                </h2>

                <div className="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label
                            htmlFor="company_id"
                            className="text-sm font-medium"
                        >
                            Company *
                        </label>

                        <select
                            id="company_id"
                            value={data.company_id}
                            onChange={(event) =>
                                changeCompany(
                                    event.target.value
                                )
                            }
                            className={fieldClass}
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

                        <ErrorText
                            message={errors.company_id}
                        />
                    </div>

                    <div>
                        <label
                            htmlFor="transfer_date"
                            className="text-sm font-medium"
                        >
                            Transfer Date *
                        </label>

                        <input
                            id="transfer_date"
                            type="date"
                            value={data.transfer_date}
                            onChange={(event) =>
                                setData(
                                    'transfer_date',
                                    event.target.value
                                )
                            }
                            className={fieldClass}
                            required
                        />

                        <ErrorText
                            message={errors.transfer_date}
                        />
                    </div>

                    <div>
                        <label
                            htmlFor="source_warehouse_id"
                            className="text-sm font-medium"
                        >
                            Source Warehouse *
                        </label>

                        <select
                            id="source_warehouse_id"
                            value={data.source_warehouse_id}
                            onChange={(event) => {
                                const value =
                                    event.target.value;

                                setData((previous) => ({
                                    ...previous,
                                    source_warehouse_id: value,
                                    destination_warehouse_id:
                                        previous.destination_warehouse_id ===
                                        value
                                            ? ''
                                            : previous.destination_warehouse_id,
                                }));
                            }}
                            className={fieldClass}
                            disabled={!companyId}
                        >
                            <option value="">
                                Select source warehouse
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
                                            : ''}
                                    </option>
                                )
                            )}
                        </select>

                        <ErrorText
                            message={
                                errors.source_warehouse_id
                            }
                        />
                    </div>

                    <div>
                        <label
                            htmlFor="destination_warehouse_id"
                            className="text-sm font-medium"
                        >
                            Destination Warehouse *
                        </label>

                        <select
                            id="destination_warehouse_id"
                            value={
                                data.destination_warehouse_id
                            }
                            onChange={(event) =>
                                setData(
                                    'destination_warehouse_id',
                                    event.target.value
                                )
                            }
                            className={fieldClass}
                            disabled={!companyId}
                        >
                            <option value="">
                                Select destination warehouse
                            </option>

                            {availableWarehouses
                                .filter(
                                    (warehouse) =>
                                        String(
                                            warehouse.id
                                        ) !==
                                        data.source_warehouse_id
                                )
                                .map((warehouse) => (
                                    <option
                                        key={warehouse.id}
                                        value={warehouse.id}
                                    >
                                        {warehouse.name}
                                        {warehouse.code
                                            ? ` (${warehouse.code})`
                                            : ''}
                                    </option>
                                ))}
                        </select>

                        <ErrorText
                            message={
                                errors.destination_warehouse_id
                            }
                        />
                    </div>

                    <div className="sm:col-span-2">
                        <label
                            htmlFor="notes"
                            className="text-sm font-medium"
                        >
                            Notes
                        </label>

                        <textarea
                            id="notes"
                            value={data.notes}
                            onChange={(event) =>
                                setData(
                                    'notes',
                                    event.target.value
                                )
                            }
                            rows={3}
                            maxLength={5000}
                            placeholder="Optional transfer notes"
                            className={fieldClass}
                        />

                        <ErrorText
                            message={errors.notes}
                        />
                    </div>
                </div>
            </section>

            {/* Transfer Items */}
            <section className="min-w-0 rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-950 sm:p-5">
                <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-semibold text-slate-900 dark:text-white">
                            Transfer Items
                        </h2>

                        <p className="mt-1 text-xs text-slate-500">
                            Select products and quantities to
                            transfer.
                        </p>
                    </div>

                    <button
                        type="button"
                        onClick={addItem}
                        disabled={!companyId}
                        className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                    >
                        + Add Item
                    </button>
                </div>

                <ErrorText message={errors.items} />

                <div className="space-y-4">
                    {data.items.map((item, index) => {
                        const selectedProduct =
                            availableProducts.find(
                                (product) =>
                                    product.id ===
                                    item.product_id
                            );

                        return (
                            <div
                                key={index}
                                className="rounded-lg border border-slate-200 p-4 dark:border-slate-800"
                            >
                                <div className="mb-3 flex items-center justify-between">
                                    <h3 className="text-sm font-semibold">
                                        Item #{index + 1}
                                    </h3>

                                    <button
                                        type="button"
                                        onClick={() =>
                                            removeItem(index)
                                        }
                                        disabled={
                                            data.items.length ===
                                            1
                                        }
                                        className="text-xs font-semibold text-red-600 hover:underline disabled:opacity-40"
                                    >
                                        Remove
                                    </button>
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    <div>
                                        <label className="text-sm font-medium">
                                            Product *
                                        </label>

                                        <select
                                            value={
                                                item.product_id ||
                                                ''
                                            }
                                            onChange={(
                                                event
                                            ) =>
                                                changeItem(
                                                    index,
                                                    {
                                                        product_id:
                                                            Number(
                                                                event
                                                                    .target
                                                                    .value
                                                            ),
                                                        product_variant_id:
                                                            null,
                                                    }
                                                )
                                            }
                                            className={
                                                fieldClass
                                            }
                                            disabled={
                                                !companyId
                                            }
                                        >
                                            <option value="">
                                                Select product
                                            </option>

                                            {availableProducts.map(
                                                (product) => (
                                                    <option
                                                        key={
                                                            product.id
                                                        }
                                                        value={
                                                            product.id
                                                        }
                                                    >
                                                        {
                                                            product.name
                                                        }
                                                        {product.sku
                                                            ? ` (${product.sku})`
                                                            : ''}
                                                    </option>
                                                )
                                            )}
                                        </select>

                                        <ErrorText
                                            message={
                                                errors[
                                                    `items.${index}.product_id` as keyof typeof errors
                                                ]
                                            }
                                        />
                                    </div>

                                    <div>
                                        <label className="text-sm font-medium">
                                            Variant
                                            {selectedProduct?.has_variants
                                                ? ' *'
                                                : ''}
                                        </label>

                                        <select
                                            value={
                                                item.product_variant_id ??
                                                ''
                                            }
                                            onChange={(
                                                event
                                            ) =>
                                                changeItem(
                                                    index,
                                                    {
                                                        product_variant_id:
                                                            event
                                                                .target
                                                                .value
                                                                ? Number(
                                                                      event
                                                                          .target
                                                                          .value
                                                                  )
                                                                : null,
                                                    }
                                                )
                                            }
                                            className={
                                                fieldClass
                                            }
                                            disabled={
                                                !selectedProduct?.has_variants
                                            }
                                        >
                                            <option value="">
                                                {selectedProduct?.has_variants
                                                    ? 'Select variant'
                                                    : 'No variant'}
                                            </option>

                                            {selectedProduct?.variants.map(
                                                (variant) => (
                                                    <option
                                                        key={
                                                            variant.id
                                                        }
                                                        value={
                                                            variant.id
                                                        }
                                                    >
                                                        {
                                                            variant.name
                                                        }
                                                        {variant.sku
                                                            ? ` (${variant.sku})`
                                                            : ''}
                                                    </option>
                                                )
                                            )}
                                        </select>

                                        <ErrorText
                                            message={
                                                errors[
                                                    `items.${index}.product_variant_id` as keyof typeof errors
                                                ]
                                            }
                                        />
                                    </div>

                                    <div>
                                        <label className="text-sm font-medium">
                                            Quantity *
                                        </label>

                                        <input
                                            type="number"
                                            min="0.0001"
                                            step="0.0001"
                                            value={
                                                item.quantity
                                            }
                                            onChange={(
                                                event
                                            ) =>
                                                changeItem(
                                                    index,
                                                    {
                                                        quantity:
                                                            event
                                                                .target
                                                                .value,
                                                    }
                                                )
                                            }
                                            className={
                                                fieldClass
                                            }
                                        />

                                        <ErrorText
                                            message={
                                                errors[
                                                    `items.${index}.quantity` as keyof typeof errors
                                                ]
                                            }
                                        />
                                    </div>
                                </div>

                                {duplicateRows.has(index) && (
                                    <p className="mt-3 text-xs font-medium text-red-600">
                                        This product and
                                        variant combination is
                                        already selected.
                                    </p>
                                )}
                            </div>
                        );
                    })}
                </div>
            </section>

            {/* Actions */}
            <div className="flex flex-wrap items-center justify-end gap-3">
                <Link
                    href={
                        stockTransfer
                            ? `${BASE}/${stockTransfer.id}`
                            : BASE
                    }
                    className="rounded-lg border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-900"
                >
                    Cancel
                </Link>

                <button
                    type="submit"
                    disabled={cannotSubmit}
                    className="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {processing
                        ? 'Saving...'
                        : editing
                          ? 'Update Draft'
                          : 'Save Draft'}
                </button>
            </div>
        </form>
    );
}

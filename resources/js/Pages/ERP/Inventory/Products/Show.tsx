import ERPLayout from "@/Layouts/erp-layout";
import { useAuthorization } from "@/hooks/useAuthorization";
import type { Product } from "@/types/product";
import { Head, Link } from "@inertiajs/react";
import { Package } from "lucide-react";

interface Props {
    product: Product;
}

export default function Show({ product }: Props) {
    const { can } = useAuthorization();

    return (
        <ERPLayout>
            <Head title={product.name} />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold">{product.name}</h1>
                        <p className="mt-1 text-sm text-slate-500">
                            {product.product_code ?? product.sku ?? `#${product.id}`}
                        </p>
                    </div>

                    {can("erp.products.edit") && (
                        <Link
                            href={`/erp/inventory/products/${product.id}/edit`}
                            className="inline-flex h-10 items-center justify-center rounded-md border border-slate-200 px-4 text-sm font-medium hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                        >
                            Edit Product
                        </Link>
                    )}
                </div>

                <div className="grid gap-6 xl:grid-cols-[360px_minmax(0,1fr)]">
                    <section className="space-y-4">
                        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                            <div className="flex aspect-square items-center justify-center bg-slate-50 dark:bg-slate-900">
                                {product.featured_image_url ? (
                                    <img
                                        src={product.featured_image_url}
                                        alt={product.name}
                                        className="h-full w-full object-cover"
                                    />
                                ) : (
                                    <Package className="h-16 w-16 text-slate-300" />
                                )}
                            </div>
                        </div>

                        {(product.gallery_image_urls ?? []).length > 0 && (
                            <div className="grid grid-cols-4 gap-2">
                                {product.gallery_image_urls.map((image) => (
                                    <div
                                        key={image.path}
                                        className="aspect-square overflow-hidden rounded-lg border border-slate-200 dark:border-slate-800"
                                    >
                                        <img
                                            src={image.url}
                                            alt={product.name}
                                            className="h-full w-full object-cover"
                                        />
                                    </div>
                                ))}
                            </div>
                        )}
                    </section>

                    <div className="space-y-6">
                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                            <Metric label="Purchase" value={Number(product.purchase_price).toLocaleString()} />
                            <Metric label="Selling" value={Number(product.selling_price).toLocaleString()} />
                            <Metric
                                label="Inventory"
                                value={
                                    product.product_type === "service"
                                        ? "N/A"
                                        : product.track_inventory
                                          ? "Tracked"
                                          : "Not tracked"
                                }
                            />
                            <Metric label="Status" value={product.is_active ? "Active" : "Inactive"} />
                        </div>

                        <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                            <h2 className="font-semibold">Product details</h2>
                            <dl className="mt-5 grid gap-4 text-sm md:grid-cols-2">
                                <Detail label="Company" value={product.company?.name ?? "—"} />
                                <Detail label="Category" value={product.category?.name ?? "—"} />
                                <Detail label="Brand" value={product.brand?.name ?? "—"} />
                                <Detail label="Unit" value={product.unit ? `${product.unit.name} (${product.unit.short_name})` : "—"} />
                                <Detail label="SKU" value={product.sku ?? "—"} />
                                <Detail label="Barcode" value={product.barcode ?? "—"} />
                            </dl>
                        </section>

                        {product.short_description && (
                            <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                                <h2 className="font-semibold">Short description</h2>
                                <p className="mt-4 whitespace-pre-wrap text-sm leading-6 text-slate-600 dark:text-slate-300">
                                    {product.short_description}
                                </p>
                            </section>
                        )}

                        {product.description && (
                            <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                                <h2 className="font-semibold">Description</h2>
                                <p className="mt-4 whitespace-pre-wrap text-sm leading-6 text-slate-600 dark:text-slate-300">
                                    {product.description}
                                </p>
                            </section>
                        )}

                        {product.has_variants && (
                            <section className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                                <h2 className="font-semibold">Variants</h2>
                                <div className="mt-4 space-y-2">
                                    {(product.variants ?? []).map((variant) => (
                                        <div
                                            key={variant.id}
                                            className="grid gap-2 rounded-md border border-slate-200 p-3 text-sm md:grid-cols-4 dark:border-slate-800"
                                        >
                                            <span className="font-medium">{variant.name}</span>
                                            <span className="text-slate-500">{variant.sku ?? "—"}</span>
                                            <span className="text-slate-500">
                                                {variant.selling_price !== null
                                                    ? Number(variant.selling_price).toLocaleString()
                                                    : "Default price"}
                                            </span>
                                            <span>{variant.is_active ? "Active" : "Inactive"}</span>
                                        </div>
                                    ))}
                                </div>
                            </section>
                        )}
                    </div>
                </div>
            </div>
        </ERPLayout>
    );
}

function Metric({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
            <div className="text-xs text-slate-500">{label}</div>
            <div className="mt-2 text-lg font-semibold">{value}</div>
        </div>
    );
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-xs text-slate-500">{label}</dt>
            <dd className="mt-1 font-medium">{value}</dd>
        </div>
    );
}

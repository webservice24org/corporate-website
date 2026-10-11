import ERPLayout from "@/Layouts/erp-layout";
import DeleteConfirmDialog from "@/Components/Common/DeleteConfirmDialog";
import { useAuthorization } from "@/hooks/useAuthorization";
import type { CompanyOption, Product } from "@/types/product";
import { Head, Link, router } from "@inertiajs/react";
import { Eye, Package, Pencil, Plus, Search, Trash2 } from "lucide-react";
import { useState } from "react";

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

interface Props {
    products: {
        data: Product[];
        links: PaginationLink[];
        total?: number;
    };
    companies: CompanyOption[];
    filters?: {
        search?: string;
        company_id?: string | number;
        product_type?: string;
        status?: string;
    };
}

export default function Index({ products, companies, filters }: Props) {
    const { can } = useAuthorization();

    const [search, setSearch] = useState(filters?.search ?? "");
    const [companyFilter, setCompanyFilter] = useState(
        filters?.company_id !== undefined && filters?.company_id !== null
            ? String(filters.company_id)
            : ""
    );
    const [typeFilter, setTypeFilter] = useState(filters?.product_type ?? "");
    const [statusFilter, setStatusFilter] = useState(filters?.status ?? "");
    const [deletingProduct, setDeletingProduct] = useState<Product | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    const applyFilters = () => {
        router.get(
            "/erp/inventory/products",
            {
                search: search || undefined,
                company_id: companyFilter || undefined,
                product_type: typeFilter || undefined,
                status: statusFilter || undefined,
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

        router.get(
            "/erp/inventory/products",
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            }
        );
    };

    const confirmDelete = () => {
        if (!deletingProduct) return;

        setIsDeleting(true);

        router.delete(`/erp/inventory/products/${deletingProduct.id}`, {
            preserveScroll: true,
            onFinish: () => {
                setIsDeleting(false);
                setDeletingProduct(null);
            },
        });
    };

    return (
        <ERPLayout>
            <Head title="Products" />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold">Products</h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Shared catalog for Inventory, Purchasing and Sales.
                        </p>
                    </div>

                    {can("erp.products.create") && (
                        <Link
                            href="/erp/inventory/products/create"
                            className="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-slate-900 px-4 text-sm font-medium text-white dark:bg-white dark:text-slate-950"
                        >
                            <Plus className="h-4 w-4" />
                            Add Product
                        </Link>
                    )}
                </div>

                <div className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                    <div className="grid gap-3 md:grid-cols-2 2xl:grid-cols-[minmax(220px,1fr)_220px_170px_160px_auto_auto]">
                        <div className="relative">
                            <Search className="absolute left-3 top-3 h-4 w-4 text-slate-400" />
                            <input
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                onKeyDown={(event) => event.key === "Enter" && applyFilters()}
                                placeholder="Search product, code, SKU or barcode"
                                className="h-10 w-full rounded-md border border-slate-200 bg-white pl-9 pr-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                            />
                        </div>

                        <select
                            value={companyFilter}
                            onChange={(event) => setCompanyFilter(event.target.value)}
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
                            <option value="">All Companies</option>
                            {companies.map((company) => (
                                <option key={company.id} value={company.id}>
                                    {company.name}
                                </option>
                            ))}
                        </select>

                        <select
                            value={typeFilter}
                            onChange={(event) => setTypeFilter(event.target.value)}
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
                            <option value="">All Types</option>
                            <option value="physical">Physical</option>
                            <option value="service">Service</option>
                        </select>

                        <select
                            value={statusFilter}
                            onChange={(event) => setStatusFilter(event.target.value)}
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-800 dark:bg-slate-950"
                        >
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>

                        <button
                            type="button"
                            onClick={applyFilters}
                            className="h-10 rounded-md bg-slate-900 px-4 text-sm font-medium text-white dark:bg-white dark:text-slate-950"
                        >
                            Apply
                        </button>

                        <button
                            type="button"
                            onClick={resetFilters}
                            className="h-10 rounded-md border border-slate-200 px-4 text-sm font-medium dark:border-slate-800"
                        >
                            Reset
                        </button>
                    </div>
                </div>

                <div className="min-w-0 overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                    <div className="w-full overflow-x-auto">
                        <table className="w-full min-w-[1050px] text-left text-sm">
                            <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase text-slate-500 dark:border-slate-800 dark:bg-slate-900/50">
                                <tr>
                                    <th className="px-5 py-3">Product</th>
                                    <th className="px-5 py-3">Type</th>
                                    <th className="px-5 py-3">Category</th>
                                    <th className="px-5 py-3">Brand</th>
                                    <th className="px-5 py-3">Unit</th>
                                    <th className="px-5 py-3 text-right">Selling Price</th>
                                    <th className="px-5 py-3">Status</th>
                                    <th className="px-5 py-3 text-right">Actions</th>
                                </tr>
                            </thead>

                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {products.data.map((product) => (
                                    <tr key={product.id}>
                                        <td className="px-5 py-4">
                                            <div className="flex items-center gap-3">
                                                <div className="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50 dark:border-slate-800 dark:bg-slate-900">
                                                    {product.featured_image_url ? (
                                                        <img
                                                            src={product.featured_image_url}
                                                            alt={product.name}
                                                            className="h-full w-full object-cover"
                                                        />
                                                    ) : (
                                                        <Package className="h-5 w-5 text-slate-400" />
                                                    )}
                                                </div>

                                                <div className="min-w-0">
                                                    <Link
                                                        href={`/erp/inventory/products/${product.id}`}
                                                        className="font-medium hover:underline"
                                                    >
                                                        {product.name}
                                                    </Link>
                                                    <div className="mt-0.5 text-xs text-slate-500">
                                                        {product.product_code ?? product.sku ?? `#${product.id}`}
                                                        {product.variants_count
                                                            ? ` · ${product.variants_count} variants`
                                                            : ""}
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                        <td className="px-5 py-4 capitalize">
                                            {product.product_type}
                                        </td>
                                        <td className="px-5 py-4">{product.category?.name ?? "—"}</td>
                                        <td className="px-5 py-4">{product.brand?.name ?? "—"}</td>
                                        <td className="px-5 py-4">{product.unit?.short_name ?? "—"}</td>
                                        <td className="px-5 py-4 text-right font-medium">
                                            {Number(product.selling_price).toLocaleString()}
                                        </td>
                                        <td className="px-5 py-4">
                                            <span
                                                className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${
                                                    product.is_active
                                                        ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"
                                                        : "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300"
                                                }`}
                                            >
                                                {product.is_active ? "Active" : "Inactive"}
                                            </span>
                                        </td>

                                        <td className="px-5 py-4">
                                            <div className="flex justify-end gap-2">
                                                {can("erp.products.view") && (
                                                    <Link
                                                        href={`/erp/inventory/products/${product.id}`}
                                                        title="View Product"
                                                        className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                                                    >
                                                        <Eye className="h-4 w-4" />
                                                    </Link>
                                                )}

                                                {can("erp.products.edit") && (
                                                    <Link
                                                        href={`/erp/inventory/products/${product.id}/edit`}
                                                        title="Edit Product"
                                                        className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-900"
                                                    >
                                                        <Pencil className="h-4 w-4" />
                                                    </Link>
                                                )}

                                                {can("erp.products.delete") && (
                                                    <button
                                                        type="button"
                                                        title="Archive Product"
                                                        onClick={() => setDeletingProduct(product)}
                                                        className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-red-200 text-red-600 hover:bg-red-50 dark:border-red-900 dark:hover:bg-red-950/30"
                                                    >
                                                        <Trash2 className="h-4 w-4" />
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}

                                {products.data.length === 0 && (
                                    <tr>
                                        <td colSpan={8} className="px-5 py-12 text-center text-slate-500">
                                            No products found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {products.links?.length > 3 && (
                    <div className="flex flex-wrap gap-2">
                        {products.links.map((link, index) => (
                            <button
                                key={`${link.label}-${index}`}
                                type="button"
                                disabled={!link.url}
                                onClick={() => link.url && router.visit(link.url, { preserveScroll: true })}
                                className={`h-9 rounded-md border px-3 text-sm ${
                                    link.active
                                        ? "bg-slate-900 text-white dark:bg-white dark:text-slate-950"
                                        : "border-slate-200 dark:border-slate-800"
                                } disabled:opacity-40`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </div>

            <DeleteConfirmDialog
                open={!!deletingProduct}
                onOpenChange={(open) => {
                    if (!open && !isDeleting) {
                        setDeletingProduct(null);
                    }
                }}
                onConfirm={confirmDelete}
                title="Archive Product?"
                itemName={deletingProduct?.name}
                processing={isDeleting}
            />
        </ERPLayout>
    );
}
